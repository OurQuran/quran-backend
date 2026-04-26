<?php

namespace App\Console\Commands\MushafAyahs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class VerifyMushafAyahMappingsByText extends Command
{
    protected $signature = 'qiraat:verify-ayah-map
        {qiraat_reading_id : qiraat_readings.id}
        {--only-problems : Only output mismatches}
        {--limit=0 : Stop after N reported rows (0 = unlimited, still capped by report-limit)}
        {--report= : Path to report file (CSV). Default storage/app/qiraat_import_logs}
        {--report-limit=20000 : Max report lines}
    ';

    protected $description = 'Verify mushaf_ayah_to_ayah_map mappings by comparing normalized Arabic text for exact/combined/split.';

    private $reportFp = null;
    private int $reportLines = 0;
    private int $reportLimit = 20000;
    private string $reportPath = '';

    private int $cntGroups = 0;
    private int $cntOk = 0;
    private int $cntMismatch = 0;
    private int $cntMissingRefs = 0;

    public function handle(): int
    {
        $qiraatId = (int) $this->argument('qiraat_reading_id');
        $onlyProblems = (bool) $this->option('only-problems');
        $stopAfter = (int) ($this->option('limit') ?? 0);
        if ($stopAfter < 0) $stopAfter = 0;

        if (!DB::table('qiraat_readings')->where('id', $qiraatId)->exists()) {
            $this->error("qiraat_readings not found: id={$qiraatId}");
            return self::FAILURE;
        }

        $this->openReport($this->option('report'), $qiraatId);

        try {
            $this->info("Verifying mappings for qiraat_reading_id={$qiraatId}");
            $this->line("Mode: " . ($onlyProblems ? "ONLY-PROBLEMS" : "ALL") . " | Report: {$this->reportPath}");

            // We verify by mushaf_ayah_id group, because combined/split rely on grouping.
            $mushafIds = DB::table('mushaf_ayahs')
                ->where('qiraat_reading_id', $qiraatId)
                ->pluck('id')
                ->all();

            if (empty($mushafIds)) {
                $this->warn("No mushaf_ayahs found for this qiraat.");
                return self::SUCCESS;
            }

            // Stream groups by mushaf_ayah_id
            DB::table('mushaf_ayah_to_ayah_map as map')
                ->join('mushaf_ayahs as m', 'm.id', '=', 'map.mushaf_ayah_id')
                ->where('m.qiraat_reading_id', $qiraatId)
                ->select(
                    'map.mushaf_ayah_id',
                    DB::raw("MIN(map.map_type) as map_type") // assume consistent per mushaf; if not, we will detect later
                )
                ->groupBy('map.mushaf_ayah_id')
                ->orderBy('map.mushaf_ayah_id')
                ->chunkById(2000, function ($groups) use ($onlyProblems, $stopAfter) {

                    foreach ($groups as $g) {
                        $this->cntGroups++;

                        $mushafId = (int) $g->mushaf_ayah_id;

                        $groupRows = DB::table('mushaf_ayah_to_ayah_map as map')
                            ->where('map.mushaf_ayah_id', $mushafId)
                            ->select('map.ayah_id', 'map.map_type', 'map.part_no', 'map.parts_total', 'map.ayah_order')
                            ->orderByRaw("COALESCE(map.ayah_order, 999999), COALESCE(map.part_no, 999999), map.ayah_id")
                            ->get();

                        if ($groupRows->isEmpty()) {
                            // should not happen because we grouped from map table
                            continue;
                        }

                        // detect inconsistent map_type within the group
                        $types = $groupRows->pluck('map_type')->unique()->values()->all();
                        if (count($types) > 1) {
                            $this->cntMismatch++;
                            $this->reportRow($mushafId, null, 'inconsistent_map_types', 'types=' . implode(',', $types), '', '');
                            if ($this->shouldStop($stopAfter)) return false;
                            continue;
                        }

                        $mapType = (string) $types[0];

                        // load mushaf row
                        $m = DB::table('mushaf_ayahs')
                            ->select('id', 'surah_id', 'number_in_surah', 'text')
                            ->where('id', $mushafId)
                            ->first();

                        if (!$m) {
                            $this->cntMissingRefs++;
                            $this->reportRow($mushafId, null, 'missing_mushaf_row', '', '', '');
                            if ($this->shouldStop($stopAfter)) return false;
                            continue;
                        }

                        $mushafText = (string) $m->text;
                        $mushafNorm = $this->normalizeArabic($mushafText);

                        // verify according to type
                        if ($mapType === 'exact') {
                            // should be exactly one mapping in most cases, but we validate text equality for each
                            foreach ($groupRows as $r) {
                                $a = DB::table('ayahs')->select('id', 'text')->where('id', (int)$r->ayah_id)->first();
                                if (!$a) {
                                    $this->cntMissingRefs++;
                                    $this->reportRow($mushafId, (int)$r->ayah_id, 'missing_base_ayah', '', $this->preview($mushafText), '');
                                    if ($this->shouldStop($stopAfter)) return false;
                                    continue;
                                }

                                $baseNorm = $this->normalizeArabic((string)$a->text);
                                $ok = ($mushafNorm !== '' && $mushafNorm === $baseNorm);

                                if ($ok) {
                                    $this->cntOk++;
                                    if (!$onlyProblems) {
                                        $this->reportRow($mushafId, (int)$r->ayah_id, 'ok_exact', '', $this->preview($mushafText), $this->preview((string)$a->text));
                                    }
                                } else {
                                    $this->cntMismatch++;
                                    $reason = 'mismatch_exact';
                                    $details = 'len_m=' . mb_strlen($mushafNorm) . '|len_a=' . mb_strlen($baseNorm);
                                    $this->reportRow($mushafId, (int)$r->ayah_id, $reason, $details, $this->preview($mushafText), $this->preview((string)$a->text));
                                    if ($this->shouldStop($stopAfter)) return false;
                                }
                            }

                            continue;
                        }

                        if ($mapType === 'combined') {
                            // build base concatenation in ayah_order
                            $ordered = $groupRows->sortBy(fn($x) => (int)($x->ayah_order ?? 999999))->values();

                            $baseConcat = '';
                            $missing = false;

                            foreach ($ordered as $r) {
                                $a = DB::table('ayahs')->select('id', 'text')->where('id', (int)$r->ayah_id)->first();
                                if (!$a) { $missing = true; break; }
                                $baseConcat .= $this->normalizeArabic((string)$a->text);
                            }

                            if ($missing) {
                                $this->cntMissingRefs++;
                                $this->reportRow($mushafId, null, 'missing_base_ayah_in_combined', '', $this->preview($mushafText), '');
                                if ($this->shouldStop($stopAfter)) return false;
                                continue;
                            }

                            $ok = ($mushafNorm !== '' && $mushafNorm === $baseConcat);

                            if ($ok) {
                                $this->cntOk++;
                                if (!$onlyProblems) {
                                    $this->reportRow($mushafId, null, 'ok_combined', 'count=' . $ordered->count(), $this->preview($mushafText), '');
                                }
                            } else {
                                $this->cntMismatch++;
                                $details = 'base_count=' . $ordered->count() . '|len_m=' . mb_strlen($mushafNorm) . '|len_concat=' . mb_strlen($baseConcat);
                                $this->reportRow($mushafId, null, 'mismatch_combined', $details, $this->preview($mushafText), '');
                                if ($this->shouldStop($stopAfter)) return false;
                            }

                            continue;
                        }

                        if ($mapType === 'split') {
                            // split is stored as multiple mushaf_ayah_id rows mapping to same ayah_id,
                            // BUT your table groups by mushaf_ayah_id. So for split verification, we verify from base ayah side.
                            // We'll handle split verification in a separate pass after this chunk.
                            continue;
                        }

                        // unknown type
                        $this->cntMismatch++;
                        $this->reportRow($mushafId, null, 'unknown_map_type', $mapType, $this->preview($mushafText), '');
                        if ($this->shouldStop($stopAfter)) return false;

                    }
                }, 'map.mushaf_ayah_id', 'mushaf_ayah_id');

            // Second pass: verify SPLIT groups by ayah_id
            $this->verifySplitGroups($qiraatId, $onlyProblems, $stopAfter);

            $this->newLine();
            $this->info("Summary:");
            $this->line("Groups checked: {$this->cntGroups}");
            $this->line("OK: {$this->cntOk}");
            $this->line("Mismatches: {$this->cntMismatch}");
            $this->line("Missing references: {$this->cntMissingRefs}");
            $this->line("Report lines: {$this->reportLines} (limit {$this->reportLimit})");
            $this->line("Report: {$this->reportPath}");

            return self::SUCCESS;
        } finally {
            $this->closeReport();
        }
    }

