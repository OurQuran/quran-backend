<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportQiraatMushafAyahsFromExcel extends Command
{
    protected $signature = 'qiraat:import-mushaf-ayahs-excel
        {qiraat_reading_id : qiraat_readings.id OR "auto" to import all mappings}
        {path? : Excel file (.xlsx), multiple files separated by comma, or a directory. If omitted or "auto", uses mapping for that qiraat_reading_id}
        {--sheet=0 : Sheet index (default 0)}
        {--chunk=500 : Upsert chunk size (min 200)}
        {--dry-run : Do not insert, only simulate}
        {--report= : CSV report path. If omitted, auto in storage/app/qiraat_import_logs}
        {--report-limit=50000 : Max report lines}
    ';

    protected $description = 'Build mushaf_ayahs by applying (Hafs kalima -> Qiraat kalima) diffs from Excel onto ayahs table (harakat-insensitive). Supports batch "auto".';

    private $reportFp = null;
    private int $reportLines = 0;
    private int $reportLimit = 50000;
    private string $reportPath = '';

    /**
     * qiraat_readings.id => relative Excel file path (relative to base storage/app)
     */
    private array $excelByQiraatReadingId = [
        9  => 'qiraat_excels/1_hisham.xlsx',
        10 => 'qiraat_excels/1_thakwan.xlsx',
        11 => 'qiraat_excels/8_xalaf.xlsx',
        12 => 'qiraat_excels/8_xallad.xlsx',
        13 => 'qiraat_excels/7_haris.xlsx',
        14 => 'qiraat_excels/7_doori.xlsx',
        15 => 'qiraat_excels/6_wardan.xlsx',
        16 => 'qiraat_excels/6_jamar.xlsx',
        17 => 'qiraat_excels/11_rwais.xlsx',
        18 => 'qiraat_excels/11_rawh.xlsx',
        19 => 'qiraat_excels/9_ishaq.xlsx',
        20 => 'qiraat_excels/9_idris.xlsx',
    ];

    /**
     * diffs["surah:ayah"] = [
     *   ['hafs' => '...', 'qiraa' => '...', 'file' => '...', 'row' => 12],
     *   ...
     * ]
     */
    private array $diffs = [];

    public function handle(): int
    {
        $qiraatArg = $this->argument('qiraat_reading_id');
        $qiraatArg = is_string($qiraatArg) ? trim($qiraatArg) : (string) $qiraatArg;

        $sheetIndex = (int) ($this->option('sheet') ?? 0);
        $chunk      = max(200, (int) ($this->option('chunk') ?? 500));
        $dryRun     = (bool) $this->option('dry-run');

        $this->reportLimit = (int) ($this->option('report-limit') ?? 50000);
        if ($this->reportLimit <= 0) $this->reportLimit = 50000;

        // Batch mode: qiraat_reading_id = "auto"
        if (strtolower($qiraatArg) === 'auto') {
            $this->info("Batch mode: importing ALL mapped qiraat excel files...");
            $this->line("Mapped qiraat ids: " . implode(', ', array_keys($this->excelByQiraatReadingId)));
            $this->line("Sheet: {$sheetIndex} | Chunk: {$chunk} | Mode: " . ($dryRun ? 'DRY-RUN' : 'INSERT'));

            $ok = 0;
            $fail = 0;

            foreach ($this->excelByQiraatReadingId as $qiraatId => $_relPath) {
                $this->newLine();
                $this->info("=== Import qiraat_reading_id={$qiraatId} ===");

                try {
                    $result = $this->runSingle((int) $qiraatId, $sheetIndex, $chunk, $dryRun, true);
                    if ($result === self::SUCCESS) $ok++;
                    else $fail++;
                } catch (\Throwable $e) {
                    $fail++;
                    $this->error("Failed qiraat_reading_id={$qiraatId}: " . $e->getMessage());
                }
            }

            $this->newLine();
            $this->info("Batch finished. OK={$ok} | FAIL={$fail}");
            return $fail > 0 ? self::FAILURE : self::SUCCESS;
        }

        // Single mode
        $qiraatId = (int) $qiraatArg;
        return $this->runSingle($qiraatId, $sheetIndex, $chunk, $dryRun, false);
    }

    private function runSingle(int $qiraatId, int $sheetIndex, int $chunk, bool $dryRun, bool $batchMode): int
    {
        if (!DB::table('qiraat_readings')->where('id', $qiraatId)->exists()) {
            $this->error("qiraat_readings not found: id={$qiraatId}");
            return self::FAILURE;
        }

        // Resolve path:
        // - If user provides {path} and it's not "auto", use it as-is
        // - Else use mapping for this qiraatId (relative to storage/app)
        $pathArg = $this->resolveExcelPathFromMap($qiraatId);

        if ($pathArg === '') {
            $this->error("No excel path resolved for qiraat_reading_id={$qiraatId}. Provide {path} or add it to mapping.");
            return self::FAILURE;
        }

        $files = $this->resolveExcelFiles($pathArg);
        if (empty($files)) {
            $this->error("No .xlsx files found from path: {$pathArg}");
            return self::FAILURE;
        }

        // Reset diffs per qiraat
        $this->diffs = [];

        // Report: if batch mode, default to per-qiraat report (unless user explicitly passed --report)
        $reportOpt = $this->option('report');
        $reportOpt = is_string($reportOpt) ? trim($reportOpt) : null;

        $reportPath = $reportOpt;
        if ($batchMode && ($reportOpt === null || $reportOpt === '')) {
            // auto per qiraat
            $reportPath = null;
        }

        $this->openReport($reportPath, $qiraatId);

        $this->info("Loading Excel diffs...");
        $this->line("Qiraat: {$qiraatId}");
        $this->line("Files: " . count($files) . " | Sheet: {$sheetIndex}");
        $this->line("Mode: " . ($dryRun ? "DRY-RUN" : "INSERT") . " | Chunk: {$chunk}");
        $this->line("Report: {$this->reportPath}");

        foreach ($files as $file) {
            try {
                $this->loadDiffsFromExcel($file, $sheetIndex);
            } catch (\Throwable $e) {
                $this->reportRow(0, 0, 0, "excel_read_error: {$e->getMessage()}", $file, '');
                $this->warn("Failed reading: {$file} | {$e->getMessage()}");
            }
        }

        $this->info("Diff keys loaded: " . count($this->diffs));

        // Process ALL ayahs and apply diffs
        $buffer = [];
        $processedAyahs = 0;
        $changedAyahs = 0;

        DB::table('ayahs')
            ->select(['surah_id', 'number_in_surah', 'text', 'page', 'juz_id', 'hizb_id', 'sajda'])
            ->orderBy('surah_id')
            ->orderBy('number_in_surah')
            ->chunk(2000, function ($ayahs) use (
                $qiraatId,
                $dryRun,
                $chunk,
                &$buffer,
                &$processedAyahs,
                &$changedAyahs
            ) {
                foreach ($ayahs as $ayah) {
                    $processedAyahs++;

                    $surah = (int) $ayah->surah_id;
                    $aya   = (int) $ayah->number_in_surah;
                    $key   = "{$surah}:{$aya}";

                    $baseText = (string) ($ayah->text ?? '');
                    if ($baseText === '') {
                        $this->reportRow(0, $surah, $aya, 'empty_base_ayah_text', '', '');
                        continue;
                    }

                    $finalText = $baseText;

                    // pull meta from ayahs row
                    $page   = isset($ayah->page) ? (int) $ayah->page : null;
                    $juzId  = isset($ayah->juz_id) ? (int) $ayah->juz_id : null;
                    $hizbId = isset($ayah->hizb_id) ? (int) $ayah->hizb_id : null;
                    $sajda  = $ayah->sajda ?? null;

                    if (isset($this->diffs[$key])) {
                        $before = $finalText;

                        foreach ($this->diffs[$key] as $chg) {
                            $finalText = $this->replaceHafsWithQiraaHarakatInsensitive(
                                $finalText,
                                (string) $chg['hafs'],
                                (string) $chg['qiraa'],
                                function (string $reason, string $needle, string $context) use ($surah, $aya, $chg) {
                                    $extra = "file={$chg['file']} row={$chg['row']} needle={$needle}";
                                    $this->reportRow(0, $surah, $aya, $reason . ' | ' . $extra, $context, '');
                                }
                            );
                        }

                        if ($finalText !== $before) {
                            $changedAyahs++;
                        }
                    }

                    $buffer[] = [
                        'qiraat_reading_id' => $qiraatId,
                        'surah_id'          => $surah,
                        'number_in_surah'   => $aya,
                        'text'              => $finalText,

                        // ✅ passthrough
                        'page'              => $page,
                        'juz_id'            => $juzId,
                        'hizb_id'           => $hizbId,
                        'sajda'             => $sajda,

                        'ayah_template'     => null,
                        'pure_text'         => null,
                    ];

                    if (count($buffer) >= $chunk) {
                        $this->flush($buffer, $dryRun);
                        $buffer = [];
                        $this->line("Processed: {$processedAyahs} | Changed: {$changedAyahs} | ReportLines: {$this->reportLines}");
                    }
                }
            });

        if (!empty($buffer)) {
            $this->flush($buffer, $dryRun);
        }

        $this->newLine();
        $this->info("Done for qiraat_reading_id={$qiraatId}.");
        $this->line("Ayahs processed: {$processedAyahs}");
        $this->line("Ayahs changed: {$changedAyahs}");
        $this->line("Report lines: {$this->reportLines}");
        $this->line("Report file: {$this->reportPath}");

        $this->closeReport();

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------------
    // Path resolution (relative mapping + user path)
    // ---------------------------------------------------------------------

    private function resolveExcelPathFromMap(int $qiraatId): string
    {
        $arg = $this->argument('path');
        $arg = is_string($arg) ? trim($arg) : '';

        // User provided an explicit path/directory/list
        if ($arg !== '' && strtolower($arg) !== 'auto') {
            return $this->expandPath($arg);
        }

        // Mapping: relative to storage/app
        $rel = $this->excelByQiraatReadingId[$qiraatId] ?? '';
        if ($rel === '') return '';

        // If someone accidentally put an absolute path in mapping, keep it
        if ($this->isAbsolutePath($rel)) {
            return $this->expandPath($rel);
        }

        return storage_path('app/' . ltrim($rel, '/'));
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') return false;
        if (str_starts_with($path, '/')) return true; // linux
        return (bool) preg_match('/^[A-Za-z]:\\\\/', $path); // windows
    }

    private function expandPath(string $path): string
    {
        $path = trim($path);

        // ~ expansion
        if ($path !== '' && $path[0] === '~') {
            $home = getenv('HOME') ?: '';
            if ($home !== '') {
                $path = $home . substr($path, 1);
            }
        }

        return $path;
    }

    // ---------------------------------------------------------------------
    // Excel reading
    // ---------------------------------------------------------------------

    private function resolveExcelFiles(string $pathArg): array
    {
        $pathArg = trim($pathArg);

        // Allow comma-separated list
        $parts = array_filter(array_map('trim', explode(',', $pathArg)));

        $files = [];
        foreach ($parts as $p) {
            $p = $this->expandPath($p);

            if (is_dir($p)) {
                $found = glob(rtrim($p, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.xlsx') ?: [];
                foreach ($found as $f) $files[] = $f;
                continue;
            }

            if (is_file($p) && str_ends_with(strtolower($p), '.xlsx')) {
                $files[] = $p;
                continue;
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    private function loadDiffsFromExcel(string $file, int $sheetIndex): void
    {
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getSheet($sheetIndex);

        $rows = $sheet->toArray(null, true, true, true);

        if (count($rows) < 2) {
            $this->reportRow(0, 0, 0, 'excel_empty_or_no_data_rows', $file, '');
            return;
        }

        $header = $rows[1] ?? [];
        $colMap = $this->detectColumnsFromHeader($header);

        for ($i = 2; $i <= count($rows); $i++) {
            $r = $rows[$i] ?? [];

            $surah = (int) ($r[$colMap['surah']] ?? 0);
            $ayah  = (int) ($r[$colMap['ayah']] ?? 0);

            $hafs  = trim((string) ($r[$colMap['hafs']] ?? ''));
            $qiraa = trim((string) ($r[$colMap['qiraa']] ?? ''));

            if ($surah <= 0 || $ayah <= 0 || $hafs === '' || $qiraa === '') {
                continue;
            }

            $key = "{$surah}:{$ayah}";
            $this->diffs[$key][] = [
                'hafs'  => $hafs,
                'qiraa' => $qiraa,
                'file'  => basename($file),
                'row'   => $i,
            ];
        }
    }

    private function detectColumnsFromHeader(array $headerRow): array
    {
        $norm = [];
        foreach ($headerRow as $col => $val) {
            $v = strtolower(trim((string) $val));
            $v = preg_replace('/\s+/', ' ', $v);
            $norm[$col] = $v;
        }

        $find = function (array $candidates) use ($norm): ?string {
            foreach ($norm as $col => $name) {
                foreach ($candidates as $cand) {
                    if ($name === $cand) return $col;
                }
            }
            return null;
        };

        $surahCol = $find(['surah', 'sura', 'sura_no', 'surah_no', 'surah_id']);
        $ayahCol  = $find(['ayah', 'aya', 'aya_no', 'ayah_no', 'number_in_surah', 'ayah_number']);
        $hafsCol  = $find(['hafs', 'hafs_word', 'hafs_kalma', 'hafs_kalima', 'word_hafs', 'h']);
        $qiraaCol = $find(['qiraa', 'qiraat', 'qiraat_word', 'qiraa_word', 'qiraa_kalma', 'qiraat_kalma', 'q']);

        return [
            'surah' => $surahCol ?: 'A',
            'ayah'  => $ayahCol  ?: 'B',
            'hafs'  => $hafsCol  ?: 'C',
            'qiraa' => $qiraaCol ?: 'D',
        ];
    }

    // ---------------------------------------------------------------------
    // Harakat-insensitive replacement (using your stripping behavior)
    // ---------------------------------------------------------------------

    private function replaceHafsWithQiraaHarakatInsensitive(
        string $ayahOriginal,
        string $hafsWord,
        string $qiraaWord,
        callable $report
    ): string {
        $hafsPure = $this->normalizeNeedle($hafsWord);
        if ($hafsPure === '') {
            $report('empty_hafs_after_strip', $hafsWord, $ayahOriginal);
            return $ayahOriginal;
        }

        $map = $this->buildPureIndexMap($ayahOriginal);
        $ayahPure = $map['pure'];

        $pos = mb_strpos($ayahPure, $hafsPure, 0, 'UTF-8');
        if ($pos === false) {
            $report('hafs_not_found_pure', $hafsWord, $ayahOriginal);
            return $ayahOriginal;
        }

        // Count matches (optional) — for reporting only
        $matchCount = $this->mb_substr_count_all($ayahPure, $hafsPure);
        if ($matchCount > 1) {
            $report('hafs_multiple_matches_replace_first (count=' . $matchCount . ')', $hafsWord, $ayahOriginal);
        }

        $matchLen = mb_strlen($hafsPure, 'UTF-8');
        $startIndex = $pos;
        $endIndex   = $pos + $matchLen - 1;

        $startByte = $map['mapStart'][$startIndex] ?? null;
        $endByteExcl = $map['mapEnd'][$endIndex] ?? null;

        if ($startByte === null || $endByteExcl === null || $endByteExcl < $startByte) {
            $report('index_map_failed', $hafsWord, $ayahOriginal);
            return $ayahOriginal;
        }

        // Extend end to consume any following removable marks (harakat etc.)
        $endByteExcl = $this->extendEndOverRemovables($ayahOriginal, $endByteExcl);

        $before = substr($ayahOriginal, 0, $startByte);
        $after  = substr($ayahOriginal, $endByteExcl);

        $new = $before . $qiraaWord . $after;

        if (trim($new) === '') {
            $report('empty_after_replace', $hafsWord, $ayahOriginal);
            return $ayahOriginal;
        }

        return $new;
    }

    private function mb_substr_count_all(string $haystack, string $needle): int
    {
        if ($needle === '') return 0;

        $count = 0;
        $offset = 0;

        while (true) {
            $pos = mb_strpos($haystack, $needle, $offset, 'UTF-8');
            if ($pos === false) break;

            $count++;
            $offset = $pos + 1; // allow overlaps; change to +mb_strlen($needle) if you want non-overlap
        }

        return $count;
    }

    private function buildPureIndexMap(string $original): array
    {
        $len = mb_strlen($original, 'UTF-8');

        $pure = '';
        $mapStart = [];
        $mapEnd = [];

        $bytePos = 0;
        $pureIndex = 0;

        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($original, $i, 1, 'UTF-8');
            $chBytes = strlen($ch);

            $normalized = $this->normalizeOrDropCharForPure($ch);
            if ($normalized === '') {
                $bytePos += $chBytes;
                continue;
            }

            $mapStart[$pureIndex] = $bytePos;
            $mapEnd[$pureIndex]   = $bytePos + $chBytes;

            $pure .= $normalized;
            $pureIndex++;

            $bytePos += $chBytes;
        }

        return [
            'pure' => $pure,
            'mapStart' => $mapStart,
            'mapEnd' => $mapEnd,
        ];
    }

    private function extendEndOverRemovables(string $original, int $endByteExcl): int
    {
        while (true) {
            $tail = substr($original, $endByteExcl);
            if ($tail === '') break;

            $ch = mb_substr($tail, 0, 1, 'UTF-8');
            if ($ch === '') break;

            $normalized = $this->normalizeOrDropCharForPure($ch);
            if ($normalized !== '') break;

            $endByteExcl += strlen($ch);
        }

        return $endByteExcl;
    }

    private function normalizeOrDropCharForPure(string $ch): string
    {
        if ($ch === 'ٱ' || $ch === 'آ' || $ch === 'أ' || $ch === 'إ') return 'ا';

        if ($ch === "\u{200C}" || $ch === "\u{200D}" || $ch === "\u{FEFF}") return '';

        if ($ch === 'ـ') return '';

        if ($ch === 'ى') return 'ي';

        if (preg_match('/^[\x{064B}-\x{0652}\x{0653}-\x{065F}\x{0670}\x{06D6}-\x{06E4}\x{06E7}\x{06E8}\x{06EB}-\x{06EC}\x{06DD}\x{06DE}\x{08F0}-\x{08FF}]$/u', $ch)) {
            return '';
        }

        static $drop = null;
        if ($drop === null) {
            $drop = array_flip([
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
                'ۥ',
                'ۦ',
                'ۧ',
                'ۨ',
                'ۭ',
                '◌',
                '◌ٰ',
                '◌ّ',
            ]);
        }
        if (isset($drop[$ch])) return '';

        return $ch;
    }

    private function removeArabicDiacritics(string $text): string
    {
        $text = str_replace(['ٱ'], ['ا'], $text);
        $text = str_replace(['آ', 'أ', 'إ'], ['ا', 'ا', 'ا'], $text);
        $text = str_replace(['ى'], ['ي'], $text); // in removeArabicDiacritics

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

        $pureText = str_replace($additionalSymbols, '', $pureText);

        return trim((string) $pureText);
    }

    // ---------------------------------------------------------------------
    // DB flush + reporting
    // ---------------------------------------------------------------------

    private function flush(array &$rows, bool $dryRun): int
    {
        if (empty($rows)) return 0;

        if ($dryRun) {
            return count($rows);
        }

        DB::table('mushaf_ayahs')->upsert(
            $rows,
            ['qiraat_reading_id', 'surah_id', 'number_in_surah'],
            ['text', 'page', 'juz_id', 'hizb_id', 'sajda', 'ayah_template', 'pure_text']
        );

        return count($rows);
    }

    private function openReport(?string $path, int $qiraatId): void
    {
        $dir = storage_path('app/qiraat_import_logs');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $this->reportPath = $path ?: ($dir . '/qiraat_excel_' . $qiraatId . '_' . now()->format('Y-m-d_His') . '_report.csv');

        $fp = @fopen($this->reportPath, 'w');
        if (!$fp) {
            $this->warn("Could not open report file: {$this->reportPath}");
            $this->reportFp = null;
            return;
        }

        $this->reportFp = $fp;
        $this->reportLines = 0;

        fputcsv($this->reportFp, [
            'row_index',
            'surah_no',
            'aya_no',
            'reason',
            'context_preview',
            'reference_preview',
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
        string $reason,
        string $context,
        string $reference = ''
    ): void {
        if (!$this->reportFp) return;
        if ($this->reportLines >= $this->reportLimit) return;

        $contextPreview = mb_substr(trim($context), 0, 160);
        $refPreview = mb_substr(trim($reference), 0, 160);

        fputcsv($this->reportFp, [
            $rowIndex,
            $surahNo,
            $ayaNo,
            $reason,
            $contextPreview,
            $refPreview,
        ]);

        $this->reportLines++;
    }

    private function normalizeNeedle(string $s): string
    {
        $s = $this->removeArabicDiacritics($s);
        // trim common punctuation that appears in Excel cells
        return trim($s, " \t\n\r\0\x0B،,.;:!؟");
    }
}
