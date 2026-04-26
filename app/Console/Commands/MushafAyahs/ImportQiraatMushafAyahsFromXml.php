<?php

namespace App\Console\Commands\MushafAyahs;

use App\Console\Concerns\InteractsWithCsvReports;
use App\Console\Concerns\InteractsWithImportPaths;
use App\Console\Concerns\UpsertsMushafAyahs;
use App\Support\QiraatImportMaps;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportQiraatMushafAyahsFromXml extends Command
{
    use InteractsWithCsvReports;
    use InteractsWithImportPaths;
    use UpsertsMushafAyahs;

    protected $signature = 'qiraat:import-mushaf-ayahs-xml
        {qiraat_reading : qiraat_readings.id or stable code}
        {file? : Path to XML (optional). If omitted or "auto", uses reading code => XML mapping}
        {--dataset-root= : Base folder of quran-data-kfgqpc repo (used when file is omitted or relative)}
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
    private array $referenceAyahMeta = []; // key => ['hizb_id' => ?, 'sajda' => ?]

    // Track which ayahs we've seen (reporting only)
    private array $seenAyahs = [];
    private array $referenceAyahs = [];
    private array $referenceAyahTexts = [];
    private array $duplicates = [];
    private array $extraAyahs = [];

    public function handle(): int
    {
        $readingArg = $this->argument('qiraat_reading');
        $qiraatId = QiraatImportMaps::resolveReadingId(is_string($readingArg) ? trim($readingArg) : (string) $readingArg);

        $chunk = max(200, (int) ($this->option('chunk') ?? 1000));
        $dryRun = (bool) $this->option('dry-run');
        $reportOnly = (bool) $this->option('report-only');

        if (!$qiraatId) {
            $this->error('qiraat_readings not found for the provided reading argument.');
            return self::FAILURE;
        }

        // If report-only is enabled, behave like dry-run (no inserts)
        if ($reportOnly) {
            $dryRun = true;
        }

        // Resolve XML path (either from argument or from mapping)
        $file = $this->resolveXmlPath($qiraatId);

        if (!$file) {
            $this->error("No XML file resolved. Provide {file} or add qiraat_reading_id={$qiraatId} to the mapping array.");
            return self::FAILURE;
        }

        if (!is_file($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        // Load reference ayahs from the ayahs table (for reporting only)
        $this->loadReferenceAyahs();
        $this->info("Loaded " . count($this->referenceAyahs) . " reference ayahs from ayahs table");

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
            $this->line("XML: {$file}");
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
                    $this->reportRow($processed, 0, 0, 'empty_row_xml', '', '');
                    continue;
                }

                $sx = @simplexml_load_string($rowXml);
                if (!$sx) {
                    $skipped++;
                    $this->reportRow($processed, 0, 0, 'invalid_xml_row (malformed XML structure)', substr($rowXml, 0, 100), '');
                    continue;
                }

                $suraNo  = (int) ($sx->sura_no ?? 0);
                $ayaNo   = (int) ($sx->aya_no ?? 0);
                $ayaText = trim((string) ($sx->aya_text ?? ''));

                if ($suraNo <= 0 || $ayaNo <= 0 || $ayaText === '') {
                    $skipped++;
                    $reasonDetail = [];
                    if ($suraNo <= 0) $reasonDetail[] = 'invalid_surah_no';
                    if ($ayaNo <= 0) $reasonDetail[] = 'invalid_aya_no';
                    if ($ayaText === '') $reasonDetail[] = 'empty_text';
                    $this->reportRow($processed, $suraNo, $ayaNo, 'invalid_row_fields: ' . implode(', ', $reasonDetail), $ayaText, '');
                    continue;
                }

                // Reporting-only: compare to base ayah reference table
                $ayahKey = "{$suraNo}:{$ayaNo}";

                if (!isset($this->referenceAyahs[$ayahKey])) {
                    $this->extraAyahs[$ayahKey] = $ayaText;
                    $this->reportRow($processed, $suraNo, $ayaNo, 'extra_ayah_not_in_reference', $ayaText, '');
                }

                if (isset($this->seenAyahs[$ayahKey])) {
                    $this->duplicates[$ayahKey] = ($this->duplicates[$ayahKey] ?? 1) + 1;
                    $this->reportRow(
                        $processed,
                        $suraNo,
                        $ayaNo,
                        'duplicate_ayah (occurrence #' . $this->duplicates[$ayahKey] . ')',
                        $ayaText,
                        $this->seenAyahs[$ayahKey]
                    );
                } else {
                    $this->seenAyahs[$ayahKey] = $ayaText;
                }

                // Optional fields if your XML contains them
                $page = isset($sx->page) ? (int) $sx->page : null;
                $jozz = isset($sx->jozz) ? (int) $sx->jozz : null;

                $cleanText = $this->stripTrailingVerseNumber($ayaText);

                if ($cleanText === '') {
                    $skipped++;
                    $this->reportRow($processed, $suraNo, $ayaNo, 'empty_text_after_strip (text only contained verse number)', $ayaText, '');
                    continue;
                }

                // IMPORTANT: match your FINAL mushaf_ayahs schema (no ayah_id, no timestamps)
                $meta = $this->referenceAyahMeta[$ayahKey] ?? ['hizb_id' => null, 'sajda' => null];
                $buffer[] = [
                    'qiraat_reading_id' => $qiraatId,
                    'surah_id'          => $suraNo,
                    'number_in_surah'   => $ayaNo,
                    'text'              => $cleanText,

                    'page'              => $page,
                    'juz_id'            => $jozz,
                    'hizb_id'           => $meta['hizb_id'],
                    'sajda'             => $meta['sajda'],

                    'ayah_template'     => null,
                    'pure_text'         => null,
                ];

                if (count($buffer) >= $chunk) {
                    $upserted += $this->upsertMushafAyahRows($buffer, $dryRun);
                    $buffer = [];
                    $this->line("Processed: {$processed} | Upserted: {$upserted} | Skipped: {$skipped} | ReportLines: {$this->reportLines}");
                }
            }

            if (!empty($buffer)) {
                $upserted += $this->upsertMushafAyahRows($buffer, $dryRun);
            }

            // Report missing ayahs (based on reference table, reporting only)
            $this->reportMissingAyahs();

            $this->newLine();
            $this->info("Done.");
            $this->line("Processed ROW nodes: {$processed}");
            $this->line("Upserted: {$upserted}" . ($dryRun ? " (simulated)" : ""));
            $this->line("Skipped invalid rows: {$skipped}");
            $this->line("Report lines written: {$this->reportLines}");
            $this->line("Report file: {$this->reportPath}");

            $this->newLine();
            $this->info("=== AYAH COMPARISON SUMMARY ===");
            $this->line("Reference ayahs (from ayahs table): " . count($this->referenceAyahs));
            $this->line("Dataset ayahs (from XML): " . count($this->seenAyahs));
            $this->line("Duplicates found: " . count($this->duplicates));
            $this->line("Extra ayahs (in dataset but not in reference): " . count($this->extraAyahs));

            $missingCount = count($this->referenceAyahs) - count($this->seenAyahs);
            if ($missingCount > 0) {
                $this->warn("Missing ayahs: {$missingCount}");
            } elseif ($missingCount < 0) {
                $this->warn("Extra ayahs: " . abs($missingCount));
            } else {
                $this->info("Ayah count matches!");
            }

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
            try { $reader->close(); } catch (\Throwable $e) {}
            $this->closeReport();

            libxml_clear_errors();
            libxml_use_internal_errors($prevUseErrors);
        }
    }

    /**
     * Resolve XML path from:
     * - argument {file} if provided and not "auto"
     * - else mapping qiraat_readings.id => relative xml path
     *
     * If the chosen path is relative, it will be resolved under --dataset-root (or default).
     */
    private function resolveXmlPath(int $qiraatId): ?string
    {
        $arg = $this->argument('file');
        $arg = is_string($arg) ? trim($arg) : '';

        // Default dataset root (you can change this to your actual default)
        $root = $this->option('dataset-root');
        $root = is_string($root) && trim($root) !== ''
            ? trim($root)
            : QiraatImportMaps::DEFAULT_XML_DATASET_ROOT;

        $root = rtrim($this->expandPath($root), DIRECTORY_SEPARATOR);

        // If user passed a file and it's not "auto", use it
        if ($arg !== '' && strtolower($arg) !== 'auto') {
            $path = $this->expandPath($arg);

            // If relative, resolve under dataset root
            if (!$this->isAbsolutePath($path)) {
                $path = $root . DIRECTORY_SEPARATOR . $path;
            }

            return $path;
        }

        $code = DB::table('qiraat_readings')->where('id', $qiraatId)->value('code');
        $rel = $code ? (QiraatImportMaps::xmlByReadingCode()[$code] ?? null) : null;
        if (!$rel) return null;

        return $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $rel);
    }

    private function loadReferenceAyahs(): void
    {
        $ayahs = DB::table('ayahs')
            ->select('surah_id', 'number_in_surah', 'text', 'hizb_id', 'sajda')
            ->get();

        foreach ($ayahs as $ayah) {
            $key = "{$ayah->surah_id}:{$ayah->number_in_surah}";
            $this->referenceAyahs[$key] = true;
            $this->referenceAyahTexts[$key] = $ayah->text ?? '';

            $this->referenceAyahMeta[$key] = [
                'hizb_id' => $ayah->hizb_id ?? null,
                'sajda'   => $ayah->sajda ?? null,
            ];
        }
    }

    private function reportMissingAyahs(): void
    {
        $this->info("Checking for missing ayahs...");

        $missingCount = 0;
        foreach ($this->referenceAyahs as $ayahKey => $_) {
            if (!isset($this->seenAyahs[$ayahKey])) {
                [$surahNo, $ayaNo] = explode(':', $ayahKey);
                $referenceText = $this->referenceAyahTexts[$ayahKey] ?? '';

                $reason = 'missing_ayah_from_dataset';

                if ($ayaNo == 1 && in_array((int) $surahNo, [1, 9], true)) {
                    $reason .= ' (special case: Surah ' . $surahNo . ')';
                }

                $this->reportRow(
                    0,
                    (int) $surahNo,
                    (int) $ayaNo,
                    $reason,
                    '',
                    $referenceText
                );

                $missingCount++;
            }
        }

        if ($missingCount > 0) {
            $this->warn("Found {$missingCount} missing ayahs (logged to report)");
        }
    }

    private function stripTrailingVerseNumber(string $text): string
    {
        $text = trim(str_replace("\xC2\xA0", ' ', $text));

        $text = preg_replace(
            '/[\s]+[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]+$/u',
            '',
            $text
        );

        return trim((string) $text);
    }

    private function openReport(?string $path, int $qiraatId): void
    {
        $this->reportLimit = (int) ($this->option('report-limit') ?? 20000);
        if ($this->reportLimit <= 0) {
            $this->reportLimit = 20000;
        }

        $this->openCsvReport($path, 'qiraat_' . $qiraatId . '_' . now()->format('Y-m-d_His') . '_report.csv', [
            'row_index',
            'surah_no',
            'aya_no',
            'reason',
            'dataset_text_preview',
            'reference_text_preview',
        ]);
    }

    private function closeReport(): void
    {
        $this->closeCsvReport();
    }

    private function reportRow(
        int $rowIndex,
        int $surahNo,
        int $ayaNo,
        string $reason,
        string $datasetText,
        string $referenceText = ''
    ): void {
        $datasetPreview = mb_substr(trim($datasetText), 0, 120);
        $referencePreview = mb_substr(trim($referenceText), 0, 120);

        if ($referenceText === '' && isset($this->referenceAyahTexts["{$surahNo}:{$ayaNo}"])) {
            $referencePreview = mb_substr(trim($this->referenceAyahTexts["{$surahNo}:{$ayaNo}"]), 0, 120);
        }

        $this->writeCsvReportRow([
            $rowIndex,
            $surahNo,
            $ayaNo,
            $reason,
            $datasetPreview,
            $referencePreview,
        ]);
    }
}
