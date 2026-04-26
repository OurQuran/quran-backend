<?php

namespace App\Console\Commands\MushafWords;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auto-map mushaf_words -> words using:
 * - mushaf_ayah_to_ayah_map (exact/combined/split at ayah level), like map mushaf ayahs to base ayahs
 * - qiraat_differences (qiraat_reading_id, surah, ayah, hafs_text) as "diff blocks": (surah, ayah) locates
 *   the base ayah, then hafs_text is matched in that ayah's base words to get the span [start_i, end_i]
 *
 * Word mapping strategies (in order):
 *  1) EXACT: norm(mushaf_word) == norm(base_word)
 *  2) EXACT (SKELETON): skel(mushaf_word) == skel(base_word)  (rasm-tolerant, removes alef)
 *  3) COMBINED: mushaf token equals concat of next K base tokens (K <= maxCombinedWords)
 *  4) SPLIT: concat of next K mushaf tokens equals base token (K <= maxSplitWords)
 *  5) If inside a diff block: local DP alignment for that block (token-level Needleman-Wunsch)
 *
 * Guarantees:
 * - Safe to re-run; converges.
 * - No broken combined/split groups remain (cleanup deletes broken groups).
 * - Combined/split rebuilt as whole groups when forced.
 *
 * Notes:
 * - Decorative symbol ۞ is ignored during normalization to prevent drift.
 * - Parameter-limit safe: deletes/checks are chunked or use subqueries.
 */
class AutoMapMushafWords extends Command
{
    protected $signature = 'qiraat:auto-map-words
        {qiraat_reading_id : qiraat_readings.id OR "auto" to run for all qiraat present in mushaf_ayahs}
        {--only-unmapped : Only process mushaf_words that have no mapping rows yet (but forced remaps still occur)}
        {--max-combined-words=4 : Max base words to combine (2..N)}
        {--max-split-words=4 : Max mushaf words to split (2..N)}
        {--dry-run : Do not write anything}
        {--report= : Path to report CSV (default: storage/app/qiraat_import_logs)}
        {--report-limit=20000 : Max report lines}
        {--window-radius=10 : Pointer drift window (tokens) for combined/split attempts}
        {--forward-scan=25 : Forward scan window (tokens) for combined/split attempts}
        {--preclean : Cleanup broken groups before mapping}
        {--postclean : Cleanup broken groups after mapping}
        {--keep-hamza : If set, do NOT remove hamza during normalization}
        {--dp-max-block=30 : Max tokens per diff block for DP fallback (keeps it fast/reliable)}
    ';

    protected $description = 'Auto-map mushaf_words to base words with diff-block guidance + exact/combined/split + cleanup + reporting.';

    private $reportFp = null;
    private int $reportLines = 0;
    private int $reportLimit = 20000;
    private string $reportPath = '';

    // counters
    private int $cntExact = 0;
    private int $cntCombined = 0;
    private int $cntSplit = 0;
    private int $cntDp = 0;
    private int $cntUnresolved = 0;

    // For reporting context
    private int $curSurahId = 0;
    private int $curAyahNo = 0;

    public function handle(): int
    {
        $arg = $this->argument('qiraat_reading_id');
        $onlyUnmapped = (bool) $this->option('only-unmapped');
        $dryRun = (bool) $this->option('dry-run');

        $maxCombined = max(2, (int) ($this->option('max-combined-words') ?? 4));
        $maxSplit    = max(2, (int) ($this->option('max-split-words') ?? 4));

        $this->reportLimit = (int) ($this->option('report-limit') ?? 20000);
        if ($this->reportLimit <= 0) $this->reportLimit = 20000;

        if (strtolower((string) $arg) === 'auto') {
            $qiraatIds = $this->resolveAutoQiraatIds();
            foreach ($qiraatIds as $id) {
                $this->runSingle((int) $id, $onlyUnmapped, $dryRun, $maxCombined, $maxSplit);
            }
            return self::SUCCESS;
        }

        return $this->runSingle((int) $arg, $onlyUnmapped, $dryRun, $maxCombined, $maxSplit);
    }

