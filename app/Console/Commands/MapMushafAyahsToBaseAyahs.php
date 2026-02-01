<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MapMushafAyahsToBaseAyahs extends Command
{
    protected $signature = 'qiraat:map-mushaf-ayahs
        {qiraat_reading_id : qiraat_readings.id}
        {--only-unmapped : Only map rows where mushaf_ayahs.ayah_id is null}
        {--dry-run : Do not update database, only report what would happen}
        {--report= : Path to report file (CSV). If omitted, auto in storage/app/qiraat_import_logs}
        {--report-limit=20000 : Max report lines}
    ';

    protected $description = 'Map mushaf_ayahs rows to base ayahs.id using (surah_id, number_in_surah) and produce error report.';

    private $reportFp = null;
    private int $reportLines = 0;
    private int $reportLimit = 20000;
    private string $reportPath = '';

    // counters (so you still get totals even if report hits limit)
    private int $cntBaseDuplicates = 0;
    private int $cntMushafDuplicates = 0;
    private int $cntInvalidMushaf = 0;
    private int $cntMissingBase = 0;
    private int $cntConflicts = 0;

    public function handle(): int
    {
        $qiraatId     = (int) $this->argument('qiraat_reading_id');
        $onlyUnmapped = (bool) $this->option('only-unmapped');
        $dryRun       = (bool) $this->option('dry-run');

        if (!DB::table('qiraat_readings')->where('id', $qiraatId)->exists()) {
            $this->error("qiraat_readings not found: id={$qiraatId}");
            return self::FAILURE;
        }

        $this->openReport($this->option('report'), $qiraatId);

        try {
            $this->info("Mapping mushaf_ayahs -> ayahs for qiraat_reading_id={$qiraatId}");
            $this->line("Mode: " . ($dryRun ? "DRY-RUN" : "UPDATE") . " | " . ($onlyUnmapped ? "ONLY-UNMAPPED" : "ALL"));
            $this->line("Report: {$this->reportPath}");

            // 1) duplicates in base ayahs
            $this->checkBaseDuplicates();

            // 2) duplicates in mushaf for this qiraat
            $this->checkMushafDuplicates($qiraatId);

            // 3) invalid mushaf rows
            $this->reportInvalidMushafRows($qiraatId);

            // 4) mapping
            $wouldMap = $this->countMappable($qiraatId, $onlyUnmapped);

            if ($dryRun) {
                $this->warn("DRY-RUN: would map {$wouldMap} rows.");
            } else {
                $mapped = $this->bulkMap($qiraatId, $onlyUnmapped);
                $this->info("Mapped rows: {$mapped}");
            }

            // 5) missing base ayah matches
            $this->reportMissingBaseAyahs($qiraatId, $onlyUnmapped);

            // 6) conflicts
            $this->reportMappingConflicts($qiraatId);

            $this->newLine();
            $this->info("Summary:");
            $this->line("- Base duplicates: {$this->cntBaseDuplicates}");
            $this->line("- Mushaf duplicates (this qiraat): {$this->cntMushafDuplicates}");
            $this->line("- Invalid mushaf rows: {$this->cntInvalidMushaf}");
            $this->line("- Missing base ayah matches: {$this->cntMissingBase}");
            $this->line("- Mapping conflicts: {$this->cntConflicts}");
            $this->line("- Report lines written: {$this->reportLines} (limit: {$this->reportLimit})");
            $this->line("- Report file: {$this->reportPath}");

            return self::SUCCESS;
        } finally {
            $this->closeReport();
        }
    }

    /**
     * Count mushaf rows that have a matching base ayah.
     */
    private function countMappable(int $qiraatId, bool $onlyUnmapped): int
    {
        $q = DB::table('mushaf_ayahs as m')
            ->join('ayahs as a', function ($join) {
                $join->on('a.surah_id', '=', 'm.surah_id')
                    ->on('a.number_in_surah', '=', 'm.number_in_surah');
            })
            ->where('m.qiraat_reading_id', $qiraatId);

        if ($onlyUnmapped) {
            $q->whereNull('m.ayah_id');
        }

        return (int) $q->count();
    }

    /**
     * Bulk mapping optimized per driver.
     */
    private function bulkMap(int $qiraatId, bool $onlyUnmapped): int
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $sql = "
                UPDATE mushaf_ayahs m
                SET ayah_id = a.id,
                    updated_at = NOW()
                FROM ayahs a
                WHERE m.qiraat_reading_id = ?
                  AND a.surah_id = m.surah_id
                  AND a.number_in_surah = m.number_in_surah
            ";
            if ($onlyUnmapped) {
                $sql .= " AND m.ayah_id IS NULL ";
            }

            return (int) DB::affectingStatement($sql, [$qiraatId]);
        }

        if ($driver === 'mysql') {
            $sql = "
                UPDATE mushaf_ayahs m
                JOIN ayahs a
                  ON a.surah_id = m.surah_id
                 AND a.number_in_surah = m.number_in_surah
                SET m.ayah_id = a.id,
                    m.updated_at = NOW()
                WHERE m.qiraat_reading_id = ?
            ";
            if ($onlyUnmapped) {
                $sql .= " AND m.ayah_id IS NULL ";
            }

            return (int) DB::affectingStatement($sql, [$qiraatId]);
        }

        // Fallback chunk updates
        $this->warn("Driver '{$driver}' not optimized; using chunked updates.");

        $affected = 0;

        DB::table('mushaf_ayahs as m')
            ->select('m.id', 'm.surah_id', 'm.number_in_surah')
            ->where('m.qiraat_reading_id', $qiraatId)
            ->when($onlyUnmapped, fn($q) => $q->whereNull('m.ayah_id'))
            ->orderBy('m.id')
            ->chunkById(2000, function ($rows) use (&$affected) {
                $map = [];

                foreach ($rows as $r) {
                    $map[$r->surah_id . ':' . $r->number_in_surah] = $r->id;
                }

                $surahIds = $rows->pluck('surah_id')->unique()->values()->all();

                $base = DB::table('ayahs')
                    ->select('id', 'surah_id', 'number_in_surah')
                    ->whereIn('surah_id', $surahIds)
                    ->get()
                    ->keyBy(fn($x) => $x->surah_id . ':' . $x->number_in_surah);

                $now = now();

                foreach ($rows as $r) {
                    $k = $r->surah_id . ':' . $r->number_in_surah;
                    if (!isset($base[$k])) continue;

                    $affected += DB::table('mushaf_ayahs')
                        ->where('id', $r->id)
                        ->update([
                            'ayah_id' => $base[$k]->id,
                            'updated_at' => $now,
                        ]);
                }
            }, 'm.id');

        return $affected;
    }

    /**
     * FIXED for Postgres: use HAVING COUNT(*) > 1 (not alias "c")
     */
    private function checkBaseDuplicates(): void
    {
        $dups = DB::table('ayahs')
            ->selectRaw('surah_id, number_in_surah, COUNT(*) as c')
            ->groupBy('surah_id', 'number_in_surah')
            ->havingRaw('COUNT(*) > 1')
            ->limit(500)
            ->get();

        $this->cntBaseDuplicates = (int) $dups->count();

        if ($dups->isNotEmpty()) {
            $this->warn("Base ayahs duplicates found (surah_id, number_in_surah). This must be fixed!");
            foreach ($dups as $d) {
                $this->reportRow(0, (int) $d->surah_id, (int) $d->number_in_surah, 'base_duplicate', "count={$d->c}");
            }
        }
    }

    private function checkMushafDuplicates(int $qiraatId): void
    {
        $dups = DB::table('mushaf_ayahs')
            ->selectRaw('surah_id, number_in_surah, COUNT(*) as c')
            ->where('qiraat_reading_id', $qiraatId)
            ->groupBy('surah_id', 'number_in_surah')
            ->havingRaw('COUNT(*) > 1')
            ->limit(500)
            ->get();

        $this->cntMushafDuplicates = (int) $dups->count();

        if ($dups->isNotEmpty()) {
            $this->warn("Mushaf duplicates found for this qiraat (surah_id, number_in_surah). Check your unique index!");
            foreach ($dups as $d) {
                $this->reportRow(0, (int) $d->surah_id, (int) $d->number_in_surah, 'mushaf_duplicate', "count={$d->c}");
            }
        }
    }

    private function reportInvalidMushafRows(int $qiraatId): void
    {
        // Count first (so you know even if report limit stops)
        $this->cntInvalidMushaf = (int) DB::table('mushaf_ayahs')
            ->where('qiraat_reading_id', $qiraatId)
            ->where(function ($q) {
                $q->where('surah_id', '<=', 0)
                    ->orWhere('surah_id', '>', 114)
                    ->orWhere('number_in_surah', '<=', 0);
            })
            ->count();

        $rows = DB::table('mushaf_ayahs')
            ->select('id', 'surah_id', 'number_in_surah', 'text')
            ->where('qiraat_reading_id', $qiraatId)
            ->where(function ($q) {
                $q->where('surah_id', '<=', 0)
                    ->orWhere('surah_id', '>', 114)
                    ->orWhere('number_in_surah', '<=', 0);
            })
            ->orderBy('id')
            ->limit($this->reportLimit)
            ->get();

        foreach ($rows as $r) {
            $this->reportRow((int) $r->id, (int) $r->surah_id, (int) $r->number_in_surah, 'invalid_mushaf_row', $this->preview($r->text));
        }
    }

    private function reportMissingBaseAyahs(int $qiraatId, bool $onlyUnmapped): void
    {
        // Count first
        $countQ = DB::table('mushaf_ayahs as m')
            ->leftJoin('ayahs as a', function ($join) {
                $join->on('a.surah_id', '=', 'm.surah_id')
                    ->on('a.number_in_surah', '=', 'm.number_in_surah');
            })
            ->where('m.qiraat_reading_id', $qiraatId)
            ->whereNull('a.id');

        if ($onlyUnmapped) {
            $countQ->whereNull('m.ayah_id');
        }

        $this->cntMissingBase = (int) $countQ->count();

        $q = DB::table('mushaf_ayahs as m')
            ->leftJoin('ayahs as a', function ($join) {
                $join->on('a.surah_id', '=', 'm.surah_id')
                    ->on('a.number_in_surah', '=', 'm.number_in_surah');
            })
            ->select('m.id', 'm.surah_id', 'm.number_in_surah', 'm.text')
            ->where('m.qiraat_reading_id', $qiraatId)
            ->whereNull('a.id');

        if ($onlyUnmapped) {
            $q->whereNull('m.ayah_id');
        }

        $q->orderBy('m.id')
            ->chunkById(2000, function ($rows) {
                foreach ($rows as $r) {
                    $this->reportRow((int) $r->id, (int) $r->surah_id, (int) $r->number_in_surah, 'missing_base_ayah', $this->preview($r->text));
                }
            }, 'm.id');
    }

    private function reportMappingConflicts(int $qiraatId): void
    {
        // Count first
        $this->cntConflicts = (int) DB::table('mushaf_ayahs as m')
            ->join('ayahs as a', 'a.id', '=', 'm.ayah_id')
            ->where('m.qiraat_reading_id', $qiraatId)
            ->whereNotNull('m.ayah_id')
            ->where(function ($w) {
                $w->whereColumn('a.surah_id', '!=', 'm.surah_id')
                    ->orWhereColumn('a.number_in_surah', '!=', 'm.number_in_surah');
            })
            ->count();

        $q = DB::table('mushaf_ayahs as m')
            ->join('ayahs as a', 'a.id', '=', 'm.ayah_id')
            ->select(
                'm.id',
                'm.surah_id',
                'm.number_in_surah',
                'm.text',
                'a.surah_id as base_surah',
                'a.number_in_surah as base_ayah_no'
            )
            ->where('m.qiraat_reading_id', $qiraatId)
            ->whereNotNull('m.ayah_id')
            ->where(function ($w) {
                $w->whereColumn('a.surah_id', '!=', 'm.surah_id')
                    ->orWhereColumn('a.number_in_surah', '!=', 'm.number_in_surah');
            })
            ->orderBy('m.id');

        $q->chunkById(2000, function ($rows) {
            foreach ($rows as $r) {
                $extra = "base={$r->base_surah}:{$r->base_ayah_no} | text=" . $this->preview($r->text);
                $this->reportRow((int) $r->id, (int) $r->surah_id, (int) $r->number_in_surah, 'mapping_conflict', $extra);
            }
        }, 'm.id');
    }

    // ---------------- Report helpers ----------------

    private function openReport(?string $path, int $qiraatId): void
    {
        $dir = storage_path('app/qiraat_import_logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $this->reportLimit = (int) ($this->option('report-limit') ?? 20000);
        if ($this->reportLimit <= 0) $this->reportLimit = 20000;

        $this->reportPath = $path ?: ($dir . '/map_qiraat_' . $qiraatId . '_' . now()->format('Y-m-d_His') . '_report.csv');

        $fp = @fopen($this->reportPath, 'w');
        if (!$fp) {
            $this->warn("Could not open report file for writing: {$this->reportPath}");
            $this->reportFp = null;
            return;
        }

        $this->reportFp = $fp;

        fputcsv($this->reportFp, [
            'mushaf_row_id',
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

    private function reportRow(int $mushafRowId, int $surahId, int $ayaNo, string $reason, string $details): void
    {
        if (!$this->reportFp) return;
        if ($this->reportLines >= $this->reportLimit) return;

        fputcsv($this->reportFp, [
            $mushafRowId,
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
        return mb_substr($t, 0, 140);
    }
}
