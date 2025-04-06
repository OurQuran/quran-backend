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
        $words = DB::table('words')->orderBy('ayah_id')->orderBy('id')->get();

        foreach ($words as $word) {
            $wordText = trim($word->word);
            $wordTemplate = "<span id=\"{$word->id}\">{$wordText}</span>";

            // Update the word_template column with the generated span
            DB::table('words')->where('id', $word->id)->update([
                'word_template' => $wordTemplate
            ]);
        }

        $this->info('Word HTML spans generated successfully.');
    }
}
