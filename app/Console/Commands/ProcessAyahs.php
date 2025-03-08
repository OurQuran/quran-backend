<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// TODO: makes the template styles better
class ProcessAyahs extends Command
{
    protected $signature = 'process:ayahs';
    protected $description = 'Extract words from ayahs and insert them.';

    public function handle()
    {
        $ayahs = DB::table('ayahs')->get();
        $diacriticMarks = ['ۜ', 'ۛ', 'ۚ', 'ۙ', 'ۘ', 'ۗ', 'ۖ'];

        foreach ($ayahs as $ayah) {
            $text = trim($ayah->text);
            $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
            $processedWords = [];

            foreach ($words as $word) {
                if (in_array($word, $diacriticMarks)) {
                    // Append diacritic to the last word instead of treating it as separate
                    $processedWords[count($processedWords) - 1] .= " $word";
                } else {
                    $processedWords[] = $word;
                }
            }

            foreach ($processedWords as $processedWord => $word) {
                DB::table('words')->insertGetId([
                    'ayah_id' => $ayah->id,
                    'position' => $ayah->number_in_surah,
                    'word' => $word
                ]);
            }
        }

        $this->info('Words processed and stored.');
    }
}
