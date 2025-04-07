<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateWordHTML extends Command
{
    protected $signature = 'generate:word-html';
    protected $description = 'Generate word spans for each word in the words table';

    public function handle()
    {
        $words = DB::table('words')->orderBy('ayah_id')->orderBy('position')->get();
        $totalWords = $words->count();

        $this->info("Generating HTML spans for $totalWords words...");

        $processed = 0;

        foreach ($words as $index => $word) {
            $wordText = trim($word->word);
            $wordTemplate = "<span id=\"{$word->id}\">{$wordText}</span>";

            DB::table('words')->where('id', $word->id)->update([
                'word_template' => $wordTemplate
            ]);

            $processed++;

            // Log progress every 100 words
            if ($processed % 100 === 0) {
                $this->line("Processed $processed / $totalWords words...");
            }
        }

        $this->info("✅ Done. Generated HTML for $processed words.");
    }
}
