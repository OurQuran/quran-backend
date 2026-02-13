<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

function runCmd($self, string $title, string $command, array $params = [], bool $failFast = true): int
{
    $self->info($title);

    $code = Artisan::call($command, $params);

    $out = trim(Artisan::output());
    if ($out !== '') {
        $self->line($out);
    }

    if ($code !== 0 && $failFast) {
        $self->error("❌ Command failed: {$command}");
    }

    return $code;
}

Artisan::command('process:mushaf-xml {--dry-run}', function () {
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
        // '--keep-hamza' => true,
        // '--only-unmapped' => true,
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

        $code = runCmd(
            $this,
            "▶ [1/3] XML import (qiraat={$id})",
            'qiraat:import-mushaf-ayahs-xml',
            ['qiraat_reading_id' => $id, '--dry-run' => $dry]
        );
        if ($code !== 0) return $code;

        $code = runCmd(
            $this,
            "▶ [2/3] Auto-map by text (qiraat={$id})",
            'qiraat:auto-map-by-text',
            array_merge(['qiraat_reading_id' => $id], $autoMapOpts)
        );
        if ($code !== 0) return $code;

        $code = runCmd(
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
})->purpose('XML only: import each qiraat, then auto-map, then repair (repeat per qiraat).');

Artisan::command('process:mushaf-excel {--dry-run}', function () {
    $dry = (bool) $this->option('dry-run');

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
    $this->info(" dry-run: " . ($dry ? 'YES' : 'NO'));
    $this->info(str_repeat('=', 38));
    $this->newLine();

    $code = runCmd(
        $this,
        "▶ [1/3] Excel import (qiraat=auto)",
        'qiraat:import-mushaf-ayahs-excel',
        ['qiraat_reading_id' => 'auto', '--dry-run' => $dry]
    );
    if ($code !== 0) return $code;

    $code = runCmd(
        $this,
        "▶ [2/3] Auto-map by text (qiraat=auto, only-unmapped)",
        'qiraat:auto-map-by-text',
        array_merge(['qiraat_reading_id' => 'auto'], $autoMapOpts)
    );
    if ($code !== 0) return $code;

    $code = runCmd(
        $this,
        "▶ [3/3] Repair + exact map (qiraat=auto)",
        'qiraat:repair-and-exact-map',
        ['qiraat_reading_id' => 'auto', '--dry-run' => $dry]
    );
    if ($code !== 0) return $code;

    $this->newLine();
    $this->info("✅ process:mushaf-excel completed successfully.");
    return 0;
})->purpose('Excel only: import (auto) then auto-map (only-unmapped) then repair.');

Artisan::command('generate:mushaf-template', function () {
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

        if ($code !== 0) {
            $this->error("❌ Failed at {$cmd}, aborting.");
            return $code;
        }
    }

    $this->info('✅ All mushaf generation steps completed.');
    return 0;
});
