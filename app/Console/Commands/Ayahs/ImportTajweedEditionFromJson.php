<?php

namespace App\Console\Commands\Ayahs;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ImportTajweedEditionFromJson extends Command
{
    protected $signature = 'import:tajweed-edition
        {--source=database/data/tajweed/react-native-quran-tajweed-0.1.3/src-data : Folder containing surah_001.json ... surah_114.json}
        {--identifier=tajweed-uthmani : Edition identifier to create/update}
        {--name=Tajweed Uthmani : Edition display name}
        {--english-name=Tajweed Uthmani : Edition English display name}
        {--qiraat-reading-id=1 : qiraat_readings.id to attach to the edition}
        {--chunk=500 : Upsert chunk size}
        {--replace : Delete existing ayah_edition rows for this edition before importing}
        {--dry-run : Validate and convert without writing to the database}';

    protected $description = 'Import vendored Quran Tajweed JSON into a text edition.';

    /**
     * Numeric rules used by react-native-quran-tajweed 0.1.3, mapped to the
     * existing one-letter bracket codes consumed by the frontend.
     */
    private const RULE_CODE_MAP = [
        0 => 'h', // ham_wasl
        1 => 'l', // laam_shamsiyah
        2 => 's', // silent letter
        3 => 'n', // madda_normal
        4 => 'p', // madda_permissible
        5 => 'o', // madda_obligatory
        6 => 'm', // madda_necessary
        7 => 'q', // qalqalah
        8 => 'g', // ghunnah
        9 => 'f', // ikhfa
        10 => 'c', // ikhfa_shafawi
        11 => 'a', // idgham_with_ghunnah
        12 => 'u', // idgham_without_ghunnah
        13 => 'w', // idgham_shafawi
        14 => 'i', // iqlab
        15 => 'd', // idgham_mutajanisayn
        16 => 'b', // idgham_mutaqaribayn
        17 => 't', // tafkhim (heavy letters)
        // 18 tarqiq is intentionally omitted; it is package-added and the
        // source theme leaves it uncolored.
    ];

    private const RULE_PRIORITY = [
        6, 5, 4, 3, 7, 17, 14, 11, 13, 12, 15, 16, 9, 10, 8, 0, 1, 2,
    ];

