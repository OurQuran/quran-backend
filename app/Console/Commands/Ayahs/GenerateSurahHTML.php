<?php

namespace App\Console\Commands\Ayahs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateSurahHTML extends Command
{
    protected $signature = 'generate:surah-html';
    protected $description = 'Generate concatenated surah text with ayah templates';

    public function handle(): int
    {
        $surahs = DB::table('surahs')->orderBy('id')->get();

        foreach ($surahs as $surah) {
            $ayahTemplates = DB::table('ayahs')
                ->where('surah_id', $surah->id)
                ->orderBy('number_in_surah')
                ->pluck('ayah_template')
                ->toArray();

            if (empty($ayahTemplates)) {
                $this->warn("Skipping Surah ID: {$surah->id} (No ayah templates found)");
                continue;
            }

            DB::table('surahs')->where('id', $surah->id)->update([
                'surah_template' => '<div>' . implode('', $ayahTemplates) . '</div>',
            ]);
        }

        $this->info('Surah HTML generated successfully.');

        return self::SUCCESS;
    }
}
