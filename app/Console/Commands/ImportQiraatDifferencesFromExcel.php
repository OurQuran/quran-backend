<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import qiraat_differences from Excel files.
 *
 * Inserts each row as-is: qiraat_reading_id, surah, ayah (from Excel), hafs_text, qiraat_text, explanation.
 * No ayah_id or word IDs; surah and ayah are the qiraat's own numbering.
 *
 * Excel columns (auto-detected): surah, ayah, hafs (hafs_text), qiraa (qiraat_text), explanation (optional).
 */
class ImportQiraatDifferencesFromExcel extends Command
{
    protected $signature = 'qiraat:import-differences-excel
        {qiraat_reading_id : qiraat_readings.id OR "auto" to import all mapped ids}
        {path? : Excel file (.xlsx) or directory. If omitted, uses mapping for that qiraat_reading_id}
        {--sheet=0 : Sheet index (default 0)}
        {--chunk=200 : Insert chunk size}
        {--dry-run : Do not insert, only report}
        {--report= : CSV report path. If omitted, auto in storage/app/qiraat_import_logs}
        {--report-limit=50000 : Max report lines}
    ';

    protected $description = 'Import qiraat_differences from Excel (qiraat_reading_id, ayah_id, hafs_text, qiraat_text, explanation).';

    private $reportFp = null;
    private int $reportLines = 0;
    private int $reportLimit = 50000;
    private string $reportPath = '';

    /**
     * qiraat_readings.id => path (relative to storage/app, or absolute).
     * Used by "auto" and by console.php import:qiraat-differences-from-excel.
     * Do not add id 1 (Hafs) to this mapping.
     */
    private array $excelByQiraatReadingId = [
        2  => 'excels/5_warsh.xlsx',            // Warsh 'an Nafi
        3  => 'excels/10_shouba.xlsx',         // Shu'bah 'an Asim
        4  => 'excels/4_qaloon.xlsx',           // Qalun 'an Nafi
        5  => 'excels/3_doori.xlsx',            // Ad-Duri 'an Abu Amr
        6  => 'excels/3_soosi.xlsx',            // As-Susi 'an Abu Amr
        7  => 'excels/2_bazzi.xlsx',            // Al-Bazzi 'an Ibn Kathir
        8  => 'excels/2_qumbul.xlsx',           // Qunbul 'an Ibn Kathir
        9  => 'excels/1_hisham.xlsx',                         // Hisham 'an Ibn Amir
        10 => 'excels/1_thakwan.xlsx',                        // Ibn Dhakwan 'an Ibn Amir
        11 => 'excels/8_xalaf.xlsx',                          // Khalaf 'an Hamzah
        12 => 'excels/8_xallad.xlsx',                         // Khallad 'an Hamzah
        13 => 'excels/7_haris.xlsx',                          // Abu Al-Harith 'an Al-Kisai
        14 => 'excels/7_doori.xlsx',                          // Ad-Duri 'an Al-Kisai
        15 => 'excels/6_wardan.xlsx',                         // Ibn Wardan 'an Abu Ja'far
        16 => 'excels/6_jamar.xlsx',                          // Ibn Jammaz 'an Abu Ja'far
        17 => 'excels/11_rwais.xlsx',                         // Ruways 'an Ya'qub
        18 => 'excels/11_rawh.xlsx',                          // Rawh 'an Ya'qub
        19 => 'excels/9_ishaq.xlsx',                          // Ishaq 'an Khalaf al-'Ashir
        20 => 'excels/9_idris.xlsx',                          // Idris 'an Khalaf al-'Ashir
    ];

    public function handle(): int
    {
        $arg = $this->argument('qiraat_reading_id');
        $arg = is_string($arg) ? trim($arg) : (string) $arg;
        $sheetIndex = (int) ($this->option('sheet') ?? 0);
        $chunk = max(50, (int) ($this->option('chunk') ?? 200));
        $dryRun = (bool) $this->option('dry-run');

        $this->reportLimit = (int) ($this->option('report-limit') ?? 50000);
        if ($this->reportLimit <= 0) $this->reportLimit = 50000;

        if (strtolower($arg) === 'auto') {
            $ids = array_keys($this->excelByQiraatReadingId);
            sort($ids, SORT_NUMERIC);
            $this->info('Auto: importing for ' . count($ids) . ' mapped id(s): ' . implode(', ', $ids));
            $ok = 0;
            $fail = 0;
            foreach ($ids as $qiraatId) {
                $qiraatId = (int) $qiraatId;
                $this->newLine();
                $this->info("=== qiraat_reading_id={$qiraatId} ===");
                $result = $this->runSingle($qiraatId, $sheetIndex, $chunk, $dryRun);
                if ($result === self::SUCCESS) $ok++;
                else $fail++;
            }
            $this->newLine();
            $this->info("Auto finished. OK={$ok} | FAIL={$fail}");
            return $fail > 0 ? self::FAILURE : self::SUCCESS;
        }

        $qiraatId = (int) $arg;
        if (!DB::table('qiraat_readings')->where('id', $qiraatId)->exists()) {
            $this->error("qiraat_readings not found: id={$qiraatId}");
            return self::FAILURE;
        }
        return $this->runSingle($qiraatId, $sheetIndex, $chunk, $dryRun);
    }