    public function handle(): int
    {
        $sourceDir = $this->resolvePath(trim((string) $this->option('source')));
        $identifier = trim((string) $this->option('identifier'));
        $name = trim((string) $this->option('name'));
        $englishName = trim((string) $this->option('english-name'));
        $qiraatReadingId = (int) $this->option('qiraat-reading-id');
        $chunkSize = max(100, (int) $this->option('chunk'));
        $replace = (bool) $this->option('replace');
        $dryRun = (bool) $this->option('dry-run');

        if ($identifier === '' || !Str::isAscii($identifier)) {
            $this->error('The edition identifier must be a non-empty ASCII string.');
            return self::FAILURE;
        }

        if (!File::isDirectory($sourceDir)) {
            $this->error("Source folder not found: {$sourceDir}");
            return self::FAILURE;
        }

        $ayahMap = $this->loadAyahMap();
        if (count($ayahMap) !== 6236) {
            $this->warn('Expected 6236 ayahs in the database, found ' . count($ayahMap) . '. Import will still validate against available ayahs.');
        }

        $this->line("Source: {$sourceDir}");
        $this->line("Edition: {$identifier}");
        $this->line('Mode: ' . ($dryRun ? 'DRY-RUN' : 'WRITE') . ($replace ? ' with replace' : ' with upsert'));

        $rows = [];
        $seen = [];
        $ruleCounts = [];
        $processed = 0;
        $missing = [];

        for ($surah = 1; $surah <= 114; $surah++) {
            $file = $sourceDir . '/surah_' . str_pad((string) $surah, 3, '0', STR_PAD_LEFT) . '.json';

            if (!File::isFile($file)) {
                $this->error("Missing source file: {$file}");
                return self::FAILURE;
            }

            $payload = json_decode(File::get($file), true);
            if (!is_array($payload) || !isset($payload['ayahs']) || !is_array($payload['ayahs'])) {
                $this->error("Invalid source JSON shape: {$file}");
                return self::FAILURE;
            }

            foreach ($payload['ayahs'] as $sourceAyah) {
                $ayahNumber = (int) ($sourceAyah['ayah'] ?? 0);
                $key = "{$surah}:{$ayahNumber}";
                $seen[$key] = true;

                if (!isset($ayahMap[$key])) {
                    $missing[] = $key;
                    continue;
                }

                [$markup, $ayahRuleCounts] = $this->convertSegments($sourceAyah['s'] ?? []);
                foreach ($ayahRuleCounts as $code => $count) {
                    $ruleCounts[$code] = ($ruleCounts[$code] ?? 0) + $count;
                }

                $rows[] = [
                    'ayah_id' => $ayahMap[$key]['id'],
                    'data' => $markup,
                    'is_audio' => 0,
                ];
                $processed++;
            }
        }

        $referenceKeys = array_fill_keys(array_keys($ayahMap), true);
        $notSeen = array_diff_key($referenceKeys, $seen);

        $this->newLine();
        $this->line("Converted ayahs: {$processed}");
        $this->line('Missing in DB: ' . count($missing));
        $this->line('Missing in source: ' . count($notSeen));
        $this->line('Rule counts: ' . $this->formatRuleCounts($ruleCounts));

        if (!empty($missing)) {
            $this->warn('First missing DB keys: ' . implode(', ', array_slice($missing, 0, 10)));
        }

        if (!empty($notSeen)) {
            $this->warn('First missing source keys: ' . implode(', ', array_slice(array_keys($notSeen), 0, 10)));
        }

        if ($processed !== 6236 || !empty($missing) || !empty($notSeen)) {
            $this->error('Import validation failed. Expected exactly 6236 source ayahs mapped to DB ayahs.');
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('Dry run complete. No database rows were changed.');
            return self::SUCCESS;
        }

        DB::transaction(function () use (
            $identifier,
            $name,
            $englishName,
            $qiraatReadingId,
            $replace,
            $rows,
            $chunkSize
        ) {
            DB::table('editions')->updateOrInsert(
                ['identifier' => $identifier],
                [
                    'language' => 'ar',
                    'name' => $name,
                    'english_name' => $englishName,
                    'format' => 'text',
                    'type' => 'quran',
                    'qiraat_reading_id' => $qiraatReadingId > 0 ? $qiraatReadingId : null,
                ]
            );

            $edition = DB::table('editions')
                ->where('identifier', $identifier)
                ->first(['id']);

            if (!$edition) {
                throw new \RuntimeException("Failed to create/find edition {$identifier}.");
            }

            if ($replace) {
                DB::table('ayah_edition')
                    ->where('edition_id', $edition->id)
                    ->delete();
            }

            $written = 0;
            $nextPivotId = ((int) DB::table('ayah_edition')->max('id')) + 1;
            foreach (array_chunk($rows, $chunkSize) as $chunk) {
                foreach ($chunk as $row) {
                    $existing = DB::table('ayah_edition')
                        ->where('ayah_id', $row['ayah_id'])
                        ->where('edition_id', $edition->id)
                        ->first(['id']);

                    if ($existing) {
                        DB::table('ayah_edition')
                            ->where('id', $existing->id)
                            ->update([
                                'data' => $row['data'],
                                'is_audio' => $row['is_audio'],
                            ]);
                    } else {
                        DB::table('ayah_edition')->insert([
                            'id' => $nextPivotId++,
                            'ayah_id' => $row['ayah_id'],
                            'edition_id' => $edition->id,
                            'data' => $row['data'],
                            'is_audio' => $row['is_audio'],
                        ]);
                    }
                    $written++;
                }

                $this->line("Written: {$written}/" . count($rows));
            }
        });

        $this->info("Imported {$processed} ayahs into edition {$identifier}.");

        return self::SUCCESS;
    }

    private function loadAyahMap(): array
    {
        $ayahMap = [];

        DB::table('ayahs')
            ->select('id', 'surah_id', 'number_in_surah')
            ->orderBy('id')
            ->chunkById(500, function ($ayahs) use (&$ayahMap) {
                foreach ($ayahs as $ayah) {
                    $ayahMap["{$ayah->surah_id}:{$ayah->number_in_surah}"] = [
                        'id' => (int) $ayah->id,
                    ];
                }
            });

        return $ayahMap;
    }

    private function resolvePath(string $path): string
    {
        if ($path === '') {
            return base_path();
        }

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function convertSegments(mixed $segments): array
    {
        if (!is_array($segments)) {
            return ['', []];
        }

        $markup = '';
        $ruleCounts = [];

        foreach ($segments as $segment) {
            if (!is_array($segment) || count($segment) < 1) {
                continue;
            }

            $text = (string) ($segment[0] ?? '');
            $rules = is_array($segment[1] ?? null) ? $segment[1] : [];

            $code = $this->resolveBracketCode($rules);
            if ($code === null) {
                $markup .= $text;
                continue;
            }

            $ruleCounts[$code] = ($ruleCounts[$code] ?? 0) + 1;
            $markup .= "[{$code}[{$text}]";
        }

        return [$markup, $ruleCounts];
    }

    private function resolveBracketCode(array $rules): ?string
    {
        $normalized = array_map(static fn ($rule) => (int) $rule, $rules);

        foreach (self::RULE_PRIORITY as $rule) {
            if (in_array($rule, $normalized, true) && isset(self::RULE_CODE_MAP[$rule])) {
                return self::RULE_CODE_MAP[$rule];
            }
        }

        return null;
    }

    private function formatRuleCounts(array $ruleCounts): string
    {
        ksort($ruleCounts);

        return collect($ruleCounts)
            ->map(fn (int $count, string $code) => "{$code}={$count}")
            ->implode(', ');
    }
}
