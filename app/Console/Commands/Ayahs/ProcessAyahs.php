<?php

namespace App\Console\Commands\Ayahs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessAyahs extends Command
{
    protected $signature = 'process:ayahs
        {--chunk=2000 : Ayah chunk size}
        {--dry-run : Do not write, only simulate}';

    protected $description = 'Extract words from ayahs and upsert into words.';

    public function handle(): int
    {
        $chunkSize = max(200, (int) ($this->option('chunk') ?? 2000));
        $dryRun = (bool) $this->option('dry-run');

        $diacriticSet = array_flip(['ۜ', 'ۛ', 'ۚ', 'ۙ', 'ۘ', 'ۗ', 'ۖ']);

        $totalAyahs = DB::table('ayahs')->count();
        $this->info("Processing {$totalAyahs} ayahs... (chunk={$chunkSize}, " . ($dryRun ? 'DRY-RUN' : 'WRITE') . ")");

        $processedAyahs = 0;
        $wordCount = 0;

        DB::table('ayahs')
            ->select('id', 'surah_id', 'number_in_surah', 'text')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($ayahs) use (
                &$processedAyahs,
                &$wordCount,
                $dryRun,
                $diacriticSet
            ) {
                foreach ($ayahs as $ayah) {
                    $processedAyahs++;
                    $text = trim((string) $ayah->text);

                    if ($text === '') {
                        continue;
                    }

                    $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

                    $processed = [];
                    foreach ($words as $w) {
                        if (isset($diacriticSet[$w])) {
                            if (!empty($processed)) {
                                $processed[count($processed) - 1] .= " {$w}";
                            }
                            continue;
                        }
                        $processed[] = $w;
                    }

                    if (!$dryRun) {
                        foreach ($processed as $position => $word) {
                            DB::table('words')->updateOrInsert(
                                ['ayah_id' => $ayah->id, 'position' => $position + 1],
                                ['word' => $word]
                            );
                        }

                        // Remove any extra positions left from a previous run
                        $lastPos = count($processed);
                        if ($lastPos > 0) {
                            DB::table('words')
                                ->where('ayah_id', $ayah->id)
                                ->where('position', '>', $lastPos)
                                ->delete();
                        }
                    }

                    $wordCount += count($processed);
                }

                $this->line("Processed: {$processedAyahs}/{$totalAyahs}");
            }, 'id');

        $this->newLine();
        $this->info("Done. Ayahs: {$processedAyahs} | Words: {$wordCount}" . ($dryRun ? ' (simulated)' : ''));

        return self::SUCCESS;
    }
}
