<?php

namespace App\Console\Commands\Qiraat;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

class AuditQiraatText extends Command
{
    protected $signature = 'qiraat:audit-text
        {--qiraat=auto : qiraat_readings.id, stable code, or auto}
        {--skip-db : Do not scan database rows}
        {--scan-sources : Also scan source files under storage/app}
        {--source-path=storage/app : Source file or directory to scan when --scan-sources is used}
        {--source-limit=0 : Stop source scan after N files (0 = unlimited)}
        {--main-font= : Main Quran font path. Defaults to sibling frontend quran_font_4.ttf when present}
        {--fallback-font= : Fallback font path. Defaults to sibling frontend NotoNaskhArabic-Regular.ttf when present}
        {--min-severity=info : Minimum severity to write: info, medium, high, error}
        {--report= : CSV path. Defaults to storage/app/qiraat_import_logs/qiraat_text_audit_*.csv}
        {--limit=0 : Stop after N reported issues (0 = unlimited)}
    ';

    protected $description = 'Audit mushaf/qiraat text and source files for Unicode, glyph coverage, and suspicious import patterns.';

    private const ARABIC_RE = '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u';

    private $reportFp = null;
    private string $reportPath = '';
    private int $reported = 0;
    private int $limit = 0;
    private array $mainFontCmap = [];
    private array $fallbackFontCmap = [];
    private ?string $mainFontPath = null;
    private ?string $fallbackFontPath = null;
    private array $issueCounts = [];
    private array $detectedIssueCounts = [];
    private array $tableCounts = [];
    private array $sourceFileCounts = [];
    private string $minSeverity = 'info';

    public function handle(): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(0);

        $this->limit = max(0, (int) $this->option('limit'));
        $this->minSeverity = $this->normalizeSeverity((string) $this->option('min-severity'));
        $this->openReport((string) ($this->option('report') ?? ''));
        $this->loadFontCoverage();

        if (!(bool) $this->option('skip-db')) {
            $this->info('Auditing database text...');
            $qiraatIds = $this->resolveQiraatIds((string) $this->option('qiraat'));
            if (empty($qiraatIds)) {
                $this->warn('No qiraat ids resolved.');
            } else {
                $this->scanMushafAyahs($qiraatIds);
                $this->scanQiraatDifferences($qiraatIds);
            }
        }

        if ((bool) $this->option('scan-sources')) {
            $this->newLine();
            $this->info('Auditing source files...');
            $this->scanSourceFiles((string) $this->option('source-path'), max(0, (int) $this->option('source-limit')));
        }

        $this->closeReport();
        $this->newLine();
        $this->info('Audit complete.');
        $this->line('Report: ' . $this->reportPath);
        $this->line('Issues reported: ' . $this->reported . ($this->limit > 0 ? " / limit {$this->limit}" : ''));
        $this->line('Main font: ' . ($this->mainFontPath ?: 'not loaded'));
        $this->line('Fallback font: ' . ($this->fallbackFontPath ?: 'not loaded'));

        if (!empty($this->tableCounts)) {
            $this->line('Rows scanned: ' . $this->formatCounts($this->tableCounts));
        }
        if (!empty($this->sourceFileCounts)) {
            $this->line('Source files scanned: ' . $this->formatCounts($this->sourceFileCounts));
        }
        if (!empty($this->issueCounts)) {
            $this->line('Reported issue counts: ' . $this->formatCounts($this->issueCounts));
        }
        if (!empty($this->detectedIssueCounts)) {
            $this->line('Detected issue counts: ' . $this->formatCounts($this->detectedIssueCounts));
        }

