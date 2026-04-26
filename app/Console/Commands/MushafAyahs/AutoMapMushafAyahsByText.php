<?php

namespace App\Console\Commands\MushafAyahs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ALL-IN-ONE Qiraat Mushaf -> Base Ayah mapping (HARDENED)
 *
 * What it does (in this order):
 *  1) Pre-clean existing mappings for this qiraat:
 *     - delete mixed map_type for same mushaf_ayah_id
 *     - delete broken combined groups
 *     - delete broken split groups
 *  2) Fast exact fill using pure_text (SQL pass) for unmapped only
 *  3) Main mapper using normalized Arabic:
 *        EXACT (O(1) hash index) -> COMBINED -> SPLIT
 *     with forced remap for combined/split
 *  4) Post-clean again (same rules)
 *  5) Final exact fill (pure_text) for any newly unmapped after cleanup
 *
 * Guarantees:
 *  - No "half combined" remains after it finishes (broken groups are deleted).
 *  - Combined/split are always rebuilt as whole groups (forced delete + rebuild).
 *  - Safe to re-run; converges.
 *
 * Notes:
 *  - Exact matching is now global within surah via hash index (no pointer dependence).
 *  - Normalization is safer: keeps Arabic script letters (not only 0621..064A),
 *    removes Quranic marks, harakat, tatweel, and (optionally) hamza.
 */
class AutoMapMushafAyahsByText extends Command
{
    protected $signature = 'qiraat:auto-map-by-text
        {qiraat_reading_id : qiraat_readings.id OR "auto" to run for all qiraat present in mushaf_ayahs}
        {--only-unmapped : Only process mushaf_ayahs that have no mapping rows yet (but combined/split will force remap)}
        {--max-combined=4 : Max base ayahs to try combining (2..N)}
        {--max-split=4 : Max mushaf parts to try splitting (2..N)}
        {--dry-run : Do not write anything}
        {--report= : Path to report file (CSV). If omitted, auto in storage/app/qiraat_import_logs}
        {--report-limit=20000 : Max report lines}
        {--window-radius=15 : (Used only for combined/split pointer drift heuristics)}
        {--forward-scan=40 : (Used only for combined/split pointer drift heuristics)}
        {--preclean : Run cleanup before mapping (recommended)}
        {--postclean : Run cleanup after mapping (recommended)}
        {--fast-exact : Run fast pure_text exact fill before main mapping (recommended)}
        {--final-exact : Run fast pure_text exact fill after cleanup (recommended)}
        {--keep-hamza : If set, do NOT remove hamza "ء" during normalization}
    ';

    protected $description = 'All-in-one auto-map mushaf_ayahs to base ayahs with cleanup + fast exact fill + normalized matching (exact/combined/split).';

    private $reportFp = null;
    private int $reportLines = 0;
    private int $reportLimit = 20000;
    private string $reportPath = '';

    private int $cntExact = 0;
    private int $cntCombined = 0;
    private int $cntSplit = 0;
    private int $cntUnresolved = 0;

