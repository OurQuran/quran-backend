<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessAyahs extends Command
{
    protected $signature = 'process:ayahs';
    protected $description = 'Extract words from ayahs and insert them.';

    public function handle()
    {
        $ayahs = DB::table('ayahs')->get();
        $totalAyahs = $ayahs->count();
        $diacriticMarks = ['ۜ', 'ۛ', 'ۚ', 'ۙ', 'ۘ', 'ۗ', 'ۖ'];
        $wordCount = 0;

        $this->info("Processing $totalAyahs ayahs...");

        foreach ($ayahs as $index => $ayah) {
            $this->line("[$index / $totalAyahs] Processing Ayah ID: {$ayah->id} (Surah: {$ayah->surah_id}, Number in Surah: {$ayah->number_in_surah})");

            $text = trim($ayah->text);
            $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
            $processedWords = [];

            foreach ($words as $word) {
                if (in_array($word, $diacriticMarks)) {
                    if (!empty($processedWords)) {
                        $processedWords[count($processedWords) - 1] .= " $word";
                    }
                } else {
                    $processedWords[] = $word;
                }
            }

            foreach ($processedWords as $position => $word) {
                DB::table('words')->updateOrInsert([
                    'ayah_id' => $ayah->id,
                    'position' => $position + 1,
                ], [
                    'word' => $word
                ]);
                $wordCount++;
            }

            $this->line("    ↳ Inserted " . count($processedWords) . " words.");
        }

        $this->info("✅ Done. Total words processed: $wordCount.");
    }
}