    private function runSingle(int $qiraatId, bool $onlyUnmapped, bool $dryRun, int $maxCombined, int $maxSplit): int
    {
        if (!DB::table('qiraat_readings')->where('id', $qiraatId)->exists()) {
            $this->error("Qiraat ID {$qiraatId} not found.");
            return self::FAILURE;
        }

        $windowRadius = max(1, (int) ($this->option('window-radius') ?? 10));
        $forwardScan  = max(1, (int) ($this->option('forward-scan') ?? 25));
        $dpMaxBlock   = max(5, (int) ($this->option('dp-max-block') ?? 30));

        $doPreclean   = (bool) $this->option('preclean');
        $doPostclean  = (bool) $this->option('postclean');

        $this->resetCounters();
        $this->openReport($this->option('report'), $qiraatId);

        $this->info(
            "Qiraat={$qiraatId} | " .
                ($dryRun ? "DRY-RUN" : "WRITE") . " | " .
                ($onlyUnmapped ? "ONLY-UNMAPPED" : "REMAP") . " | " .
                "maxCombinedWords={$maxCombined}, maxSplitWords={$maxSplit} | window={$windowRadius}, scan={$forwardScan}, dpMaxBlock={$dpMaxBlock}"
        );
        $this->line("Report: {$this->reportPath}");

        if (!$dryRun && $doPreclean) {
            $this->line("Pre-clean: removing broken/mixed word groups...");
            $this->cleanupAllBrokenWordGroupsForQiraat($qiraatId);
        }

        // Ensure mushaf_ayahs are mapped to base ayahs (by surah_id, number_in_surah) so word mapping can run
        $ensured = $this->ensureAyahMappingsForQiraat($qiraatId, $dryRun);
        if ($ensured > 0) {
            $this->line("Auto-created {$ensured} missing ayah-level mapping(s) (mushaf_ayah -> ayahs).");
        }

        $mushafAyahs = DB::table('mushaf_ayahs')
            ->select('id', 'surah_id', 'number_in_surah')
            ->where('qiraat_reading_id', $qiraatId)
            ->orderBy('surah_id')
            ->orderBy('number_in_surah')
            ->orderBy('id')
            ->get();

        foreach ($mushafAyahs as $ma) {
            $mushafAyahId = (int) $ma->id;
            $this->curSurahId = (int) $ma->surah_id;
            $this->curAyahNo  = (int) $ma->number_in_surah;

            $ayMap = $this->loadAyahMappingGroup($mushafAyahId, $qiraatId);
            if ($ayMap === null) {
                $this->reportRow($mushafAyahId, $this->curSurahId, $this->curAyahNo, 'no_ayah_mapping', '');
                continue;
            }

            // For split groups, only process when we're the first mushaf ayah (avoid duplicate work)
            if ($ayMap['type'] === 'split') {
                $firstMushafId = min($ayMap['mushaf_ayah_ids']);
                if ($mushafAyahId !== $firstMushafId) {
                    continue;
                }
            }

            [$mushafWordSeq, $baseWordSeq, $context] = $this->buildWordSequencesForAyahGroup($qiraatId, $ayMap);

            if (empty($mushafWordSeq) || empty($baseWordSeq)) {
                $this->reportRow($mushafAyahId, $this->curSurahId, $this->curAyahNo, 'empty_word_seq', $context);
                continue;
            }

            $diffBlocks = $this->loadDiffBlocksAsIndices($qiraatId, $baseWordSeq);

            if ($onlyUnmapped && $this->allMushafWordsAlreadyMapped($mushafWordSeq)) {
                continue;
            }

            [$rowsToUpsert, $forceRemapMushafWordIds] = $this->mapWordSequences(
                $mushafWordSeq,
                $baseWordSeq,
                $diffBlocks,
                $maxCombined,
                $maxSplit,
                $windowRadius,
                $forwardScan,
                $dpMaxBlock,
                $mushafAyahId
            );

            if (!$dryRun && (!$onlyUnmapped || !empty($rowsToUpsert))) {
                $currentMushafWordIds = array_values(array_unique(array_map(
                    fn($token) => (int) ($token['id'] ?? 0),
                    $mushafWordSeq
                )));

                $this->saveWordMappingsUpsert($currentMushafWordIds, $rowsToUpsert, $onlyUnmapped, $forceRemapMushafWordIds);
            }
        }

        if (!$dryRun && $doPostclean) {
            $this->line("Post-clean: removing broken/mixed word groups...");
            $this->cleanupAllBrokenWordGroupsForQiraat($qiraatId);
        }

        $this->newLine();
        $this->info("Completed qiraat={$qiraatId}");
        $this->line("- Exact: {$this->cntExact}");
        $this->line("- Combined: {$this->cntCombined}");
        $this->line("- Split: {$this->cntSplit}");
        $this->line("- DP-block: {$this->cntDp}");
        $this->line("- Unresolved: {$this->cntUnresolved}");
        $this->line("- Report lines: {$this->reportLines} (limit {$this->reportLimit})");
        $this->line("- Report file: {$this->reportPath}");

        $this->closeReport();
        return self::SUCCESS;
    }

    /**
     * Ayah-level mapping group load.
     */
    private function loadAyahMappingGroup(int $mushafAyahId, int $qiraatId): ?array
    {
        $rows = DB::table('mushaf_ayah_to_ayah_map')
            ->where('mushaf_ayah_id', $mushafAyahId)
            ->orderByRaw("CASE WHEN map_type='combined' THEN ayah_order ELSE 1 END")
            ->get();

        if ($rows->isEmpty()) return null;

        $type = (string) $rows[0]->map_type;
        // MapMushafAyahsToBaseAyahs uses 'exact_base_dup' when base has duplicates; treat as exact
        if ($type === 'exact_base_dup') {
            $type = 'exact';
        }

        if ($type === 'exact') {
            return [
                'type' => 'exact',
                'mushaf_ayah_ids' => [$mushafAyahId],
                'base_ayah_ids' => [(int) $rows[0]->ayah_id],
                'meta' => [],
            ];
        }

        if ($type === 'combined') {
            $baseIds = $rows->pluck('ayah_id')->map(fn($v) => (int)$v)->all();
            return [
                'type' => 'combined',
                'mushaf_ayah_ids' => [$mushafAyahId],
                'base_ayah_ids' => $baseIds,
                'meta' => [
                    'parts_total' => (int) ($rows[0]->parts_total ?? count($baseIds)),
                ],
            ];
        }

        if ($type === 'split') {
            $baseAyahId = (int) $rows[0]->ayah_id;

            $group = DB::table('mushaf_ayah_to_ayah_map as m')
                ->join('mushaf_ayahs as ma', 'ma.id', '=', 'm.mushaf_ayah_id')
                ->where('m.ayah_id', $baseAyahId)
                ->where('m.map_type', 'split')
                ->where('ma.qiraat_reading_id', $qiraatId)
                ->orderBy('m.part_no')
                ->select('m.*')
                ->get();

            $mushafIds = $group->pluck('mushaf_ayah_id')->map(fn($v) => (int)$v)->all();

            return [
                'type' => 'split',
                'mushaf_ayah_ids' => $mushafIds,
                'base_ayah_ids' => [$baseAyahId],
                'meta' => [
                    'parts_total' => (int) ($group[0]->parts_total ?? count($mushafIds)),
                ],
            ];
        }

        return null;
    }

