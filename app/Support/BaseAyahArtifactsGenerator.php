<?php

namespace App\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BaseAyahArtifactsGenerator
{
    public function generate(Command $command, array $options = []): int
    {
        $chunk = max(200, (int) ($options['chunk'] ?? 2000));
        $batch = max(100, (int) ($options['batch'] ?? 800));
        $dry = (bool) ($options['dry_run'] ?? false);
        $mode = (string) ($options['mode'] ?? 'both');

        $generatePure = in_array($mode, ['both', 'pure'], true);
        $generateHtml = in_array($mode, ['both', 'html'], true);

        $q = DB::table('ayahs as a')
            ->select('a.id', 'a.number_in_surah', 'a.text', 'a.pure_text', 'a.ayah_template')
            ->orderBy('a.id');

        $total = (clone $q)->count();
        $command->info(
            "Generating base ayah artifacts for {$total} ayahs... mode={$mode} " .
            "(chunk={$chunk}, batch={$batch}, " . ($dry ? 'DRY-RUN' : 'WRITE') . ")"
        );

        $processed = 0;
        $pureUpdated = 0;
        $htmlUpdated = 0;
        $pendingPure = [];
        $pendingHtml = [];

        $q->chunkById($chunk, function ($ayahs) use (
            &$processed,
            &$pureUpdated,
            &$htmlUpdated,
            &$pendingPure,
            &$pendingHtml,
            $batch,
            $dry,
            $generatePure,
            $generateHtml,
            $command
        ) {
            $ayahIds = $ayahs->pluck('id')->map(fn ($v) => (int) $v)->all();
            $wordTemplatesByAyah = [];

            if ($generateHtml && !empty($ayahIds)) {
                $wordTemplates = DB::table('words as w')
                    ->select('w.ayah_id', 'w.position', 'w.word', 'w.word_template')
                    ->whereIn('w.ayah_id', $ayahIds)
                    ->orderBy('w.ayah_id')
                    ->orderBy('w.position')
                    ->orderBy('w.id')
                    ->get();

                foreach ($wordTemplates as $word) {
                    $ayahId = (int) $word->ayah_id;
                    $tpl = (string) ($word->word_template ?? '');

                    if ($tpl === '') {
                        $safe = htmlspecialchars(trim((string) ($word->word ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $tpl = '<span>' . $safe . '</span>';
                    }

                    $wordTemplatesByAyah[$ayahId][] = $tpl;
                }
            }

            foreach ($ayahs as $ayah) {
                $processed++;

                $id = (int) ($ayah->id ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $text = (string) ($ayah->text ?? '');
                if ($text === '') {
                    continue;
                }

                if ($generatePure) {
                    $pure = $this->removeArabicDiacriticsFast($text);
                    if ((string) ($ayah->pure_text ?? '') !== $pure) {
                        $pendingPure[$id] = $pure;
                        $pureUpdated++;
                    }
                }

                if ($generateHtml) {
                    $parts = $wordTemplatesByAyah[$id] ?? [];
                    $ayahText = implode(' ', $parts);
                    $ayahNumber = (int) ($ayah->number_in_surah ?? 0);

                    if ($ayahNumber !== 0) {
                        $ayahText .= ' ' . $this->toQuranicNumber($ayahNumber);
                    }

                    $ayahHtml = '<div dir="rtl">' . $ayahText . '</div>';
                    if ((string) ($ayah->ayah_template ?? '') !== $ayahHtml) {
                        $pendingHtml[$id] = $ayahHtml;
                        $htmlUpdated++;
                    }
                }

                if (count($pendingPure) >= $batch) {
                    $this->flushPureUpdates($pendingPure, $dry);
                    $pendingPure = [];
                }

                if (count($pendingHtml) >= $batch) {
                    $this->flushHtmlUpdates($pendingHtml, $dry);
                    $pendingHtml = [];
                }
            }

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            $command->line("Processed={$processed} | PureUpdated={$pureUpdated} | HtmlUpdated={$htmlUpdated}");
        }, 'id');

        if (!empty($pendingPure)) {
            $this->flushPureUpdates($pendingPure, $dry);
        }

        if (!empty($pendingHtml)) {
            $this->flushHtmlUpdates($pendingHtml, $dry);
        }

        $command->newLine();
        $command->info("✅ Finished. Processed={$processed} | PureUpdated={$pureUpdated} | HtmlUpdated={$htmlUpdated}");

        return Command::SUCCESS;
    }

    private function flushPureUpdates(array $idToPure, bool $dryRun): void
    {
        if ($dryRun || empty($idToPure)) {
            return;
        }

        $max = 1500;
        if (count($idToPure) > $max) {
            foreach (array_chunk($idToPure, $max, true) as $chunk) {
                $this->flushPureUpdates($chunk, false);
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
        UPDATE ayahs AS a
        SET pure_text = v.pure_text
        FROM (
            SELECT v.id::bigint AS id, v.pure_text::text AS pure_text
            FROM (VALUES " . implode(',', $values) . ") AS v(id, pure_text)
        ) AS v
        WHERE a.id = v.id
    ";

        DB::update($sql, $bindings);
    }

    private function flushHtmlUpdates(array $idToHtml, bool $dryRun): void
    {
        if ($dryRun || empty($idToHtml)) {
            return;
        }

        $max = 400;
        if (count($idToHtml) > $max) {
            foreach (array_chunk($idToHtml, $max, true) as $chunk) {
                $this->flushHtmlUpdates($chunk, false);
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
            UPDATE ayahs AS a
            SET ayah_template = v.ayah_template
            FROM (
                SELECT v.id::bigint AS id, v.ayah_template::text AS ayah_template
                FROM (VALUES " . implode(',', $values) . ") AS v(id, ayah_template)
            ) AS v
            WHERE a.id = v.id
        ";

        DB::update($sql, $bindings);
    }

    private function removeArabicDiacriticsFast(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        if (!preg_match('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{08F0}-\x{08FF}\x{200C}\x{200D}\x{FEFF}]/u', $text)) {
            $text = str_replace(['ٱ', 'آ', 'أ', 'إ'], ['ا', 'ا', 'ا', 'ا'], $text);
            $text = preg_replace('/\s+/u', ' ', $text);
            return trim($text);
        }

        $text = str_replace(['ٱ', 'آ', 'أ', 'إ'], ['ا', 'ا', 'ا', 'ا'], $text);

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
                'ۖ', 'ۗ', 'ۘ', 'ۙ', 'ۚ', 'ۛ', 'ۜ', '۩', '۝', '۞',
                'ۡ', 'ۢ', 'ۤ', 'ۥ', 'ۦ', 'ۧ', 'ۨ', 'ۭ',
                '◌', '◌ٰ', '◌ّ',
                'ٓ', 'ٰ', 'ٕ', 'ٔ',
            ];
        }

        $pure = preg_replace($pattern, '', $text);
        $pure = str_replace($extra, '', (string) $pure);
        $pure = preg_replace('/\s+/u', ' ', (string) $pure);

        return trim((string) $pure);
    }

    private function toQuranicNumber(int $number): string
    {
        $arabicDigits = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        return '﴾' . implode('', array_map(fn ($digit) => $arabicDigits[(int) $digit], str_split((string) $number))) . '﴿';
    }
}
