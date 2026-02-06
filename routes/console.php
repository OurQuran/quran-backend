<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('process:mushaf-insertion {--dry-run}', function () {
    $dry = (bool) $this->option('dry-run');

    // 1) Import the full KFGQPC-based ones (XML)
    $xmlIds = [2,3,4,5,6,7,8];

    foreach ($xmlIds as $id) {
        $this->info("=== XML import qiraat_reading_id={$id} ===");

        Artisan::call('qiraat:import-mushaf-ayahs-xml', [
            'qiraat_reading_id' => $id,
            '--dry-run'         => $dry,
        ]);

        $this->line(Artisan::output());
    }

    // 2) Auto-map ALL qiraat currently present in mushaf_ayahs (includes the XML ones)
    $this->info("=== Auto-map by text (auto) ===");
    Artisan::call('qiraat:auto-map-by-text', [
        'qiraat_reading_id' => 'auto',
        '--only-unmapped'   => true,
        '--dry-run'         => $dry,
    ]);
    $this->line(Artisan::output());

    // 3) Import the Excel-based ones (auto does all mapped excel files)
    $this->info("=== Excel import (auto) ===");
    Artisan::call('qiraat:import-mushaf-ayahs-excel', [
        'qiraat_reading_id' => 'auto',
        '--dry-run'         => $dry,
    ]);
    $this->line(Artisan::output());

    // 4) After Excel import, run mapping again (because new mushaf_ayahs were inserted)
    $this->info("=== Auto-map by text (auto) after Excel ===");
    Artisan::call('qiraat:auto-map-by-text', [
        'qiraat_reading_id' => 'auto',
        '--only-unmapped'   => true,
        '--dry-run'         => $dry,
    ]);
    $this->line(Artisan::output());

})->purpose('Import mushaf ayahs (XML+Excel) and auto-map them to base ayahs');

Artisan::command('generate:mushaf-template', function () {
    $steps = [
        'process:ayahs-mushaf',
        'generate:pure-words-mushaf',
        'generate:word-html-mushaf',
        'generate:pure-ayahs-mushaf',
        'generate:ayah-html-mushaf',
    ];

    foreach ($steps as $cmd) {
        $this->info("▶ Running {$cmd}");
        $code = $this->call($cmd);

        if ($code !== 0) {
            $this->error("❌ Failed at {$cmd}, aborting.");
            return $code;
        }
    }

    $this->info('✅ All mushaf generation steps completed.');
});