    public function handle(): int
    {
        $arg = $this->argument('qiraat_reading_id');

        $onlyUnmapped = (bool) $this->option('only-unmapped');
        $dryRun       = (bool) $this->option('dry-run');

        $maxCombined  = max(2, (int) ($this->option('max-combined') ?? 4));
        $maxSplit     = max(2, (int) ($this->option('max-split') ?? 4));

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

        $windowRadius = max(1, (int) ($this->option('window-radius') ?? 15));
        $forwardScan  = max(1, (int) ($this->option('forward-scan') ?? 40));

        $doPreclean   = (bool) $this->option('preclean');
        $doPostclean  = (bool) $this->option('postclean');
        $doFastExact  = (bool) $this->option('fast-exact');
        $doFinalExact = (bool) $this->option('final-exact');

        $this->resetCounters();
        $this->openReport($this->option('report'), $qiraatId);

        // Build BOTH:
        // - baseBySurah: ordered list for combined/split pointer logic
        // - baseIndex: O(1) exact match per surah (norm => [id, idx] or array of candidates)
        [$baseBySurah, $baseIndex] = $this->loadBaseAyahsBySurahWithIndex();

        $this->info("Qiraat={$qiraatId} | " .
            ($dryRun ? "DRY-RUN" : "WRITE") . " | " .
            ($onlyUnmapped ? "ONLY-UNMAPPED" : "REMAP") . " | " .
            "maxCombined={$maxCombined}, maxSplit={$maxSplit} | window={$windowRadius}, scan={$forwardScan}"
        );
        $this->line("Report: {$this->reportPath}");

        if (!$dryRun && $doPreclean) {
            $this->line("Pre-clean: removing broken/mixed groups...");
            $this->cleanupAllBrokenGroupsForQiraat($qiraatId);
        }

        if (!$dryRun && $doFastExact) {
            $this->line("Fast exact fill (pure_text) BEFORE main mapping...");
            $ins = $this->fastExactFillByPureText($qiraatId);
            $this->line("Fast exact inserted/updated: {$ins}");
        }

        for ($surahId = 1; $surahId <= 114; $surahId++) {
            if (empty($baseBySurah[$surahId])) continue;

            $mushafRows = $this->loadMushafRowsForSurah($qiraatId, $surahId, $onlyUnmapped);
            if ($mushafRows->isEmpty()) continue;

            $baseAyahs = $baseBySurah[$surahId]; // ordered base list
            $index     = $baseIndex[$surahId] ?? []; // norm => candidate(s)
            $mushafArr = $mushafRows->values()->all();
            $exactAnchorIdxByMushafIdx = $this->buildExactAnchorIndexMap($mushafArr, $index);

            $currentMushafIds = array_map(fn($row) => (int) $row->id, $mushafArr);
            $toUpsert = [];
            $remapMushafIds = [];
            $forceRemapIds = [];

            $mIdx = 0;

            // pointer anchor from first mushaf number_in_surah (still useful for combined/split)
            $bIdx = 0;
            if (!empty($mushafArr) && !empty($mushafArr[0]->number_in_surah)) {
                $hint = max(1, (int) $mushafArr[0]->number_in_surah);
                $bIdx = min(count($baseAyahs) - 1, max(0, $hint - 1));
            }
            $bIdx = $this->clampIndex($bIdx, count($baseAyahs));

            while ($mIdx < count($mushafArr)) {
                $m = $mushafArr[$mIdx];
                $mNo = (int) ($m->number_in_surah ?? 0);

                // re-anchor on drift (helps combined/split)
                if ($mNo > 0) {
                    $expectedB = $mNo - 1;
                    if ($expectedB >= 0 && abs($bIdx - $expectedB) > 6) {
                        $bIdx = $this->clampIndex($expectedB, count($baseAyahs));
                    }
                }

                $mNorm = $this->normalizeArabic((string) $m->text);
                if ($mNorm === '') {
                    $this->cntUnresolved++;
                    $this->reportRow((int)$m->id, $surahId, $mNo, 'empty_text', '');
                    $mIdx++;
                    continue;
                }

                // 1) EXACT (O(1) global within surah)
                $exact = $this->findExactFromIndex($mNorm, $index);
                if ($exact !== null) {
                    $this->cntExact++;

                    $toUpsert[] = $this->mapRow(
                        (int) $m->id,
                        (int) $exact['id'],
                        'exact',
                        null,
                        null,
                        null
                    );

                    $remapMushafIds[(int)$m->id] = true;
                    // move pointer forward only if this exact has an idx
                    if (isset($exact['idx'])) {
                        $bIdx = $this->clampIndex(((int)$exact['idx']) + 1, count($baseAyahs));
                    }
                    $mIdx++;
                    continue;
                }

                // 2) COMBINED (uses ordered base + pointer)
                $combined = $this->tryCombined($mNorm, $baseAyahs, $bIdx, $maxCombined, $windowRadius, $forwardScan);
                if ($combined) {
                    $partsTotal = count($combined['ids']);
                    $forceRemapIds[(int)$m->id] = true;

                    foreach ($combined['ids'] as $order => $baseId) {
                        $toUpsert[] = $this->mapRow(
                            (int) $m->id,
                            (int) $baseId,
                            'combined',
                            null,
                            $partsTotal,
                            $order + 1
                        );
                    }

                    $this->cntCombined++;
                    $bIdx = $this->clampIndex($combined['next_b_idx'], count($baseAyahs));
                    $mIdx++;
                    continue;
                }

                // 3) SPLIT (uses ordered base + pointer)
                $split = $this->trySplit($mIdx, $mushafArr, $baseAyahs, $bIdx, $maxSplit, $windowRadius, $forwardScan);
                if ($split) {
                    $partsTotal = count($split['m_ids']);

                    foreach ($split['m_ids'] as $mId) {
                        $forceRemapIds[(int)$mId] = true;
                    }

                    foreach ($split['m_ids'] as $partNo => $mId) {
                        $toUpsert[] = $this->mapRow(
                            (int) $mId,
                            (int) $split['base_id'],
                            'split',
                            $partNo + 1,
                            $partsTotal,
                            null
                        );
                    }

                    $this->cntSplit++;
                    $mIdx += $partsTotal;
                    $bIdx = $this->clampIndex($split['next_b_idx'], count($baseAyahs));
                    continue;
                }

                $sequenceBaseIdx = $this->inferBaseIndexFromNearbyExactAnchors(
                    $mIdx,
                    $exactAnchorIdxByMushafIdx,
                    count($mushafArr),
                    count($baseAyahs)
                );

                if ($sequenceBaseIdx !== null && isset($baseAyahs[$sequenceBaseIdx])) {
                    $baseId = (int) $baseAyahs[$sequenceBaseIdx]['id'];

                    $toUpsert[] = $this->mapRow(
                        (int) $m->id,
                        $baseId,
                        'exact',
                        null,
                        null,
                        null
                    );

                    $remapMushafIds[(int) $m->id] = true;
                    $this->cntExact++;
                    $bIdx = $this->clampIndex($sequenceBaseIdx + 1, count($baseAyahs));
                    $mIdx++;
                    continue;
                }

                // unresolved
                $this->cntUnresolved++;
                $this->reportRow((int) $m->id, $surahId, $mNo, 'unresolved', $this->preview($m->text));
                $mIdx++;
            }

            if (!$dryRun && (!$onlyUnmapped || !empty($toUpsert))) {
                $this->saveMappingsUpsert($currentMushafIds, $remapMushafIds, $toUpsert, $onlyUnmapped, $forceRemapIds);
            }
        }

        if (!$dryRun && $doPostclean) {
            $this->line("Post-clean: removing broken/mixed groups...");
            $this->cleanupAllBrokenGroupsForQiraat($qiraatId);
        }

        if (!$dryRun && $doFinalExact) {
            $this->line("Fast exact fill (pure_text) AFTER cleanup...");
            $ins = $this->fastExactFillByPureText($qiraatId);
            $this->line("Final exact inserted/updated: {$ins}");
        }

        $this->newLine();
        $this->info("Completed qiraat={$qiraatId}");
        $this->line("- Exact: {$this->cntExact}");
        $this->line("- Combined: {$this->cntCombined}");
        $this->line("- Split: {$this->cntSplit}");
        $this->line("- Unresolved: {$this->cntUnresolved}");
        $this->line("- Report lines: {$this->reportLines} (limit {$this->reportLimit})");
        $this->line("- Report file: {$this->reportPath}");

        $this->closeReport();
        return self::SUCCESS;
    }

