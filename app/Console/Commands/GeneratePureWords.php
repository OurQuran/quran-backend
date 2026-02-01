<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Word;

class GeneratePureWords extends Command
{
    protected $signature = 'generate:pure-words {--chunk=100} {--dry-run}';
    protected $description = 'Generate pure words by removing Arabic diacritics/Quranic symbols (including madd marks) and saving into pure_word';

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Generating pure words (chunk={$chunk}" . ($dryRun ? ', DRY RUN' : '') . ")...");

        $updated = 0;

        Word::query()
            ->orderBy('id')
            ->chunkById($chunk, function ($words) use (&$updated, $dryRun) {
                foreach ($words as $word) {
                    $original = (string) $word->word;
                    $pure = $this->removeArabicDiacritics($original);

                    if ((string) $word->pure_word !== $pure) {
                        if (!$dryRun) {
                            $word->pure_word = $pure;
                            $word->save();
                        }
                        $updated++;
                    }
                }

                $this->info("Updated {$updated} words so far...");
            });

        $this->info("Done. Updated {$updated} records.");
        return self::SUCCESS;
    }

    /**
     * Remove Arabic diacritics, Quranic symbols, and unwanted characters from a given word text.
     * ✅ Includes madd-related marks (ٓ U+0653, ٰ U+0670, ۤ U+06E4, etc.)
     */
    private function removeArabicDiacritics(string $text): string
    {
        // Normalize Alef Wasla (ٱ) to Alef (ا)
        $text = str_replace(['ٱ'], ['ا'], $text);

        // Optional: normalize Alef with Maddah (آ) -> Alef (ا)
        // If you want to keep it as "آ", remove this line.
        $text = str_replace(['آ'], ['ا'], $text);

        $pattern = '/[' .
            // Arabic Tashkeel (diacritics)
            '\x{064B}-\x{0652}' . // tanwin, fatha, damma, kasra, shadda, sukun

            // Extended Arabic diacritics (✅ includes maddah above U+0653)
            '\x{0653}-\x{065F}' .

            // Superscript Alef (dagger alif) ✅ madd harakah sign
            '\x{0670}' .

            // Quranic marks and annotations
            '\x{06D6}-\x{06DC}' .
            '\x{06DD}' .
            '\x{06DE}' .
            '\x{06DF}-\x{06E4}' . // ✅ includes small high madda (ۤ U+06E4)
            '\x{06E7}' .
            '\x{06E8}' .
            '\x{06EB}-\x{06EC}' .
            '\x{06ED}' .

            // More Arabic combining marks
            '\x{08F0}-\x{08FF}' .

            // Invisible formatting characters
            '\x{200C}\x{200D}\x{FEFF}' .
            ']/u';

        $pure = preg_replace($pattern, '', $text);

        // Extra safety for any leftovers (not strictly necessary with the regex above)
        $pure = str_replace([
            'ۖ','ۗ','ۘ','ۙ','ۚ','ۛ','ۜ','۩','۝','۞',
            'ۡ','ۢ','ۤ','ۥ','ۦ','ۧ','ۨ','ۭ','◌','◌ٰ','◌ّ',
            'ٓ','ٰ' // maddah above, dagger alif (in case they survive for any reason)
        ], '', $pure);

        // Normalize whitespace
        $pure = preg_replace('/\s+/u', ' ', $pure);

        return trim($pure);
    }
}
