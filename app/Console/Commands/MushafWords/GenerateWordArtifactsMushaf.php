<?php

namespace App\Console\Commands\MushafWords;

use App\Support\MushafWordArtifactsGenerator;
use Illuminate\Console\Command;

class GenerateWordArtifactsMushaf extends Command
{
    protected $signature = 'generate:word-artifacts-mushaf
        {--qiraat= : Only process one qiraat_reading_id (via join to mushaf_ayahs)}
        {--mode=both : pure, html, or both}
        {--chunk=5000 : Read chunk size}
        {--batch=5000 : Update batch size}
        {--dry-run : Do not write, only simulate}';

    protected $description = 'Generate mushaf_words.pure_word and/or word_template in one pass.';

    public function handle(MushafWordArtifactsGenerator $generator): int
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
