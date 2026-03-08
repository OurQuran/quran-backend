<?php

namespace App\Console\Commands;

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
        {qiraat_reading_id : qiraat_readings.id or "auto" for all (includes 1 = base from ayahs)}
        {--dry-run : Do not write}
        {--class=qiraat-diff : CSS class name for difference spans}
    ';

    protected $description = 'Precompile whole Quran into qiraat_diff_ayahs/qiraat_diff_words (text, pure_text, diff class).';

    private string $diffClass = 'qiraat-diff';

    public function handle(): int
    {
        $arg = (string) $this->argument('qiraat_reading_id');
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
            return [(int) $arg];
        }
        $fromMushaf = DB::table('mushaf_ayahs')->distinct()->pluck('qiraat_reading_id')->map(fn ($x) => (int) $x)->filter(fn ($x) => $x > 0)->values()->all();
        $all = array_unique(array_merge([1], $fromMushaf));
        sort($all);
        return array_values($all);
    }

    private function precompileForQiraat(int $qiraatId, bool $dryRun): void
    {
        $hasMushaf = DB::table('mushaf_ayahs')->where('qiraat_reading_id', $qiraatId)->exists();

        if ($qiraatId === 1 && !$hasMushaf) {
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
                $span = $isDiff
                    ? '<span id="' . $mid . '" class="' . $this->diffClass . '">' . $safeWord . '</span>'
                    : '<span id="' . $mid . '">' . $safeWord . '</span>';
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
}
