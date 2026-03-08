<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import tags and ayah_tags from SQL dump files (e.g. quran_banna_public_tags.sql,
 * quran_banna_public_ayah_tags.sql). Run tags first so ayah_tags foreign keys resolve.
 *
 * Use:
 *   php artisan tags:import-from-sql /path/to/tags.sql /path/to/ayah_tags.sql
 *   php artisan tags:import-from-sql ... --dry-run
 *
 * Default: upsert (insert or update on duplicate id). No duplicate key errors.
 */
class ImportTagsFromSql extends Command
{
    protected $signature = 'tags:import-from-sql
        {tags_sql : Path to tags SQL file (e.g. quran_banna_public_tags.sql)}
        {ayah_tags_sql : Path to ayah_tags SQL file (e.g. quran_banna_public_ayah_tags.sql)}
        {--dry-run : Only report file sizes and line counts, do not execute}
    ';

    protected $description = 'Import tags and ayah_tags from SQL dump files (upsert: insert or update on duplicate id).';

    public function handle(): int
    {
        $tagsPath = $this->argument('tags_sql');
        $ayahTagsPath = $this->argument('ayah_tags_sql');
        $dryRun = (bool) $this->option('dry-run');

        $tagsPath = $this->resolvePath($tagsPath);
        $ayahTagsPath = $this->resolvePath($ayahTagsPath);

        if (!$tagsPath || !is_readable($tagsPath)) {
            $this->error("Tags SQL file not found or not readable: " . $this->argument('tags_sql'));
            return self::FAILURE;
        }
        if (!$ayahTagsPath || !is_readable($ayahTagsPath)) {
            $this->error("Ayah tags SQL file not found or not readable: " . $this->argument('ayah_tags_sql'));
            return self::FAILURE;
        }

        $driver = DB::getDriverName();
        $this->info("DB driver: {$driver}");
        $this->info("Tags file: {$tagsPath}");
        $this->info("Ayah tags file: {$ayahTagsPath}");
        $this->info("Mode: upsert (insert or update on duplicate id).");

        if ($dryRun) {
            $tagsLines = $this->countInsertLines($tagsPath);
            $ayahLines = $this->countInsertLines($ayahTagsPath);
            $this->line("Tags file: ~{$tagsLines} INSERT lines.");
            $this->line("Ayah tags file: ~{$ayahLines} INSERT lines.");
            $this->info("Dry-run: no SQL executed.");
            return self::SUCCESS;
        }

        $this->line("Importing tags first...");
        $tagsOk = $this->runSqlFile($tagsPath, 'tags', $driver);
        if (!$tagsOk) {
            return self::FAILURE;
        }

        $this->line("Importing ayah_tags...");
        $ayahOk = $this->runSqlFile($ayahTagsPath, 'ayah_tags', $driver);
        if (!$ayahOk) {
            return self::FAILURE;
        }

        $this->info("Done.");
        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, '~')) {
            $path = getenv('HOME') . substr($path, 1);
        }
        return realpath($path) ?: $path;
    }

    private function countInsertLines(string $path): int
    {
        $n = 0;
        $fp = fopen($path, 'r');
        if (!$fp) return 0;
        while (($line = fgets($fp)) !== false) {
            if (stripos($line, 'INSERT INTO') !== false) $n++;
        }
        fclose($fp);
        return $n;
    }

    /**
     * Run SQL file: one INSERT per line, normalize table name and add upsert (ON CONFLICT DO UPDATE / ON DUPLICATE KEY UPDATE).
     */
    private function runSqlFile(string $path, string $tableLabel, string $driver): bool
    {
        $fp = fopen($path, 'r');
        if (!$fp) {
            $this->error("Could not open: {$path}");
            return false;
        }

        $inserted = 0;
        $skipped = 0;
        $errors = 0;

        while (($line = fgets($fp)) !== false) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'INSERT INTO') === false) {
                continue;
            }

            $sql = $this->prepareInsertLine($line, $tableLabel, $driver);
            if ($sql === null) {
                $skipped++;
                continue;
            }

            try {
                DB::unprepared($sql);
                $inserted++;
                if ($inserted % 500 === 0) {
                    $this->line("  {$tableLabel}: {$inserted} rows...");
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->error("  Error at line: " . substr($line, 0, 80) . "...");
                $this->error("  " . $e->getMessage());
                if ($errors >= 5) {
                    $this->error("Too many errors, stopping.");
                    fclose($fp);
                    return false;
                }
            }
        }

        fclose($fp);
        $this->line("  {$tableLabel}: {$inserted} inserted, {$skipped} skipped, {$errors} errors.");
        return $errors === 0;
    }

    /**
     * Normalize table name (public.tags -> tags), inject generated_by for tags, and add upsert (ON CONFLICT DO UPDATE / ON DUPLICATE KEY UPDATE).
     */
    private function prepareInsertLine(string $line, string $tableLabel, string $driver): ?string
    {
        $sql = str_replace(['INSERT INTO public.tags ', 'INSERT INTO public.ayah_tags '], ['INSERT INTO tags ', 'INSERT INTO ayah_tags '], $line);
        if ($sql === $line) {
            $sql = preg_replace('/INSERT INTO\s+public\.(\w+)\s+/i', 'INSERT INTO $1 ', $line);
        }

        if ($tableLabel === 'tags') {
            $sql = $this->injectGeneratedByForTags($sql);
        }

        $sql = $this->appendUpsert($sql, $tableLabel, $driver);

        return $sql;
    }

    /**
     * Append ON CONFLICT DO UPDATE (pgsql) or ON DUPLICATE KEY UPDATE (mysql) so insert becomes upsert.
     */
    private function appendUpsert(string $sql, string $tableLabel, string $driver): string
    {
        $sql = rtrim($sql, " \t\n\r;");

        if ($driver === 'pgsql') {
            if ($tableLabel === 'tags') {
                $sql .= " ON CONFLICT (id) DO UPDATE SET parent_id = EXCLUDED.parent_id, name = EXCLUDED.name, created_by = EXCLUDED.created_by, updated_by = EXCLUDED.updated_by, generated_by = EXCLUDED.generated_by, created_at = EXCLUDED.created_at, updated_at = EXCLUDED.updated_at";
            } else {
                $sql .= " ON CONFLICT (id) DO UPDATE SET tag_id = EXCLUDED.tag_id, ayah_id = EXCLUDED.ayah_id, notes = EXCLUDED.notes, created_by = EXCLUDED.created_by, updated_by = EXCLUDED.updated_by, approved_by = EXCLUDED.approved_by, approved_at = EXCLUDED.approved_at, created_at = EXCLUDED.created_at, updated_at = EXCLUDED.updated_at";
            }
            $sql .= ';';
        } elseif ($driver === 'mysql') {
            if ($tableLabel === 'tags') {
                $sql .= " ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), name = VALUES(name), created_by = VALUES(created_by), updated_by = VALUES(updated_by), generated_by = VALUES(generated_by), created_at = VALUES(created_at), updated_at = VALUES(updated_at)";
            } else {
                $sql .= " ON DUPLICATE KEY UPDATE tag_id = VALUES(tag_id), ayah_id = VALUES(ayah_id), notes = VALUES(notes), created_by = VALUES(created_by), updated_by = VALUES(updated_by), approved_by = VALUES(approved_by), approved_at = VALUES(approved_at), created_at = VALUES(created_at), updated_at = VALUES(updated_at)";
            }
            $sql .= ';';
        }

        return $sql;
    }

    /**
     * Add generated_by column and value 'human' to tags INSERT (dump has no generated_by).
     * Column list: ..., updated_by, created_at, updated_at) -> ..., updated_by, generated_by, created_at, updated_at)
     * Values: ..., <updated_by>, 'created_at', 'updated_at') -> ..., <updated_by>, 'human', 'created_at', 'updated_at')
     * Uses greedy .* so we match the value immediately before the two timestamps (updated_by).
     */
    private function injectGeneratedByForTags(string $sql): string
    {
        $sql = preg_replace('/\bupdated_by,\s*created_at,\s*updated_at\s*\)/i', 'updated_by, generated_by, created_at, updated_at)', $sql);
        $sql = preg_replace(
            "/^(.*)(, )(null|\d+)(, '\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d+', '\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d+'\);\s*)$/",
            '$1$2$3, \'human\'$4',
            $sql
        );
        return $sql;
    }
}
