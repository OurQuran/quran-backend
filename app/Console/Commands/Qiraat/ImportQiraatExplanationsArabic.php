<?php

namespace App\Console\Commands\Qiraat;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills the "ar" key of qiraat_differences.explanation (jsonb {"ku": ..., "ar": ...}) from the CSV written by Data-Cleaning/Code/qiraat_explanations_ar.py.
 *
 * Each CSV row carries the row id plus surah, ayah, hafs_text and qiraat_text. A row is only written when the
 * database row with that id still has exactly those values, so a CSV made for an older import can never put
 * an explanation on the wrong row.
 */
class ImportQiraatExplanationsArabic extends Command
{
    protected $signature = 'qiraat:import-explanations-ar
        {file : CSV from qiraat_explanations_ar.py (id, reading_code, surah, ayah, hafs_text, qiraat_text, explanation_ar)}
        {--dry-run : Report what would change without writing}
        {--reset : Remove the "ar" key from every row first (use when the CSV is a complete replacement)}';

    protected $description = 'Import Arabic explanations for qiraat_differences from the Data-Cleaning CSV.';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        if (!is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $handle = fopen($file, 'rb');
        $header = fgetcsv($handle);
        if ($header === false) {
            $this->error('Empty CSV.');

            return self::FAILURE;
        }
        $header[0] = ltrim($header[0], "\xEF\xBB\xBF"); // BOM
        $col = array_flip($header);
        foreach (['id', 'surah', 'ayah', 'hafs_text', 'qiraat_text', 'explanation_ar'] as $needed) {
            if (!isset($col[$needed])) {
                $this->error("Missing column: {$needed}");

                return self::FAILURE;
            }
        }

        if ($this->option('reset') && !$dry) {
            DB::statement("UPDATE qiraat_differences SET explanation = NULLIF(explanation - 'ar', '{}'::jsonb) WHERE explanation->>'ar' IS NOT NULL");
        }

        $stats = ['updated' => 0, 'unchanged' => 0, 'missing' => 0, 'mismatch' => 0];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < count($header)) {
                continue;
            }
            $existing = DB::table('qiraat_differences')->where('id', (int) $row[$col['id']])
                ->first(['surah', 'ayah', 'hafs_text', 'qiraat_text', 'explanation']);
            if (!$existing) {
                $stats['missing']++;
                continue;
            }
            if ((int) $existing->surah !== (int) $row[$col['surah']]
                || (int) $existing->ayah !== (int) $row[$col['ayah']]
                || $existing->hafs_text !== $row[$col['hafs_text']]
                || $existing->qiraat_text !== $row[$col['qiraat_text']]) {
                $stats['mismatch']++;
                continue;
            }
            $text = trim($row[$col['explanation_ar']]);
            $current = json_decode((string) $existing->explanation, true)['ar'] ?? null;
            if ($text === '' || $current === $text) {
                $stats['unchanged']++;
                continue;
            }
            if (!$dry) {
                DB::table('qiraat_differences')->where('id', (int) $row[$col['id']])->update([
                    'explanation' => DB::raw("COALESCE(explanation, '{}'::jsonb) || jsonb_build_object('ar', " . DB::getPdo()->quote($text) . '::text)'),
                ]);
            }
            $stats['updated']++;
        }
        fclose($handle);

        $this->table(['updated', 'unchanged', 'missing id', 'row differs from CSV'], [array_values($stats)]);
        $this->info($dry ? 'Dry run: nothing was written.' : 'Done.');

        return $stats['mismatch'] > 0 || $stats['missing'] > 0 ? self::INVALID : self::SUCCESS;
    }
}