    /**
     * Ensure every mushaf_ayah for this qiraat has at least one row in mushaf_ayah_to_ayah_map,
     * using a safe exact-text match within the same surah.
     *
     * This is intentionally conservative. Some qiraat shift ayah numbering (for example
     * basmala handling), so blindly mapping by number_in_surah can poison word alignment.
     */
    private function ensureAyahMappingsForQiraat(int $qiraatId, bool $dryRun): int
    {
        $unmapped = DB::table('mushaf_ayahs as m')
            ->where('m.qiraat_reading_id', $qiraatId)
            ->whereNotNull('m.surah_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('mushaf_ayah_to_ayah_map as map')
                    ->whereColumn('map.mushaf_ayah_id', 'm.id');
            })
            ->select('m.id', 'm.surah_id', 'm.text', 'm.pure_text')
            ->get();

        if ($unmapped->isEmpty()) {
            return 0;
        }

        $surahIds = $unmapped->pluck('surah_id')->filter()->unique()->map(fn($v) => (int) $v)->values()->all();
        if (empty($surahIds)) {
            return 0;
        }

        $baseRows = DB::table('ayahs')
            ->whereIn('surah_id', $surahIds)
            ->orderBy('surah_id')
            ->orderBy('number_in_surah')
            ->orderBy('id')
            ->get(['id', 'surah_id', 'text', 'pure_text']);

        $baseBySurahAndNorm = [];
        foreach ($baseRows as $row) {
            $surahId = (int) $row->surah_id;
            $norm = $this->normalizeAyahText((string) ($row->pure_text ?: $row->text ?: ''));
            if ($norm === '') {
                continue;
            }

            $baseBySurahAndNorm[$surahId][$norm][] = (int) $row->id;
        }

        $now = now();
        $rows = [];

        foreach ($unmapped as $row) {
            $surahId = (int) $row->surah_id;
            $norm = $this->normalizeAyahText((string) ($row->pure_text ?: $row->text ?: ''));
            if ($norm === '') {
                continue;
            }

            $candidates = $baseBySurahAndNorm[$surahId][$norm] ?? [];
            if (count($candidates) !== 1) {
                continue;
            }

            $rows[] = [
                'mushaf_ayah_id' => (int) $row->id,
                'ayah_id' => (int) $candidates[0],
                'map_type' => 'exact',
                'part_no' => null,
                'parts_total' => null,
                'ayah_order' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($dryRun) {
            return count($rows);
        }

        if (empty($rows)) {
            return 0;
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('mushaf_ayah_to_ayah_map')->upsert(
                $chunk,
                ['mushaf_ayah_id', 'ayah_id'],
                ['map_type', 'part_no', 'parts_total', 'ayah_order', 'updated_at']
            );
        }

        return count($rows);
    }

    private function normalizeAyahText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $parts = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($parts)) {
            return '';
        }

        $normalized = array_map(fn($part) => $this->normalizeArabicWord($part), $parts);
        $normalized = array_values(array_filter($normalized, fn($part) => $part !== ''));

