<?php

namespace App\Console\Commands\MushafAyahs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Qiraat repair + exact mapping (SQL-based)
 *
 * Steps (optional via flags):
 *  1) Fix numbering drift safely:
 *     - clamp number_in_surah to base max only if it won't violate mushaf unique constraint
 *     - otherwise set number_in_surah to NULL
 *  2) Cleanup map table:
 *     - delete mixed map_type per mushaf_ayah_id
 *     - delete broken combined groups
 *     - delete broken split groups
 *  3) Fast exact mapping:
 *     - by (surah_id, number_in_surah) using base_pick MIN(id) + exact/exact_base_dup
 *  4) Safe exact mapping:
 *     - by pure_text equality (still-unmapped only)
 *  5) Health checks:
 *     - remaining unmapped
 *     - remaining bad combined groups
 *     - remaining bad split groups
 *
 * Use:
 *  php artisan qiraat:repair-and-exact-map 2 --fix-numbering --clean --exact-number --exact-pure-text --health-check
 *  php artisan qiraat:repair-and-exact-map auto --fix-numbering --clean --exact-number --exact-pure-text --health-check
 */
class QiraatRepairAndExactMap extends Command
{
    protected $signature = 'qiraat:repair-and-exact-map
        {qiraat_reading_id : qiraat_readings.id OR "auto" to run for all qiraat present in mushaf_ayahs}
        {--dry-run : Do not write anything (wraps everything in a transaction and rolls back)}
        {--fix-numbering : Fix numbering drift (clamp-to-max-if-safe else NULL)}
        {--clean : Cleanup mixed/broken combined/split groups in mushaf_ayah_to_ayah_map}
        {--exact-number : Fast exact fill by (surah_id, number_in_surah) for unmapped}
        {--exact-pure-text : Exact fill by pure_text equality (still-unmapped only)}
        {--fuzzy-text : Fuzzy similarity pass for still-unmapped (handles numbering offsets and orthographic variants like superscript alef)}
        {--fuzzy-min-score=70 : Minimum similar_text() percentage to accept a fuzzy match (0-100)}
        {--fuzzy-window=8 : Search ±N positions in the same surah when fuzzy matching}
        {--health-check : Print remaining_unmapped + bad group counts at the end}
        {--fix-missing-3252 : Force-map base ayah_id=3252 by text for still-unmapped mushaf rows (ignores number_in_surah)}
    ';

    protected $description = 'Repairs mushaf numbering drift, cleans broken mapping groups, and performs SQL exact mapping passes (number + pure_text + fuzzy).';

