<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Ayah;

class GeneratePureAyahs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'generate:pure-ayahs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate pure ayahs by removing Arabic diacritics and special Quranic symbols from the text and saving it into the pure_text column';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Generating pure ayahs...');
        $count = 0;

        // Process Ayahs in chunks to avoid memory issues
        Ayah::chunk(100, function ($ayahs) use (&$count) {
            foreach ($ayahs as $ayah) {
                // Remove diacritics and Quranic symbols from the ayah text
                $pureText = $this->removeArabicDiacritics($ayah->text);

                // Only update if the text actually changed
                if ($ayah->pure_text !== $pureText) {
                    $ayah->pure_text = $pureText;
                    $ayah->save();
                    $count++;
                }
            }
            $this->info("Processed {$count} ayahs so far...");
        });

        $this->info("All ayahs processed successfully. Updated {$count} records.");
    }

    /**
     * Remove Arabic diacritics, Quranic symbols, and unwanted characters from a given text.
     *
     * @param string $text
     * @return string
     */
    private function removeArabicDiacritics(string $text): string
    {
        // Replace Alef Wasla (ٱ) with a normal Alef (ا)
        $text = str_replace(['ٱ'], ['ا'], $text);

        // ✅ Minimal: normalize madd/hamza alef forms to plain alef for pure text
        $text = str_replace(['آ','أ','إ'], ['ا','ا','ا'], $text);

        // Define a comprehensive pattern to match all Arabic diacritics and Quranic symbols
        $pattern = '/[' .
            // Arabic Tashkeel (diacritics)
            '\x{064B}-\x{0652}' . // Includes: ً(Fathatan), ٌ(Dammatan), ٍ(Kasratan), َ(Fatha), ُ(Damma), ِ(Kasra), ّ(Shadda), ْ(Sukun)

            // Extended Arabic diacritics
            '\x{0653}-\x{065F}' . // Includes: ٓ(Maddah), ٔ(Hamza Above), ٕ(Hamza Below), and others
            '\x{0670}' .          // ٰ Arabic letter superscript Alef

            // Quranic marks and annotations
            '\x{06D6}-\x{06DC}' . // Includes: ۖ(Small Fatha), ۗ(Small Damma), ۘ(Small Kasra), ۙ, ۚ, ۛ, ۜ
            '\x{06DD}' .          // ۝ End of Ayah
            '\x{06DE}' .          // ۞ Start of Rub El Hizb
            '\x{06DF}-\x{06E4}' . // Various small high letters and symbols
            '\x{06E7}' .          // Small Yeh
            '\x{06E8}' .          // Small Noon
            '\x{06EB}-\x{06EC}' . // Special ligature symbols

            // Additional Arabic marks
            '\x{08F0}-\x{08F3}' . // Various combining marks
            '\x{08F4}-\x{08FF}' . // More extended Arabic marks

            // Invisible formatting characters
            '\x{200C}' .          // Zero Width Non-Joiner
            '\x{200D}' .          // Zero Width Joiner
            '\x{FEFF}' .          // Byte Order Mark (BOM)
            ']/u';

        // Remove all matched characters
        $pureText = preg_replace($pattern, '', $text);

        // Additional specific symbols with direct representation
        $additionalSymbols = [
            'ۖ',  // Small Fatha (U+06D6)
            'ۗ',  // Small Damma (U+06D7)
            'ۘ',  // Small Kasra (U+06D8)
            'ۙ',  // Small Dammatan (U+06D9)
            'ۚ',  // Small Kasratan (U+06DA)
            'ۛ',  // Small Three Dots (U+06DB)
            'ۜ',  // Small Seen (U+06DC)
            '۩',  // Sajdah (U+06E9) - Prostration mark
            'ٕ',   // Hamza Below (U+0655)
            'ٰ',   // Superscript Alef (U+0670)
            'ٰٰ',  // Double Superscript Alef

            // Detailed annotation of specific diacritics
            'ً',   // Fathatan (U+064B)
            'ٌ',   // Dammatan (U+064C)
            'ٍ',   // Kasratan (U+064D)
            'َ',   // Fatha (U+064E)
            'ُ',   // Damma (U+064F)
            'ِ',   // Kasra (U+0650)
            'ّ',   // Shadda (U+0651)
            'ْ',   // Sukun (U+0652)
            'ٓ',   // Maddah Above (U+0653)
            'ٔ',   // Hamza Above (U+0654)

            // Pause marks in Quranic text
            '۝',   // End of Ayah (U+06DD)
            '۞',   // Start of Rub El Hizb (U+06DE)
            'ۡ',   // Small Dotless Head of Khah (U+06E1)
            'ۢ',   // Small Meem (U+06E2)
            'ۤ',   // Small High Madda (U+06E4)
            'ٓ', // Maddah Above (U+0653)
            'ۥ',   // Small Waw (U+06E5)
            'ۦ',   // Small Yeh (U+06E6)
            'ۧ',   // Small High Yeh (U+06E7)
            'ۨ',   // Small High Noon (U+06E8)
            'ۭ',   // Small High Noon with Kasra (U+06ED)

            // Smaller signs sometimes used in Quranic text
            '◌',   // Dotted Circle placeholder (U+25CC)
            '◌ٰ',  // Dotted Circle with Superscript Alef
            '◌ّ',  // Dotted Circle with Shadda
        ];

        $pureText = str_replace($additionalSymbols, '', $pureText);

        // Return cleaned text with trimmed whitespace
        return trim($pureText);
    }
}
