<?php

namespace App\Console\Commands\MushafAyahs;

use App\Support\MushafAyahArtifactsGenerator;
use Illuminate\Console\Command;

class GenerateAyahArtifactsMushaf extends Command
{
    protected $signature = 'generate:ayah-artifacts-mushaf
        {--qiraat= : Only process one qiraat_reading_id}
        {--mode=both : pure, html, or both}
        {--chunk=2000 : Ayah chunk size}
        {--batch=800 : Update batch size}
        {--dry-run : Do not write, only simulate}';

    protected $description = 'Generate mushaf_ayahs.pure_text and/or ayah_template in one pass.';

    public function handle(MushafAyahArtifactsGenerator $generator): int
    {
        return $generator->generate($this, [
            'qiraat' => $this->option('qiraat'),
            'mode' => $this->option('mode'),
            'chunk' => $this->option('chunk'),
            'batch' => $this->option('batch'),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);
    }
}
