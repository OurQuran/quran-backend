<?php

namespace App\Console\Commands\Qiraat;

use App\Support\QiraatImportMaps;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Precompile qiraat difference data into qiraat_diff_ayahs and qiraat_diff_words.
 * Includes the WHOLE Quran for each qiraat. For qiraat 1 (base) uses ayahs/words table
 * if no mushaf_ayahs for qiraat 1. text, pure_text, ayah_template and word/pure_word
 * are always set. Diff spans get class "qiraat-diff" for highlighting.
 *
 * Run once after word mapping. Safe to re-run (replaces data per qiraat).
 *
 *   php artisan qiraat:precompile-diff-html 7
 *   php artisan qiraat:precompile-diff-html auto
 */
class PrecompileQiraatDiffHtml extends Command
{
    protected $signature = 'qiraat:precompile-diff-html
        {qiraat_reading : qiraat_readings.id, stable code, or "auto" for all (includes seeded Hafs/base)}
        {--dry-run : Do not write}
        {--class=qiraat-diff : CSS class name for difference spans}
    ';

    protected $description = 'Precompile whole Quran into qiraat_diff_ayahs/qiraat_diff_words (text, pure_text, diff class).';

    private string $diffClass = 'qiraat-diff';

    public function handle(): int
    {
        $arg = (string) $this->argument('qiraat_reading');
        $dryRun = (bool) $this->option('dry-run');
        $this->diffClass = trim((string) $this->option('class')) ?: 'qiraat-diff';

        $qiraatIds = $this->resolveQiraatIds($arg);
        if (empty($qiraatIds)) {
            $this->warn('No qiraat_reading_id to process.');
            return self::SUCCESS;
        }

        foreach ($qiraatIds as $qid) {
            if (!DB::table('qiraat_readings')->where('id', $qid)->exists()) {
                $this->error("Qiraat reading {$qid} not found.");
                return self::FAILURE;
            }
            $this->precompileForQiraat($qid, $dryRun);
        }

        return self::SUCCESS;
    }

    private function resolveQiraatIds(string $arg): array
    {
        if (strtolower($arg) !== 'auto') {
            $resolved = QiraatImportMaps::resolveReadingId(trim($arg));
            return $resolved ? [$resolved] : [];
        }
        $fromMushaf = DB::table('mushaf_ayahs')->distinct()->pluck('qiraat_reading_id')->map(fn ($x) => (int) $x)->filter(fn ($x) => $x > 0)->values()->all();
        $all = array_unique(array_merge([QiraatImportMaps::baseReadingId()], $fromMushaf));
        sort($all);
        return array_values($all);
    }

    private function precompileForQiraat(int $qiraatId, bool $dryRun): void
    {
        $hasMushaf = DB::table('mushaf_ayahs')->where('qiraat_reading_id', $qiraatId)->exists();

        if (QiraatImportMaps::usesBaseAyahs($qiraatId) && !$hasMushaf) {
            $this->precompileFromBaseAyahs($qiraatId, $dryRun);
            return;
        }

        $this->precompileFromMushaf($qiraatId, $dryRun);
    }

