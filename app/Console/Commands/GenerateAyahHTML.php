<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateAyahHTML extends Command
{
    protected $signature = 'generate:ayah-html';
    protected $description = 'Generate concatenated ayah text with spans and Quranic numbering';

    public function handle()
    {
        // Fetch ayahs in proper order
        $ayahs = DB::table('ayahs')
            ->orderBy('surah_id')
            ->orderBy('number_in_surah')
            ->get();

        foreach ($ayahs as $ayah) {
            // Fetch words in the correct order based on id
            $wordTemplates = DB::table('words')
                ->where('ayah_id', $ayah->id)
                ->orderBy('id')
                ->pluck('word_template')
                ->toArray();

            // Concatenate words into a single string with spaces
            $ayahText = implode(' ', $wordTemplates);

            // Get metadata
            $ayahNumber = (int) $ayah->number_in_surah;
            $surahId = (int) $ayah->surah_id;

            // Append Quranic numbering if not Bismillah
            if ($ayahNumber !== 0) {
                $ayahText .= ' ' . $this->toQuranicNumber($ayahNumber);
            }

            // Wrap ayah in a div with RTL direction
            $ayahHTML = '<div dir="rtl">' . $ayahText . '</div>';

            // Store the generated template in the ayahs table
            DB::table('ayahs')->where('id', $ayah->id)->update([
                'ayah_template' => $ayahHTML
            ]);
        }

        $this->info('Ayah HTML generated successfully.');
    }

    private function toQuranicNumber($number)
    {
        $arabicDigits = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        return '﴾' . implode('', array_map(fn($digit) => $arabicDigits[$digit], str_split((string) $number))) . '﴿';
    }
}
