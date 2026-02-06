<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GeneratePureAyahsMushaf extends Command
{
    protected $signature = 'generate:pure-ayahs-mushaf
        {--qiraat= : Only process one qiraat_reading_id}
        {--chunk=5000 : Read chunk size}
        {--batch=20000 : Update batch size}
        {--dry-run : Do not write, only simulate}';

    protected $description = 'Generate mushaf_ayahs.pure_text by removing diacritics/symbols (FAST, UPDATE-only).';

    public function handle(): int
    {
        $chunk = max(200, (int) ($this->option('chunk') ?? 5000));
        $batch = max(500, (int) ($this->option('batch') ?? 20000));
        $dry   = (bool) $this->option('dry-run');

        $q = DB::table('mushaf_ayahs')
            ->selectRaw('id, qiraat_reading_id, text, pure_text')
            ->orderBy('id');

        $qiraatOpt = $this->option('qiraat');
        if ($qiraatOpt !== null && trim((string) $qiraatOpt) !== '') {
            $q->where('qiraat_reading_id', (int) $qiraatOpt);
        }

        $this->info("Generating pure_text... (chunk={$chunk}, batch={$batch}, " . ($dry ? 'DRY-RUN' : 'WRITE') . ")");

        $processed = 0;
        $updated   = 0;

        // [id => pure_text]
        $pending = [];

        $q->chunkById($chunk, function ($ayahs) use (&$processed, &$updated, &$pending, $batch, $dry) {
            foreach ($ayahs as $a) {
                $processed++;

                $id = $a->id;
                if ($id === null) continue;
                $id = (int) $id;
                if ($id <= 0) continue;

                $text = (string) ($a->text ?? '');
                if ($text === '') continue;

                $pure = $this->removeArabicDiacriticsFast($text);

                if ((string) ($a->pure_text ?? '') === $pure) {
                    continue;
                }

                $pending[$id] = $pure;
                $updated++;

                if (count($pending) >= $batch) {
                    $this->flushUpdateOnly($pending, $dry);
                    $pending = [];
                }
            }

            $this->line("Processed={$processed} | Updated={$updated}");
        }, 'id');

        if (!empty($pending)) {
            $this->flushUpdateOnly($pending, $dry);
        }

        $this->newLine();
        $this->info("✅ Done. Processed={$processed} | Updated={$updated}");
        return self::SUCCESS;
    }

    private function flushUpdateOnly(array $idToPure, bool $dryRun): void
    {
        if ($dryRun || empty($idToPure)) return;

        // ✅ hard cap: never send an enormous statement
        $MAX = 1500; // 500..2000 recommended on 128MB PHP
        if (count($idToPure) > $MAX) {
            foreach (array_chunk($idToPure, $MAX, true) as $chunk) {
                $this->flushUpdateOnly($chunk, false);
            }
            return;
        }

        $values = [];
        $bindings = [];

        foreach ($idToPure as $id => $pure) {
            $values[] = '(?, ?)';
            $bindings[] = (int) $id;
            $bindings[] = (string) $pure;
        }

        $sql = "
        UPDATE mushaf_ayahs AS m
        SET pure_text = v.pure_text
        FROM (
            SELECT v.id::bigint AS id, v.pure_text::text AS pure_text
            FROM (VALUES " . implode(',', $values) . ") AS v(id, pure_text)
        ) AS v
        WHERE m.id = v.id
    ";

        DB::update($sql, $bindings);
    }

    /**
     * Fast remover:
     * - quick skip if no marks exist
     * - normalize alef variants
     * - strip Quranic marks/harakat via cached regex
     */
    private function removeArabicDiacriticsFast(string $text): string
    {
        $text = trim($text);
        if ($text === '') return '';

        // quick skip: if no relevant marks, just normalize alef variants and whitespace
        if (!preg_match('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{08F0}-\x{08FF}\x{200C}\x{200D}\x{FEFF}]/u', $text)) {
            $text = str_replace(['ٱ','آ','أ','إ'], ['ا','ا','ا','ا'], $text);
            $text = preg_replace('/\s+/u', ' ', $text);
            return trim($text);
        }

        // normalize alef variants
        $text = str_replace(['ٱ','آ','أ','إ'], ['ا','ا','ا','ا'], $text);

        static $pattern = null;
        static $extra = null;

        if ($pattern === null) {
            $pattern = '/[' .
                '\x{064B}-\x{0652}' .      // tashkeel
                '\x{0653}-\x{065F}' .      // extended marks (incl maddah)
                '\x{0670}' .               // dagger alif
                '\x{06D6}-\x{06DC}' .      // small marks
                '\x{06DD}' .               // ۝
                '\x{06DE}' .               // ۞
                '\x{06DF}-\x{06E4}' .      // small high letters incl ۤ
                '\x{06E7}' .
                '\x{06E8}' .
                '\x{06EB}-\x{06EC}' .
                '\x{06ED}' .
                '\x{08F0}-\x{08FF}' .      // extended combining
                '\x{200C}\x{200D}\x{FEFF}' . // invisibles
                ']/u';

            $extra = [
                'ۖ','ۗ','ۘ','ۙ','ۚ','ۛ','ۜ','۩','۝','۞',
                'ۡ','ۢ','ۤ','ۥ','ۦ','ۧ','ۨ','ۭ',
                '◌','◌ٰ','◌ّ',
                'ٓ','ٰ','ٕ','ٔ',
            ];
        }

        $pure = preg_replace($pattern, '', $text);
        $pure = str_replace($extra, '', (string) $pure);
        $pure = preg_replace('/\s+/u', ' ', (string) $pure);

        return trim((string) $pure);
    }
}
