<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

/**
 * Run an Artisan command and return exit code. Surfaces exceptions so nothing fails silently.
 */
function runCmd($self, string $title, string $command, array $params = [], bool $failFast = true): int
{
    $self->info($title);

    try {
        $code = Artisan::call($command, $params);
    } catch (\Throwable $e) {
        $self->error("❌ Command threw: {$command}");
        $self->error($e->getMessage());
        $self->line($e->getFile() . ':' . $e->getLine());
        if ($failFast) {
            throw $e;
        }
        return 1;
    }

    $out = trim(Artisan::output());
    if ($out !== '') {
        $self->line($out);
    }

    if ($code !== 0 && $failFast) {
        $self->error("❌ Command failed (exit {$code}): {$command}");
    }

    return $code;
}

/** Run a step and run gc after to limit memory growth in long pipelines. */
function runCmdAndGc($self, string $title, string $command, array $params = [], bool $failFast = true): int
{
    $code = runCmd($self, $title, $command, $params, $failFast);
    gc_collect_cycles();
    return $code;
}

Artisan::command('process:mushaf-xml {--dry-run}', function () {
    try {
        ini_set('memory_limit', '512M');
        $dry = (bool) $this->option('dry-run');

        $xmlIds = [2, 3, 4, 5, 6, 7, 8];

        $autoMapOpts = [
            '--max-combined' => 6,
            '--max-split'    => 6,
            '--preclean'     => true,
            '--fast-exact'   => true,
            '--postclean'    => true,
            '--final-exact'  => true,
            '--dry-run'      => $dry,
        ];

        $repairOpts = [
            '--clean' => true,
            '--exact-number' => true,
            '--exact-pure-text'=> true,
            '--health-check' => true,
            '--fix-missing-3252' => true,
        ];

        $this->newLine();
        $this->info(str_repeat('=', 38));
        $this->info(" Mushaf XML Pipeline (per qiraat)");
        $this->info(" dry-run: " . ($dry ? 'YES' : 'NO'));
        $this->info(str_repeat('=', 38));
        $this->newLine();

        foreach ($xmlIds as $id) {
            $this->info(str_repeat('-', 38));
            $this->info("=== Qiraat {$id} ===");

            $code = runCmdAndGc(
                $this,
                "▶ [1/3] XML import (qiraat={$id})",
                'qiraat:import-mushaf-ayahs-xml',
                ['qiraat_reading_id' => $id, '--dry-run' => $dry]
            );
            if ($code !== 0) return $code;

            $code = runCmdAndGc(
                $this,
                "▶ [2/3] Auto-map by text (qiraat={$id})",
                'qiraat:auto-map-by-text',
                array_merge(['qiraat_reading_id' => $id], $autoMapOpts)
            );
            if ($code !== 0) return $code;

            $code = runCmdAndGc(
                $this,
                "▶ [3/3] Repair + exact map (qiraat={$id})",
                'qiraat:repair-and-exact-map',
                array_merge(['qiraat_reading_id' => $id], $repairOpts)
            );
            if ($code !== 0) return $code;
        }

        $this->newLine();
        $this->info("✅ process:mushaf-xml completed successfully.");
        return 0;
    } catch (\Throwable $e) {
        $this->error('❌ process:mushaf-xml failed: ' . $e->getMessage());
        $this->line($e->getFile() . ':' . $e->getLine());
        return 1;
    }
})->purpose('XML only: import each qiraat, then auto-map, then repair (repeat per qiraat).');

