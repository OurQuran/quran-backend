<?php

namespace App\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MushafWordArtifactsGenerator
{
    public function generate(Command $command, array $options = []): int
    {
        $chunk = max(200, (int) ($options['chunk'] ?? 5000));
        $batch = max(200, (int) ($options['batch'] ?? 5000));
        $dry = (bool) ($options['dry_run'] ?? false);
        $qiraatId = isset($options['qiraat']) && trim((string) $options['qiraat']) !== ''
            ? (int) $options['qiraat']
            : null;
        $mode = (string) ($options['mode'] ?? 'both');

        $generatePure = in_array($mode, ['both', 'pure'], true);
        $generateHtml = in_array($mode, ['both', 'html'], true);

        $q = DB::table('mushaf_words as w')
            ->selectRaw('w.id as id, w.word, w.pure_word, w.word_template')
            ->orderBy('w.id');

        if ($qiraatId !== null) {
            $q->join('mushaf_ayahs as ma', 'ma.id', '=', 'w.mushaf_ayah_id')
                ->where('ma.qiraat_reading_id', $qiraatId)
                ->distinct();
        }

        $command->info(
            "Generating mushaf word artifacts... mode={$mode} (chunk={$chunk}, batch={$batch}, " .
            ($dry ? 'DRY-RUN' : 'WRITE') . ")"
        );

        $processed = 0;
        $pureUpdated = 0;
        $htmlUpdated = 0;
        $pendingPure = [];
        $pendingHtml = [];

        $q->chunkById($chunk, function ($words) use (
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
            foreach ($words as $w) {
                $processed++;

                $id = (int) ($w->id ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $wordText = trim((string) ($w->word ?? ''));
                if ($wordText === '') {
                    continue;
                }

                if ($generatePure) {
                    $pure = $this->removeArabicDiacriticsFast($wordText);
                    if ((string) ($w->pure_word ?? '') !== $pure) {
                        $pendingPure[$id] = $pure;
                        $pureUpdated++;
                    }
                }

                if ($generateHtml) {
                    $safeWord = htmlspecialchars($wordText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $tpl = '<span id="' . $id . '">' . $safeWord . '</span>';
                    if ((string) ($w->word_template ?? '') !== $tpl) {
                        $pendingHtml[$id] = $tpl;
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

            $command->line("Processed={$processed} | PureUpdated={$pureUpdated} | HtmlUpdated={$htmlUpdated}");
        }, 'w.id', 'id');

        if (!empty($pendingPure)) {
            $this->flushPureUpdates($pendingPure, $dry);
        }

        if (!empty($pendingHtml)) {
            $this->flushHtmlUpdates($pendingHtml, $dry);
        }

        $command->newLine();
        $command->info("✅ Done. Processed={$processed} | PureUpdated={$pureUpdated} | HtmlUpdated={$htmlUpdated}");

        return Command::SUCCESS;
    }

    private function flushPureUpdates(array $idToPure, bool $dryRun): void
    {
        if ($dryRun || empty($idToPure)) {
            return;
        }

        $valuesSql = [];
        $bindings = [];

        foreach ($idToPure as $id => $pure) {
            $valuesSql[] = '(?, ?)';
            $bindings[] = (int) $id;
            $bindings[] = (string) $pure;
        }

        $sql = "
        UPDATE mushaf_words AS w
        SET pure_word = v.pure_word
        FROM (
            SELECT
                v.id::bigint AS id,
                v.pure_word::text AS pure_word
            FROM (VALUES " . implode(',', $valuesSql) . ") AS v(id, pure_word)
        ) AS v
        WHERE w.id = v.id
    ";

        DB::update($sql, $bindings);
    }

    private function flushHtmlUpdates(array $idToTpl, bool $dryRun): void
    {
        if ($dryRun || empty($idToTpl)) {
            return;
        }

        $max = 1500;
        if (count($idToTpl) > $max) {
            foreach (array_chunk($idToTpl, $max, true) as $chunk) {
                $this->flushHtmlUpdates($chunk, false);
            }
            return;
        }

        $valuesSql = [];
        $bindings = [];

        foreach ($idToTpl as $id => $tpl) {
            $valuesSql[] = '(?, ?)';
            $bindings[] = (int) $id;
            $bindings[] = (string) $tpl;
        }

        $sql = "
        UPDATE mushaf_words AS w
        SET word_template = v.word_template
        FROM (
            SELECT v.id::bigint AS id, v.word_template::text AS word_template
            FROM (VALUES " . implode(',', $valuesSql) . ") AS v(id, word_template)
        ) AS v
        WHERE w.id = v.id
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
                'ۡ', 'ۢ', 'ۤ', 'ۥ', 'ۦ', 'ۧ', 'ۨ', 'ۭ', '◌', '◌ٰ', '◌ّ',
                'ٓ', 'ٰ', 'ٕ', 'ٔ',
            ];
        }

        $pure = preg_replace($pattern, '', $text);
        $pure = str_replace($extra, '', (string) $pure);
        $pure = preg_replace('/\s+/u', ' ', (string) $pure);

        return trim((string) $pure);
    }
}
