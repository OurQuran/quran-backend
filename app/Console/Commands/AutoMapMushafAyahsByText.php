<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AutoMapMushafAyahsByText extends Command
{
    protected $signature = 'qiraat:auto-map-by-text
        {qiraat_reading_id : qiraat_readings.id OR "auto" to run for all qiraat present in mushaf_ayahs}
        {--only-unmapped : Only process mushaf_ayahs that have no mapping rows yet}
        {--max-combined=4 : Max base ayahs to try combining (2..N)}
        {--max-split=4 : Max mushaf parts to try splitting (2..N)}
        {--dry-run : Do not write anything}
        {--report= : Path to report file (CSV). If omitted, auto per-qiraat in storage/app/qiraat_import_logs}
        {--report-limit=20000 : Max report lines}
    ';

    protected $description = 'Auto-map mushaf_ayahs to base ayahs using Arabic text normalization: exact, combined, split. Supports "auto".';

    private $reportFp = null;
    private int $reportLines = 0;
    private int $reportLimit = 20000;
    private string $reportPath = '';

    private int $cntExact = 0;
    private int $cntCombined = 0;
    private int $cntSplit = 0;
    private int $cntUnresolved = 0;

    public function handle(): int
    {
        $arg = $this->argument('qiraat_reading_id');
        $arg = is_string($arg) ? trim($arg) : (string) $arg;

        $onlyUnmapped = (bool) $this->option('only-unmapped');
        $dryRun = (bool) $this->option('dry-run');

        $maxCombined = max(2, (int) ($this->option('max-combined') ?? 4));
        $maxSplit    = max(2, (int) ($this->option('max-split') ?? 4));

        $this->reportLimit = (int) ($this->option('report-limit') ?? 20000);
        if ($this->reportLimit <= 0) $this->reportLimit = 20000;

        // Batch mode
        if (strtolower($arg) === 'auto') {
            $qiraatIds = $this->resolveAutoQiraatIds();

            if (empty($qiraatIds)) {
                $this->error("Auto mode found no qiraat_reading_id values in mushaf_ayahs.");
                return self::FAILURE;
            }

            $this->info("Batch mode: auto-map by text for ALL qiraat IDs found in mushaf_ayahs");
            $this->line("Qiraat IDs: " . implode(', ', $qiraatIds));
            $this->line("Mode: " . ($dryRun ? "DRY-RUN" : "WRITE") . " | " . ($onlyUnmapped ? "ONLY-UNMAPPED" : "ALL"));
            $this->line("MaxCombined={$maxCombined} | MaxSplit={$maxSplit}");

            $ok = 0;
            $fail = 0;

            foreach ($qiraatIds as $qiraatId) {
                $this->newLine();
                $this->info("=== Auto map qiraat_reading_id={$qiraatId} ===");

                try {
                    $res = $this->runSingle(
                        (int) $qiraatId,
                        $onlyUnmapped,
                        $dryRun,
                        $maxCombined,
                        $maxSplit,
                        true // batchMode
                    );

                    if ($res === self::SUCCESS) $ok++;
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
        $qiraatId = (int) $arg;
        return $this->runSingle($qiraatId, $onlyUnmapped, $dryRun, $maxCombined, $maxSplit, false);
    }

    private function runSingle(
        int $qiraatId,
        bool $onlyUnmapped,
        bool $dryRun,
        int $maxCombined,
        int $maxSplit,
        bool $batchMode
    ): int {
        if (!DB::table('qiraat_readings')->where('id', $qiraatId)->exists()) {
            $this->error("qiraat_readings not found: id={$qiraatId}");
            return self::FAILURE;
        }

        // Reset counters per run
        $this->cntExact = 0;
        $this->cntCombined = 0;
        $this->cntSplit = 0;
        $this->cntUnresolved = 0;
        $this->reportLines = 0;

        // Report handling:
        // In batch mode: always per-qiraat unless user passed a directory-like path
        $reportOpt = $this->option('report');
        $reportOpt = is_string($reportOpt) ? trim($reportOpt) : null;

        $reportPath = $reportOpt;
        if ($batchMode) {
            // Avoid overwriting: if user gave a file path, ignore it and auto per-qiraat
            // If they gave a directory, we’ll still auto-name inside it.
            $reportPath = $reportOpt;
        }

        $this->openReport($reportPath, $qiraatId);

        try {
            $this->info("Auto mapping by text for qiraat_reading_id={$qiraatId}");
            $this->line("Mode: " . ($dryRun ? "DRY-RUN" : "WRITE") . " | " . ($onlyUnmapped ? "ONLY-UNMAPPED" : "ALL"));
            $this->line("MaxCombined={$maxCombined} | MaxSplit={$maxSplit}");
            $this->line("Report: {$this->reportPath}");

            // Build base ayahs per surah, ordered
            $baseBySurah = $this->loadBaseAyahsBySurah();

            // Process surah by surah for speed and better split logic
            for ($surahId = 1; $surahId <= 114; $surahId++) {
                if (empty($baseBySurah[$surahId])) continue;

                $mushafRows = $this->loadMushafRowsForSurah($qiraatId, $surahId, $onlyUnmapped);
                if ($mushafRows->isEmpty()) continue;

                // normalized base text => list of base ayah ids (handle duplicates)
                $baseNormMap = [];
                foreach ($baseBySurah[$surahId] as $b) {
                    $baseNormMap[$b['norm']][] = $b['id'];
                }

                $toUpsert = [];
                $now = now();

                $rowsArr = $mushafRows->values()->all();
                $i = 0;

                while ($i < count($rowsArr)) {
                    $m = $rowsArr[$i];
                    $mId = (int) $m->id;
                    $mNo = (int) $m->number_in_surah;
                    $mText = (string) $m->text;
                    $mNorm = $this->normalizeArabic($mText);

                    if ($mNorm === '') {
                        $this->cntUnresolved++;
                        $this->reportRow($mId, $surahId, $mNo, 'unresolved_empty_norm', $this->preview($mText));
                        $i++;
                        continue;
                    }

                    // 1) EXACT (only if unique base match)
                    if (isset($baseNormMap[$mNorm]) && count($baseNormMap[$mNorm]) === 1) {
                        $ayahId = (int) $baseNormMap[$mNorm][0];

                        $toUpsert[] = [
                            'mushaf_ayah_id' => $mId,
                            'ayah_id'        => $ayahId,
                            'map_type'       => 'exact',
                            'part_no'        => null,
                            'parts_total'    => null,
                            'ayah_order'     => null,
                            'created_at'     => $now,
                            'updated_at'     => $now,
                        ];
                        $this->cntExact++;
                        $i++;
                        continue;
                    }

                    // 2) COMBINED
                    $combined = $this->tryCombined($mNorm, $baseBySurah[$surahId], $maxCombined);
                    if ($combined !== null) {
                        $order = 1;
                        foreach ($combined as $ayahId) {
                            $toUpsert[] = [
                                'mushaf_ayah_id' => $mId,
                                'ayah_id'        => (int) $ayahId,
                                'map_type'       => 'combined',
                                'part_no'        => null,
                                'parts_total'    => null,
                                'ayah_order'     => $order++,
                                'created_at'     => $now,
                                'updated_at'     => $now,
                            ];
                        }
                        $this->cntCombined++;
                        $i++;
                        continue;
                    }

                    // 3) SPLIT
                    $split = $this->trySplit($i, $rowsArr, $baseBySurah[$surahId], $maxSplit);
                    if ($split !== null) {
                        $partNo = 1;
                        foreach ($split['parts'] as $partMushafId) {
                            $toUpsert[] = [
                                'mushaf_ayah_id' => (int) $partMushafId,
                                'ayah_id'        => (int) $split['ayah_id'],
                                'map_type'       => 'split',
                                'part_no'        => $partNo++,
                                'parts_total'    => (int) $split['total'],
                                'ayah_order'     => null,
                                'created_at'     => $now,
                                'updated_at'     => $now,
                            ];
                        }
                        $this->cntSplit++;
                        $i += (int) $split['total'];
                        continue;
                    }

                    // Unresolved
                    $this->cntUnresolved++;
                    $this->reportRow($mId, $surahId, $mNo, 'unresolved_no_match', $this->preview($mText));
                    $i++;
                }

                if (!$dryRun && !empty($toUpsert)) {
                    DB::table('mushaf_ayah_to_ayah_map')->upsert(
                        $toUpsert,
                        ['mushaf_ayah_id', 'ayah_id'],
                        ['map_type', 'part_no', 'parts_total', 'ayah_order', 'updated_at']
                    );
                }
            }

            $this->newLine();
            $this->info("Done for qiraat_reading_id={$qiraatId}.");
            $this->line("Exact: {$this->cntExact}");
            $this->line("Combined: {$this->cntCombined}");
            $this->line("Split: {$this->cntSplit}");
            $this->line("Unresolved: {$this->cntUnresolved}");
            $this->line("Report lines: {$this->reportLines} (limit {$this->reportLimit})");
            $this->line("Report: {$this->reportPath}");

            return self::SUCCESS;
        } finally {
            $this->closeReport();
        }
    }

    /**
     * Auto qiraat ids source of truth:
     * - any qiraat_reading_id that exists in mushaf_ayahs table.
     */
    private function resolveAutoQiraatIds(): array
    {
        return DB::table('mushaf_ayahs')
            ->select('qiraat_reading_id')
            ->distinct()
            ->orderBy('qiraat_reading_id')
            ->pluck('qiraat_reading_id')
            ->map(fn ($v) => (int) $v)
            ->values()
            ->all();
    }

    // ---------- matching helpers ----------

    private function tryCombined(string $mNorm, array $baseOrdered, int $maxCombined): ?array
    {
        $mLen = mb_strlen($mNorm);
        if ($mLen < 10) return null;

        $n = count($baseOrdered);

        for ($start = 0; $start < $n; $start++) {
            $first = $baseOrdered[$start]['norm'];
            if ($first === '' || !str_starts_with($mNorm, $first)) continue;

            $acc = $first;
            $ids = [(int) $baseOrdered[$start]['id']];

            for ($k = 2; $k <= $maxCombined; $k++) {
                $idx = $start + ($k - 1);
                if ($idx >= $n) break;

                $next = $baseOrdered[$idx]['norm'];
                if ($next === '') break;

                $acc .= $next;
                $ids[] = (int) $baseOrdered[$idx]['id'];

                if ($acc === $mNorm) return $ids;

                if (mb_strlen($acc) > $mLen) break;
            }
        }

        return null;
    }

    private function trySplit(int $startIndex, array $mushafRows, array $baseOrdered, int $maxSplit): ?array
    {
        $mCount = count($mushafRows);
        if ($startIndex >= $mCount) return null;

        $parts = [];
        $acc = '';
        $firstText = (string) $mushafRows[$startIndex]->text;
        $firstNorm = $this->normalizeArabic($firstText);
        if ($firstNorm === '') return null;

        $candidates = [];
        foreach ($baseOrdered as $b) {
            if ($b['norm'] !== '' && str_starts_with($b['norm'], $firstNorm)) {
                $candidates[] = $b;
            }
        }
        if (empty($candidates)) return null;

        for ($take = 2; $take <= $maxSplit; $take++) {
            $idx = $startIndex + ($take - 1);
            if ($idx >= $mCount) break;

            $t = (string) $mushafRows[$idx]->text;
            $n = $this->normalizeArabic($t);
            if ($n === '') break;

            if ($take === 2) {
                $parts = [
                    (int) $mushafRows[$startIndex]->id,
                    (int) $mushafRows[$idx]->id,
                ];
                $acc = $firstNorm . $n;
            } else {
                $parts[] = (int) $mushafRows[$idx]->id;
                $acc .= $n;
            }

            foreach ($candidates as $cand) {
                if ($acc === $cand['norm']) {
                    return [
                        'ayah_id' => (int) $cand['id'],
                        'parts'   => $parts,
                        'total'   => $take,
                    ];
                }
            }
        }

        return null;
    }

    // ---------- data loading ----------

    private function loadBaseAyahsBySurah(): array
    {
        $rows = DB::table('ayahs')
            ->select('id', 'surah_id', 'number_in_surah', 'text')
            ->orderBy('surah_id')
            ->orderBy('number_in_surah')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $sid = (int) $r->surah_id;
            $out[$sid] ??= [];

            $norm = $this->normalizeArabic((string) $r->text);

            $out[$sid][] = [
                'id'   => (int) $r->id,
                'no'   => (int) $r->number_in_surah,
                'norm' => $norm,
            ];
        }
        return $out;
    }

    private function loadMushafRowsForSurah(int $qiraatId, int $surahId, bool $onlyUnmapped)
    {
        $q = DB::table('mushaf_ayahs as m')
            ->select('m.id', 'm.number_in_surah', 'm.text')
            ->where('m.qiraat_reading_id', $qiraatId)
            ->where('m.surah_id', $surahId)
            ->orderBy('m.number_in_surah')
            ->orderBy('m.id');

        if ($onlyUnmapped) {
            $q->whereNotExists(function ($s) {
                $s->select(DB::raw(1))
                    ->from('mushaf_ayah_to_ayah_map as map')
                    ->whereColumn('map.mushaf_ayah_id', 'm.id');
            });
        }

        return $q->get();
    }

    // ---------- Arabic normalization ----------

    private function normalizeArabic(string $text): string
    {
        $t = trim($text);

        $t = str_replace("\xC2\xA0", ' ', $t);
        $t = str_replace("ـ", "", $t);

        $t = preg_replace('/[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]+/u', '', $t);
        $t = preg_replace('/\p{Mn}+/u', '', $t);

        $t = str_replace(['أ','إ','آ'], 'ا', $t);
        $t = str_replace(['ى'], 'ي', $t);
        $t = str_replace(['ؤ'], 'و', $t);
        $t = str_replace(['ئ'], 'ي', $t);
        $t = str_replace(['ة'], 'ه', $t);

        $t = preg_replace('/[۞۩ۭۤۚۖۗۛۜۢۦۧ۟۠ۡۥٰۧ]/u', '', $t);
        $t = preg_replace('/[^\p{Arabic}]+/u', '', $t);

        return $t ?? '';
    }

    // ---------- reporting ----------

    private function openReport(?string $path, int $qiraatId): void
    {
        $dir = storage_path('app/qiraat_import_logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $this->reportLimit = (int) ($this->option('report-limit') ?? 20000);
        if ($this->reportLimit <= 0) $this->reportLimit = 20000;

        // If user gave a directory, write file inside it
        if (is_string($path) && $path !== '' && is_dir($path)) {
            $this->reportPath = rtrim($path, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . 'auto_map_text_qiraat_' . $qiraatId . '_' . now()->format('Y-m-d_His') . '_report.csv';
        } else {
            // If user gave a file path and we are in batch mode, this may overwrite.
            // We still protect by suffixing qiraatId if path ends with .csv.
            if (is_string($path) && $path !== '' && str_ends_with(strtolower($path), '.csv')) {
                $base = preg_replace('/\.csv$/i', '', $path);
                $this->reportPath = $base . "_qiraat_" . $qiraatId . ".csv";
            } else {
                $this->reportPath = $path ?: ($dir . '/auto_map_text_qiraat_' . $qiraatId . '_' . now()->format('Y-m-d_His') . '_report.csv');
            }
        }

        $fp = @fopen($this->reportPath, 'w');
        if (!$fp) {
            $this->warn("Could not open report file for writing: {$this->reportPath}");
            $this->reportFp = null;
            return;
        }

        $this->reportFp = $fp;

        fputcsv($this->reportFp, [
            'mushaf_ayah_id',
            'surah_id',
            'number_in_surah',
            'reason',
            'details',
        ]);
    }

    private function closeReport(): void
    {
        if ($this->reportFp) {
            fclose($this->reportFp);
            $this->reportFp = null;
        }
    }

    private function reportRow(int $mushafAyahId, int $surahId, int $ayaNo, string $reason, string $details): void
    {
        if (!$this->reportFp) return;
        if ($this->reportLines >= $this->reportLimit) return;

        fputcsv($this->reportFp, [
            $mushafAyahId,
            $surahId,
            $ayaNo,
            $reason,
            $details,
        ]);

        $this->reportLines++;
    }

    private function preview(?string $text): string
    {
        $t = trim((string) $text);
        return mb_substr($t, 0, 160);
    }
}
