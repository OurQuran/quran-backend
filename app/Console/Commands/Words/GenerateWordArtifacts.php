<?php

namespace App\Console\Commands\Words;

use App\Support\BaseWordArtifactsGenerator;
use Illuminate\Console\Command;

class GenerateWordArtifacts extends Command
{
    protected $signature = 'generate:word-artifacts
        {--mode=both : pure, html, or both}
        {--chunk=5000 : Read chunk size}
        {--batch=5000 : Update batch size}
        {--dry-run : Do not write, only simulate}';

    protected $description = 'Generate words.pure_word and/or word_template in one pass.';

    public function handle(BaseWordArtifactsGenerator $generator): int
    {
        return $generator->generate($this, [
            'mode' => $this->option('mode'),
            'chunk' => $this->option('chunk'),
            'batch' => $this->option('batch'),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);
    }
}