    private function runSingle(int $qiraatId, int $sheetIndex, int $chunk, bool $dryRun): int
    {
        if (!DB::table('qiraat_readings')->where('id', $qiraatId)->exists()) {
            $this->warn("qiraat_reading_id={$qiraatId} not in qiraat_readings — skipping.");
            return self::SUCCESS;
        }

        $pathArg = $this->resolveExcelPath($qiraatId);
        if ($pathArg === '') {
            $this->error("No Excel path for qiraat_reading_id={$qiraatId}. Provide {path} or add to mapping.");
            return self::FAILURE;
        }

        $files = $this->resolveExcelFiles($pathArg);
        if (empty($files)) {
            $this->error("No .xlsx files found: {$pathArg}");
            $this->line("Resolved path exists: " . (file_exists($pathArg) ? 'yes' : 'no'));
            return self::FAILURE;
        }

        $this->openReport($this->option('report'), $qiraatId);

        $this->info("Importing qiraat_differences for qiraat_reading_id={$qiraatId}");
        $this->line("Path: " . $files[0]);
        $this->line("Files: " . count($files) . " | Sheet: {$sheetIndex} | " . ($dryRun ? 'DRY-RUN' : 'INSERT'));
        $this->line("Report: {$this->reportPath}");

        $rowsToInsert = [];
        $inserted = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($files as $file) {
            try {
                $loaded = $this->loadRowsFromExcel($file, $sheetIndex);
                if (empty($loaded)) {
                    $this->warn("  No rows loaded from " . basename($file) . " (check sheet index {$sheetIndex} and column headers: Surah, Ayah, Hafs, Qeraa).");
                }
                foreach ($loaded as $row) {
                    $resolved = $this->resolveRowToDifference($qiraatId, $row);
                    if ($resolved === null) {
                        $errors++;
                        continue;
                    }
                    if ($resolved === 'skip') {
                        $skipped++;
                        continue;
                    }
                    $rowsToInsert[] = $resolved;
                    if (count($rowsToInsert) >= $chunk) {
                        if (!$dryRun) {
                            $inserted += $this->upsertChunk($rowsToInsert);
                        } else {
                            $inserted += count($rowsToInsert);
                        }
                        $rowsToInsert = [];
                    }
                }
            } catch (\Throwable $e) {
                $this->reportRow(0, 0, 'excel_error', $file, $e->getMessage());
                $this->warn("File {$file}: " . $e->getMessage());
                $errors++;
            }
        }

        if (!empty($rowsToInsert)) {
            if (!$dryRun) {
                $inserted += $this->upsertChunk($rowsToInsert);
            } else {
                $inserted += count($rowsToInsert);
            }
        }

        $this->closeReport();
        $this->newLine();
        $this->info("Done. Inserted/updated: {$inserted} | Skipped: {$skipped} | Errors: {$errors} | Report lines: {$this->reportLines}");
        if ($inserted === 0 && $errors > 0) {
            $this->warn("No rows inserted; check report for reasons (e.g. ayah_not_found).");
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveExcelPath(int $qiraatId): string
    {
        $arg = $this->argument('path');
        $arg = is_string($arg) ? trim($arg) : '';
        if ($arg !== '') {
            return $this->expandPath($arg);
        }
        $rel = $this->excelByQiraatReadingId[$qiraatId] ?? '';
        if ($rel === '') return '';
        if ($this->isAbsolutePath($rel)) {
            return $this->expandPath($rel);
        }
        // Path relative to storage/app (not Storage::disk('local') which is storage/app/private in this project)
        return storage_path('app/' . ltrim($rel, '/'));
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') return false;
        if (str_starts_with($path, '/')) return true;
        return (bool) preg_match('/^[A-Za-z]:\\\\/', $path);
    }

    private function expandPath(string $path): string
    {
        $path = trim($path);
        if ($path !== '' && $path[0] === '~') {
            $home = getenv('HOME') ?: '';
            if ($home !== '') $path = $home . substr($path, 1);
        }
        return $path;
    }

    private function resolveExcelFiles(string $pathArg): array
    {
        $pathArg = trim($pathArg);
        $parts = array_filter(array_map('trim', explode(',', $pathArg)));
        $files = [];
        foreach ($parts as $p) {
            $p = $this->expandPath($p);
            if (is_dir($p)) {
                $found = glob(rtrim($p, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.xlsx') ?: [];
                foreach ($found as $f) $files[] = $f;
            } elseif (is_file($p) && str_ends_with(strtolower($p), '.xlsx')) {
                $files[] = $p;
            }
        }
        $files = array_values(array_unique($files));
        sort($files);
        return $files;
    }

    private function loadRowsFromExcel(string $file, int $sheetIndex): array
    {
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getSheet($sheetIndex);
        $rows = $sheet->toArray(null, true, true, true);
        if (count($rows) < 2) {
            return [];
        }
        $header = $rows[1] ?? [];
        $colMap = $this->detectColumnsFromHeader($header);
        $out = [];
        for ($i = 2; $i <= count($rows); $i++) {
            $r = $rows[$i] ?? [];
            $surah = (int) ($r[$colMap['surah']] ?? 0);
            $ayah = (int) ($r[$colMap['ayah']] ?? 0);
            $hafs = trim((string) ($r[$colMap['hafs']] ?? ''));
            $qiraa = trim((string) ($r[$colMap['qiraa']] ?? ''));
            $explanation = isset($colMap['explanation']) ? trim((string) ($r[$colMap['explanation']] ?? '')) : '';
            if ($surah <= 0 || $ayah <= 0 || $hafs === '') {
                continue;
            }
            $out[] = [
                'surah' => $surah,
                'ayah' => $ayah,
                'hafs' => $hafs,
                'qiraa' => $qiraa,
                'explanation' => $explanation,
                'file' => basename($file),
                'row' => $i,
            ];
        }
        return $out;
    }

    private function detectColumnsFromHeader(array $headerRow): array
    {
        $norm = [];
        foreach ($headerRow as $col => $val) {
            $v = strtolower(trim((string) $val));
            $v = preg_replace('/\s+/', ' ', $v);
            $norm[$col] = $v;
        }
        // Match header value: exact, or with spaces normalized to underscores
        $find = function (array $candidates) use ($norm): ?string {
            foreach ($norm as $col => $name) {
                if ($name === '') continue;
                $nameUnderscore = str_replace(' ', '_', $name);
                foreach ($candidates as $cand) {
                    if ($name === $cand || $nameUnderscore === $cand) return $col;
                }
            }
            return null;
        };
        $surahCol = $find(['surah', 'sura', 'sura_no', 'surah_no', 'surah_id']);
        $ayahCol = $find(['ayah', 'aya', 'aya_no', 'ayah_no', 'number_in_surah', 'ayah_number', 'ayah number']);
        $hafsCol = $find(['hafs', 'hafs_word', 'hafs_kalma', 'hafs_kalima', 'hafs kalma', 'word_hafs', 'h']);
        $qiraaCol = $find(['qiraa', 'qiraat', 'qiraat_word', 'qiraa_word', 'qiraa_kalma', 'qiraat_kalma', 'qeraa options', 'qeraa kalma', 'q']);
        $explCol = $find(['explanation', 'explain', 'note', 'notes', 'comment', 'wasf']);
        return array_filter([
            'surah' => $surahCol ?: 'A',
            'ayah' => $ayahCol ?: 'B',
            'hafs' => $hafsCol ?: 'C',
            'qiraa' => $qiraaCol ?: 'D',
            'explanation' => $explCol ?: null,
        ], fn($v) => $v !== null);
    }

    /**
     * Build one qiraat_differences row from Excel data. Stores surah and ayah as-is from the Excel
     * (ayah number can differ per qiraat); no ayah_id or word IDs.
     */
    private function resolveRowToDifference(int $qiraatId, array $row): array|string|null
    {
        $surah = (int) $row['surah'];
        $ayah = (int) $row['ayah'];
        $qiraatText = trim((string) ($row['qiraa'] ?? ''));
        $now = now();
        return [
            'qiraat_reading_id' => $qiraatId,
            'surah' => $surah,
            'ayah' => $ayah,
            'hafs_text' => $row['hafs'],
            'qiraat_options' => $qiraatText !== '' ? $qiraatText : ' ',
            'qiraat_text' => $qiraatText,
            'explanation' => $row['explanation'] !== '' ? $row['explanation'] : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Find (start_word_id, end_word_id) for hafs_text by matching normalized text to base words.
     */
    private function findWordSpanForHafsText($words, string $hafsText, int $surah, int $ayah, string $file, int $excelRow): ?array
    {
        $normalizedHafs = $this->normalizeForMatch($hafsText);
        if ($normalizedHafs === '') {
            $this->reportRow($surah, $ayah, 'hafs_empty_after_normalize', $hafsText, "file={$file} row={$excelRow}");
            return null;
        }

        $wordList = $words->all();
        $normalizedWords = [];
        foreach ($wordList as $w) {
            $raw = (string) ($w->pure_word ?? $w->word ?? '');
            $normalizedWords[] = $this->normalizeForMatch($raw);
        }

        $withSpaces = implode(' ', $normalizedWords);
        $noSpaces = implode('', $normalizedWords);
        $normalizedHafsNoSpaces = $this->normalizeForMatchNoSpaces($hafsText);

        $startIdx = null;
        $endIdx = null;

        if (mb_strpos($withSpaces, $normalizedHafs, 0, 'UTF-8') !== false) {
            $span = $this->findSpanInNormalizedWords($normalizedWords, $normalizedHafs, true);
            if ($span !== null) {
                [$startIdx, $endIdx] = $span;
            }
        }
        if ($startIdx === null && $normalizedHafsNoSpaces !== '' && mb_strpos($noSpaces, $normalizedHafsNoSpaces, 0, 'UTF-8') !== false) {
            $span = $this->findSpanInNormalizedWords($normalizedWords, $normalizedHafsNoSpaces, false);
            if ($span !== null) {
                [$startIdx, $endIdx] = $span;
            }
        }

        if ($startIdx === null) {
            $this->reportRow($surah, $ayah, 'hafs_not_found_in_ayah_words', $hafsText, "file={$file} row={$excelRow}");
            return null;
        }

        $startWordId = (int) $wordList[$startIdx]->id;
        $endWordId = (int) $wordList[$endIdx]->id;
        return [$startWordId, $endWordId];
    }

    private function findSpanInNormalizedWords(array $normalizedWords, string $needle, bool $withSpaces): ?array
    {
        $n = count($normalizedWords);
        if ($n === 0) return null;
        $needleLen = mb_strlen($needle, 'UTF-8');

        if ($withSpaces) {
            $full = implode(' ', $normalizedWords);
            $pos = mb_strpos($full, $needle, 0, 'UTF-8');
            if ($pos === false) return null;
            $endPos = $pos + $needleLen - 1;
            $startIdx = null;
            $endIdx = null;
            $cur = 0;
            for ($i = 0; $i < $n; $i++) {
                $len = mb_strlen($normalizedWords[$i], 'UTF-8');
                $wordEnd = $cur + $len;
                if ($startIdx === null && $wordEnd > $pos) $startIdx = $i;
                if ($endIdx === null && $wordEnd >= $endPos) {
                    $endIdx = $i;
                    break;
                }
                $cur = $wordEnd + 1;
            }
            if ($startIdx !== null && $endIdx !== null) {
                return [$startIdx, $endIdx];
            }
        } else {
            $full = implode('', $normalizedWords);
            $pos = mb_strpos($full, $needle, 0, 'UTF-8');
            if ($pos === false) return null;
            $endPos = $pos + $needleLen - 1;
            $cur = 0;
            $startIdx = null;
            $endIdx = null;
            for ($i = 0; $i < $n; $i++) {
                $len = mb_strlen($normalizedWords[$i], 'UTF-8');
                $wordEnd = $cur + $len - 1;
                if ($startIdx === null && $wordEnd >= $pos) $startIdx = $i;
                if ($endIdx === null && $wordEnd >= $endPos) {
                    $endIdx = $i;
                    break;
                }
                $cur += $len;
            }
            if ($startIdx !== null && $endIdx !== null) {
                return [$startIdx, $endIdx];
            }
        }
        return null;
    }

    private function normalizeForMatch(string $s): string
    {
        $s = $this->removeArabicDiacritics($s);
        return trim($s, " \t\n\r\0\x0B،,.;:!؟");
    }

    private function normalizeForMatchNoSpaces(string $s): string
    {
        return str_replace(' ', '', $this->normalizeForMatch($s));
    }

    private function removeArabicDiacritics(string $text): string
    {
        $text = str_replace(['ٱ'], ['ا'], $text);
        $text = str_replace(['آ', 'أ', 'إ'], ['ا', 'ا', 'ا'], $text);
        $text = str_replace(['ى'], ['ي'], $text);
        $pattern = '/[' .
            '\x{0640}' .
            '\x{064B}-\x{0652}' .
            '\x{0653}-\x{065F}' .
            '\x{0670}' .
            '\x{06D6}-\x{06DC}' .
            '\x{06DD}' .
            '\x{06DE}' .
            '\x{06DF}-\x{06E4}' .
            '\x{06E7}' .
            '\x{06E8}' .
            '\x{06EB}-\x{06EC}' .
            '\x{08F0}-\x{08F3}' .
            '\x{08F4}-\x{08FF}' .
            '\x{200C}' .
            '\x{200D}' .
            '\x{FEFF}' .
            ']/u';
        $pureText = preg_replace($pattern, '', $text);
        $additionalSymbols = [
            'ۖ',
            'ۗ',
            'ۘ',
            'ۙ',
            'ۚ',
            'ۛ',
            'ۜ',
            '۩',
            'ٕ',
            'ٰ',
            'ٰٰ',
            'ً',
            'ٌ',
            'ٍ',
            'َ',
            'ُ',
            'ِ',
            'ّ',
            'ْ',
            'ٓ',
            'ٔ',
            '۝',
            '۞',
            'ۡ',
            'ۢ',
            'ۤ',
            'ٓ',
            'ۥ',
            'ۦ',
            'ۧ',
            'ۨ',
            'ۭ',
            '◌',
            '◌ٰ',
            '◌ّ',
        ];
        $pureText = str_replace($additionalSymbols, '', $pureText ?? '');
        return trim((string) $pureText);
    }

    private function upsertChunk(array $rows): int
    {
        if (empty($rows)) return 0;
        $uniqueKeys = ['qiraat_reading_id', 'surah', 'ayah', 'hafs_text'];
        $updateCols = ['qiraat_options', 'qiraat_text', 'explanation', 'updated_at'];
        foreach (array_chunk($rows, 100) as $chunk) {
            $deduped = $this->deduplicateByUniqueKey($chunk, $uniqueKeys);
            DB::table('qiraat_differences')->upsert($deduped, $uniqueKeys, $updateCols);
        }
        return count($rows);
    }

    /** Dedupe by unique key so PostgreSQL ON CONFLICT sees each key once per statement. */
    private function deduplicateByUniqueKey(array $rows, array $uniqueKeys): array
    {
        $byKey = [];
        foreach ($rows as $row) {
            $key = implode("\0", array_map(fn($k) => (string) ($row[$k] ?? ''), $uniqueKeys));
            $byKey[$key] = $row;
        }
        return array_values($byKey);
    }

    private function openReport(?string $path, int $qiraatId): void
    {
        $dir = storage_path('app/qiraat_import_logs');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $this->reportPath = $path ?: ($dir . '/qiraat_differences_import_' . $qiraatId . '_' . now()->format('Y-m-d_His') . '_report.csv');
        $fp = @fopen($this->reportPath, 'w');
        if (!$fp) {
            $this->warn("Could not open report: {$this->reportPath}");
            $this->reportFp = null;
            return;
        }
        $this->reportFp = $fp;
        $this->reportLines = 0;
        fputcsv($this->reportFp, ['surah', 'ayah', 'reason', 'context', 'extra']);
    }

    private function closeReport(): void
    {
        if ($this->reportFp) {
            fclose($this->reportFp);
            $this->reportFp = null;
        }
    }

    private function reportRow(int $surah, int $ayah, string $reason, string $context, string $extra = ''): void
    {
        if (!$this->reportFp) return;
        if ($this->reportLines >= $this->reportLimit) return;
        fputcsv($this->reportFp, [
            $surah,
            $ayah,
            $reason,
            mb_substr($context, 0, 200),
            mb_substr($extra, 0, 200),
        ]);
        $this->reportLines++;
    }
}
