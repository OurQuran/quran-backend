<?php

namespace App\Console\Commands\MushafAyahs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ProcessAyahsMushaf extends Command
{
    protected $signature = 'process:ayahs-mushaf
        {--qiraat= : Only process one qiraat_reading_id}
        {--chunk=2000 : Ayah chunk size}
        {--batch=20000 : Upsert batch size (rows)}
        {--dry-run : Do not write, only simulate}';

    protected $description = 'Extract words from mushaf_ayahs and upsert into mushaf_words (FAST bulk).';

    public function handle(): int
    {
        $chunkSize = max(200, (int) ($this->option('chunk') ?? 2000));
        $batchSize = max(1000, (int) ($this->option('batch') ?? 20000));
        $dryRun    = (bool) $this->option('dry-run');

        // O(1) lookup instead of in_array()
        $diacriticSet = array_flip(['ۜ', 'ۛ', 'ۚ', 'ۙ', 'ۘ', 'ۗ', 'ۖ']);

        $q = DB::table('mushaf_ayahs')
            ->select('id', 'qiraat_reading_id', 'text')
            ->orderBy('id');

        $qiraatOpt = $this->option('qiraat');
        if ($qiraatOpt !== null && trim((string) $qiraatOpt) !== '') {
            $q->where('qiraat_reading_id', (int) $qiraatOpt);
        }

        $totalAyahs = (clone $q)->count();
        $this->info("Processing {$totalAyahs} mushaf ayahs... (chunk={$chunkSize}, batch={$batchSize}, " . ($dryRun ? 'DRY-RUN' : 'WRITE') . ")");

        $rows = [];
        $processedAyahs = 0;
        $processedWords = 0;

        $q->chunkById($chunkSize, function ($ayahs) use (
            &$rows,
            &$processedAyahs,
            &$processedWords,
            $batchSize,
            $dryRun,
            $diacriticSet
        ) {
            foreach ($ayahs as $ayah) {
                $processedAyahs++;

                $text = trim((string) $ayah->text);
                if ($text === '') {
                    // If empty, also clean old rows (optional)
                    if (!$dryRun) {
                        DB::table('mushaf_words')->where('mushaf_ayah_id', (int) $ayah->id)->delete();
                    }
                    continue;
                }

                $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

                // Merge diacritic-only marks into previous word
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

                // Build upsert rows
                foreach ($processed as $i => $w) {
                    $rows[] = [
                        'mushaf_ayah_id' => (int) $ayah->id,
                        'position'       => $i + 1,
                        'word'           => $w,
                    ];
                }

                $processedWords += count($processed);

                // Clean old extra positions from previous runs
                if (!$dryRun) {
                    $lastPos = count($processed);
                    DB::table('mushaf_words')
                        ->where('mushaf_ayah_id', (int) $ayah->id)
                        ->where('position', '>', $lastPos)
                        ->delete();
                }

                // Flush when reaching batch size
                if (count($rows) >= $batchSize) {
                    $this->flushRows($rows, $dryRun);
                    $rows = [];
                }
            }
        }, 'id');

        if (!empty($rows)) {
            $this->flushRows($rows, $dryRun);
        }

        $this->newLine();
        $this->info("Done. Ayahs processed: {$processedAyahs} | Words processed: {$processedWords}");

        return self::SUCCESS;
    }

    private function flushRows(array $rows, bool $dryRun): void
    {
        if ($dryRun || empty($rows)) return;

        DB::table('mushaf_words')->upsert(
            $rows,
            ['mushaf_ayah_id', 'position'],
            ['word']
        );
    }
}
