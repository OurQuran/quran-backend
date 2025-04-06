<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportAyahs extends Command
{
    // You can optionally allow a file path as an argument
    protected $signature = 'import:ayahs {file=storage/app/uthmani-qurancom.md}';
    protected $description = 'Import Ayahs from a Markdown file based on headings.';

    public function handle()
    {
        $filePath = $this->argument('file');

        if (!File::exists($filePath)) {
            $this->error("File not found: $filePath");
            return 1;
        }

        $contents = File::get($filePath);

        // Use a regex to capture each section:
        // - It finds a heading that starts at the beginning of a line with a number.
        // - Captures the heading number and the following text until the next heading or end of file.
        $pattern = '/^#\s*(\d+)\s*$(.*?)(?=^#|\z)/ms';
        preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            $this->error("No ayah sections found in the file.");
            return 1;
        }

        foreach ($matches as $match) {
            $ayahId = trim($match[1]);
            $text = trim($match[2]);

            // Insert or update the record in your "ayahs" table.
            // Assumes that the "id" column corresponds to the ayah number.
            DB::table('ayahs')->updateOrInsert(
                ['id' => $ayahId],
                ['text' => $text]
            );

            $this->info("Imported ayah #{$ayahId}.");
        }

        $this->info("Import complete.");
        return 0;
    }
}