    public function handle(): int
    {
        $arg = (string) $this->argument('qiraat_reading_id');
        $dry = (bool) $this->option('dry-run');

        $doFix       = (bool) $this->option('fix-numbering');
        $doClean     = (bool) $this->option('clean');
        $doExactNo   = (bool) $this->option('exact-number');
        $doExactText = (bool) $this->option('exact-pure-text');
        $doFuzzy     = (bool) $this->option('fuzzy-text');
        $doHealth    = (bool) $this->option('health-check');

        // Default behavior if user ran without flags: do everything.
        if (!$doFix && !$doClean && !$doExactNo && !$doExactText && !$doFuzzy && !$doHealth) {
            $doFix = $doClean = $doExactNo = $doExactText = $doFuzzy = $doHealth = true;
        }

        $qiraatIds = [];
        if (strtolower($arg) === 'auto') {
            $qiraatIds = DB::table('mushaf_ayahs')->distinct()->pluck('qiraat_reading_id')->map(fn($x) => (int)$x)->all();
            if (empty($qiraatIds)) {
                $this->warn("No qiraat_reading_id found in mushaf_ayahs.");
                return self::SUCCESS;
            }
        } else {
            $qiraatIds = [(int) $arg];
        }

        $fuzzyMin    = max(1, min(100, (int) ($this->option('fuzzy-min-score') ?? 70)));
        $fuzzyWindow = max(1, (int) ($this->option('fuzzy-window') ?? 8));

        foreach ($qiraatIds as $qid) {
            $ok = $this->runForOne($qid, $dry, $doFix, $doClean, $doExactNo, $doExactText, $doFuzzy, $fuzzyMin, $fuzzyWindow, $doHealth);
            if (!$ok) return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function runForOne(
        int $qiraatId,
        bool $dry,
        bool $doFix,
        bool $doClean,
        bool $doExactNo,
        bool $doExactText,
        bool $doFuzzy,
        int $fuzzyMin,
        int $fuzzyWindow,
        bool $doHealth,
    ): bool {
        // validate qiraat exists if you want; optional:
        if (!DB::table('qiraat_readings')->where('id', $qiraatId)->exists()) {
            $this->error("Qiraat ID {$qiraatId} not found in qiraat_readings.");
            return false;
        }

        $this->newLine();
        $this->info("=== qiraat={$qiraatId} | " . ($dry ? "DRY-RUN" : "WRITE") . " ===");

        $stats = [
            'fixed_numbers_safe'  => 0,
            'fixed_numbers_null'  => 0,
            'deleted_mixed'       => 0,
            'deleted_bad_combined'=> 0,
            'deleted_bad_split'   => 0,
            'ins_by_number'       => 0,
            'ins_by_pure_text'    => 0,
            'ins_by_fuzzy'        => 0,
            'ins_missing_3252'    => 0,
        ];

        try {
            DB::transaction(function () use (
                $qiraatId, $dry, $doFix, $doClean, $doExactNo, $doExactText,
                $doFuzzy, $fuzzyMin, $fuzzyWindow, &$stats
            ) {
                if ($doFix) {
                    $this->line("1) Fix numbering drift (safe clamp / null) ...");
                    [$safe, $nulled] = $this->fixNumberingDrift($qiraatId);
                    $stats['fixed_numbers_safe'] = $safe;
                    $stats['fixed_numbers_null'] = $nulled;
                }

                if ($doClean) {
                    $this->line("2) Cleanup mixed/broken groups ...");
                    [$mix, $badC, $badS] = $this->cleanupMappings($qiraatId);
                    $stats['deleted_mixed']        = $mix;
                    $stats['deleted_bad_combined'] = $badC;
                    $stats['deleted_bad_split']    = $badS;
                }

                if ($doExactNo) {
                    $this->line("3) Fast exact by number_in_surah ...");
                    $stats['ins_by_number'] = $this->exactFillByNumber($qiraatId);
                }

                if ($doExactText) {
                    $this->line("4) Exact fill by pure_text ...");
                    $stats['ins_by_pure_text'] = $this->exactFillByPureText($qiraatId);
                }

                if ($doFuzzy) {
                    $this->line("5) Fuzzy similarity match (window=±{$fuzzyWindow}, min={$fuzzyMin}%) ...");
                    $stats['ins_by_fuzzy'] = $this->fuzzyTextMatch($qiraatId, $fuzzyMin, $fuzzyWindow);
                }

                if ($dry) {
                    throw new \RuntimeException("__DRY_RUN_ROLLBACK__");
                }
            });

        } catch (\RuntimeException $e) {
            if ($dry && $e->getMessage() === "__DRY_RUN_ROLLBACK__") {
                $this->warn("Dry-run: rolled back (no changes written).");
            } else {
                $this->error("Failed qiraat={$qiraatId}: " . $e->getMessage());
                return false;
            }
        } catch (\Throwable $e) {
            $this->error("Failed qiraat={$qiraatId}: " . $e->getMessage());
            return false;
        }

        $this->line("Done steps for qiraat={$qiraatId}");
        $this->table(
            ['metric', 'count'],
            collect($stats)->map(fn($v, $k) => ['metric' => $k, 'count' => (string)$v])->values()->all()
        );

        if ($doHealth) {
            $this->line("6) Health checks ...");
            $health = $this->healthChecks($qiraatId);
            $this->table(
                ['check', 'count'],
                [
                    ['remaining_unmapped', (string) $health['remaining_unmapped']],
                    ['bad_combined_groups', (string) $health['bad_combined_groups']],
                    ['bad_split_groups', (string) $health['bad_split_groups']],
                ]
            );
        }

        return true;
    }

    /**
     * Step 1: Fix numbering drift safely (scoped to qiraat).
     */
    private function fixNumberingDrift(int $qiraatId): array
    {
        // 1A) safe clamp (won't violate mushaf unique constraint)
        $sqlSafe = "
WITH base_counts AS (
    SELECT surah_id, MAX(number_in_surah) AS base_max_no
    FROM ayahs
    GROUP BY surah_id
)
UPDATE mushaf_ayahs ma
SET number_in_surah = bc.base_max_no
FROM base_counts bc
WHERE ma.qiraat_reading_id = ?
  AND ma.surah_id = bc.surah_id
  AND ma.number_in_surah IS NOT NULL
  AND ma.number_in_surah > bc.base_max_no
  AND NOT EXISTS (
      SELECT 1
      FROM mushaf_ayahs ma2
      WHERE ma2.qiraat_reading_id = ma.qiraat_reading_id
        AND ma2.surah_id = ma.surah_id
        AND ma2.number_in_surah = bc.base_max_no
        AND ma2.id <> ma.id
  )
        ";

        $safe = (int) DB::affectingStatement($sqlSafe, [$qiraatId]);

        // 1B) any remaining drift -> null (still scoped to qiraat)
        $sqlNull = "
WITH base_counts AS (
    SELECT surah_id, MAX(number_in_surah) AS base_max_no
    FROM ayahs
    GROUP BY surah_id
)
UPDATE mushaf_ayahs ma
SET number_in_surah = NULL
FROM base_counts bc
WHERE ma.qiraat_reading_id = ?
  AND ma.surah_id = bc.surah_id
  AND ma.number_in_surah IS NOT NULL
  AND ma.number_in_surah > bc.base_max_no
        ";

        $nulled = (int) DB::affectingStatement($sqlNull, [$qiraatId]);

        return [$safe, $nulled];
    }

    /**
     * Step 2: Cleanup mixed / broken combined / broken split (scoped to qiraat via join to mushaf_ayahs).
     */
    private function cleanupMappings(int $qiraatId): array
    {
        // mixed map types per mushaf_ayah_id
        $sqlMixed = "
DELETE FROM mushaf_ayah_to_ayah_map m
USING (
    SELECT m2.mushaf_ayah_id
    FROM mushaf_ayah_to_ayah_map m2
    JOIN mushaf_ayahs ma ON ma.id = m2.mushaf_ayah_id
    WHERE ma.qiraat_reading_id = ?
    GROUP BY m2.mushaf_ayah_id
    HAVING COUNT(DISTINCT m2.map_type) > 1
) x
WHERE m.mushaf_ayah_id = x.mushaf_ayah_id
        ";
        $mixed = (int) DB::affectingStatement($sqlMixed, [$qiraatId]);

        // broken combined groups
        $sqlBadCombined = "
DELETE FROM mushaf_ayah_to_ayah_map m
USING (
    SELECT m2.mushaf_ayah_id
    FROM mushaf_ayah_to_ayah_map m2
    JOIN mushaf_ayahs ma ON ma.id = m2.mushaf_ayah_id
    WHERE ma.qiraat_reading_id = ?
      AND m2.map_type = 'combined'
    GROUP BY m2.mushaf_ayah_id
    HAVING
        COUNT(*) <> MAX(m2.parts_total)
        OR MIN(m2.ayah_order) <> 1
        OR MAX(m2.ayah_order) <> MAX(m2.parts_total)
        OR COUNT(DISTINCT m2.ayah_order) <> COUNT(*)
) bad
WHERE m.mushaf_ayah_id = bad.mushaf_ayah_id
        ";
        $badC = (int) DB::affectingStatement($sqlBadCombined, [$qiraatId]);

        // broken split groups (grouped by base ayah_id)
        $sqlBadSplit = "
DELETE FROM mushaf_ayah_to_ayah_map m
USING (
    SELECT m2.ayah_id
    FROM mushaf_ayah_to_ayah_map m2
    JOIN mushaf_ayahs ma ON ma.id = m2.mushaf_ayah_id
    WHERE ma.qiraat_reading_id = ?
      AND m2.map_type = 'split'
    GROUP BY m2.ayah_id
    HAVING
        COUNT(*) <> MAX(m2.parts_total)
        OR MIN(m2.part_no) <> 1
        OR MAX(m2.part_no) <> MAX(m2.parts_total)
        OR COUNT(DISTINCT m2.part_no) <> COUNT(*)
) bad
WHERE m.map_type = 'split'
  AND m.ayah_id = bad.ayah_id
        ";
        $badS = (int) DB::affectingStatement($sqlBadSplit, [$qiraatId]);

        return [$mixed, $badC, $badS];
    }

    /**
     * Step 3: fast exact fill by (surah_id, number_in_surah) for unmapped only.
     */
    private function exactFillByNumber(int $qiraatId): int
    {
        $sql = "
WITH base_pick AS (
    SELECT surah_id, number_in_surah, MIN(id) AS ayah_id, COUNT(*) AS c
    FROM ayahs
    GROUP BY surah_id, number_in_surah
),
unmapped AS (
    SELECT ma.id, ma.surah_id, ma.number_in_surah
    FROM mushaf_ayahs ma
    WHERE ma.qiraat_reading_id = ?
      AND ma.surah_id IS NOT NULL
      AND ma.number_in_surah IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM mushaf_ayah_to_ayah_map map WHERE map.mushaf_ayah_id = ma.id
      )
)
INSERT INTO mushaf_ayah_to_ayah_map
(mushaf_ayah_id, ayah_id, map_type, part_no, parts_total, ayah_order, created_at, updated_at)
SELECT
    u.id,
    bp.ayah_id,
    CASE WHEN bp.c = 1 THEN 'exact' ELSE 'exact_base_dup' END,
    NULL, NULL, NULL,
    NOW(), NOW()
FROM unmapped u
JOIN base_pick bp
  ON bp.surah_id = u.surah_id
 AND bp.number_in_surah = u.number_in_surah
ON CONFLICT (mushaf_ayah_id, ayah_id)
DO UPDATE SET
    map_type    = EXCLUDED.map_type,
    part_no     = NULL,
    parts_total = NULL,
    ayah_order  = NULL,
    updated_at  = EXCLUDED.updated_at
        ";

        return (int) DB::affectingStatement($sql, [$qiraatId]);
    }

    /**
     * Step 4: exact fill by pure_text equality for still-unmapped.
     */
    private function exactFillByPureText(int $qiraatId): int
    {
        $sql = "
WITH still_unmapped AS (
    SELECT ma.id, ma.surah_id, ma.number_in_surah, ma.pure_text
    FROM mushaf_ayahs ma
    WHERE ma.qiraat_reading_id = ?
      AND NOT EXISTS (
          SELECT 1 FROM mushaf_ayah_to_ayah_map map WHERE map.mushaf_ayah_id = ma.id
      )
)
INSERT INTO mushaf_ayah_to_ayah_map
(mushaf_ayah_id, ayah_id, map_type, part_no, parts_total, ayah_order, created_at, updated_at)
SELECT
    su.id,
    a.id,
    'exact',
    NULL, NULL, NULL,
    NOW(), NOW()
FROM still_unmapped su
JOIN ayahs a
  ON a.surah_id = su.surah_id
 AND a.number_in_surah = su.number_in_surah
WHERE COALESCE(su.pure_text,'') <> ''
  AND COALESCE(a.pure_text,'') <> ''
  AND (
    replace(replace(replace(replace(replace(replace(su.pure_text,
      'ے','ي'),
      'ې','ي'),
      'ی','ي'),
      'ى','ي'),
      'ک','ك'),
      'ڪ','ك')
    =
    replace(replace(replace(replace(replace(replace(a.pure_text,
      'ے','ي'),
      'ې','ي'),
      'ی','ي'),
      'ى','ي'),
      'ک','ك'),
      'ڪ','ك')
  )
ON CONFLICT (mushaf_ayah_id, ayah_id)
DO UPDATE SET
    map_type    = EXCLUDED.map_type,
    part_no     = NULL,
    parts_total = NULL,
    ayah_order  = NULL,
    updated_at  = EXCLUDED.updated_at
    ";

        return (int) DB::affectingStatement($sql, [$qiraatId]);
    }

    /**
     * Step 5: Fuzzy similarity matching for still-unmapped ayahs.
     *
     * Handles cases the SQL passes cannot:
     *  - Numbering offsets (Warsh 55:17 → Hafs 55:19): searches ±window positions
     *  - Orthographic alef variants (يَٰنِ vs يَانِ): pure_text strips U+0670 but keeps ا,
     *    so normalized forms differ; similar_text() bridges that gap
     *  - Split detection: consecutive mushaf pair that together cover one base ayah
     *  - Combined detection: single mushaf ayah that covers 2-3 consecutive base ayahs
     *
     * Returns the number of map rows inserted.
     */
    private function fuzzyTextMatch(int $qiraatId, int $minScore, int $window): int
    {
        // Load still-unmapped mushaf ayahs with a valid pure_text
        $unmapped = DB::table('mushaf_ayahs as ma')
            ->leftJoin('mushaf_ayah_to_ayah_map as m', 'm.mushaf_ayah_id', '=', 'ma.id')
            ->where('ma.qiraat_reading_id', $qiraatId)
            ->whereNull('m.id')
            ->whereNotNull('ma.pure_text')
            ->where('ma.pure_text', '<>', '')
            ->select('ma.id', 'ma.surah_id', 'ma.number_in_surah', 'ma.pure_text')
            ->orderBy('ma.surah_id')->orderBy('ma.number_in_surah')
            ->get();

        if ($unmapped->isEmpty()) return 0;

        // Load all base ayahs, keyed by surah_id then number_in_surah
        $baseFlat = DB::table('ayahs')
            ->whereNotNull('pure_text')
            ->where('pure_text', '<>', '')
            ->select('id', 'surah_id', 'number_in_surah', 'pure_text')
            ->get();

        $baseBySurahNum = [];
        foreach ($baseFlat as $b) {
            $baseBySurahNum[$b->surah_id][$b->number_in_surah] = $b;
        }

        // Group unmapped by surah, ordered by number_in_surah
        $bySurah = [];
        foreach ($unmapped as $ma) {
            $bySurah[$ma->surah_id][] = $ma;
        }

        $toInsert = [];
        $claimed  = []; // mushaf_ayah_id => true  (prevents double-claiming in split)
        $now = now();

        foreach ($bySurah as $surahId => $surahList) {
            $baseByNum = $baseBySurahNum[$surahId] ?? [];
            if (empty($baseByNum)) continue;

            $count = count($surahList);

            for ($i = 0; $i < $count; $i++) {
                $ma = $surahList[$i];
                if (isset($claimed[$ma->id])) continue;

                $mPure = (string) $ma->pure_text;
                $mNum  = (int) ($ma->number_in_surah ?? 0);

                // Candidate base ayahs within the search window
                $candidates = [];
                for ($n = max(1, $mNum - $window); $n <= $mNum + $window; $n++) {
                    if (isset($baseByNum[$n])) {
                        $candidates[] = $baseByNum[$n];
                    }
                }
                if (empty($candidates)) continue;

                // --- A) Split detection: this + next consecutive unmapped → single base ---
                $next = $surahList[$i + 1] ?? null;
                if (
                    $next &&
                    !isset($claimed[$next->id]) &&
                    (int) $next->number_in_surah === $mNum + 1
                ) {
                    $combinedMushaf = $mPure . ' ' . (string) $next->pure_text;

                    $bestSplit = 0.0;
                    $bestSplitBase = null;
                    foreach ($candidates as $c) {
                        similar_text($combinedMushaf, (string) $c->pure_text, $pct);
                        if ($pct > $bestSplit) {
                            $bestSplit     = $pct;
                            $bestSplitBase = $c;
                        }
                    }

                    // Individual best scores (to confirm split beats them)
                    $bestIndiv1 = 0.0;
                    $bestIndiv2 = 0.0;
                    foreach ($candidates as $c) {
                        similar_text($mPure, (string) $c->pure_text, $p1);
                        similar_text((string) $next->pure_text, (string) $c->pure_text, $p2);
                        $bestIndiv1 = max($bestIndiv1, $p1);
                        $bestIndiv2 = max($bestIndiv2, $p2);
                    }

                    if (
                        $bestSplit >= $minScore &&
                        $bestSplit > max($bestIndiv1, $bestIndiv2) + 15 &&
                        $bestSplitBase !== null
                    ) {
                        $baseId = $bestSplitBase->id;
                        $toInsert[] = [
                            'mushaf_ayah_id' => $ma->id,
                            'ayah_id'        => $baseId,
                            'map_type'       => 'split',
                            'part_no'        => 1,
                            'parts_total'    => 2,
                            'ayah_order'     => null,
                            'created_at'     => $now,
                            'updated_at'     => $now,
                        ];
                        $toInsert[] = [
                            'mushaf_ayah_id' => $next->id,
                            'ayah_id'        => $baseId,
                            'map_type'       => 'split',
                            'part_no'        => 2,
                            'parts_total'    => 2,
                            'ayah_order'     => null,
                            'created_at'     => $now,
                            'updated_at'     => $now,
                        ];
                        $claimed[$ma->id]   = true;
                        $claimed[$next->id] = true;
                        $i++; // skip next
                        continue;
                    }
                }

                // --- B) Combined detection: single mushaf → 2 or 3 consecutive base ---
                $bestCombined = 0.0;
                $bestCombinedBases = null;
                foreach ($candidates as $c) {
                    $cNum = (int) $c->number_in_surah;

                    // Try pairs
                    $b2 = $baseByNum[$cNum + 1] ?? null;
                    if ($b2) {
                        $joined2 = (string) $c->pure_text . ' ' . (string) $b2->pure_text;
                        similar_text($mPure, $joined2, $pct2);
                        if ($pct2 > $bestCombined) {
                            $bestCombined      = $pct2;
                            $bestCombinedBases = [$c, $b2];
                        }

                        // Try triples
                        $b3 = $baseByNum[$cNum + 2] ?? null;
                        if ($b3) {
                            $joined3 = $joined2 . ' ' . (string) $b3->pure_text;
                            similar_text($mPure, $joined3, $pct3);
                            if ($pct3 > $bestCombined) {
                                $bestCombined      = $pct3;
                                $bestCombinedBases = [$c, $b2, $b3];
                            }
                        }
                    }
                }

                // --- C) Best single exact match ---
                $bestSingle = 0.0;
                $bestSingleBase = null;
                foreach ($candidates as $c) {
                    similar_text($mPure, (string) $c->pure_text, $pct);
                    if ($pct > $bestSingle) {
                        $bestSingle     = $pct;
                        $bestSingleBase = $c;
                    }
                }

                // --- Decide ---
                if (
                    $bestCombined >= $minScore &&
                    $bestCombinedBases !== null &&
                    $bestCombined > $bestSingle + 10
                ) {
                    // Combined: one mushaf ayah covers multiple consecutive base ayahs
                    foreach ($bestCombinedBases as $order => $baseRow) {
                        $toInsert[] = [
                            'mushaf_ayah_id' => $ma->id,
                            'ayah_id'        => $baseRow->id,
                            'map_type'       => 'combined',
                            'part_no'        => null,
                            'parts_total'    => null,
                            'ayah_order'     => $order + 1,
                            'created_at'     => $now,
                            'updated_at'     => $now,
                        ];
                    }
                    $claimed[$ma->id] = true;
                } elseif ($bestSingle >= $minScore && $bestSingleBase !== null) {
                    // Exact (fuzzy) single match
                    $toInsert[] = [
                        'mushaf_ayah_id' => $ma->id,
                        'ayah_id'        => $bestSingleBase->id,
                        'map_type'       => 'exact',
                        'part_no'        => null,
                        'parts_total'    => null,
                        'ayah_order'     => null,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ];
                    $claimed[$ma->id] = true;
                }
            }
        }

        if (empty($toInsert)) return 0;

        $inserted = 0;
        foreach (array_chunk($toInsert, 500) as $chunk) {
            $inserted += DB::table('mushaf_ayah_to_ayah_map')->insertOrIgnore($chunk);
        }

        return $inserted;
    }

    /**
     * Step 6: Health checks
     */
    private function healthChecks(int $qiraatId): array
    {
        $remaining = (int) DB::table('mushaf_ayahs as ma')
            ->where('ma.qiraat_reading_id', $qiraatId)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('mushaf_ayah_to_ayah_map as map')
                    ->whereColumn('map.mushaf_ayah_id', 'ma.id');
            })
            ->count();