Artisan::command('process:mushaf-excel {--dry-run}', function () {
    try {
        ini_set('memory_limit', '512M');
        $dry = (bool) $this->option('dry-run');

        $excelIds = range(10, 20);

        $autoMapOpts = [
            '--max-combined'   => 6,
            '--max-split'      => 6,
            '--preclean'       => true,
            '--fast-exact'     => true,
            '--postclean'      => true,
            '--final-exact'    => true,
            '--only-unmapped'  => true,
            '--dry-run'        => $dry,
        ];

        $this->newLine();
        $this->info(str_repeat('=', 38));
        $this->info(" Mushaf Excel Pipeline");
        $this->info(" qiraat ids: " . implode(', ', $excelIds));
        $this->info(" dry-run: " . ($dry ? 'YES' : 'NO'));
        $this->info(str_repeat('=', 38));
        $this->newLine();

        foreach ($excelIds as $id) {
            $this->info(str_repeat('-', 38));
            $this->info("=== Qiraat {$id} ===");

            $code = runCmdAndGc(
                $this,
                "▶ [1/3] Excel import (qiraat={$id})",
                'qiraat:import-mushaf-ayahs-excel',
                ['qiraat_reading_id' => $id, '--dry-run' => $dry]
            );
            if ($code !== 0) return $code;

            $code = runCmdAndGc(
                $this,
                "▶ [2/3] Auto-map by text (qiraat={$id}, only-unmapped)",
                'qiraat:auto-map-by-text',
                array_merge(['qiraat_reading_id' => $id], $autoMapOpts)
            );
            if ($code !== 0) return $code;

            $code = runCmdAndGc(
                $this,
                "▶ [3/3] Repair + exact map (qiraat={$id})",
                'qiraat:repair-and-exact-map',
                ['qiraat_reading_id' => $id, '--dry-run' => $dry]
            );
            if ($code !== 0) return $code;
        }

        $this->newLine();
        $this->info("✅ process:mushaf-excel completed successfully.");
        return 0;
    } catch (\Throwable $e) {
        $this->error('❌ process:mushaf-excel failed: ' . $e->getMessage());
        $this->line($e->getFile() . ':' . $e->getLine());
        return 1;
    }
})->purpose('Excel only: import then auto-map then repair (per qiraat id 10–20).');

Artisan::command('process:mushaf-words-map {--dry-run}', function () {
    try {
        ini_set('memory_limit', '512M');
        $dry = (bool) $this->option('dry-run');

        $ids = range(2, 20);

        $wordMapOpts = [
            '--max-combined-words' => 6,
            '--max-split-words'    => 6,
            '--preclean'           => true,
            '--postclean'          => true,
            '--only-unmapped'      => true,
            '--dry-run'            => $dry,
        ];

        $this->newLine();
        $this->info(str_repeat('=', 38));
        $this->info(" Mushaf words map (qiraat 2–20)");
        $this->info(" dry-run: " . ($dry ? 'YES' : 'NO'));
        $this->info(str_repeat('=', 38));
        $this->newLine();

        $repairOpts = [
            '--clear'  => true,
            '--remap'  => true,
            '--dry-run' => $dry,
        ];

        foreach ($ids as $id) {
            $this->info(str_repeat('-', 38));
            $this->info("=== Qiraat {$id} ===");

            $code = runCmdAndGc(
                $this,
                "▶ [1/2] Auto-map words (qiraat={$id})",
                'qiraat:auto-map-words',
                array_merge(['qiraat_reading_id' => $id], $wordMapOpts)
            );
            if ($code !== 0) return $code;

            $code = runCmdAndGc(
                $this,
                "▶ [2/2] Repair word maps (qiraat={$id})",
                'qiraat:repair-word-maps',
                array_merge(['qiraat_reading_id' => $id], $repairOpts)
            );
            if ($code !== 0) return $code;
        }

        $this->newLine();
        $this->info("✅ process:mushaf-words-map completed successfully.");
        return 0;
    } catch (\Throwable $e) {
        $this->error('❌ process:mushaf-words-map failed: ' . $e->getMessage());
        $this->line($e->getFile() . ':' . $e->getLine());
        return 1;
    }
})->purpose('Auto-map mushaf words then repair (clear failed + remap) for qiraat ids 2–20.');

Artisan::command('generate:mushaf-template', function () {
    try {
        ini_set('memory_limit', '512M');
        $steps = [
            'process:ayahs-mushaf',
            'generate:pure-words-mushaf',
            'generate:word-html-mushaf',
            'generate:pure-ayahs-mushaf',
            'generate:ayah-html-mushaf',
        ];

        $this->newLine();
        $this->info("=== Mushaf Template Generation ===");

        foreach ($steps as $cmd) {
            $this->info("▶ Running {$cmd}");
            $code = $this->call($cmd);
            gc_collect_cycles();

            if ($code !== 0) {
                $this->error("❌ Failed at {$cmd} (exit {$code}), aborting.");
                return $code;
            }
        }

        $this->info('✅ All mushaf generation steps completed.');
        return 0;
    } catch (\Throwable $e) {
        $this->error('❌ generate:mushaf-template failed: ' . $e->getMessage());
        $this->line($e->getFile() . ':' . $e->getLine());
        return 1;
    }
});