    private function verifySplitGroups(int $qiraatId, bool $onlyProblems, int $stopAfter): void
    {
        $this->info("Verifying split groups by ayah_id...");

        DB::table('mushaf_ayah_to_ayah_map as map')
            ->join('mushaf_ayahs as m', 'm.id', '=', 'map.mushaf_ayah_id')
            ->where('m.qiraat_reading_id', $qiraatId)
            ->where('map.map_type', 'split')
            ->select('map.ayah_id')
            ->groupBy('map.ayah_id')
            ->orderBy('map.ayah_id')
            ->chunkById(2000, function ($groups) use ($onlyProblems, $stopAfter) {

                foreach ($groups as $g) {
                    $ayahId = (int) $g->ayah_id;

                    $base = DB::table('ayahs')->select('id', 'text')->where('id', $ayahId)->first();
                    if (!$base) {
                        $this->cntMissingRefs++;
                        $this->reportRow(null, $ayahId, 'missing_base_ayah_for_split', '', '', '');
                        if ($this->shouldStop($stopAfter)) return false;
                        continue;
                    }

                    $baseNorm = $this->normalizeArabic((string)$base->text);

                    $parts = DB::table('mushaf_ayah_to_ayah_map as map')
                        ->join('mushaf_ayahs as m', 'm.id', '=', 'map.mushaf_ayah_id')
                        ->where('map.ayah_id', $ayahId)
                        ->where('map.map_type', 'split')
                        ->select('m.id as mushaf_ayah_id', 'm.text', 'map.part_no', 'map.parts_total')
                        ->orderBy('map.part_no')
                        ->get();

                    if ($parts->isEmpty()) continue;

                    $concat = '';
                    foreach ($parts as $p) {
                        $concat .= $this->normalizeArabic((string)$p->text);
                    }

                    $ok = ($concat !== '' && $concat === $baseNorm);

                    if ($ok) {
                        $this->cntOk++;
                        if (!$onlyProblems) {
                            $this->reportRow((int)$parts[0]->mushaf_ayah_id, $ayahId, 'ok_split', 'parts=' . $parts->count(), '', $this->preview((string)$base->text));
                        }
                    } else {
                        $this->cntMismatch++;
                        $details = 'parts=' . $parts->count() . '|len_concat=' . mb_strlen($concat) . '|len_base=' . mb_strlen($baseNorm);
                        $this->reportRow((int)$parts[0]->mushaf_ayah_id, $ayahId, 'mismatch_split', $details, '', $this->preview((string)$base->text));
                        if ($this->shouldStop($stopAfter)) return false;
                    }

                }

            }, 'map.ayah_id', 'ayah_id');
    }