        $badCombined = (int) DB::selectOne("
SELECT COUNT(*) AS c
FROM (
    SELECT m.mushaf_ayah_id
    FROM mushaf_ayah_to_ayah_map m
    JOIN mushaf_ayahs ma ON ma.id = m.mushaf_ayah_id
    WHERE ma.qiraat_reading_id = ?
      AND m.map_type='combined'
    GROUP BY m.mushaf_ayah_id
    HAVING
        COUNT(*) <> MAX(m.parts_total)
        OR MIN(m.ayah_order) <> 1
        OR MAX(m.ayah_order) <> MAX(m.parts_total)
        OR COUNT(DISTINCT m.ayah_order) <> COUNT(*)
) t
        ", [$qiraatId])->c ?? 0;

        $badSplit = (int) DB::selectOne("
SELECT COUNT(*) AS c
FROM (
    SELECT m.ayah_id
    FROM mushaf_ayah_to_ayah_map m
    JOIN mushaf_ayahs ma ON ma.id = m.mushaf_ayah_id
    WHERE ma.qiraat_reading_id = ?
      AND m.map_type='split'
    GROUP BY m.ayah_id
    HAVING
        COUNT(*) <> MAX(m.parts_total)
        OR MIN(m.part_no) <> 1
        OR MAX(m.part_no) <> MAX(m.parts_total)
        OR COUNT(DISTINCT m.part_no) <> COUNT(*)
) t
        ", [$qiraatId])->c ?? 0;

        return [
            'remaining_unmapped'   => $remaining,
            'bad_combined_groups'  => $badCombined,
            'bad_split_groups'     => $badSplit,
        ];
    }
}
