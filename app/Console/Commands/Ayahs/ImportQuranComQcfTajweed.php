<?php

namespace App\Console\Commands\Ayahs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportQuranComQcfTajweed extends Command
{
    protected $signature = 'import:quran-com-qcf-tajweed
        {--dry-run : Parse and report without writing}
        {--timeout=30 : HTTP timeout per request in seconds}';

    protected $description = 'Import Quran.com QCF word glyphs into ayahs.qcf_tajweed_template for TajweedV4 rendering.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $timeout = (int) $this->option('timeout');

        $this->info('Importing Quran.com QCF Tajweed templates'.($dryRun ? ' (dry-run)' : '').'...');

        $ayahsByKey = DB::table('ayahs')
            ->get(['id', 'surah_id', 'number_in_surah', 'page'])
            ->keyBy(fn ($ayah) => ((int) $ayah->surah_id).':'.((int) $ayah->number_in_surah));

        $wordsByAyahId = DB::table('words')
            ->orderBy('position')
            ->get(['id', 'ayah_id', 'position'])
            ->groupBy('ayah_id');

        $updates = [];
        $converted = 0;
        $missingAyahs = 0;
        $missingWords = 0;

        for ($surah = 1; $surah <= 114; $surah++) {
            $response = Http::timeout($timeout)
                ->retry(2, 500)
                ->acceptJson()
                ->get("https://api.quran.com/api/v4/verses/by_chapter/{$surah}", [
                    'words' => 'true',
                    'word_fields' => 'code_v2',
                    'per_page' => 300,
                ]);

            if (! $response->successful()) {
                $this->error("Failed to fetch surah {$surah}: HTTP {$response->status()}");

                return self::FAILURE;
            }

            foreach ($response->json('verses', []) as $verse) {
                $verseNumber = (int) ($verse['verse_number'] ?? 0);
                $ayah = $ayahsByKey->get("{$surah}:{$verseNumber}");

                if (! $ayah) {
                    $missingAyahs++;
                    continue;
                }

                $wordRows = $wordsByAyahId->get($ayah->id, collect())->values();
                $wordRowIndex = 0;
                $spans = [];

                foreach ($verse['words'] ?? [] as $word) {
                    $charType = (string) ($word['char_type_name'] ?? '');
                    $code = trim((string) ($word['code_v2'] ?? ''));

                    if ($code === '') {
                        continue;
                    }

                    if ($charType === 'word') {
                        $wordRow = $wordRows->get($wordRowIndex);
                        if (! $wordRow) {
                            $missingWords++;
                            continue;
                        }

                        $wordRowIndex++;
                        $spans[] = '<span id="'.$wordRow->id.'" class="qcf-tajweed-word qcf-tajweed-p'.$ayah->page.'">'.htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>';
                    } elseif ($charType === 'end') {
                        $spans[] = '<span class="qcf-tajweed-end qcf-tajweed-p'.$ayah->page.'">'.htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</span>';
                    }
                }

                if ($spans === []) {
                    continue;
                }

                $updates[] = [
                    'id' => (int) $ayah->id,
                    'qcf_tajweed_template' => implode(' ', $spans),
                ];
                $converted++;
            }

            $this->line("Fetched surah {$surah}");
        }

        $this->info("Converted ayahs: {$converted}");
        $this->info("Missing ayahs: {$missingAyahs}");
        $this->info("Missing word rows: {$missingWords}");

        if ($dryRun) {
            $this->info('Dry run complete. No rows changed.');

            return self::SUCCESS;
        }

        foreach (array_chunk($updates, 500) as $chunk) {
            DB::transaction(function () use ($chunk) {
                foreach ($chunk as $row) {
                    DB::table('ayahs')
                        ->where('id', $row['id'])
                        ->update(['qcf_tajweed_template' => $row['qcf_tajweed_template']]);
                }
            });
        }

        $this->info('QCF Tajweed templates imported.');

        return self::SUCCESS;
    }
}