    /**
     * Qiraat 1 from ayahs + words (base). Whole Quran; no diff words (list of differences empty).
     */
    private function precompileFromBaseAyahs(int $qiraatId, bool $dryRun): void
    {
        $this->info("Qiraat {$qiraatId} (base from ayahs) – whole Quran...");

        if (!$dryRun) {
            $this->deleteForQiraat($qiraatId);
        }

        $ayahs = DB::table('ayahs')->orderBy('id')->get(['id', 'surah_id', 'number_in_surah', 'page', 'text', 'pure_text', 'hizb_id', 'juz_id']);
        $insertedAyahs = 0;

        foreach ($ayahs as $ayah) {
            $words = DB::table('words')->where('ayah_id', $ayah->id)->orderBy('position')->get(['id', 'position', 'word', 'word_template', 'pure_word']);
            $wordTemplates = [];
            foreach ($words as $w) {
                $safeWord = htmlspecialchars(trim((string) ($w->word ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $wordTemplates[] = '<span id="' . $w->id . '">' . $safeWord . '</span>';
            }
            $ayahTemplate = implode(' ', $wordTemplates);

            if (!$dryRun) {
                DB::table('qiraat_diff_ayahs')->insert([
                    'qiraat_reading_id' => $qiraatId,
                    'mushaf_ayah_id' => null,
                    'ayah_id' => $ayah->id,
                    'surah_id' => $ayah->surah_id,
                    'number_in_surah' => $ayah->number_in_surah,
                    'page' => $ayah->page,
                    'hizb_id' => $ayah->hizb_id ?? null,
                    'juz_id' => $ayah->juz_id ?? null,
                    'text' => $ayah->text,
                    'pure_text' => $ayah->pure_text ?? $ayah->text,
                    'ayah_template' => $ayahTemplate,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $insertedAyahs++;
        }

        $this->line($dryRun ? "  [DRY-RUN] Would insert {$insertedAyahs} ayahs (no diff words)." : "  Inserted {$insertedAyahs} ayahs (base: no diff words).");
    }

    /**
     * Whole Quran from mushaf_ayahs; diff words get class and go into qiraat_diff_words.
     */
    private function precompileFromMushaf(int $qiraatId, bool $dryRun): void
    {
        $this->info("Qiraat {$qiraatId} – whole Quran from mushaf...");

        $mushafAyahIds = DB::table('mushaf_ayahs')
            ->where('qiraat_reading_id', $qiraatId)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if (empty($mushafAyahIds)) {
            $this->line("  No mushaf ayahs. Skipping.");
            return;
        }

        if (!$dryRun) {
            $this->deleteForQiraat($qiraatId);
        }

        $diffWordIdsByAyah = DB::table('mushaf_word_to_word_map as m')
            ->join('mushaf_words as mw', 'mw.id', '=', 'm.mushaf_word_id')
            ->whereIn('mw.mushaf_ayah_id', $mushafAyahIds)
            ->whereNotNull('m.qiraat_difference_id')
            ->select('mw.mushaf_ayah_id', 'm.mushaf_word_id')
            ->get()
            ->groupBy('mushaf_ayah_id')
            ->map(fn ($g) => $g->pluck('mushaf_word_id')->unique()->all())
            ->all();

        $diffWordIdsByAyah = $this->mergeDiffWordIdListsByAyah(
            $diffWordIdsByAyah,
            $this->diffWordIdsByMushafDifferenceText($mushafAyahIds)
        );

        $ayahs = DB::table('mushaf_ayahs')
            ->whereIn('id', $mushafAyahIds)
            ->get(['id', 'surah_id', 'number_in_surah', 'page', 'text', 'pure_text', 'hizb_id', 'juz_id']);

        $insertedAyahs = 0;
        $insertedWords = 0;

        foreach ($ayahs as $ayah) {
            $mushafAyahId = (int) $ayah->id;
            $diffWordIds = $diffWordIdsByAyah[$mushafAyahId] ?? [];

            $words = DB::table('mushaf_words')
                ->where('mushaf_ayah_id', $mushafAyahId)
                ->orderBy('position')
                ->get(['id', 'position', 'word', 'word_template', 'pure_word']);

            $wordTemplates = [];
            $diffWordsPayload = [];

            foreach ($words as $w) {
                $mid = (int) $w->id;
                $isDiff = in_array($mid, $diffWordIds, true);
                $safeWord = htmlspecialchars(trim((string) ($w->word ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $span = '<span id="' . $mid . '">' . $safeWord . '</span>';
                if ($isDiff) {
                    $span = $this->addClassToFirstSpan($span, $this->diffClass);
                }
                $wordTemplates[] = $span;

                if ($isDiff) {
                    $diffWordsPayload[] = [
                        'mushaf_word_id' => $mid,
                        'position' => $w->position,
                        'word' => $w->word,
                        'word_template' => $span,
                        'pure_word' => $w->pure_word ?? $w->word,
                    ];
                }
            }

            $ayahTemplate = implode(' ', $wordTemplates);

            if (!$dryRun) {
                $diffAyahId = DB::table('qiraat_diff_ayahs')->insertGetId([
                    'qiraat_reading_id' => $qiraatId,
                    'mushaf_ayah_id' => $mushafAyahId,
                    'ayah_id' => null,
                    'surah_id' => $ayah->surah_id,
                    'number_in_surah' => $ayah->number_in_surah,
                    'page' => $ayah->page,
                    'hizb_id' => $ayah->hizb_id ?? null,
                    'juz_id' => $ayah->juz_id ?? null,
                    'text' => $ayah->text,
                    'pure_text' => $ayah->pure_text ?? $ayah->text,
                    'ayah_template' => $ayahTemplate,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($diffWordsPayload as $row) {
                    DB::table('qiraat_diff_words')->insert([
                        'qiraat_diff_ayah_id' => $diffAyahId,
                        'mushaf_word_id' => $row['mushaf_word_id'],
                        'word_id' => null,
                        'position' => $row['position'],
                        'word' => $row['word'],
                        'word_template' => $row['word_template'],
                        'pure_word' => $row['pure_word'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $insertedAyahs++;
            $insertedWords += count($diffWordsPayload);
        }

        $this->line($dryRun ? "  [DRY-RUN] Would insert {$insertedAyahs} ayahs, {$insertedWords} diff words." : "  Inserted {$insertedAyahs} ayahs, {$insertedWords} diff words.");
    }

    private function deleteForQiraat(int $qiraatId): void
    {
        DB::table('qiraat_diff_words')->whereIn('qiraat_diff_ayah_id', function ($q) use ($qiraatId) {
            $q->select('id')->from('qiraat_diff_ayahs')->where('qiraat_reading_id', $qiraatId);
        })->delete();
        DB::table('qiraat_diff_ayahs')->where('qiraat_reading_id', $qiraatId)->delete();
    }

    private function addClassToFirstSpan(string $html, string $className): string
    {
        $className = trim($className);
        if ($className === '') {
            return $html;
        }

        if (!preg_match('/<span\b([^>]*)>/iu', $html, $match, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $tag = $match[0][0];
        $offset = $match[0][1];
        $attrs = $match[1][0] ?? '';
        $safeClass = htmlspecialchars($className, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if (preg_match('/\sclass=(["\'])(.*?)\1/iu', $attrs, $classMatch)) {
            $existing = preg_split('/\s+/', trim(html_entity_decode($classMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: [];
            if (in_array($className, $existing, true)) {
                return $html;
            }

            $newClassAttr = 'class=' . $classMatch[1] . trim($classMatch[2] . ' ' . $safeClass) . $classMatch[1];
            $newTag = preg_replace('/\sclass=(["\'])(.*?)\1/iu', ' ' . $newClassAttr, $tag, 1);
        } else {
            $newTag = rtrim(substr($tag, 0, -1)) . ' class="' . $safeClass . '">';
        }

        if (!is_string($newTag) || $newTag === '') {
            return $html;
        }

        return substr_replace($html, $newTag, $offset, strlen($tag));
    }

    private function mergeDiffWordIdListsByAyah(array $existing, array $fallback): array
    {
        foreach ($fallback as $ayahId => $wordIds) {
            $merged = array_values(array_unique(array_merge(
                array_map('intval', $existing[$ayahId] ?? []),
                array_map('intval', $wordIds)
            )));
            $existing[$ayahId] = $merged;
        }

        return $existing;
    }

    private function diffWordIdsByMushafDifferenceText(array $mushafAyahIds): array
    {
        if (empty($mushafAyahIds)) {
            return [];
        }

        $wordRows = DB::table('mushaf_words')
            ->whereIn('mushaf_ayah_id', $mushafAyahIds)
            ->orderBy('mushaf_ayah_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'mushaf_ayah_id', 'word', 'pure_word']);

        if ($wordRows->isEmpty()) {
            return [];
        }

        $wordsByAyah = $wordRows
            ->groupBy('mushaf_ayah_id')
            ->map(function ($rows) {
                return $rows->map(function ($word) {
                    return [
                        'id' => (int) $word->id,
                        'norm' => $this->normalizeDiffText((string) ($word->pure_word ?: $word->word ?: '')),
                    ];
                })->values()->all();
            })
            ->all();

        $diffRows = DB::table('mushaf_ayah_to_ayah_map as map')
            ->join('mushaf_ayahs as ma', 'ma.id', '=', 'map.mushaf_ayah_id')
            ->join('ayahs as a', 'a.id', '=', 'map.ayah_id')
            ->join('qiraat_differences as d', function ($join) {
                $join->on('d.qiraat_reading_id', '=', 'ma.qiraat_reading_id')
                    ->on('d.surah', '=', 'a.surah_id')
                    ->on('d.ayah', '=', 'a.number_in_surah');
            })
            ->whereIn('map.mushaf_ayah_id', $mushafAyahIds)
            ->select('map.mushaf_ayah_id', 'd.hafs_text', 'd.qiraat_text', 'd.qiraat_options')
            ->get();

        $diffWordIdsByAyah = [];

        foreach ($diffRows as $diff) {
            $ayahId = (int) $diff->mushaf_ayah_id;
            $words = $wordsByAyah[$ayahId] ?? [];
            if (empty($words)) {
                continue;
            }

            $needles = [
                (string) ($diff->qiraat_text ?? ''),
                (string) ($diff->qiraat_options ?? ''),
                (string) ($diff->hafs_text ?? ''),
            ];

            foreach ($needles as $needleText) {
                $needle = $this->normalizeDiffText($needleText);
                if ($needle === '') {
                    continue;
                }

                $span = $this->findSpanInNormalizedWords($words, $needle);
                if ($span === null) {
                    continue;
                }

                [$start, $end] = $span;
                for ($i = $start; $i <= $end; $i++) {
                    if (isset($words[$i]['id'])) {
                        $diffWordIdsByAyah[$ayahId][] = (int) $words[$i]['id'];
                    }
                }

                break;
            }
        }

        return array_map(fn ($ids) => array_values(array_unique($ids)), $diffWordIdsByAyah);
    }

    private function findSpanInNormalizedWords(array $words, string $needle): ?array
    {
        $count = count($words);
        if ($count === 0 || $needle === '') {
            return null;
        }

        for ($start = 0; $start < $count; $start++) {
            $noSpace = '';
            for ($end = $start; $end < $count; $end++) {
                $noSpace .= $words[$end]['norm'] ?? '';
                if ($noSpace === $needle) {
                    return [$start, $end];
                }
                if (mb_strlen($noSpace) > mb_strlen($needle)) {
                    break;
                }
            }

            $withSpace = '';
            for ($end = $start; $end < $count; $end++) {
                $withSpace .= ($withSpace === '' ? '' : ' ') . ($words[$end]['norm'] ?? '');
                if ($withSpace === $needle) {
                    return [$start, $end];
                }
                if (mb_strlen($withSpace) > mb_strlen($needle)) {
                    break;
                }
            }
        }

        return null;
    }

    private function normalizeDiffText(string $text): string
    {
        return \normalizeArabicForSearch($text);
    }
}
