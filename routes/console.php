<?php

use App\Support\QiraatImportMaps;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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

/**
 * Resolve a user-supplied qiraat selector (stable code or numeric id) into a pipeline target.
 *
 * @return array{id:int, code:string, import_command:string, import_source:string, import_mode:string}|null
 */
function resolveQiraatPipelineTarget(string $reading): ?array
{
    $id = QiraatImportMaps::resolveReadingId($reading);
    if ($id === null) {
        return null;
    }

    $code = null;

    try {
        $row = DB::table('qiraat_readings')->where('id', $id)->first(['code', 'imam', 'riwaya']);
    } catch (\Throwable) {
        $row = DB::table('qiraat_readings')->where('id', $id)->first(['imam', 'riwaya']);
    }

    if ($row && isset($row->code) && is_string($row->code) && $row->code !== '') {
        $code = $row->code;
    }

    if ($code === null && $row) {
        foreach (QiraatImportMaps::readingDefinitions() as $definition) {
            $imamEn = data_get($row, 'imam.en');
            $riwayaEn = data_get($row, 'riwaya.en');

            if ($imamEn === $definition['imam']['en'] && $riwayaEn === $definition['riwaya']['en']) {
                $code = $definition['code'];
                break;
            }
        }
    }

    if ($code === null) {
        return null;
    }

    $xmlMap = QiraatImportMaps::xmlByReadingCode();
    if (array_key_exists($code, $xmlMap)) {
        return [
            'id' => $id,
            'code' => $code,
            'import_command' => 'qiraat:import-mushaf-ayahs-xml',
            'import_source' => $xmlMap[$code],
            'import_mode' => 'xml',
        ];
    }

    $excelMap = QiraatImportMaps::mushafExcelByReadingCode();
    if (array_key_exists($code, $excelMap)) {
        return [
            'id' => $id,
            'code' => $code,
            'import_command' => 'qiraat:import-mushaf-ayahs-excel',
            'import_source' => $excelMap[$code],
            'import_mode' => 'excel',
        ];
    }

    return [
        'id' => $id,
        'code' => $code,
        'import_command' => '',
        'import_source' => '',
        'import_mode' => 'none',
    ];
}

