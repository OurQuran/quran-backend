<?php

namespace App\Console\Commands\Ayahs;

use App\Support\BaseAyahArtifactsGenerator;
use Illuminate\Console\Command;

class GenerateAyahArtifacts extends Command
{
    protected $signature = 'generate:ayah-artifacts
        {--mode=both : pure, html, or both}
        {--chunk=2000 : Ayah chunk size}
        {--batch=800 : Update batch size}
        {--dry-run : Do not write, only simulate}';

    protected $description = 'Generate ayahs.pure_text and/or ayah_template in one pass.';

    public function handle(BaseAyahArtifactsGenerator $generator): int
    {
        return $generator->generate($this, [
            'mode' => $this->option('mode'),
            'chunk' => $this->option('chunk'),
            'batch' => $this->option('batch'),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);
    }
}