        return self::SUCCESS;
    }

    private function resolveQiraatIds(string $value): array
    {
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'auto') {
            return DB::table('mushaf_ayahs')
                ->distinct()
                ->orderBy('qiraat_reading_id')
                ->pluck('qiraat_reading_id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->values()
                ->all();
        }

        if (ctype_digit($value)) {
            return [(int) $value];
        }

        $id = DB::table('qiraat_readings')->where('code', $value)->value('id');
        return $id ? [(int) $id] : [];
    }

    private function scanMushafAyahs(array $qiraatIds): void
    {
        DB::table('mushaf_ayahs')
            ->whereIn('qiraat_reading_id', $qiraatIds)
            ->select(['id', 'qiraat_reading_id', 'surah_id', 'number_in_surah', 'text', 'pure_text', 'ayah_template'])
            ->orderBy('qiraat_reading_id')
            ->orderBy('surah_id')
            ->orderBy('number_in_surah')
            ->chunkById(1000, function ($rows) {
                foreach ($rows as $row) {
                    $this->tableCounts['mushaf_ayahs'] = ($this->tableCounts['mushaf_ayahs'] ?? 0) + 1;
                    $meta = [
                        'source' => 'db',
                        'location' => 'mushaf_ayahs.id=' . $row->id,
                        'qiraat_id' => (string) $row->qiraat_reading_id,
                        'surah' => (string) $row->surah_id,
                        'ayah' => (string) $row->number_in_surah,
                    ];
                    $this->auditText((string) $row->text, $meta + ['field' => 'text']);
                    $this->auditText((string) ($row->pure_text ?? ''), $meta + ['field' => 'pure_text']);
                    $templateText = trim(strip_tags((string) ($row->ayah_template ?? '')));
                    if ($templateText !== '') {
                        $this->auditText($templateText, $meta + ['field' => 'ayah_template_text']);
                    }
                    if ($this->shouldStop()) {
                        return false;
                    }
                }
            });
    }

    private function scanQiraatDifferences(array $qiraatIds): void
    {
        DB::table('qiraat_differences')
            ->whereIn('qiraat_reading_id', $qiraatIds)
            ->select(['id', 'qiraat_reading_id', 'surah', 'ayah', 'hafs_text', 'qiraat_text', 'qiraat_options'])
            ->orderBy('qiraat_reading_id')
            ->orderBy('surah')
            ->orderBy('ayah')
            ->chunkById(1000, function ($rows) {
                foreach ($rows as $row) {
                    $this->tableCounts['qiraat_differences'] = ($this->tableCounts['qiraat_differences'] ?? 0) + 1;
                    $meta = [
                        'source' => 'db',
                        'location' => 'qiraat_differences.id=' . $row->id,
                        'qiraat_id' => (string) $row->qiraat_reading_id,
                        'surah' => (string) $row->surah,
                        'ayah' => (string) $row->ayah,
                    ];
                    $this->auditText((string) ($row->hafs_text ?? ''), $meta + ['field' => 'hafs_text']);
                    $this->auditText((string) ($row->qiraat_text ?? ''), $meta + ['field' => 'qiraat_text']);
                    $this->auditText((string) ($row->qiraat_options ?? ''), $meta + ['field' => 'qiraat_options']);
                    if ($this->shouldStop()) {
                        return false;
                    }
                }
            });
    }

    private function scanSourceFiles(string $path, int $sourceLimit): void
    {
        $path = $this->resolvePath($path);
        $files = $this->collectSourceFiles($path);
        if ($sourceLimit > 0) {
            $files = array_slice($files, 0, $sourceLimit);
        }

        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $this->sourceFileCounts[$ext ?: 'unknown'] = ($this->sourceFileCounts[$ext ?: 'unknown'] ?? 0) + 1;
            try {
                if ($ext === 'xlsx') {
                    $this->scanExcelFile($file);
                } elseif (in_array($ext, ['html', 'htm', 'xml', 'csv', 'txt', 'json'], true)) {
                    $this->scanTextFile($file);
                }
            } catch (\Throwable $e) {
                $this->reportIssue([
                    'source' => 'source_file',
                    'location' => $file,
                    'field' => '',
                    'qiraat_id' => '',
                    'surah' => '',
                    'ayah' => '',
                ], 'error', 'source_read_error', $e->getMessage(), '');
            }

            if ($this->shouldStop()) {
                break;
            }
        }
    }

    private function scanExcelFile(string $file): void
    {
        $reader = IOFactory::createReaderForFile($file);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file);

        foreach ($spreadsheet->getWorksheetIterator() as $sheetIndex => $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rowIndex = $row->getRowIndex();
                foreach ($row->getCellIterator() as $cell) {
                    $value = trim((string) $cell->getFormattedValue());
                    if ($value === '' || !$this->looksRelevant($value)) {
                        continue;
                    }
                    $this->auditText($value, [
                        'source' => 'source_file',
                        'location' => $file . '#sheet=' . $sheetIndex . '!' . $cell->getCoordinate() . ':row=' . $rowIndex,
                        'field' => 'cell',
                        'qiraat_id' => '',
                        'surah' => '',
                        'ayah' => '',
                    ]);
                    if ($this->shouldStop()) {
                        return;
                    }
                }
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    private function scanTextFile(string $file): void
    {
        $handle = fopen($file, 'rb');
        if (!$handle) {
            throw new \RuntimeException('Unable to open file.');
        }
        $lineNo = 0;
        while (($line = fgets($handle)) !== false) {
            $lineNo++;
            $line = trim($line);
            if ($line === '' || !$this->looksRelevant($line)) {
                continue;
            }
            $text = strip_tags($line);
            $this->auditText($text, [
                'source' => 'source_file',
                'location' => $file . ':line=' . $lineNo,
                'field' => 'line',
                'qiraat_id' => '',
                'surah' => '',
                'ayah' => '',
            ]);
            if ($this->shouldStop()) {
                break;
            }
        }
        fclose($handle);
    }

    private function auditText(string $text, array $meta): void
    {
        if ($text === '') {
            return;
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            $this->reportIssue($meta, 'high', 'invalid_utf8', '', $this->preview($text));
            return;
        }

        if (str_contains($text, "\u{FFFD}")) {
            $this->reportIssue($meta, 'high', 'replacement_character', 'U+FFFD present', $this->preview($text));
        }
        if (preg_match('/[\x{25A1}\x{25A0}\x{25CC}]/u', $text, $m)) {
            $this->reportIssue($meta, 'high', 'literal_square_or_dotted_circle', $this->describeChars($m[0]), $this->preview($text));
        }
        if (preg_match('/\x{0640}{2,}/u', $text, $m)) {
            $this->reportIssue($meta, 'medium', 'repeated_tatweel', $this->describeChars($m[0]), $this->preview($text));
        }
        if (preg_match('/\x{0640}{2,}[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]+\x{0640}{2,}/u', $text, $m)) {
            $this->reportIssue($meta, 'high', 'tatweel_mark_tatweel_pattern', $this->describeChars($m[0]), $this->preview($text));
        }
        if (preg_match('/[\x{E000}-\x{F8FF}]/u', $text, $m)) {
            $this->reportIssue($meta, 'high', 'private_use_character', $this->describeChars($m[0]), $this->preview($text));
        }
        if (preg_match('/[^\P{C}\n\r\t]/u', $text, $m)) {
            $this->reportIssue($meta, 'medium', 'control_or_format_character', $this->describeChars($m[0]), $this->preview($text));
        }

        $this->auditFontCoverage($text, $meta);
    }

    private function auditFontCoverage(string $text, array $meta): void
    {
        if (empty($this->mainFontCmap) && empty($this->fallbackFontCmap)) {
            return;
        }

        $unsupportedMain = [];
        $unsupportedAll = [];

        foreach ($this->codepoints($text) as $cp) {
            if (!$this->isArabicRelevantCodepoint($cp)) {
                continue;
            }
            $inMain = isset($this->mainFontCmap[$cp]);
            $inFallback = isset($this->fallbackFontCmap[$cp]);
            if (!$inMain) {
                $unsupportedMain[$cp] = true;
            }
            if (!$inMain && !$inFallback) {
                $unsupportedAll[$cp] = true;
            }
        }

        if (!empty($unsupportedAll)) {
            $this->reportIssue($meta, 'high', 'unsupported_by_main_and_fallback_fonts', $this->formatCodepoints(array_keys($unsupportedAll)), $this->preview($text));
        } elseif (!empty($unsupportedMain)) {
            $this->reportIssue($meta, 'info', 'unsupported_by_main_font_only', $this->formatCodepoints(array_keys($unsupportedMain)), $this->preview($text));
        }
    }

    private function looksRelevant(string $text): bool
    {
        return (bool) preg_match(self::ARABIC_RE, $text)
            || preg_match('/[\x{0640}\x{25A1}\x{25A0}\x{25CC}\x{FFFD}]/u', $text);
    }

    private function isArabicRelevantCodepoint(int $cp): bool
    {
        return ($cp >= 0x0600 && $cp <= 0x06FF)
            || ($cp >= 0x0750 && $cp <= 0x077F)
            || ($cp >= 0x08A0 && $cp <= 0x08FF)
            || ($cp >= 0xFB50 && $cp <= 0xFDFF)
            || ($cp >= 0xFE70 && $cp <= 0xFEFF)
            || in_array($cp, [0x200C, 0x200D, 0xFD3E, 0xFD3F], true);
    }

    private function openReport(string $path): void
    {
        $path = trim($path);
        if ($path === '') {
            $dir = storage_path('app/qiraat_import_logs');
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $path = $dir . '/qiraat_text_audit_' . now()->format('Y-m-d_His') . '_' . substr(str_replace('.', '', uniqid('', true)), -8) . '.csv';
        } else {
            $path = $this->resolvePath($path);
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }

        $this->reportPath = $path;
        $this->reportFp = fopen($path, 'w');
        if (!$this->reportFp) {
            throw new \RuntimeException("Unable to open report: {$path}");
        }

        fputcsv($this->reportFp, [
            'source',
            'location',
            'field',
            'qiraat_id',
            'surah',
            'ayah',
            'severity',
            'issue',
            'details',
            'text_preview',
        ]);
    }

    private function closeReport(): void
    {
        if (is_resource($this->reportFp)) {
            fclose($this->reportFp);
        }
        $this->reportFp = null;
    }

    private function reportIssue(array $meta, string $severity, string $issue, string $details, string $preview): void
    {
        $this->detectedIssueCounts[$issue] = ($this->detectedIssueCounts[$issue] ?? 0) + 1;

        if (!$this->severityAllowed($severity)) {
            return;
        }

        if ($this->shouldStop()) {
            return;
        }

        $this->reported++;
        $this->issueCounts[$issue] = ($this->issueCounts[$issue] ?? 0) + 1;

        fputcsv($this->reportFp, [
            $meta['source'] ?? '',
            $meta['location'] ?? '',
            $meta['field'] ?? '',
            $meta['qiraat_id'] ?? '',
            $meta['surah'] ?? '',
            $meta['ayah'] ?? '',
            $severity,
            $issue,
            $details,
            $preview,
        ]);
    }

    private function shouldStop(): bool
    {
        return $this->limit > 0 && $this->reported >= $this->limit;
    }

    private function collectSourceFiles(string $path): array
    {
        if (is_file($path)) {
            return [$path];
        }
        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['xlsx', 'html', 'htm', 'xml', 'csv', 'txt', 'json'], true)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    private function loadFontCoverage(): void
    {
        $main = trim((string) ($this->option('main-font') ?? ''));
        $fallback = trim((string) ($this->option('fallback-font') ?? ''));

        if ($main === '') {
            $main = base_path('../quran-frontend/public/fonts/quran/quran_font_4.ttf');
        }
        if ($fallback === '') {
            $fallback = base_path('../quran-frontend/public/fonts/quran/fallback/NotoNaskhArabic-Regular.ttf');
        }

        $main = $this->resolvePath($main);
        $fallback = $this->resolvePath($fallback);

        if (is_file($main)) {
            $this->mainFontPath = $main;
            $this->mainFontCmap = $this->readFontCmap($main);
        } else {
            $this->warn("Main font not found: {$main}");
        }

        if (is_file($fallback)) {
            $this->fallbackFontPath = $fallback;
            $this->fallbackFontCmap = $this->readFontCmap($fallback);
        } else {
            $this->warn("Fallback font not found: {$fallback}");
        }
    }

    private function readFontCmap(string $path): array
    {
        $data = file_get_contents($path);
        if ($data === false || strlen($data) < 12) {
            return [];
        }

        $numTables = $this->u16($data, 4);
        $cmapOffset = null;
        $cmapLength = null;
        for ($i = 0; $i < $numTables; $i++) {
            $entry = 12 + ($i * 16);
            $tag = substr($data, $entry, 4);
            if ($tag === 'cmap') {
                $cmapOffset = $this->u32($data, $entry + 8);
                $cmapLength = $this->u32($data, $entry + 12);
                break;
            }
        }
        if ($cmapOffset === null || $cmapLength === null) {
            return [];
        }

        $numSubtables = $this->u16($data, $cmapOffset + 2);
        $coverage = [];
        for ($i = 0; $i < $numSubtables; $i++) {
            $record = $cmapOffset + 4 + ($i * 8);
            $subOffset = $cmapOffset + $this->u32($data, $record + 4);
            if ($subOffset <= 0 || $subOffset >= strlen($data)) {
                continue;
            }
            $format = $this->u16($data, $subOffset);
            if ($format === 4) {
                $coverage += $this->readCmapFormat4($data, $subOffset);
            } elseif ($format === 12) {
                $coverage += $this->readCmapFormat12($data, $subOffset);
            }
        }

        return $coverage;
    }

    private function readCmapFormat4(string $data, int $offset): array
    {
        $segCount = intdiv($this->u16($data, $offset + 6), 2);
        $endOffset = $offset + 14;
        $startOffset = $endOffset + ($segCount * 2) + 2;
        $coverage = [];

        for ($i = 0; $i < $segCount; $i++) {
            $end = $this->u16($data, $endOffset + ($i * 2));
            $start = $this->u16($data, $startOffset + ($i * 2));
            if ($start === 0xFFFF && $end === 0xFFFF) {
                continue;
            }
            for ($cp = $start; $cp <= $end && $cp <= 0xFFFF; $cp++) {
                $coverage[$cp] = true;
            }
        }

        return $coverage;
    }

    private function readCmapFormat12(string $data, int $offset): array
    {
        $groups = $this->u32($data, $offset + 12);
        $coverage = [];
        $groupOffset = $offset + 16;

        for ($i = 0; $i < $groups; $i++) {
            $entry = $groupOffset + ($i * 12);
            $start = $this->u32($data, $entry);
            $end = $this->u32($data, $entry + 4);
            if ($end - $start > 20000) {
                continue;
            }
            for ($cp = $start; $cp <= $end; $cp++) {
                $coverage[$cp] = true;
            }
        }

        return $coverage;
    }

    private function codepoints(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_map(fn (string $char) => mb_ord($char, 'UTF-8'), $chars);
    }

    private function describeChars(string $text): string
    {
        return $this->formatCodepoints($this->codepoints($text));
    }

    private function formatCodepoints(array $cps): string
    {
        $cps = array_values(array_unique(array_map('intval', $cps)));
        sort($cps);
        return implode(' ', array_map(fn (int $cp) => sprintf('U+%04X', $cp), $cps));
    }

    private function preview(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        return mb_substr($text, 0, 220, 'UTF-8');
    }

    private function formatCounts(array $counts): string
    {
        ksort($counts);
        return implode(', ', array_map(fn ($key, $value) => "{$key}={$value}", array_keys($counts), $counts));
    }

    private function normalizeSeverity(string $severity): string
    {
        $severity = strtolower(trim($severity));
        return in_array($severity, ['info', 'medium', 'high', 'error'], true) ? $severity : 'info';
    }

    private function severityAllowed(string $severity): bool
    {
        $rank = ['info' => 0, 'medium' => 1, 'high' => 2, 'error' => 3];
        return ($rank[$severity] ?? 0) >= ($rank[$this->minSeverity] ?? 0);
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return $path;
        }
        if (str_starts_with($path, '/')) {
            return $path;
        }
        return base_path($path);
    }

    private function u16(string $data, int $offset): int
    {
        $value = unpack('n', substr($data, $offset, 2));
        return (int) ($value[1] ?? 0);
    }

    private function u32(string $data, int $offset): int
    {
        $value = unpack('N', substr($data, $offset, 4));
        return (int) ($value[1] ?? 0);
    }
}
