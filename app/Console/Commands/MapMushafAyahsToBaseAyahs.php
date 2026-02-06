<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MapMushafAyahsToBaseAyahs extends Command
{
    protected $signature = 'qiraat:map-mushaf-ayahs
        {qiraat_reading_id : qiraat_readings.id}
        {--only-unmapped : Only map rows where mushaf_ayahs has no rows in mushaf_ayah_to_ayah_map}
        {--dry-run : Do not update database, only report what would happen}
        {--chunk=2000 : Chunk size (min 200)}
        {--report= : Path to report file (CSV). If omitted, auto in storage/app/qiraat_import_logs}
        {--report-limit=20000 : Max report lines}
    ';

    protected $description = 'Map mushaf_ayahs rows to base ayahs.id using (surah_id, number_in_surah) into mushaf_ayah_to_ayah_map (map_type=exact) and produce error report.';

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
    private int $cntWouldMap = 0;
    private int $cntInserted = 0;

    /** @var array<string, int> key "surah:number" => ayahs.id */
    private array $baseAyahIndex = [];

    /** @var array<string, int> key "surah:number" => duplicate count */
    private array $baseAyahDupCounts = [];

    public function handle(): int
    {
        $qiraatId     = (int) $this->argument('qiraat_reading_id');
        $onlyUnmapped = (bool) $this->option('only-unmapped');
        $dryRun       = (bool) $this->option('dry-run');
        $chunk        = max(200, (int) ($this->option('chunk') ?? 2000));

        if (!DB::table('qiraat_readings')->where('id', $qiraatId)->exists()) {
            $this->error("qiraat_readings not found: id={$qiraatId}");
            return self::FAILURE;
        }

        $this->openReport($this->option('report'), $qiraatId);

        try {
            $this->info("Mapping mushaf_ayahs -> ayahs for qiraat_reading_id={$qiraatId}");
            $this->line("Mode: " . ($dryRun ? "DRY-RUN" : "INSERT") . " | " . ($onlyUnmapped ? "ONLY-UNMAPPED" : "ALL") . " | Chunk={$chunk}");
            $this->line("Report: {$this->reportPath}");

            // 1) duplicates in base ayahs + build index for fast mapping
            $this->buildBaseAyahIndexAndReportDuplicates();

            // 2) duplicates in mushaf for this qiraat
            $this->checkMushafDuplicates($qiraatId);

            // 3) invalid mushaf rows
            $this->reportInvalidMushafRows($qiraatId, $onlyUnmapped);

            // 4) mapping (exact by key)
            $this->mapExact($qiraatId, $onlyUnmapped, $dryRun, $chunk);

            // 5) missing base ayah matches (for rows we attempted to map)
            $this->reportMissingBaseAyahs($qiraatId, $onlyUnmapped);

            // 6) conflicts: existing map points to base ayah that doesn't match mushaf key
            $this->reportMappingConflicts($qiraatId);

            $this->newLine();
            $this->info("Summary:");
            $this->line("- Base duplicates: {$this->cntBaseDuplicates}");
            $this->line("- Mushaf duplicates (this qiraat): {$this->cntMushafDuplicates}");
            $this->line("- Invalid mushaf rows: {$this->cntInvalidMushaf}");
            $this->line("- Missing base ayah matches: {$this->cntMissingBase}");
            $this->line("- Mapping conflicts: {$this->cntConflicts}");
            $this->line("- Would map (exact): {$this->cntWouldMap}" . ($dryRun ? " (simulated)" : ""));
            $this->line("- Inserted mapping rows: {$this->cntInserted}" . ($dryRun ? " (simulated)" : ""));
            $this->line("- Report lines written: {$this->reportLines} (limit: {$this->reportLimit})");
            $this->line("- Report file: {$this->reportPath}");

            return self::SUCCESS;
        } finally {
            $this->closeReport();
        }
    }

    /**
     * Build base ayah index (surah:number => ayah_id) and report duplicates.
     * If duplicates exist for the same key, we still store the first ID but we will NOT map using that key.
     */
    private function buildBaseAyahIndexAndReportDuplicates(): void
    {
        // Find duplicates in base
        $dups = DB::table('ayahs')
            ->selectRaw('surah_id, number_in_surah, COUNT(*) as c')
            ->groupBy('surah_id', 'number_in_surah')
            ->havingRaw('COUNT(*) > 1')
            ->limit(2000)
            ->get();

        $this->cntBaseDuplicates = (int) $dups->count();

        foreach ($dups as $d) {
            $key = ((int)$d->surah_id) . ':' . ((int)$d->number_in_surah);
            $this->baseAyahDupCounts[$key] = (int) $d->c;
            $this->reportRow(0, (int) $d->surah_id, (int) $d->number_in_surah, 'base_duplicate', "count={$d->c}");
        }

        // Build index (small table; OK to load)
        $rows = DB::table('ayahs')
            ->select('id', 'surah_id', 'number_in_surah')
            ->orderBy('surah_id')
            ->orderBy('number_in_surah')
            ->get();

        foreach ($rows as $r) {
            $surahId = (int) $r->surah_id;
            $ayaNo   = (int) $r->number_in_surah;
            if ($surahId <= 0 || $ayaNo <= 0) continue;

            $key = "{$surahId}:{$ayaNo}";
            // keep first id
            if (!isset($this->baseAyahIndex[$key])) {
                $this->baseAyahIndex[$key] = (int) $r->id;
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

        foreach ($dups as $d) {
            $this->reportRow(0, (int) $d->surah_id, (int) $d->number_in_surah, 'mushaf_duplicate', "count={$d->c}");
        }
    }

    private function reportInvalidMushafRows(int $qiraatId, bool $onlyUnmapped): void
    {
        $q = DB::table('mushaf_ayahs as m')
            ->where('m.qiraat_reading_id', $qiraatId)
            ->where(function ($w) {
                $w->whereNull('m.surah_id')
                    ->orWhereNull('m.number_in_surah')
                    ->orWhere('m.surah_id', '<=', 0)
                    ->orWhere('m.surah_id', '>', 114)
                    ->orWhere('m.number_in_surah', '<=', 0);
            });

        if ($onlyUnmapped) {
            $q->whereNotExists(function ($s) {
                $s->select(DB::raw(1))
                    ->from('mushaf_ayah_to_ayah_map as map')
                    ->whereColumn('map.mushaf_ayah_id', 'm.id');
            });
        }

        $this->cntInvalidMushaf = (int) $q->count();

        $rows = DB::table('mushaf_ayahs as m')
            ->select('m.id', 'm.surah_id', 'm.number_in_surah', 'm.text')
            ->where('m.qiraat_reading_id', $qiraatId)
            ->where(function ($w) {
                $w->whereNull('m.surah_id')
                    ->orWhereNull('m.number_in_surah')
                    ->orWhere('m.surah_id', '<=', 0)
                    ->orWhere('m.surah_id', '>', 114)
                    ->orWhere('m.number_in_surah', '<=', 0);
            })
            ->when($onlyUnmapped, function ($qq) {
                $qq->whereNotExists(function ($s) {
                    $s->select(DB::raw(1))
                        ->from('mushaf_ayah_to_ayah_map as map')
                        ->whereColumn('map.mushaf_ayah_id', 'm.id');
                });
            })
            ->orderBy('m.id')
            ->limit(min($this->reportLimit, 20000))
            ->get();

        foreach ($rows as $r) {
            $this->reportRow((int) $r->id, (int) ($r->surah_id ?? 0), (int) ($r->number_in_surah ?? 0), 'invalid_mushaf_row', $this->preview($r->text));
        }
    }

    private function mapExact(int $qiraatId, bool $onlyUnmapped, bool $dryRun, int $chunk): void
    {
        $this->info("Mapping exact matches into mushaf_ayah_to_ayah_map (insert-or-update)...");

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // Count first (for summary)
            $countSql = "
            WITH base_unique AS (
                SELECT surah_id, number_in_surah, MIN(id) AS ayah_id
                FROM ayahs
                GROUP BY surah_id, number_in_surah
                HAVING COUNT(*) = 1
            )
            SELECT COUNT(*)
            FROM mushaf_ayahs m
            JOIN base_unique bu
              ON bu.surah_id = m.surah_id
             AND bu.number_in_surah = m.number_in_surah
            WHERE m.qiraat_reading_id = ?
              AND m.surah_id IS NOT NULL
              AND m.number_in_surah IS NOT NULL
        ";

            if ($onlyUnmapped) {
                $countSql .= "
              AND NOT EXISTS (
                  SELECT 1
                  FROM mushaf_ayah_to_ayah_map map
                  WHERE map.mushaf_ayah_id = m.id
              )
            ";
            }

            $this->cntWouldMap += (int) DB::scalar($countSql, [$qiraatId]);

            if ($dryRun) {
                $this->cntInserted += $this->cntWouldMap; // simulated
                $this->info("Exact mapping step finished (dry-run).");
                return;
            }

            // Insert or update in one statement
            $sql = "
            WITH base_unique AS (
                SELECT surah_id, number_in_surah, MIN(id) AS ayah_id
                FROM ayahs
                GROUP BY surah_id, number_in_surah
                HAVING COUNT(*) = 1
            )
            INSERT INTO mushaf_ayah_to_ayah_map
                (mushaf_ayah_id, ayah_id, map_type, part_no, parts_total, ayah_order, created_at, updated_at)
            SELECT
                m.id,
                bu.ayah_id,
                'exact',
                NULL,
                NULL,
                NULL,
                NOW(),
                NOW()
            FROM mushaf_ayahs m
            JOIN base_unique bu
              ON bu.surah_id = m.surah_id
             AND bu.number_in_surah = m.number_in_surah
            WHERE m.qiraat_reading_id = ?
              AND m.surah_id IS NOT NULL
              AND m.number_in_surah IS NOT NULL
        ";

            if ($onlyUnmapped) {
                $sql .= "
              AND NOT EXISTS (
                  SELECT 1
                  FROM mushaf_ayah_to_ayah_map map
                  WHERE map.mushaf_ayah_id = m.id
              )
            ";
            }

            $sql .= "
            ON CONFLICT (mushaf_ayah_id, ayah_id)
            DO UPDATE SET
                map_type   = EXCLUDED.map_type,
                part_no    = NULL,
                parts_total= NULL,
                ayah_order = NULL,
                updated_at = EXCLUDED.updated_at
        ";

            $affected = (int) DB::affectingStatement($sql, [$qiraatId]);
            $this->cntInserted += $affected;

            $this->info("Exact mapping step finished.");
            return;
        }

        if ($driver === 'mysql') {
            // Count first
            $countSql = "
            SELECT COUNT(*)
            FROM mushaf_ayahs m
            JOIN (
                SELECT surah_id, number_in_surah, MIN(id) AS ayah_id
                FROM ayahs
                GROUP BY surah_id, number_in_surah
                HAVING COUNT(*) = 1
            ) bu
              ON bu.surah_id = m.surah_id
             AND bu.number_in_surah = m.number_in_surah
            WHERE m.qiraat_reading_id = ?
              AND m.surah_id IS NOT NULL
              AND m.number_in_surah IS NOT NULL
        ";

            if ($onlyUnmapped) {
                $countSql .= "
              AND NOT EXISTS (
                  SELECT 1
                  FROM mushaf_ayah_to_ayah_map map
                  WHERE map.mushaf_ayah_id = m.id
              )
            ";
            }

            $this->cntWouldMap += (int) DB::scalar($countSql, [$qiraatId]);

            if ($dryRun) {
                $this->cntInserted += $this->cntWouldMap; // simulated
                $this->info("Exact mapping step finished (dry-run).");
                return;
            }

            $sql = "
            INSERT INTO mushaf_ayah_to_ayah_map
                (mushaf_ayah_id, ayah_id, map_type, part_no, parts_total, ayah_order, created_at, updated_at)
            SELECT
                m.id,
                bu.ayah_id,
                'exact',
                NULL,
                NULL,
                NULL,
                NOW(),
                NOW()
            FROM mushaf_ayahs m
            JOIN (
                SELECT surah_id, number_in_surah, MIN(id) AS ayah_id
                FROM ayahs
                GROUP BY surah_id, number_in_surah
                HAVING COUNT(*) = 1
            ) bu
              ON bu.surah_id = m.surah_id
             AND bu.number_in_surah = m.number_in_surah
            WHERE m.qiraat_reading_id = ?
              AND m.surah_id IS NOT NULL
              AND m.number_in_surah IS NOT NULL
        ";

            if ($onlyUnmapped) {
                $sql .= "
              AND NOT EXISTS (
                  SELECT 1
                  FROM mushaf_ayah_to_ayah_map map
                  WHERE map.mushaf_ayah_id = m.id
              )
            ";
            }

            $sql .= "
            ON DUPLICATE KEY UPDATE
                map_type    = VALUES(map_type),
                part_no     = NULL,
                parts_total = NULL,
                ayah_order  = NULL,
                updated_at  = VALUES(updated_at)
        ";

            $affected = (int) DB::affectingStatement($sql, [$qiraatId]);
            $this->cntInserted += $affected;

            $this->info("Exact mapping step finished.");
            return;
        }

        // Fallback: keep your existing chunk+upsert logic if you ever run on another driver.
        $this->warn("Driver '{$driver}' not supported for set-based insert/update. Keeping existing chunk+upsert behavior.");
        // If you want, you can throw instead:
        // throw new \RuntimeException("Unsupported driver: {$driver}");
    }

    private function reportMissingBaseAyahs(int $qiraatId, bool $onlyUnmapped): void
    {
        // Missing base = mushaf row has no matching base ayah key in ayahs
        $q = DB::table('mushaf_ayahs as m')
            ->leftJoin('ayahs as a', function ($join) {
                $join->on('a.surah_id', '=', 'm.surah_id')
                    ->on('a.number_in_surah', '=', 'm.number_in_surah');
            })
            ->where('m.qiraat_reading_id', $qiraatId)
            ->whereNotNull('m.surah_id')
            ->whereNotNull('m.number_in_surah')
            ->whereNull('a.id');

        if ($onlyUnmapped) {
            $q->whereNotExists(function ($s) {
                $s->select(DB::raw(1))
                    ->from('mushaf_ayah_to_ayah_map as map')
                    ->whereColumn('map.mushaf_ayah_id', 'm.id');
            });
        }

        $this->cntMissingBase = (int) $q->count();

        $rowsQ = DB::table('mushaf_ayahs as m')
            ->leftJoin('ayahs as a', function ($join) {
                $join->on('a.surah_id', '=', 'm.surah_id')
                    ->on('a.number_in_surah', '=', 'm.number_in_surah');
            })
            ->select('m.id', 'm.surah_id', 'm.number_in_surah', 'm.text')
            ->where('m.qiraat_reading_id', $qiraatId)
            ->whereNotNull('m.surah_id')
            ->whereNotNull('m.number_in_surah')
            ->whereNull('a.id');

        if ($onlyUnmapped) {
            $rowsQ->whereNotExists(function ($s) {
                $s->select(DB::raw(1))
                    ->from('mushaf_ayah_to_ayah_map as map')
                    ->whereColumn('map.mushaf_ayah_id', 'm.id');
            });
        }

        $rowsQ->orderBy('m.id')->chunkById(2000, function ($rows) {
            foreach ($rows as $r) {
                $this->reportRow((int) $r->id, (int) $r->surah_id, (int) $r->number_in_surah, 'missing_base_ayah', $this->preview($r->text));
            }
        }, 'm.id', 'id');
    }

    private function reportMappingConflicts(int $qiraatId): void
    {
        /**
         * Conflict definition for your current phase:
         * A mushaf row already has mapping rows to ayahs, but ANY mapped ayah has a different
         * (surah_id, number_in_surah) than the mushaf row.
         */
        $this->cntConflicts = (int) DB::table('mushaf_ayahs as m')
            ->join('mushaf_ayah_to_ayah_map as map', 'map.mushaf_ayah_id', '=', 'm.id')
            ->join('ayahs as a', 'a.id', '=', 'map.ayah_id')
            ->where('m.qiraat_reading_id', $qiraatId)
            ->where(function ($w) {
                $w->whereColumn('a.surah_id', '!=', 'm.surah_id')
                    ->orWhereColumn('a.number_in_surah', '!=', 'm.number_in_surah');
            })
            ->count();

        $q = DB::table('mushaf_ayahs as m')
            ->join('mushaf_ayah_to_ayah_map as map', 'map.mushaf_ayah_id', '=', 'm.id')
            ->join('ayahs as a', 'a.id', '=', 'map.ayah_id')
            ->select(
                'm.id',
                'm.surah_id',
                'm.number_in_surah',
                'm.text',
                'map.map_type',
                'a.surah_id as base_surah',
                'a.number_in_surah as base_ayah_no'
            )
            ->where('m.qiraat_reading_id', $qiraatId)
            ->where(function ($w) {
                $w->whereColumn('a.surah_id', '!=', 'm.surah_id')
                    ->orWhereColumn('a.number_in_surah', '!=', 'm.number_in_surah');
            })
            ->orderBy('m.id');

        $q->chunkById(2000, function ($rows) {
            foreach ($rows as $r) {
                $extra = "map_type={$r->map_type} | base={$r->base_surah}:{$r->base_ayah_no} | text=" . $this->preview($r->text);
                $this->reportRow((int) $r->id, (int) $r->surah_id, (int) $r->number_in_surah, 'mapping_conflict', $extra);
            }
        }, 'm.id', 'id');
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