Artisan::command('process:mushaf-xml {--dry-run}', function () {
    try {
        ini_set('memory_limit', '512M');
        $dry = (bool) $this->option('dry-run');

        $xmlIds = QiraatImportMaps::resolveReadingIdsForCodes(array_keys(QiraatImportMaps::xmlByReadingCode()));

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
            '--clean'           => true,
            '--exact-number'    => true,
            '--exact-pure-text' => true,
            '--fuzzy-text'      => true,
            '--health-check'    => true,
            '--fix-missing-3252'=> true,
        ];

        $this->newLine();
        $this->info(str_repeat('=', 38));
        $this->info(" Mushaf XML Pipeline (per qiraat)");
        $this->info(" dry-run: " . ($dry ? 'YES' : 'NO'));
        $this->info(str_repeat('=', 38));
        $this->newLine();

        foreach ($xmlIds as $code => $id) {
            $this->info(str_repeat('-', 38));
            $this->info("=== Qiraat {$code} (id={$id}) ===");

            $code = runCmdAndGc(
                $this,
                "▶ [1/3] XML import (qiraat={$id})",
                'qiraat:import-mushaf-ayahs-xml',
                ['qiraat_reading' => $id, '--dry-run' => $dry]
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

        $excelIds = QiraatImportMaps::resolveReadingIdsForCodes(array_keys(QiraatImportMaps::mushafExcelByReadingCode()));

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
        $this->info(" qiraat ids: " . implode(', ', array_values($excelIds)));
        $this->info(" dry-run: " . ($dry ? 'YES' : 'NO'));
        $this->info(str_repeat('=', 38));
        $this->newLine();

        foreach ($excelIds as $code => $id) {
            $this->info(str_repeat('-', 38));
            $this->info("=== Qiraat {$code} (id={$id}) ===");

            $code = runCmdAndGc(
                $this,
                "▶ [1/3] Excel import (qiraat={$id})",
                'qiraat:import-mushaf-ayahs-excel',
                ['qiraat_reading' => $id, '--dry-run' => $dry]
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

        $ids = array_values(QiraatImportMaps::resolveReadingIdsForCodes(QiraatImportMaps::nonBaseReadingCodes()));

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
            'generate:word-artifacts-mushaf',
            'generate:ayah-artifacts-mushaf',
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

Artisan::command('process:qiraat-mushaf {reading : qiraat_readings.id or stable code} {--dry-run}', function () {
    try {
        ini_set('memory_limit', '512M');

        $reading = trim((string) $this->argument('reading'));
        $dry = (bool) $this->option('dry-run');

        $target = resolveQiraatPipelineTarget($reading);
        if ($target === null) {
            $this->error("Unknown qiraat selection: {$reading}");
            return 1;
        }

        if ($target['import_mode'] === 'none') {
            $this->error(
                "No mushaf import source is configured for qiraat {$target['code']} (id={$target['id']})."
            );
            return 1;
        }

        $isExcel = $target['import_mode'] === 'excel';

        $autoMapOpts = [
            '--max-combined' => 6,
            '--max-split' => 6,
            '--preclean' => true,
            '--fast-exact' => true,
            '--postclean' => true,
            '--final-exact' => true,
            '--dry-run' => $dry,
        ];

        if ($isExcel) {
            $autoMapOpts['--only-unmapped'] = true;
        }

        $repairOpts = [
            'qiraat_reading_id' => $target['id'],
            '--dry-run'         => $dry,
            '--clean'           => true,
            '--exact-pure-text' => true,
            '--fuzzy-text'      => true,
            '--health-check'    => true,
        ];

        if (!$isExcel) {
            $repairOpts['--fix-missing-3252'] = true;
        }

        $wordMapOpts = [
            'qiraat_reading_id' => $target['id'],
            '--max-combined-words' => 6,
            '--max-split-words' => 6,
            '--preclean' => true,
            '--postclean' => true,
            '--dry-run' => $dry,
        ];

        $wordRepairOpts = [
            'qiraat_reading_id' => $target['id'],
            '--clear' => true,
            '--remap' => true,
            '--dry-run' => $dry,
        ];

        $this->newLine();
        $this->info(str_repeat('=', 46));
        $this->info(" Qiraat Mushaf Pipeline");
        $this->info(" reading: {$target['code']} (id={$target['id']})");
        $this->info(" import: {$target['import_mode']} -> {$target['import_source']}");
        $this->info(" dry-run: " . ($dry ? 'YES' : 'NO'));
        $this->info(str_repeat('=', 46));
        $this->newLine();

        $steps = [
            [
                'title' => "[1/8] Import mushaf ayahs ({$target['import_mode']})",
                'command' => $target['import_command'],
                'params' => ['qiraat_reading' => $target['id'], '--dry-run' => $dry],
            ],
            [
                'title' => '[2/8] Auto-map ayahs by text',
                'command' => 'qiraat:auto-map-by-text',
                'params' => array_merge(['qiraat_reading_id' => $target['id']], $autoMapOpts),
            ],
            [
                'title' => '[3/8] Repair and exact-map ayahs',
                'command' => 'qiraat:repair-and-exact-map',
                'params' => $repairOpts,
            ],
            [
                'title' => '[4/8] Extract mushaf words',
                'command' => 'process:ayahs-mushaf',
                'params' => ['--qiraat' => $target['id'], '--dry-run' => $dry],
            ],
            [
                'title' => '[5/8] Generate word artifacts',
                'command' => 'generate:word-artifacts-mushaf',
                'params' => ['--qiraat' => $target['id'], '--dry-run' => $dry],
            ],
            [
                'title' => '[6/8] Map mushaf words',
                'command' => 'qiraat:auto-map-words',
                'params' => $wordMapOpts,
            ],
            [
                'title' => '[7/8] Repair word maps',
                'command' => 'qiraat:repair-word-maps',
                'params' => $wordRepairOpts,
            ],
            [
                'title' => '[8/8] Generate ayah artifacts',
                'command' => 'generate:ayah-artifacts-mushaf',
                'params' => ['--qiraat' => $target['id'], '--dry-run' => $dry],
            ],
        ];

        foreach ($steps as $step) {
            $code = runCmdAndGc($this, "▶ {$step['title']}", $step['command'], $step['params']);
            if ($code !== 0) {
                return $code;
            }
        }

        $this->newLine();
        $this->info("Completed qiraat mushaf pipeline for {$target['code']} (id={$target['id']}).");
        return 0;
    } catch (\Throwable $e) {
        $this->error('process:qiraat-mushaf failed: ' . $e->getMessage());
        $this->line($e->getFile() . ':' . $e->getLine());
        return 1;
    }
})->purpose('Run the full single-qiraat mushaf pipeline: import, fix ayah maps, generate artifacts, map words, and finalize ayah artifacts.');
