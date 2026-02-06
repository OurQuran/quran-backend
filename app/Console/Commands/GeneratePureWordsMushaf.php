<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GeneratePureWordsMushaf extends Command
{
    protected $signature = 'generate:pure-words-mushaf
        {--qiraat= : Only process one qiraat_reading_id (via join to mushaf_ayahs)}
        {--chunk=5000 : Read chunk size}
        {--batch=5000 : Update batch size (POSTGRES sweet spot 1k-10k)}
        {--dry-run : Do not write, only simulate}';

    protected $description = 'Generate mushaf_words.pure_word (FAST, UPDATE-only, Postgres optimized).';

    public function handle(): int
    {
        $chunk = max(200, (int) ($this->option('chunk') ?? 5000));
        $batch = max(200, (int) ($this->option('batch') ?? 5000));
        $dry   = (bool) $this->option('dry-run');

        $q = DB::table('mushaf_words as w')
            ->selectRaw('w.id as id, w.word, w.pure_word')
            ->orderBy('w.id');

        $qiraatOpt = trim((string) ($this->option('qiraat') ?? ''));
        if ($qiraatOpt !== '') {
            $qiraatId = (int) $qiraatOpt;
            $q->join('mushaf_ayahs as ma', 'ma.id', '=', 'w.mushaf_ayah_id')
                ->where('ma.qiraat_reading_id', $qiraatId)
                ->distinct(); // ✅ protects chunking if join duplicates happen
        }

        $this->info("Generating pure_word... (chunk={$chunk}, batch={$batch}, " . ($dry ? 'DRY-RUN' : 'WRITE') . ")");

        $processed = 0;
        $updated   = 0;
        $pending   = []; // [id => pure_word]

        $q->chunkById($chunk, function ($words) use (&$processed, &$updated, &$pending, $batch, $dry) {
            foreach ($words as $w) {
                $processed++;

                $id = (int) ($w->id ?? 0);
                if ($id <= 0) continue;

                $original = (string) ($w->word ?? '');
                if ($original === '') continue;

                $pure = $this->removeArabicDiacriticsFast($original);

                if ((string) ($w->pure_word ?? '') === $pure) {
                    continue;
                }

                $pending[$id] = $pure;
                $updated++;

                if (count($pending) >= $batch) {
                    $this->flushUpdateOnlyValues($pending, $dry);
                    $pending = [];
                }
            }

            $this->line("Processed={$processed} | Updated={$updated}");
        }, 'w.id', 'id');

        if (!empty($pending)) {
            $this->flushUpdateOnlyValues($pending, $dry);
        }

        $this->newLine();
        $this->info("✅ Done. Processed={$processed} | Updated={$updated}");
        return self::SUCCESS;
    }

    /**
     * ✅ Postgres-fast bulk update:
     * UPDATE mushaf_words w
     * SET pure_word = v.pure_word
     * FROM (VALUES (id, pure_word), ...) v(id, pure_word)
     * WHERE w.id = v.id
     */
    private function flushUpdateOnlyValues(array $idToPure, bool $dryRun): void
    {
        if ($dryRun || empty($idToPure)) return;

        $valuesSql = [];
        $bindings  = [];

        foreach ($idToPure as $id => $pure) {
            $valuesSql[] = '(?, ?)';
            $bindings[]  = (int) $id;
            $bindings[]  = (string) $pure;
        }

        $sql = "
        UPDATE mushaf_words AS w
        SET pure_word = v.pure_word
        FROM (
            SELECT
                v.id::bigint      AS id,
                v.pure_word::text AS pure_word
            FROM (VALUES " . implode(',', $valuesSql) . ") AS v(id, pure_word)
        ) AS v
        WHERE w.id = v.id
    ";

        DB::update($sql, $bindings);
    }

    private function removeArabicDiacriticsFast(string $text): string
    {
        $text = trim($text);
        if ($text === '') return '';

        if (!preg_match('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{08F0}-\x{08FF}\x{200C}\x{200D}\x{FEFF}]/u', $text)) {
            $text = str_replace(['ٱ','آ','أ','إ'], ['ا','ا','ا','ا'], $text);
            $text = preg_replace('/\s+/u', ' ', $text);
            return trim($text);
        }

        $text = str_replace(['ٱ','آ','أ','إ'], ['ا','ا','ا','ا'], $text);

        static $pattern = null;
        static $extra = null;

        if ($pattern === null) {
            $pattern = '/[' .
                '\x{064B}-\x{0652}' .
                '\x{0653}-\x{065F}' .
                '\x{0670}' .
                '\x{06D6}-\x{06DC}' .
                '\x{06DD}' .
                '\x{06DE}' .
                '\x{06DF}-\x{06E4}' .
                '\x{06E7}' .
                '\x{06E8}' .
                '\x{06EB}-\x{06EC}' .
                '\x{06ED}' .
                '\x{08F0}-\x{08FF}' .
                '\x{200C}\x{200D}\x{FEFF}' .
                ']/u';

            $extra = [
                'ۖ','ۗ','ۘ','ۙ','ۚ','ۛ','ۜ','۩','۝','۞',
                'ۡ','ۢ','ۤ','ۥ','ۦ','ۧ','ۨ','ۭ','◌','◌ٰ','◌ّ',
                'ٓ','ٰ','ٕ','ٔ',
            ];
        }

        $pure = preg_replace($pattern, '', $text);
        $pure = str_replace($extra, '', (string) $pure);
        $pure = preg_replace('/\s+/u', ' ', (string) $pure);

        return trim((string) $pure);
    }
}