        return implode(' ', $normalized);
    }

    /**
     * Build word sequences for group.
     */
    private function buildWordSequencesForAyahGroup(int $qiraatId, array $ayMap): array
    {
        $type = $ayMap['type'];

        if ($type === 'exact') {
            $mushafAyahId = $ayMap['mushaf_ayah_ids'][0];
            $baseAyahId   = $ayMap['base_ayah_ids'][0];

            $mSeq = $this->loadMushafWordsForAyah($mushafAyahId);
            $bSeq = $this->loadBaseWordsForAyah($baseAyahId);

            return [$mSeq, $bSeq, "exact ayah"];
        }

        if ($type === 'combined') {
            $mushafAyahId = $ayMap['mushaf_ayah_ids'][0];
            $baseAyahIds  = $ayMap['base_ayah_ids'];

            $mSeq = $this->loadMushafWordsForAyah($mushafAyahId);

            $bSeq = [];
            foreach ($baseAyahIds as $bid) {
                $bSeq = array_merge($bSeq, $this->loadBaseWordsForAyah($bid));
            }

            return [$mSeq, $bSeq, "combined base ayahs: " . implode(',', $baseAyahIds)];
        }

        if ($type === 'split') {
            $mushafAyahIds = $ayMap['mushaf_ayah_ids'];
            $baseAyahId    = $ayMap['base_ayah_ids'][0];

            $mSeq = [];
            foreach ($mushafAyahIds as $mid) {
                $mSeq = array_merge($mSeq, $this->loadMushafWordsForAyah($mid));
            }

            $bSeq = $this->loadBaseWordsForAyah($baseAyahId);

            return [$mSeq, $bSeq, "split mushaf ayahs: " . implode(',', $mushafAyahIds)];
        }

        return [[], [], "unknown group"];
    }

    private function loadMushafWordsForAyah(int $mushafAyahId): array
    {
        $rows = DB::table('mushaf_words')
            ->select('id', 'mushaf_ayah_id', 'position', 'word', 'pure_word')
            ->where('mushaf_ayah_id', $mushafAyahId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $raw = (string) ($r->pure_word ?: $r->word ?: '');
            $norm = $this->normalizeArabicWord($raw);
            $out[] = [
                'id' => (int)$r->id,
                'norm' => $norm,
                'skel' => $this->skeleton($norm),
                'raw' => $raw,
                'ayah_id' => null,
                'mushaf_ayah_id' => (int)$r->mushaf_ayah_id,
                'pos' => (int)($r->position ?? 0),
            ];
        }
        return $out;
    }

    private function loadBaseWordsForAyah(int $ayahId): array
    {
        $rows = DB::table('words')
            ->select('id', 'ayah_id', 'position', 'word', 'pure_word')
            ->where('ayah_id', $ayahId)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $raw = (string) ($r->pure_word ?: $r->word ?: '');
            $norm = $this->normalizeArabicWord($raw);
            $out[] = [
                'id' => (int)$r->id,
                'norm' => $norm,
                'skel' => $this->skeleton($norm),
                'raw' => $raw,
                'ayah_id' => (int)$r->ayah_id,
                'mushaf_ayah_id' => null,
                'pos' => (int)($r->position ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Diff blocks (base indices) from qiraat_differences (surah, ayah, hafs_text).
     * Matches like map mushaf ayahs to base ayahs: (surah, ayah) identifies the base ayah,
     * then hafs_text is located in that ayah's base word sequence to get [start_i, end_i].
     */
    private function loadDiffBlocksAsIndices(int $qiraatId, array $baseSeq): array
    {
        if (empty($baseSeq)) return [];

        $baseAyahIds = array_values(array_unique(array_filter(array_map(fn($t) => $t['ayah_id'] ?? null, $baseSeq))));
        if (empty($baseAyahIds)) return [];

        // (surah_id, number_in_surah) -> ayah_id (first id when multiple)
        $surahAyahToAyahId = [];
        $ayahRows = DB::table('ayahs')
            ->whereIn('id', $baseAyahIds)
            ->select('id', 'surah_id', 'number_in_surah')
            ->orderBy('id')
            ->get();
        foreach ($ayahRows as $a) {
            $key = (int)$a->surah_id . ':' . (int)$a->number_in_surah;
            if (!isset($surahAyahToAyahId[$key])) {
                $surahAyahToAyahId[$key] = (int)$a->id;
            }
        }

        // ayah_id -> [start_idx, end_idx] in baseSeq (consecutive indices for that ayah)
        $ayahIdToRange = [];
        $i = 0;
        while ($i < count($baseSeq)) {
            $aid = (int)($baseSeq[$i]['ayah_id'] ?? 0);
            if ($aid <= 0) {
                $i++;
                continue;
            }
            $start = $i;
            while ($i < count($baseSeq) && (int)($baseSeq[$i]['ayah_id'] ?? 0) === $aid) {
                $i++;
            }
            $end = $i - 1;
            if (!isset($ayahIdToRange[$aid])) {
                $ayahIdToRange[$aid] = [$start, $end];
            } else {
                // same ayah appears again (e.g. combined group); extend range
                $ayahIdToRange[$aid][1] = $end;
            }
        }

        $surahAyahKeys = array_keys($surahAyahToAyahId);
        if (empty($surahAyahKeys)) return [];

        $surahAyahPairs = [];
        foreach ($surahAyahKeys as $k) {
            [$s, $n] = explode(':', $k, 2);
            $surahAyahPairs[] = [(int)$s, (int)$n];
        }

        $rows = DB::table('qiraat_differences')
            ->select('id', 'surah', 'ayah', 'hafs_text')
            ->where('qiraat_reading_id', $qiraatId)
            ->where(function ($q) use ($surahAyahPairs) {
                foreach ($surahAyahPairs as [$surah, $ayah]) {
                    $q->orWhere(function ($q2) use ($surah, $ayah) {
                        $q2->where('surah', $surah)->where('ayah', $ayah);
                    });
                }
            })
            ->orderBy('surah')
            ->orderBy('ayah')
            ->get();

        $blocks = [];
        foreach ($rows as $r) {
            $key = (int)$r->surah . ':' . (int)$r->ayah;
            $ayahId = $surahAyahToAyahId[$key] ?? null;
            if ($ayahId === null) continue;

            $range = $ayahIdToRange[$ayahId] ?? null;
            if ($range === null) continue;

            [$rangeStart, $rangeEnd] = $range;
            $slice = array_slice($baseSeq, $rangeStart, $rangeEnd - $rangeStart + 1);
            $hafsNorm = $this->normalizeArabicWord(trim((string)$r->hafs_text));
            if ($hafsNorm === '') continue;

            $span = $this->findSpanInNormalizedWords($slice, $hafsNorm);
            if ($span === null) continue;

            [$spanStart, $spanEnd] = $span;
            $blocks[] = [
                'diff_id' => (int)$r->id,
                'start_i' => $rangeStart + $spanStart,
                'end_i'   => $rangeStart + $spanEnd,
            ];
        }

        return $this->mergeOverlappingBlocks($blocks);
    }

    /**
     * Find [start, end] (indices into $words) such that concatenation of norms matches $hafsNorm.
     * Tries no-space concat first, then space-separated.
     */
    private function findSpanInNormalizedWords(array $words, string $hafsNorm): ?array
    {
        $n = count($words);
        if ($n === 0 || $hafsNorm === '') return null;

        for ($start = 0; $start < $n; $start++) {
            $noSpace = '';
            for ($end = $start; $end < $n; $end++) {
                $noSpace .= $words[$end]['norm'] ?? '';
                if ($noSpace === $hafsNorm) {
                    return [$start, $end];
                }
                if (mb_strlen($noSpace) > mb_strlen($hafsNorm)) break;
            }
            $withSpace = '';
            for ($end = $start; $end < $n; $end++) {
                $withSpace .= ($withSpace === '' ? '' : ' ') . ($words[$end]['norm'] ?? '');
                if ($withSpace === $hafsNorm) {
                    return [$start, $end];
                }
                if (mb_strlen($withSpace) > mb_strlen($hafsNorm)) break;
            }
        }
        return null;
    }

    private function mergeOverlappingBlocks(array $blocks): array
    {
        if (empty($blocks)) return [];

        usort($blocks, fn($a, $b) => $a['start_i'] <=> $b['start_i']);
        $out = [$blocks[0]];

        for ($k = 1; $k < count($blocks); $k++) {
            $cur = $blocks[$k];
            $last = &$out[count($out) - 1];

            if ($cur['start_i'] <= $last['end_i'] + 1) {
                $last['end_i'] = max($last['end_i'], $cur['end_i']);
                if ($last['diff_id'] !== $cur['diff_id']) $last['diff_id'] = 0;
            } else {
                $out[] = $cur;
            }
        }

        return $out;
    }

    /**
     * Main mapper.
     */
    private function mapWordSequences(
        array $mSeq,
        array $bSeq,
        array $diffBlocks,
        int $maxCombined,
        int $maxSplit,
        int $windowRadius,
        int $forwardScan,
        int $dpMaxBlock,
        int $mushafAyahIdForReport
    ): array {
        $rows = [];
        $forceRemap = [];

        $m = 0;
        $b = 0;
        $diffIdx = 0;

        while ($m < count($mSeq) && $b < count($bSeq)) {
            $inDiff = $this->isIndexInDiffBlock($b, $diffBlocks, $diffIdx);
            $activeDiff = $inDiff ? $diffBlocks[$diffIdx] : null;

            if ($activeDiff && $b === $activeDiff['start_i']) {
                $blockLen = $activeDiff['end_i'] - $activeDiff['start_i'] + 1;
                if ($blockLen <= $dpMaxBlock) {
                    $dpResult = $this->alignDiffBlockWithDp($mSeq, $bSeq, $m, $activeDiff, $windowRadius, $forwardScan);
                    if ($dpResult !== null) {
                        foreach ($dpResult['rows'] as $r) {
                            $rows[] = $r;
                            $forceRemap[(int)$r['mushaf_word_id']] = true;
                        }
                        $this->cntDp++;
                        $m = $dpResult['next_m'];
                        $b = $dpResult['next_b'];
                        continue;
                    }
                }
            }

            $mTok = $mSeq[$m];
            $bTok = $bSeq[$b];

            // 1) exact norm
            if ($mTok['norm'] !== '' && $mTok['norm'] === $bTok['norm']) {
                $rows[] = $this->mapRow($mTok['id'], $bTok['id'], 'exact', null, null, null, $activeDiff['diff_id'] ?? null, 'exact_norm', 1.0);
                $this->cntExact++;
                $m++;
                $b++;
                continue;
            }

            // 1b) both empty (e.g. decorative ۞) — treat as match
            if (($mTok['norm'] ?? '') === '' && ($bTok['norm'] ?? '') === '') {
                $rows[] = $this->mapRow($mTok['id'], $bTok['id'], 'exact', null, null, null, $activeDiff['diff_id'] ?? null, 'exact_empty', 0.95);
                $this->cntExact++;
                $m++;
                $b++;
                continue;
            }

            // 2) exact skeleton (rasm tolerance)
            if (!empty($mTok['skel']) && $mTok['skel'] === ($bTok['skel'] ?? '')) {
                $rows[] = $this->mapRow($mTok['id'], $bTok['id'], 'exact', null, null, null, $activeDiff['diff_id'] ?? null, 'exact_skeleton', $inDiff ? 0.80 : 0.88);
                $this->cntExact++;
                $m++;
                $b++;
                continue;
            }

            // 3) combined (strict)
            $combined = $this->tryCombinedWords($mTok['norm'], $bSeq, $b, $maxCombined);
            if ($combined) {
                $partsTotal = count($combined['word_ids']);
                $order = 1;
                foreach ($combined['word_ids'] as $wid) {
                    $rows[] = $this->mapRow(
                        $mTok['id'],
                        $wid,
                        'combined',
                        null,
                        $partsTotal,
                        $order++,
                        $activeDiff['diff_id'] ?? null,
                        "combined_{$partsTotal}",
                        $inDiff ? 0.85 : 0.95
                    );
                }
                $forceRemap[(int)$mTok['id']] = true;
                $this->cntCombined++;
                $m++;
                $b = $combined['next_b'];
                continue;
            }

            // 4) split (strict)
            $split = $this->trySplitWords($bTok['norm'], $mSeq, $m, $maxSplit);
            if ($split) {
                $partsTotal = count($split['mushaf_ids']);
                foreach ($split['mushaf_ids'] as $idx => $mid) {
                    $rows[] = $this->mapRow(
                        $mid,
                        $bTok['id'],
                        'split',
                        $idx + 1,
                        $partsTotal,
                        null,
                        $activeDiff['diff_id'] ?? null,
                        "split_{$partsTotal}",
                        $inDiff ? 0.85 : 0.95
                    );
                    $forceRemap[(int)$mid] = true;
                }
                $this->cntSplit++;
                $m = $split['next_m'];
                $b++;
                continue;
            }

            // If mismatch outside diff block: try skipping base words to resync (e.g. mushaf verse is suffix of base)
            if (!$inDiff) {
                $skipLimit = min($windowRadius, count($bSeq) - $b - 1);
                for ($k = 1; $k <= $skipLimit; $k++) {
                    $nextB = $b + $k;
                    if ($nextB >= count($bSeq)) break;
                    $nextBTok = $bSeq[$nextB];
                    if ($mTok['norm'] !== '' && $mTok['norm'] === ($nextBTok['norm'] ?? '')) {
                        $b = $nextB;
                        continue 2;
                    }
                    if (!empty($mTok['skel']) && $mTok['skel'] === ($nextBTok['skel'] ?? '')) {
                        $b = $nextB;
                        continue 2;
                    }
                }
                $this->cntUnresolved++;
                $this->reportRow(
                    $mushafAyahIdForReport,
                    $this->curSurahId,
                    $this->curAyahNo,
                    'word_unresolved_outside_diff',
                    "mushaf_word_id={$mTok['id']} raw={$this->preview($mTok['raw'])} | base_word_id={$bTok['id']} raw={$this->preview($bTok['raw'])}"
                );
                $m++;
                continue;
            }

            // Inside diff block: be more permissive — try small skips
            if ($b + 1 < count($bSeq) && ($mTok['norm'] === $bSeq[$b + 1]['norm'] || (!empty($mTok['skel']) && $mTok['skel'] === ($bSeq[$b + 1]['skel'] ?? '')))) {
                $b++;
                continue;
            }
            if ($m + 1 < count($mSeq) && ($mSeq[$m + 1]['norm'] === $bTok['norm'] || (!empty($mSeq[$m + 1]['skel']) && $mSeq[$m + 1]['skel'] === ($bTok['skel'] ?? '')))) {
                $m++;
                continue;
            }

            $this->cntUnresolved++;
            $this->reportRow(
                $mushafAyahIdForReport,
                $this->curSurahId,
                $this->curAyahNo,
                'word_unresolved_in_diff',
                "mushaf_word_id={$mTok['id']} raw={$this->preview($mTok['raw'])} | base_word_id={$bTok['id']} raw={$this->preview($bTok['raw'])}"
            );
            $m++;
        }

        while ($m < count($mSeq)) {
            $this->cntUnresolved++;
            $this->reportRow(
                $mushafAyahIdForReport,
                $this->curSurahId,
                $this->curAyahNo,
                'mushaf_trailing',
                "mushaf_word_id={$mSeq[$m]['id']} raw={$this->preview($mSeq[$m]['raw'])}"
            );
            $m++;
        }

        while ($b < count($bSeq)) {
            $this->cntUnresolved++;
            $this->reportRow(
                $mushafAyahIdForReport,
                $this->curSurahId,
                $this->curAyahNo,
                'base_trailing',
                "base_word_id={$bSeq[$b]['id']} raw={$this->preview($bSeq[$b]['raw'])}"
            );
            $b++;
        }

        return [$rows, $forceRemap];
    }

    private function isIndexInDiffBlock(int $bIdx, array $blocks, int &$diffIdx): bool
    {
        while ($diffIdx < count($blocks) && $bIdx > $blocks[$diffIdx]['end_i']) $diffIdx++;
        if ($diffIdx >= count($blocks)) return false;
        return $bIdx >= $blocks[$diffIdx]['start_i'] && $bIdx <= $blocks[$diffIdx]['end_i'];
    }

    private function tryCombinedWords(string $mNorm, array $bSeq, int $bIdx, int $max): ?array
    {
        if ($mNorm === '') return null;

        $acc = '';
        $ids = [];

        for ($k = 0; $k < $max; $k++) {
            if (!isset($bSeq[$bIdx + $k])) break;
            $acc .= ($bSeq[$bIdx + $k]['norm'] ?? '');
            $ids[] = (int)$bSeq[$bIdx + $k]['id'];

            if ($acc === $mNorm) {
                return ['word_ids' => $ids, 'next_b' => $bIdx + $k + 1];
            }
            if (mb_strlen($acc) > mb_strlen($mNorm)) break;
        }

        return null;
    }

    private function trySplitWords(string $bNorm, array $mSeq, int $mIdx, int $max): ?array
    {
        if ($bNorm === '') return null;

        $acc = '';
        $ids = [];

        for ($k = 0; $k < $max; $k++) {
            if (!isset($mSeq[$mIdx + $k])) break;
            $acc .= ($mSeq[$mIdx + $k]['norm'] ?? '');
            $ids[] = (int)$mSeq[$mIdx + $k]['id'];

            if ($acc === $bNorm) {
                return ['mushaf_ids' => $ids, 'next_m' => $mIdx + $k + 1];
            }
            if (mb_strlen($acc) > mb_strlen($bNorm)) break;
        }

        return null;
    }

    /**
     * DP fallback: align base diff block [start_i..end_i] to a mushaf window around pointer $mIdx.
     */
    private function alignDiffBlockWithDp(array $mSeq, array $bSeq, int $mIdx, array $block, int $windowRadius, int $forwardScan): ?array
    {
        $bStart = (int)$block['start_i'];
        $bEnd   = (int)$block['end_i'];
        $diffId = (int)($block['diff_id'] ?? 0);

        $baseTokens = array_slice($bSeq, $bStart, $bEnd - $bStart + 1);

        $mStart = max(0, $mIdx - $windowRadius);
        $mEnd   = min(count($mSeq) - 1, $mIdx + $forwardScan);
        $mWindow = array_slice($mSeq, $mStart, $mEnd - $mStart + 1);

        if (empty($baseTokens) || empty($mWindow)) return null;

        $A = array_map(fn($t) => $t['norm'], $baseTokens);
        $B = array_map(fn($t) => $t['norm'], $mWindow);

        $n = count($A);
        $m = count($B);

        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        $bt = array_fill(0, $n + 1, array_fill(0, $m + 1, ''));

        for ($i = 0; $i <= $n; $i++) {
            $dp[$i][0] = $i;
            $bt[$i][0] = 'up';
        }
        for ($j = 0; $j <= $m; $j++) {
            $dp[0][$j] = $j;
            $bt[0][$j] = 'left';
        }
        $bt[0][0] = '';

        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $cost = ($A[$i - 1] !== '' && $A[$i - 1] === $B[$j - 1]) ? 0 : 1;
                $diag = $dp[$i - 1][$j - 1] + $cost;
                $up   = $dp[$i - 1][$j] + 1;
                $left = $dp[$i][$j - 1] + 1;

                $best = $diag;
                $dir = 'diag';
                if ($up < $best) {
                    $best = $up;
                    $dir = 'up';
                }
                if ($left < $best) {
                    $best = $left;
                    $dir = 'left';
                }

                $dp[$i][$j] = $best;
                $bt[$i][$j] = $dir;
            }
        }

        $i = $n;
        $j = $m;
        $pairs = [];
        while ($i > 0 || $j > 0) {
            $dir = $bt[$i][$j] ?? '';
            if ($dir === 'diag') {
                $pairs[] = [$i - 1, $j - 1];
                $i--;
                $j--;
            } elseif ($dir === 'up') {
                $pairs[] = [$i - 1, null];
                $i--;
            } else {
                $pairs[] = [null, $j - 1];
                $j--;
            }
        }
        $pairs = array_reverse($pairs);

        $rows = [];
        $lastUsedMushaf = -1;

        foreach ($pairs as [$bi, $mj]) {
            if ($bi === null || $mj === null) continue;

            $bTok = $baseTokens[$bi];
            $mTok = $mWindow[$mj];

            $match = ($bTok['norm'] !== '' && $bTok['norm'] === $mTok['norm']);
            $rows[] = $this->mapRow(
                (int)$mTok['id'],
                (int)$bTok['id'],
                'exact',
                null,
                null,
                null,
                $diffId ?: null,
                'dp_block',
                $match ? 0.9 : 0.6
            );

            $lastUsedMushaf = max($lastUsedMushaf, $mj);
        }

        if (empty($rows)) return null;

        $nextB = $bEnd + 1;
        $nextM = $mStart + ($lastUsedMushaf >= 0 ? $lastUsedMushaf + 1 : 0);

        return [
            'rows' => $rows,
            'next_m' => $nextM,
            'next_b' => $nextB,
        ];
    }

    /**
     * Persistence (upsert + safe delete) - parameter limit safe.
     */
    private function saveWordMappingsUpsert(
        array $currentMushafWordIds,
        array $rowsToUpsert,
        bool $onlyUnmapped,
        array $forceRemapMushafWordIds
    ): void
    {
        DB::transaction(function () use ($currentMushafWordIds, $rowsToUpsert, $onlyUnmapped, $forceRemapMushafWordIds) {

            $forceIds = array_keys($forceRemapMushafWordIds);

            if (!$onlyUnmapped) {
                // In remap mode, clear the whole currently processed mushaf-word sequence so
                // unresolved words cannot keep stale links from previous runs.
                $deleteIds = array_values(array_unique(array_merge($currentMushafWordIds, $forceIds)));

                foreach (array_chunk($deleteIds, 5000) as $chunkIds) {
                    DB::table('mushaf_word_to_word_map')
                        ->whereIn('mushaf_word_id', $chunkIds)
                        ->delete();
                }
            } else {
                foreach (array_chunk($forceIds, 5000) as $chunkIds) {
                    DB::table('mushaf_word_to_word_map')
                        ->whereIn('mushaf_word_id', $chunkIds)
                        ->delete();
                }
            }

            foreach (array_chunk($rowsToUpsert, 1000) as $chunk) {
                DB::table('mushaf_word_to_word_map')->upsert(
                    $chunk,
                    ['mushaf_word_id', 'word_id'],
                    ['map_type', 'part_no', 'parts_total', 'word_order', 'qiraat_difference_id', 'match_method', 'confidence', 'updated_at']
                );
            }
        });
    }

    private function allMushafWordsAlreadyMapped(array $mSeq): bool
    {
        $ids = array_values(array_unique(array_map(fn($t) => (int)$t['id'], $mSeq)));
        if (empty($ids)) return true;

        $mapped = 0;
        foreach (array_chunk($ids, 5000) as $chunkIds) {
            $mapped += (int) DB::table('mushaf_word_to_word_map')
                ->whereIn('mushaf_word_id', $chunkIds)
                ->distinct()
                ->count('mushaf_word_id');
        }

        return $mapped === count($ids);
    }

    /**
     * Cleanup broken word groups (subquery deletes => no giant parameter lists).
     */
    private function cleanupAllBrokenWordGroupsForQiraat(int $qiraatId): void
    {
        DB::table('mushaf_word_to_word_map')
            ->whereIn('mushaf_word_id', function ($q) use ($qiraatId) {
                $q->select('m.mushaf_word_id')
                    ->from('mushaf_word_to_word_map as m')
                    ->join('mushaf_words as mw', 'mw.id', '=', 'm.mushaf_word_id')
                    ->join('mushaf_ayahs as ma', 'ma.id', '=', 'mw.mushaf_ayah_id')
                    ->where('ma.qiraat_reading_id', $qiraatId)
                    ->groupBy('m.mushaf_word_id')
                    ->havingRaw('COUNT(DISTINCT m.map_type) > 1');
            })
            ->delete();

        DB::table('mushaf_word_to_word_map')
            ->whereIn('mushaf_word_id', function ($q) use ($qiraatId) {
                $q->select('m.mushaf_word_id')
                    ->from('mushaf_word_to_word_map as m')
                    ->join('mushaf_words as mw', 'mw.id', '=', 'm.mushaf_word_id')
                    ->join('mushaf_ayahs as ma', 'ma.id', '=', 'mw.mushaf_ayah_id')
                    ->where('ma.qiraat_reading_id', $qiraatId)
                    ->where('m.map_type', 'combined')
                    ->groupBy('m.mushaf_word_id')
                    ->havingRaw("
                        COUNT(*) <> MAX(m.parts_total)
                     OR MIN(m.word_order) <> 1
                     OR MAX(m.word_order) <> MAX(m.parts_total)
                     OR COUNT(DISTINCT m.word_order) <> COUNT(*)
                    ");
            })
            ->delete();

        DB::table('mushaf_word_to_word_map')
            ->where('map_type', 'split')
            ->whereIn('word_id', function ($q) use ($qiraatId) {
                $q->select('m.word_id')
                    ->from('mushaf_word_to_word_map as m')
                    ->join('mushaf_words as mw', 'mw.id', '=', 'm.mushaf_word_id')
                    ->join('mushaf_ayahs as ma', 'ma.id', '=', 'mw.mushaf_ayah_id')
                    ->where('ma.qiraat_reading_id', $qiraatId)
                    ->where('m.map_type', 'split')
                    ->groupBy('m.word_id')
                    ->havingRaw("
                        COUNT(*) <> MAX(m.parts_total)
                     OR MIN(m.part_no) <> 1
                     OR MAX(m.part_no) <> MAX(m.parts_total)
                     OR COUNT(DISTINCT m.part_no) <> COUNT(*)
                    ");
            })
            ->delete();
    }

    /**
     * Normalization (word-level).
     * - ignores ۞ (decorative)
     * - removes Quran marks + harakat
     * - normalizes Arabic letter variants
     */
    private function normalizeArabicWord(string $text): string
    {
        $t = trim($text);
        if ($t === '') return '';

        // Ignore Rub el Hizb to prevent drift
        $t = str_replace('۞', '', $t);

        if (class_exists(\Normalizer::class)) {
            $norm = \Normalizer::normalize($t, \Normalizer::FORM_KC);
            if ($norm !== false && $norm !== null) $t = $norm;
        }

        $t = str_replace('ـ', '', $t);

        if (!(bool) $this->option('keep-hamza')) {
            $t = str_replace('ء', '', $t);
        }

        $t = preg_replace('/[\x{0610}-\x{061A}\x{06D6}-\x{06ED}\x{08D3}-\x{08FF}]/u', '', $t);
        $t = preg_replace('/[\p{Mn}\p{Me}]+/u', '', $t);

        // Normalize letter variants (ئ -> ي so يستهزي/يستهزئ match across scripts)
        $t = str_replace(
            ['أ', 'إ', 'آ', 'ٱ', 'ٲ', 'ٳ', 'ٵ', 'ى', 'ئ', 'ي', 'ی', 'ې', 'ے', 'ۍ', 'ۑ', 'ؤ', 'ٶ', 'ۄ', 'ک', 'ڪ', 'ة', 'ہ', 'ە', 'ۀ', 'ۂ', 'ھ', 'ۿ', 'ۺ', 'گ'],
            ['ا', 'ا', 'ا', 'ا', 'ا', 'ا', 'ا', 'ي', 'ي', 'ي', 'ي', 'ي', 'ي', 'ي', 'ي', 'و', 'و', 'و', 'ك', 'ك', 'ه', 'ه', 'ه', 'ه', 'ه', 'ه', 'ه', 'ه', 'ك'],
            $t
        );

        $t = preg_replace('/[^\p{Arabic}]+/u', '', $t);
        return $t ?: '';
    }

    /**
     * Skeleton (rasm tolerance): remove Alef only.
     */
    private function skeleton(string $norm): string
    {
        if ($norm === '') return '';
        return str_replace('ا', '', $norm);
    }

    /**
     * Helpers.
     */
    private function resolveAutoQiraatIds(): array
    {
        return DB::table('mushaf_ayahs')->distinct()->pluck('qiraat_reading_id')->toArray();
    }

    private function mapRow(
        int $mushafWordId,
        int $wordId,
        string $type,
        ?int $partNo = null,
        ?int $partsTotal = null,
        ?int $wordOrder = null,
        ?int $diffId = null,
        ?string $method = null,
        ?float $confidence = null
    ): array {
        return [
            'mushaf_word_id' => $mushafWordId,
            'word_id' => $wordId,
            'map_type' => $type,
            'part_no' => $partNo,
            'parts_total' => $partsTotal,
            'word_order' => $wordOrder,
            'qiraat_difference_id' => $diffId,
            'match_method' => $method,
            'confidence' => $confidence,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function resetCounters(): void
    {
        $this->cntExact = 0;
        $this->cntCombined = 0;
        $this->cntSplit = 0;
        $this->cntDp = 0;
        $this->cntUnresolved = 0;
        $this->reportLines = 0;
    }

    private function openReport(?string $path, int $qiraatId): void
    {
        $dir = storage_path('app/qiraat_import_logs');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $this->reportPath = $path ?: ($dir . '/auto_map_words_qiraat_' . $qiraatId . '_' . now()->format('Y-m-d_His') . '_report.csv');

        $fp = @fopen($this->reportPath, 'w');
        if (!$fp) {
            $this->warn("Could not open report file: {$this->reportPath}");
            $this->reportFp = null;
            return;
        }

        $this->reportFp = $fp;
        fputcsv($this->reportFp, ['mushaf_ayah_id', 'surah_id', 'number_in_surah', 'reason', 'details']);
    }

    private function closeReport(): void
    {
        if ($this->reportFp) fclose($this->reportFp);
        $this->reportFp = null;
    }

    private function reportRow(int $mushafAyahId, int $surahId, int $no, string $reason, string $details): void
    {
        if (!$this->reportFp) return;
        if ($this->reportLines >= $this->reportLimit) return;

        fputcsv($this->reportFp, [$mushafAyahId, $surahId, $no, $reason, $details]);
        $this->reportLines++;
    }

    private function preview(?string $text): string
    {
        return mb_substr(trim((string)$text), 0, 120);
    }
}