    private function shouldStop(int $stopAfter): bool
    {
        if ($stopAfter <= 0) return false;
        return $this->reportLines >= $stopAfter;
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

    // ---------- report ----------
    private function openReport(?string $path, int $qiraatId): void
    {
        $dir = storage_path('app/qiraat_import_logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $this->reportLimit = (int) ($this->option('report-limit') ?? 20000);
        if ($this->reportLimit <= 0) $this->reportLimit = 20000;

        $this->reportPath = $path ?: ($dir . '/verify_map_qiraat_' . $qiraatId . '_' . now()->format('Y-m-d_His') . '_report.csv');

        $fp = @fopen($this->reportPath, 'w');
        if (!$fp) {
            $this->warn("Could not open report file for writing: {$this->reportPath}");
            $this->reportFp = null;
            return;
        }

        $this->reportFp = $fp;

        fputcsv($this->reportFp, [
            'mushaf_ayah_id',
            'ayah_id',
            'status',
            'details',
            'mushaf_text_preview',
            'base_text_preview',
        ]);
    }

    private function closeReport(): void
    {
        if ($this->reportFp) {
            fclose($this->reportFp);
            $this->reportFp = null;
        }
    }

    private function reportRow(?int $mushafAyahId, ?int $ayahId, string $status, string $details, string $mPrev, string $aPrev): void
    {
        if (!$this->reportFp) return;
        if ($this->reportLines >= $this->reportLimit) return;

        fputcsv($this->reportFp, [
            $mushafAyahId ?? 0,
            $ayahId ?? 0,
            $status,
            $details,
            $mPrev,
            $aPrev,
        ]);

        $this->reportLines++;
    }

    private function preview(?string $text): string
    {
        $t = trim((string) $text);
        return mb_substr($t, 0, 160);
    }
}