    /**
     * ----------------------------
     * Cleanup (NO half-combined!)
     * ----------------------------
     */
    private function cleanupAllBrokenGroupsForQiraat(int $qiraatId): void
    {
        // 1) Delete mushaf_ayah_ids that have mixed map types (delete all rows for that mushaf id)
        $mixedIds = DB::table('mushaf_ayah_to_ayah_map as m')
            ->join('mushaf_ayahs as ma', 'ma.id', '=', 'm.mushaf_ayah_id')
            ->where('ma.qiraat_reading_id', $qiraatId)
            ->groupBy('m.mushaf_ayah_id')
            ->havingRaw('COUNT(DISTINCT m.map_type) > 1')
            ->pluck('m.mushaf_ayah_id')
            ->all();

        if (!empty($mixedIds)) {
            DB::table('mushaf_ayah_to_ayah_map')
                ->whereIn('mushaf_ayah_id', $mixedIds)
                ->delete();
        }

        // 2) Delete broken COMBINED groups (grouped by mushaf_ayah_id)
        // IMPORTANT: use ONE havingRaw with ORs (avoid query-builder orHaving pitfalls)
        $badCombinedIds = DB::table('mushaf_ayah_to_ayah_map as m')
            ->join('mushaf_ayahs as ma', 'ma.id', '=', 'm.mushaf_ayah_id')
            ->where('ma.qiraat_reading_id', $qiraatId)
            ->where('m.map_type', 'combined')
            ->groupBy('m.mushaf_ayah_id')
            ->havingRaw("
                COUNT(*) <> MAX(m.parts_total)
             OR MIN(m.ayah_order) <> 1
             OR MAX(m.ayah_order) <> MAX(m.parts_total)
             OR COUNT(DISTINCT m.ayah_order) <> COUNT(*)
            ")
            ->pluck('m.mushaf_ayah_id')
            ->all();

        if (!empty($badCombinedIds)) {
            DB::table('mushaf_ayah_to_ayah_map')
                ->whereIn('mushaf_ayah_id', $badCombinedIds)
                ->delete();
        }

        // 3) Delete broken SPLIT groups (grouped by base ayah_id)
        $badSplitAyahIds = DB::table('mushaf_ayah_to_ayah_map as m')
            ->join('mushaf_ayahs as ma', 'ma.id', '=', 'm.mushaf_ayah_id')
            ->where('ma.qiraat_reading_id', $qiraatId)
            ->where('m.map_type', 'split')
            ->groupBy('m.ayah_id')
            ->havingRaw("
                COUNT(*) <> MAX(m.parts_total)
             OR MIN(m.part_no) <> 1
             OR MAX(m.part_no) <> MAX(m.parts_total)
             OR COUNT(DISTINCT m.part_no) <> COUNT(*)
            ")
            ->pluck('m.ayah_id')
            ->all();

        if (!empty($badSplitAyahIds)) {
            DB::table('mushaf_ayah_to_ayah_map')
                ->where('map_type', 'split')
                ->whereIn('ayah_id', $badSplitAyahIds)
                ->whereIn('mushaf_ayah_id', function ($sub) use ($qiraatId) {
                    $sub->select('id')->from('mushaf_ayahs')->where('qiraat_reading_id', $qiraatId);
                })
                ->delete();
        }
    }

    /**
     * Fast exact fill using pure_text equality.
     * - ONLY fills unmapped mushaf_ayahs for this qiraat.
     * - Uses base_pick: MIN(id) per (surah_id, number_in_surah)
     * - Requires pure_text match
     */
    private function fastExactFillByPureText(int $qiraatId): int
    {
        $sql = "
WITH base_pick AS (
    SELECT surah_id, number_in_surah, MIN(id) AS ayah_id
    FROM ayahs
    GROUP BY surah_id, number_in_surah
),
unmapped AS (
    SELECT ma.*
    FROM mushaf_ayahs ma
    WHERE ma.qiraat_reading_id = ?
      AND NOT EXISTS (
        SELECT 1
        FROM mushaf_ayah_to_ayah_map map
        WHERE map.mushaf_ayah_id = ma.id
      )
)
INSERT INTO mushaf_ayah_to_ayah_map
(mushaf_ayah_id, ayah_id, map_type, part_no, parts_total, ayah_order, created_at, updated_at)
SELECT
    u.id,
    bp.ayah_id,
    'exact',
    NULL, NULL, NULL,
    NOW(), NOW()
FROM unmapped u
JOIN base_pick bp
  ON bp.surah_id = u.surah_id
 AND bp.number_in_surah = u.number_in_surah
JOIN ayahs a
  ON a.id = bp.ayah_id
WHERE COALESCE(u.pure_text,'') <> ''
  AND COALESCE(a.pure_text,'') <> ''
  AND (
    replace(replace(replace(replace(replace(replace(u.pure_text,
      'ے','ي'),
      'ې','ي'),
      'ی','ي'),
      'ى','ي'),
      'ک','ك'),
      'ڪ','ك')
    =
    replace(replace(replace(replace(replace(replace(a.pure_text,
      'ے','ي'),
      'ې','ي'),
      'ی','ي'),
      'ى','ي'),
      'ک','ك'),
      'ڪ','ك')
  )
ON CONFLICT (mushaf_ayah_id, ayah_id)
DO UPDATE SET
  map_type    = EXCLUDED.map_type,
  part_no     = NULL,
  parts_total = NULL,
  ayah_order  = NULL,
  updated_at  = EXCLUDED.updated_at
    ";

        return (int) DB::affectingStatement($sql, [$qiraatId]);
    }

    /**
     * ----------------------------
     * Mapping + Upsert
     * ----------------------------
     */
    private function saveMappingsUpsert(
        array $currentMushafIds,
        array $remapIds,
        array $toUpsert,
        bool $onlyUnmapped,
        array $forceRemapIds = []
    ): void
    {
        DB::transaction(function () use ($currentMushafIds, $remapIds, $toUpsert, $onlyUnmapped, $forceRemapIds) {
            $normalIds = array_keys($remapIds);
            $forceIds  = array_keys($forceRemapIds);

            $deleteIds = [];
            if (!$onlyUnmapped) {
                // In remap mode, clear every currently processed mushaf ayah first so
                // unresolved rows cannot keep stale mappings from previous runs.
                $deleteIds = $currentMushafIds;
            }

            // Always delete forced ids (combined/split) even in only-unmapped mode
            $deleteIds = array_values(array_unique(array_merge($deleteIds, $forceIds)));

            if (!empty($deleteIds)) {
                DB::table('mushaf_ayah_to_ayah_map')
                    ->whereIn('mushaf_ayah_id', $deleteIds)
                    ->delete();
            }

            foreach (array_chunk($toUpsert, 1000) as $chunk) {
                DB::table('mushaf_ayah_to_ayah_map')->upsert(
                    $chunk,
                    ['mushaf_ayah_id', 'ayah_id'],
                    ['map_type', 'part_no', 'parts_total', 'ayah_order', 'updated_at']
                );
            }
        });
    }

    private function mapRow(int $mId, int $bId, string $type, ?int $partNo = null, ?int $total = null, ?int $order = null): array
    {
        return [
            'mushaf_ayah_id' => $mId,
            'ayah_id'        => $bId,
            'map_type'       => $type,
            'part_no'        => $partNo,
            'parts_total'    => $total,
            'ayah_order'     => $order,
            'created_at'     => now(),
            'updated_at'     => now(),
        ];
    }

    /**
     * ----------------------------
     * Exact matching (index)
     * ----------------------------
     */
    private function findExactFromIndex(string $mNorm, array $index): ?array
    {
        if (!isset($index[$mNorm])) return null;

        $cand = $index[$mNorm];

        // If there are duplicates, we choose the first (MIN(id) base pick ensures stability)
        if (isset($cand[0]) && is_array($cand[0])) {
            return $cand[0];
        }

        return is_array($cand) ? $cand : null;
    }

    /**
     * Build per-mushaf-row exact anchors (mushaf row index => base row index) using
     * global exact matching. These anchors help recover local numbering shifts.
     */
    private function buildExactAnchorIndexMap(array $mushafArr, array $index): array
    {
        $anchors = [];

        foreach ($mushafArr as $mIdx => $row) {
            $norm = $this->normalizeArabic((string) ($row->text ?? ''));
            if ($norm === '') {
                continue;
            }

            $exact = $this->findExactFromIndex($norm, $index);
            if ($exact !== null && isset($exact['idx'])) {
                $anchors[$mIdx] = (int) $exact['idx'];
            }
        }

        return $anchors;
    }

    /**
     * Infer a base index for the current mushaf row from nearby exact anchors.
     *
     * This is intentionally conservative: it only applies when the local offset is
     * strongly supported by nearby exact matches, which helps with qiraat-specific
     * numbering shifts like basmala handling.
     */
    private function inferBaseIndexFromNearbyExactAnchors(
        int $mIdx,
        array $anchors,
        int $mushafCount,
        int $baseCount
    ): ?int {
        $prev = null;
        $next = null;

        for ($i = $mIdx - 1; $i >= max(0, $mIdx - 6); $i--) {
            if (isset($anchors[$i])) {
                $prev = ['m_idx' => $i, 'b_idx' => (int) $anchors[$i]];
                break;
            }
        }

        for ($i = $mIdx + 1; $i <= min($mushafCount - 1, $mIdx + 6); $i++) {
            if (isset($anchors[$i])) {
                $next = ['m_idx' => $i, 'b_idx' => (int) $anchors[$i]];
                break;
            }
        }

        if ($prev && $next) {
            $prevOffset = $prev['b_idx'] - $prev['m_idx'];
            $nextOffset = $next['b_idx'] - $next['m_idx'];

            if ($prevOffset === $nextOffset) {
                $candidate = $mIdx + $prevOffset;
                return ($candidate >= 0 && $candidate < $baseCount) ? $candidate : null;
            }

            // Allow a very small drift when the surrounding anchors almost agree.
            if (abs($prevOffset - $nextOffset) === 1) {
                $candidate = $mIdx + (($mIdx - $prev['m_idx']) <= ($next['m_idx'] - $mIdx) ? $prevOffset : $nextOffset);
                return ($candidate >= 0 && $candidate < $baseCount) ? $candidate : null;
            }

            return null;
        }

        if ($prev && ($mIdx - $prev['m_idx']) <= 2) {
            $candidate = $mIdx + ($prev['b_idx'] - $prev['m_idx']);
            return ($candidate >= 0 && $candidate < $baseCount) ? $candidate : null;
        }

        if ($next && ($next['m_idx'] - $mIdx) <= 2) {
            $candidate = $mIdx + ($next['b_idx'] - $next['m_idx']);
            return ($candidate >= 0 && $candidate < $baseCount) ? $candidate : null;
        }

        return null;
    }

    /**
     * ----------------------------
     * Combined / Split matching
     * ----------------------------
     *
     * These keep pointer logic because they’re about adjacency, but they’re now allowed
     * to re-anchor slightly if needed.
     */
    private function tryCombined(
        string $mNorm,
        array $baseAyahs,
        int $bIdx,
        int $max,
        int $windowRadius,
        int $forwardScan
    ): ?array {
        // Try at pointer first
        $hit = $this->tryCombinedAtIndex($mNorm, $baseAyahs, $bIdx, $max);
        if ($hit) return $hit;

        // Small window around pointer
        $start = max(0, $bIdx - $windowRadius);
        $end   = min(count($baseAyahs) - 1, $bIdx + $windowRadius);
        for ($i = $start; $i <= $end; $i++) {
            if ($i === $bIdx) continue;
            $hit = $this->tryCombinedAtIndex($mNorm, $baseAyahs, $i, $max);
            if ($hit) return $hit;
        }

        // Forward scan
        $end2 = min(count($baseAyahs) - 1, $bIdx + $forwardScan);
        for ($i = $bIdx; $i <= $end2; $i++) {
            if ($i === $bIdx) continue;
            $hit = $this->tryCombinedAtIndex($mNorm, $baseAyahs, $i, $max);
            if ($hit) return $hit;
        }

        return null;
    }

    private function tryCombinedAtIndex(string $mNorm, array $baseAyahs, int $bIdx, int $max): ?array
    {
        $acc = '';
        $ids = [];

        for ($k = 0; $k < $max; $k++) {
            if (!isset($baseAyahs[$bIdx + $k])) break;

            $acc .= $baseAyahs[$bIdx + $k]['norm'];
            $ids[] = $baseAyahs[$bIdx + $k]['id'];

            if ($acc === $mNorm) {
                return ['ids' => $ids, 'next_b_idx' => $bIdx + $k + 1];
            }

            if (mb_strlen($acc) > mb_strlen($mNorm)) break;
        }

        return null;
    }

    private function trySplit(
        int $mIdx,
        array $mushafArr,
        array $baseAyahs,
        int $bIdx,
        int $max,
        int $windowRadius,
        int $forwardScan
    ): ?array {
        // Try at pointer first
        $hit = $this->trySplitAtIndex($mIdx, $mushafArr, $baseAyahs, $bIdx, $max);
        if ($hit) return $hit;

        // Try nearby base indices around pointer (in case pointer drifted)
        $start = max(0, $bIdx - $windowRadius);
        $end   = min(count($baseAyahs) - 1, $bIdx + $windowRadius);
        for ($i = $start; $i <= $end; $i++) {
            if ($i === $bIdx) continue;
            $hit = $this->trySplitAtIndex($mIdx, $mushafArr, $baseAyahs, $i, $max);
            if ($hit) return $hit;
        }

        // Forward scan
        $end2 = min(count($baseAyahs) - 1, $bIdx + $forwardScan);
        for ($i = $bIdx; $i <= $end2; $i++) {
            if ($i === $bIdx) continue;
            $hit = $this->trySplitAtIndex($mIdx, $mushafArr, $baseAyahs, $i, $max);
            if ($hit) return $hit;
        }

        return null;
    }

    private function trySplitAtIndex(int $mIdx, array $mushafArr, array $baseAyahs, int $bIdx, int $max): ?array
    {
        if (!isset($baseAyahs[$bIdx])) return null;

        $target = $baseAyahs[$bIdx]['norm'];
        if ($target === '') return null;

        $acc  = '';
        $mIds = [];

        for ($k = 0; $k < $max; $k++) {
            if (!isset($mushafArr[$mIdx + $k])) break;

            $partNorm = $this->normalizeArabic((string) $mushafArr[$mIdx + $k]->text);
            if ($partNorm === '') break;

            $acc .= $partNorm;
            $mIds[] = (int) $mushafArr[$mIdx + $k]->id;

            if ($acc === $target) {
                return [
                    'm_ids'      => $mIds,
                    'base_id'    => $baseAyahs[$bIdx]['id'],
                    'next_b_idx' => $bIdx + 1,
                ];
            }

            if (mb_strlen($acc) > mb_strlen($target)) break;
        }

        return null;
    }

    /**
     * ----------------------------
     * Arabic normalization (safer)
     * ----------------------------
     */
    private function normalizeArabic(string $text): string
    {
        $t = trim($text);
        if ($t === '') return '';

        // Unicode compatibility normalization helps with presentation forms (requires ext-intl)
        if (class_exists(\Normalizer::class)) {
            $norm = \Normalizer::normalize($t, \Normalizer::FORM_KC);
            if ($norm !== false && $norm !== null) {
                $t = $norm;
            }
        }

        // Remove tatweel
        $t = str_replace('ـ', '', $t);

        // Optional: remove hamza if NOT keeping it
        if (!(bool) $this->option('keep-hamza')) {
            $t = str_replace('ء', '', $t);
        }

        // Remove Qur'anic marks ranges + extended Arabic marks (adds 08D3..08FF too)
        $t = preg_replace('/[\x{0610}-\x{061A}\x{06D6}-\x{06ED}\x{08D3}-\x{08FF}]/u', '', $t);

        // Remove combining marks (harakat, etc.)
        $t = preg_replace('/[\p{Mn}\p{Me}]+/u', '', $t);

        // Normalize common letter variants (including Kurdish/Persian/Urdu)
        $t = str_replace(
            [
                // Alef forms
                'أ','إ','آ','ٱ','ٲ','ٳ','ٵ',

                // Yeh forms
                'ى','ئ','ي','ی','ې','ے','ۍ','ۑ',

                // Waw forms
                'ؤ','ٶ','ۄ',

                // Kaf forms
                'ک','ڪ',

                // Heh / ta marbuta forms
                'ة','ہ','ە','ۀ','ۂ','ھ','ۿ','ۺ',
            ],
            [
                'ا','ا','ا','ا','ا','ا','ا',
                'ي','ي','ي','ي','ي','ي','ي','ي',
                'و','و','و',
                'ك','ك',
                'ه','ه','ه','ه','ه','ه','ه','ه',
            ],
            $t
        );

        // Sometimes appears in bad sources (choose keep or remove)
        $t = str_replace('گ', 'ك', $t);

        // KEEP Arabic-script characters (not just 0621..064A)
        $t = preg_replace('/[^\p{Arabic}]+/u', '', $t);

        return $t ?: '';
    }

    /**
     * ----------------------------
     * Data loading (base with index)
     * ----------------------------
     */
    private function resolveAutoQiraatIds(): array
    {
        return DB::table('mushaf_ayahs')->distinct()->pluck('qiraat_reading_id')->toArray();
    }

    /**
     * Returns [baseBySurah, baseIndex]
     *
     * baseBySurah[surahId] = ordered list of:
     *   ['id' => int, 'ayah_no' => int, 'norm' => string]
     *
     * baseIndex[surahId][norm] = ['id'=>int,'idx'=>int] OR array of those (if duplicates)
     */
    private function loadBaseAyahsBySurahWithIndex(): array
    {
        $rows = DB::table('ayahs')
            ->selectRaw('surah_id, number_in_surah, MIN(id) AS id')
            ->groupBy('surah_id', 'number_in_surah')
            ->orderBy('surah_id')
            ->orderBy('number_in_surah')
            ->get();

        $ids = $rows->pluck('id')->all();
        $texts = DB::table('ayahs')->whereIn('id', $ids)->pluck('text', 'id');

        $baseBySurah = [];
        $baseIndex   = [];

        foreach ($rows as $r) {
            $surahId = (int) $r->surah_id;
            $id      = (int) $r->id;
            $ayahNo  = (int) $r->number_in_surah;

            $norm = $this->normalizeArabic((string) ($texts[$id] ?? ''));

            $baseBySurah[$surahId][] = [
                'id'      => $id,
                'ayah_no' => $ayahNo,
                'norm'    => $norm,
            ];
        }

        // Build index with idx positions
        foreach ($baseBySurah as $surahId => $list) {
            foreach ($list as $idx => $row) {
                $norm = $row['norm'];
                if ($norm === '') continue;

                $entry = ['id' => $row['id'], 'idx' => $idx];

                if (!isset($baseIndex[$surahId][$norm])) {
                    $baseIndex[$surahId][$norm] = $entry;
                } else {
                    // convert to list of candidates
                    if (!is_array($baseIndex[$surahId][$norm]) || !isset($baseIndex[$surahId][$norm][0])) {
                        $baseIndex[$surahId][$norm] = [$baseIndex[$surahId][$norm]];
                    }
                    $baseIndex[$surahId][$norm][] = $entry;
                }
            }
        }

        return [$baseBySurah, $baseIndex];
    }

    private function loadMushafRowsForSurah(int $qiraatId, int $surahId, bool $onlyUnmapped)
    {
        $q = DB::table('mushaf_ayahs')
            ->where('qiraat_reading_id', $qiraatId)
            ->where('surah_id', $surahId)
            ->orderBy('number_in_surah')
            ->orderBy('id');

        if ($onlyUnmapped) {
            $q->whereNotExists(function ($s) {
                $s->select(DB::raw(1))
                    ->from('mushaf_ayah_to_ayah_map')
                    ->whereColumn('mushaf_ayah_to_ayah_map.mushaf_ayah_id', 'mushaf_ayahs.id');
            });
        }

        return $q->get();
    }

    private function clampIndex(int $idx, int $count): int
    {
        if ($count <= 0) return 0;
        if ($idx < 0) return 0;
        if ($idx > $count - 1) return $count - 1;
        return $idx;
    }

    /**
     * ----------------------------
     * Reporting
     * ----------------------------
     */
    private function resetCounters(): void
    {
        $this->cntExact = 0;
        $this->cntCombined = 0;
        $this->cntSplit = 0;
        $this->cntUnresolved = 0;
        $this->reportLines = 0;
    }

    private function openReport(?string $path, int $qiraatId): void
    {
        $dir = storage_path('app/qiraat_import_logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $this->reportPath = $path ?: ($dir . '/auto_map_qiraat_' . $qiraatId . '_' . now()->format('Y-m-d_His') . '_report.csv');

        $fp = @fopen($this->reportPath, 'w');
        if (!$fp) {
            $this->warn("Could not open report file for writing: {$this->reportPath}");
            $this->reportFp = null;
            return;
        }

        $this->reportFp = $fp;

        fputcsv($this->reportFp, [
            'mushaf_ayah_id',
            'surah_id',
            'number_in_surah',
            'reason',
            'details',
        ]);
    }

    private function closeReport(): void
    {
        if ($this->reportFp) {
            fclose($this->reportFp);
            $this->reportFp = null;
        }
    }

    private function reportRow(int $id, int $surah, int $no, string $reason, string $details): void
    {
        if (!$this->reportFp) return;
        if ($this->reportLines >= $this->reportLimit) return;

        fputcsv($this->reportFp, [$id, $surah, $no, $reason, $details]);
        $this->reportLines++;
    }

    private function preview(?string $text): string
    {
        return mb_substr(trim((string) $text), 0, 140);
    }
}
