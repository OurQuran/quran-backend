<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateAyahHTMLMushaf extends Command
{
    protected $signature = 'generate:ayah-html-mushaf
        {--qiraat= : Only process one qiraat_reading_id}
        {--chunk=2000 : Ayah chunk size}
        {--batch=800 : Update batch size (ayah rows) - keep small for big HTML}
        {--dry-run : Do not write, only simulate}';

    protected $description = 'Generate ayah_template HTML from mushaf_words and store into mushaf_ayahs (FAST, UPDATE-only).';

    public function handle(): int
    {
        $chunkSize = max(200, (int) ($this->option('chunk') ?? 2000));
        $batchSize = max(100, (int) ($this->option('batch') ?? 800)); // ✅ smaller (HTML is big)
        $dryRun    = (bool) $this->option('dry-run');

        $q = DB::table('mushaf_ayahs as a')
            ->select('a.id', 'a.qiraat_reading_id', 'a.number_in_surah')
            ->orderBy('a.id');

        $qiraatOpt = $this->option('qiraat');
        if ($qiraatOpt !== null && trim((string) $qiraatOpt) !== '') {
            $q->where('a.qiraat_reading_id', (int) $qiraatOpt);
        }

        $total = (clone $q)->count();
        $this->info("Generating ayah HTML for {$total} mushaf ayahs... (chunk={$chunkSize}, batch={$batchSize}, " . ($dryRun ? 'DRY-RUN' : 'WRITE') . ")");

        $processed = 0;

        // pending updates: [id => ayah_template]
        $pending = [];

        $q->chunkById($chunkSize, function ($ayahs) use (&$processed, &$pending, $batchSize, $dryRun) {
            $ayahIds = $ayahs->pluck('id')->map(fn($v) => (int) $v)->all();
            if (empty($ayahIds)) return;

            // ✅ Pull only needed columns. If word_template exists use it, otherwise build safe span
            $words = DB::table('mushaf_words as w')
                ->select('w.mushaf_ayah_id', 'w.position', 'w.word', 'w.word_template')
                ->whereIn('w.mushaf_ayah_id', $ayahIds)
                ->orderBy('w.mushaf_ayah_id')
                ->orderBy('w.position')
                ->get();

            // group templates by ayah
            $byAyah = [];
            foreach ($words as $w) {
                $ayahId = (int) $w->mushaf_ayah_id;

                $tpl = (string) ($w->word_template ?? '');
                if ($tpl === '') {
                    $safe = htmlspecialchars(trim((string) ($w->word ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $tpl = '<span>' . $safe . '</span>';
                }

                $byAyah[$ayahId][] = $tpl;
            }

            foreach ($ayahs as $a) {
                $processed++;

                $id = (int) $a->id;
                $ayahNumber = (int) $a->number_in_surah;

                $parts = $byAyah[$id] ?? [];
                $ayahText = implode(' ', $parts);

                if ($ayahNumber !== 0) {
                    $ayahText .= ' ' . $this->toQuranicNumber($ayahNumber);
                }

                $ayahHTML = '<div dir="rtl">' . $ayahText . '</div>';

                $pending[$id] = $ayahHTML;

                if (count($pending) >= $batchSize) {
                    $this->flushUpdateOnly($pending, $dryRun);
                    $pending = [];
                }
            }

            unset($words, $byAyah);
            if (function_exists('gc_collect_cycles')) gc_collect_cycles();

            $this->line("Processed={$processed}");
        }, 'id');

        if (!empty($pending)) {
            $this->flushUpdateOnly($pending, $dryRun);
        }

        $this->newLine();
        $this->info("✅ Finished generating ayah HTML for {$processed} ayahs.");

        return self::SUCCESS;
    }

    /**
     * ✅ Postgres-fast update:
     * UPDATE ... FROM (VALUES ...) with explicit casts
     * + hard cap to avoid huge SQL/memory spikes
     */
    private function flushUpdateOnly(array $idToHtml, bool $dryRun): void
    {
        if ($dryRun || empty($idToHtml)) return;

        $MAX = 400; // ✅ keep low because ayah_template is large text
        if (count($idToHtml) > $MAX) {
            foreach (array_chunk($idToHtml, $MAX, true) as $chunk) {
                $this->flushUpdateOnly($chunk, false);
            }
            return;
        }

        $values = [];
        $bindings = [];

        foreach ($idToHtml as $id => $html) {
            $values[] = '(?, ?)';
            $bindings[] = (int) $id;
            $bindings[] = (string) $html;
        }

        $sql = "
            UPDATE mushaf_ayahs AS a
            SET ayah_template = v.ayah_template
            FROM (
                SELECT v.id::bigint AS id, v.ayah_template::text AS ayah_template
                FROM (VALUES " . implode(',', $values) . ") AS v(id, ayah_template)
            ) AS v
            WHERE a.id = v.id
        ";

        DB::update($sql, $bindings);
    }

    private function toQuranicNumber(int $number): string
    {
        static $arabicDigits = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];

        $s = (string) $number;
        $out = '';

        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $d = ord($s[$i]) - 48;
            $out .= ($d >= 0 && $d <= 9) ? $arabicDigits[$d] : '';
        }

        return '﴾' . $out . '﴿';
    }
}
