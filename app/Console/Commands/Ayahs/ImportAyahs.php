<?php

namespace App\Console\Commands\Ayahs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportAyahs extends Command
{
    protected $signature = 'import:ayahs {file=storage/app/uthmani-qurancom.md}';
    protected $description = 'Import Ayahs from a Markdown file based on headings.';

    public function handle(): int
    {
        $filePath = $this->argument('file');

        if (!File::exists($filePath)) {
            $this->error("File not found: {$filePath}");
            return self::FAILURE;
        }

        $contents = File::get($filePath);

        // Each section: heading with a number, followed by text until the next heading or EOF
        $pattern = '/^#\s*(\d+)\s*$(.*?)(?=^#|\z)/ms';
        preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER);

        if (empty($matches)) {
            $this->error("No ayah sections found in the file.");
            return self::FAILURE;
        }

        foreach ($matches as $match) {
            $ayahId = trim($match[1]);
            $text = trim($match[2]);

            DB::table('ayahs')->updateOrInsert(
                ['id' => $ayahId],
                ['text' => $text]
            );

            $this->line("Imported ayah #{$ayahId}.");
        }

        $this->info("Import complete.");

        return self::SUCCESS;
    }
}
