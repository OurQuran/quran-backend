<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateWordHTMLMushaf extends Command
{
    protected $signature = 'generate:word-html-mushaf
        {--qiraat= : Only process one qiraat_reading_id (via join to mushaf_ayahs)}
        {--chunk=10000 : Read chunk size}
        {--batch=20000 : Update batch size}
        {--dry-run : Do not write, only simulate}';

    protected $description = 'Generate word_template spans for mushaf_words (FAST bulk, UPDATE-only).';

    public function handle(): int
    {
        $chunk = max(200, (int) ($this->option('chunk') ?? 10000));
        $batch = max(500, (int) ($this->option('batch') ?? 20000));
        $dry   = (bool) $this->option('dry-run');

        $q = DB::table('mushaf_words as w')
            ->select('w.id as id', 'w.word')
            ->orderBy('w.id');

        if (($qiraatOpt = trim((string) $this->option('qiraat'))) !== '') {
            $q->join('mushaf_ayahs as ma', 'ma.id', '=', 'w.mushaf_ayah_id')
                ->where('ma.qiraat_reading_id', (int) $qiraatOpt);
        }

        $this->info("Generating word_template... (chunk={$chunk}, batch={$batch}, " . ($dry ? 'DRY-RUN' : 'WRITE') . ")");

        $processed = 0;
        $updated   = 0;

        // [id => template]
        $pending = [];

        $q->chunkById($chunk, function ($words) use (&$processed, &$updated, &$pending, $batch, $dry) {
            foreach ($words as $w) {
                $processed++;

                $id = (int) ($w->id ?? 0);
                if ($id <= 0) continue;

                $wordText = trim((string) ($w->word ?? ''));
                $safeWord = htmlspecialchars($wordText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                $tpl = '<span id="' . $id . '">' . $safeWord . '</span>';

                $pending[$id] = $tpl;
                $updated++;

                if (count($pending) >= $batch) {
                    $this->flushUpdateOnly($pending, $dry);
                    $pending = [];
                }
            }

            $this->line("Processed={$processed} | PendingUpdated={$updated}");
        }, 'w.id', 'id');

        if (!empty($pending)) {
            $this->flushUpdateOnly($pending, $dry);
        }

        $this->newLine();
        $this->info("✅ Done. Processed={$processed} | Updated={$updated}");

        return self::SUCCESS;
    }

    private function flushUpdateOnly(array $idToTpl, bool $dryRun): void
    {
        if ($dryRun || empty($idToTpl)) return;

        // ✅ hard cap so we never build a giant VALUES query
        $MAX = 1500; // adjust 500..2000 depending on your RAM

        if (count($idToTpl) > $MAX) {
            foreach (array_chunk($idToTpl, $MAX, true) as $chunk) {
                $this->flushUpdateOnly($chunk, false);
            }
            return;
        }

        $valuesSql = [];
        $bindings  = [];

        foreach ($idToTpl as $id => $tpl) {
            $valuesSql[] = '(?, ?)';
            $bindings[]  = (int) $id;
            $bindings[]  = (string) $tpl;
        }

        $sql = "
        UPDATE mushaf_words AS w
        SET word_template = v.word_template
        FROM (
            SELECT v.id::bigint AS id, v.word_template::text AS word_template
            FROM (VALUES " . implode(',', $valuesSql) . ") AS v(id, word_template)
        ) AS v
        WHERE w.id = v.id
    ";

        DB::update($sql, $bindings);
    }
}
