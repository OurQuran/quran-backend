<?php

namespace App\Console\Commands\MushafWords;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Repair/fix word-level mappings using auto-map report CSVs.
 *
 * - Parses the latest (or given) auto_map_words report for a qiraat.
 * - Optionally runs ayah-level repair first (qiraat:repair-and-exact-map).
 * - Optionally clears word mappings for ayahs that have report errors, so you can re-run auto-map.
 * - Optionally runs qiraat:auto-map-words after clear.
 *
 * Use:
 *   php artisan qiraat:repair-word-maps 7
 *   php artisan qiraat:repair-word-maps 7 --report=/path/to/report.csv --clear --dry-run
 *   php artisan qiraat:repair-word-maps 7 --clear --remap
 *   php artisan qiraat:repair-word-maps 7 --ayah-first --clear --remap
 */
class QiraatRepairWordMaps extends Command
{
    protected $signature = 'qiraat:repair-word-maps
        {qiraat_reading_id : qiraat_readings.id}
        {--report= : Path to report CSV; if omitted, use latest in storage/app/qiraat_import_logs}
        {--clear : Remove word mappings for ayahs that have report errors (so you can re-run auto-map)}
        {--remap : After --clear, run qiraat:auto-map-words for this qiraat}
        {--dry-run : Do not write; show what would be cleared}
        {--reason= : Only consider rows with reason containing this (e.g. word_unresolved); default: all}
        {--ayah-first : Run qiraat:repair-and-exact-map first (fix numbering + exact ayah mapping)}
    ';

    protected $description = 'Repair word mappings: parse auto-map report, optionally clear failed ayahs and re-run auto-map.';

    public function handle(): int
    {
        $qiraatId = (int) $this->argument('qiraat_reading_id');
        $reportPath = $this->option('report');
        $doClear = (bool) $this->option('clear');
        $doRemap = (bool) $this->option('remap');
        $dryRun = (bool) $this->option('dry-run');
        $reasonFilter = $this->option('reason');
        $ayahFirst = (bool) $this->option('ayah-first');

        if (!DB::table('qiraat_readings')->where('id', $qiraatId)->exists()) {
            $this->error("Qiraat ID {$qiraatId} not found in qiraat_readings.");
            return self::FAILURE;
        }

        if ($ayahFirst) {
            $this->info('Running ayah-level repair (qiraat:repair-and-exact-map) first...');
            $exit = Artisan::call('qiraat:repair-and-exact-map', [
                'qiraat_reading_id' => (string) $qiraatId,
                '--fix-numbering' => true,
                '--clean' => true,
                '--exact-pure-text' => true,
                '--health-check' => true,
            ]);
            if ($exit !== 0) {
                $this->error('Ayah-level repair failed.');
                return self::FAILURE;
            }
            $this->line(Artisan::output());
        }

        $path = $reportPath ?: $this->findLatestReport($qiraatId);
        if ($path === null || !is_readable($path)) {
            $this->error('No report file found. Run qiraat:auto-map-words first, or pass --report=/path/to/report.csv');
            return self::FAILURE;
        }

        $this->line("Report: {$path}");
        [$rows, $ayahIds] = $this->parseReport($path, $reasonFilter);
        if (empty($ayahIds)) {
            $this->info('No affected ayahs in report (or none match --reason). Nothing to clear.');
            if ($doRemap && !$doClear) {
                $this->warn('--remap without --clear will run auto-map for entire qiraat.');
                if ($this->confirm('Run qiraat:auto-map-words for qiraat ' . $qiraatId . '?', false)) {
                    return $this->runAutoMapWords($qiraatId);
                }
            }
            return self::SUCCESS;
        }

        $this->info("Report rows: {$rows} | Distinct ayahs: " . count($ayahIds));

        if ($doClear) {
            $count = $this->countWordMapsForAyahs($ayahIds);
            if ($dryRun) {
                $this->warn("[DRY-RUN] Would delete {$count} word map row(s) for " . count($ayahIds) . " mushaf_ayah_id(s).");
            } else {
                $deleted = $this->clearWordMapsForAyahs($ayahIds);
                $this->info("Deleted {$deleted} word map row(s) for " . count($ayahIds) . " ayah(s).");
            }
        }

        if ($doRemap && !$dryRun) {
            $this->info('Running qiraat:auto-map-words...');
            return $this->runAutoMapWords($qiraatId);
        }

        if ($doClear && !$doRemap && !$dryRun) {
            $this->line('Tip: run <info>php artisan qiraat:auto-map-words ' . $qiraatId . '</info> to re-map.');
        }

        return self::SUCCESS;
    }

    private function findLatestReport(int $qiraatId): ?string
    {
        $dir = storage_path('app/qiraat_import_logs');
        if (!is_dir($dir)) {
            return null;
        }
        $pattern = $dir . '/auto_map_words_qiraat_' . $qiraatId . '_*_report.csv';
        $files = glob($pattern);
        if (empty($files)) {
            return null;
        }
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return $files[0];
    }

    /**
     * @return array{0: int, 1: array<int>} [row count, distinct mushaf_ayah_ids]
     */
    private function parseReport(string $path, ?string $reasonFilter): array
    {
        $fp = fopen($path, 'r');
        if (!$fp) {
            return [0, []];
        }
        fgetcsv($fp); // skip header row
        $ayahIds = [];
        $rows = 0;
        while (($row = fgetcsv($fp)) !== false) {
            if (count($row) < 1) continue;
            $ayahId = (int) $row[0];
            $reason = $row[3] ?? '';
            if ($reasonFilter !== null && $reasonFilter !== '' && strpos($reason, $reasonFilter) === false) {
                continue;
            }
            $ayahIds[$ayahId] = true;
            $rows++;
        }
        fclose($fp);
        return [$rows, array_keys($ayahIds)];
    }

    private function countWordMapsForAyahs(array $mushafAyahIds): int
    {
        if (empty($mushafAyahIds)) return 0;
        return (int) DB::table('mushaf_word_to_word_map as m')
            ->join('mushaf_words as mw', 'mw.id', '=', 'm.mushaf_word_id')
            ->whereIn('mw.mushaf_ayah_id', $mushafAyahIds)
            ->count();
    }

    private function clearWordMapsForAyahs(array $mushafAyahIds): int
    {
        if (empty($mushafAyahIds)) return 0;
        $mushafWordIds = DB::table('mushaf_words')
            ->whereIn('mushaf_ayah_id', $mushafAyahIds)
            ->pluck('id')
            ->all();
        if (empty($mushafWordIds)) return 0;
        $deleted = 0;
        foreach (array_chunk($mushafWordIds, 5000) as $chunk) {
            $deleted += DB::table('mushaf_word_to_word_map')->whereIn('mushaf_word_id', $chunk)->delete();
        }
        return $deleted;
    }

    private function runAutoMapWords(int $qiraatId): int
    {
        $exit = Artisan::call('qiraat:auto-map-words', [
            'qiraat_reading_id' => (string) $qiraatId,
            '--max-combined-words' => 6,
            '--max-split-words' => 6,
            '--preclean' => true,
            '--postclean' => true,
        ]);
        $this->line(Artisan::output());
        return $exit === 0 ? self::SUCCESS : self::FAILURE;
    }
}
