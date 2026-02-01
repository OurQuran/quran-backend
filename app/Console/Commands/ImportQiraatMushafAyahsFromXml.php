<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportQiraatMushafAyahsFromXml extends Command
{
    protected $signature = 'qiraat:import-mushaf-ayahs-xml
        {file : Path to XML}
        {qiraat_reading_id : qiraat_readings.id}
        {--chunk=1000 : Insert chunk size (min 200)}
        {--dry-run : Do not insert anything, only simulate}
        {--report= : Path to report file (CSV). If omitted, auto in storage/app/qiraat_import_logs}
        {--report-limit=20000 : Max report lines}
        {--report-only : Do not insert, only produce report}';

    protected $description = 'Import qiraat mushaf from XML into mushaf_ayahs (raw), without mapping to ayahs yet.';

    private $reportFp = null;
    private int $reportLines = 0;
    private int $reportLimit = 20000;
    private string $reportPath = '';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $qiraatId = (int) $this->argument('qiraat_reading_id');

        $chunk = (int) $this->option('chunk');
        $chunk = max(200, $chunk);

        $dryRun = (bool) $this->option('dry-run');
        $reportOnly = (bool) $this->option('report-only');

        if (!is_file($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        if (!DB::table('qiraat_readings')->where('id', $qiraatId)->exists()) {
            $this->error("qiraat_readings not found: id={$qiraatId}");
            return self::FAILURE;
        }

        // If report-only is enabled, behave like dry-run (no inserts)
        if ($reportOnly) {
            $dryRun = true;
        }

        $reader = new \XMLReader();

        // Better error handling for broken XML
        $prevUseErrors = libxml_use_internal_errors(true);

        try {
            // Open XML
            if (!$reader->open($file, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
                $this->error("Failed to open XML file: {$file}");
                return self::FAILURE;
            }

            $this->openReport($this->option('report'), $qiraatId);

            $buffer = [];
            $upserted = 0;
            $processed = 0;
            $skipped = 0;

            $this->info("Reading XML (streaming)...");
            $this->line("Mode: " . ($reportOnly ? "REPORT-ONLY" : ($dryRun ? "DRY-RUN" : "INSERT")) . " | Chunk: {$chunk}");
            $this->line("Report: {$this->reportPath}");

            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'ROW') {
                    continue;
                }

                $processed++;

                $rowXml = $reader->readOuterXML();
                if (!$rowXml) {
                    $skipped++;
                    $this->reportRow($processed, 0, 0, 0, 0, 'empty_row_xml', '');
                    continue;
                }

                $sx = @simplexml_load_string($rowXml);
                if (!$sx) {
                    $skipped++;
                    $this->reportRow($processed, 0, 0, 0, 0, 'invalid_xml_row', '');
                    continue;
                }

                $suraNo = (int) ($sx->sura_no ?? 0);
                $ayaNo  = (int) ($sx->aya_no ?? 0);
                $ayaText = trim((string) ($sx->aya_text ?? ''));

                if ($suraNo <= 0 || $ayaNo <= 0 || $ayaText === '') {
                    $skipped++;
                    $this->reportRow($processed, $suraNo, $ayaNo, 0, 0, 'invalid_row_fields', $ayaText);
                    continue;
                }

                // Optional fields if your XML contains them
                $page = isset($sx->page) ? (int) $sx->page : null;
                $jozz = isset($sx->jozz) ? (int) $sx->jozz : null;

                $cleanText = $this->stripTrailingVerseNumber($ayaText);

                // You can report oddities if you want
                if ($cleanText === '') {
                    $skipped++;
                    $this->reportRow($processed, $suraNo, $ayaNo, 0, 0, 'empty_text_after_strip', $ayaText);
                    continue;
                }

                $buffer[] = [
                    'qiraat_reading_id' => $qiraatId,
                    'ayah_id' => null, // mapping later
                    'surah_id' => $suraNo,
                    'number_in_surah' => $ayaNo,
                    'text' => $cleanText,

                    'page' => $page,
                    'juz_id' => $jozz,
                    'hizb_id' => null,
                    'sajda' => null,

                    // set later per flush (faster) - keep placeholders
                    'created_at' => null,
                    'updated_at' => null,
                ];

                if (count($buffer) >= $chunk) {
                    $upserted += $this->flush($buffer, $dryRun);
                    $buffer = [];
                    $this->line("Processed: {$processed} | Upserted: {$upserted} | Skipped: {$skipped} | ReportLines: {$this->reportLines}");
                }
            }

            if (!empty($buffer)) {
                $upserted += $this->flush($buffer, $dryRun);
            }

            $this->newLine();
            $this->info("Done.");
            $this->line("Processed ROW nodes: {$processed}");
            $this->line("Upserted: {$upserted}" . ($dryRun ? " (simulated)" : ""));
            $this->line("Skipped invalid rows: {$skipped}");
            $this->line("Report lines written: {$this->reportLines}");
            $this->line("Report file: {$this->reportPath}");

            // Show libxml errors (if any)
            $errors = libxml_get_errors();
            if (!empty($errors)) {
                $this->warn("XML warnings/errors detected (showing up to 5):");
                foreach (array_slice($errors, 0, 5) as $err) {
                    $this->warn(trim($err->message));
                }
            }

            return self::SUCCESS;
        } finally {
            // Always close resources
            try { $reader->close(); } catch (\Throwable $e) {}
            $this->closeReport();

            libxml_clear_errors();
            libxml_use_internal_errors($prevUseErrors);
        }
    }

    private function flush(array &$rows, bool $dryRun): int
    {
        if (empty($rows)) {
            return 0;
        }

        // Fill timestamps once per flush (faster than now() per row)
        $now = now();
        foreach ($rows as &$r) {
            if (empty($r['created_at'])) $r['created_at'] = $now;
            $r['updated_at'] = $now;
        }
        unset($r);

        if ($dryRun) {
            return count($rows);
        }

        DB::table('mushaf_ayahs')->upsert(
            $rows,
            // Requires UNIQUE(qiraat_reading_id, surah_id, number_in_surah)
            ['qiraat_reading_id', 'surah_id', 'number_in_surah'],
            ['text', 'page', 'juz_id', 'hizb_id', 'sajda', 'updated_at']
        );

        return count($rows);
    }

    private function stripTrailingVerseNumber(string $text): string
    {
        // Normalize NBSP and trim
        $text = trim(str_replace("\xC2\xA0", ' ', $text));

        // Remove trailing Arabic/Latin digits (verse numbers) preceded by whitespace
        $text = preg_replace(
            '/[\s]+[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]+$/u',
            '',
            $text
        );

        return trim((string) $text);
    }

    private function openReport(?string $path, int $qiraatId): void
    {
        $dir = storage_path('app/qiraat_import_logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $this->reportLimit = (int) ($this->option('report-limit') ?? 20000);
        if ($this->reportLimit <= 0) {
            $this->reportLimit = 20000;
        }

        $this->reportPath = $path ?: ($dir . '/qiraat_' . $qiraatId . '_' . now()->format('Y-m-d_His') . '_report.csv');

        $fp = @fopen($this->reportPath, 'w');
        if (!$fp) {
            // Don't kill import; just disable report
            $this->warn("Could not open report file for writing: {$this->reportPath}");
            $this->reportFp = null;
            return;
        }

        $this->reportFp = $fp;

        fputcsv($this->reportFp, [
            'row_index',
            'surah_no',
            'aya_no',
            'offset',
            'computed_base_aya_no',
            'reason',
            'dataset_text_preview',
        ]);
    }

    private function closeReport(): void
    {
        if ($this->reportFp) {
            fclose($this->reportFp);
            $this->reportFp = null;
        }
    }

    private function reportRow(
        int $rowIndex,
        int $surahNo,
        int $ayaNo,
        int $offset,
        int $baseAyaNo,
        string $reason,
        string $text
    ): void {
        if (!$this->reportFp) return;
        if ($this->reportLines >= $this->reportLimit) return;

        $preview = mb_substr(trim($text), 0, 120);

        fputcsv($this->reportFp, [
            $rowIndex,
            $surahNo,
            $ayaNo,
            $offset,
            $baseAyaNo,
            $reason,
            $preview,
        ]);

        $this->reportLines++;
    }
}
