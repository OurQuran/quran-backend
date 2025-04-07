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

        $total = $ayahs->count();
        $this->info("Generating ayah HTML for $total ayahs...");

        $processed = 0;

        foreach ($ayahs as $ayah) {
            $wordTemplates = DB::table('words')
                ->where('ayah_id', $ayah->id)
                ->orderBy('id')
                ->pluck('word_template')
                ->toArray();

            $ayahText = implode(' ', $wordTemplates);

            $ayahNumber = (int) $ayah->number_in_surah;

            if ($ayahNumber !== 0) {
                $ayahText .= ' ' . $this->toQuranicNumber($ayahNumber);
            }

            $ayahHTML = '<div dir="rtl">' . $ayahText . '</div>';

            DB::table('ayahs')->where('id', $ayah->id)->update([
                'ayah_template' => $ayahHTML
            ]);

            $processed++;

            if ($processed % 100 === 0) {
                $this->line("Processed $processed / $total ayahs...");
            }
        }

        $this->info("✅ Finished generating ayah HTML for $processed ayahs.");
    }

    private function toQuranicNumber($number)
    {
        $arabicDigits = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        return '﴾' . implode('', array_map(fn($digit) => $arabicDigits[$digit], str_split((string) $number))) . '﴿';
    }
}
