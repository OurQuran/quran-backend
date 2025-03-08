<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateSurahHTML extends Command
{
    protected $signature = 'generate:surah-html';
    protected $description = 'Generate concatenated surah text with ayah templates';

    public function handle()
    {
        // Get all surahs
        $surahs = DB::table('surahs')->orderBy('id')->get();

        foreach ($surahs as $surah) {
            $ayahTemplates = DB::table('ayahs')
                ->where('surah_id', $surah->id)
                ->orderByRaw('surah_id, number_in_surah')
                ->pluck('ayah_template')
                ->toArray();

            if (empty($ayahTemplates)) {
                $this->warn("Skipping Surah ID: {$surah->id} (No ayah templates found)");
                continue;
            }

            // Generate Surah HTML
            $surahHTML = '<div>' . implode('', $ayahTemplates) . '</div>';

            // Ensure surahHTML is not empty before update
            if (!empty($surahHTML)) {
                DB::table('surahs')->where('id', $surah->id)->update([
                    'surah_template' => $surahHTML
                ]);
            } else {
                $this->warn("Skipping Surah ID: {$surah->id} (Generated HTML is empty)");
            }
        }

        $this->info('Surah HTML generated successfully.');
    }
}
