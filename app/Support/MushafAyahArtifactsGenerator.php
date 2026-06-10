<?php

namespace App\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MushafAyahArtifactsGenerator
{
    public function generate(Command $command, array $options = []): int
    {
        $chunk = max(200, (int) ($options['chunk'] ?? 2000));
        $batch = max(100, (int) ($options['batch'] ?? 800));
        $dry = (bool) ($options['dry_run'] ?? false);
        $qiraatId = isset($options['qiraat']) && trim((string) $options['qiraat']) !== ''
            ? (int) $options['qiraat']
            : null;
        $mode = (string) ($options['mode'] ?? 'both');
        $diffClass = trim((string) ($options['diff_class'] ?? 'qiraat-diff')) ?: 'qiraat-diff';

        $generatePure = in_array($mode, ['both', 'pure'], true);
        $generateHtml = in_array($mode, ['both', 'html'], true);

        $q = DB::table('mushaf_ayahs as a')
            ->select('a.id', 'a.qiraat_reading_id', 'a.number_in_surah', 'a.text', 'a.pure_text', 'a.ayah_template')
            ->orderBy('a.id');

        if ($qiraatId !== null) {
            $q->where('a.qiraat_reading_id', $qiraatId);
        }

        $total = (clone $q)->count();
        $command->info(
            "Generating mushaf ayah artifacts for {$total} ayahs... mode={$mode} " .
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
            $diffClass,
            $command
        ) {
            $ayahIds = $ayahs->pluck('id')->map(fn ($v) => (int) $v)->all();
            $wordTemplatesByAyah = [];

            if ($generateHtml && !empty($ayahIds)) {
                $diffWordIdsByAyah = DB::table('mushaf_word_to_word_map as m')
                    ->join('mushaf_words as w', 'w.id', '=', 'm.mushaf_word_id')
                    ->whereIn('w.mushaf_ayah_id', $ayahIds)
                    ->whereNotNull('m.qiraat_difference_id')
                    ->select('w.mushaf_ayah_id', 'm.mushaf_word_id')
                    ->get()
                    ->groupBy('mushaf_ayah_id')
                    ->map(function ($rows) {
                        return $rows
                            ->pluck('mushaf_word_id')
                            ->map(fn ($id) => (int) $id)
                            ->unique()
                            ->flip()
                            ->all();
                    })
                    ->all();

                $wordTemplates = DB::table('mushaf_words as w')
                    ->select('w.id', 'w.mushaf_ayah_id', 'w.position', 'w.word', 'w.word_template', 'w.pure_word')
                    ->whereIn('w.mushaf_ayah_id', $ayahIds)
                    ->orderBy('w.mushaf_ayah_id')
                    ->orderBy('w.position')
                    ->get();

                $diffWordIdsByAyah = $this->mergeDiffWordIdsByAyah(
                    $diffWordIdsByAyah,
                    $this->diffWordIdsByQiraatDifferenceText($ayahIds, $wordTemplates)
                );

                foreach ($wordTemplates as $w) {
                    $ayahId = (int) $w->mushaf_ayah_id;
                    $wordId = (int) $w->id;
                    $tpl = (string) ($w->word_template ?? '');

                    if ($tpl === '') {
                        $safe = htmlspecialchars(trim((string) ($w->word ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $tpl = '<span id="' . $wordId . '">' . $safe . '</span>';
                    }

                    if (isset($diffWordIdsByAyah[$ayahId][$wordId])) {
                        $tpl = $this->addClassToFirstSpan($tpl, $diffClass);
                    }

                    $wordTemplatesByAyah[$ayahId][] = $tpl;
                }
            }

            foreach ($ayahs as $a) {
                $processed++;

                $id = (int) ($a->id ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $text = (string) ($a->text ?? '');
                if ($text === '') {
                    continue;
                }

                if ($generatePure) {
                    $pure = $this->removeArabicDiacriticsFast($text);
                    if ((string) ($a->pure_text ?? '') !== $pure) {
                        $pendingPure[$id] = $pure;
                        $pureUpdated++;
                    }
                }

                if ($generateHtml) {
                    $parts = $wordTemplatesByAyah[$id] ?? [];
                    $ayahText = implode(' ', $parts);
                    $ayahNumber = (int) ($a->number_in_surah ?? 0);

                    if ($ayahNumber !== 0) {
                        $ayahText .= ' ' . $this->toQuranicNumber($ayahNumber);
                    }

                    $ayahHtml = '<div dir="rtl">' . $ayahText . '</div>';
                    if ((string) ($a->ayah_template ?? '') !== $ayahHtml) {
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

    private function mergeDiffWordIdsByAyah(array $existing, array $fallback): array
    {
        foreach ($fallback as $ayahId => $wordIds) {
            foreach ($wordIds as $wordId => $value) {
                $existing[(int) $ayahId][(int) $wordId] = $value;
            }
        }

        return $existing;
    }

    private function diffWordIdsByQiraatDifferenceText(array $mushafAyahIds, $wordRows): array
    {
        if (empty($mushafAyahIds) || $wordRows->isEmpty()) {
            return [];
        }

        $wordsByAyah = $wordRows
            ->groupBy('mushaf_ayah_id')
            ->map(function ($rows) {
                return $rows->map(function ($word) {
                    return [
                        'id' => (int) $word->id,
                        'norm' => $this->normalizeDiffText((string) ($word->pure_word ?: $word->word ?: '')),
                    ];
                })->values()->all();
            })
            ->all();

        $diffRows = DB::table('mushaf_ayah_to_ayah_map as map')
            ->join('mushaf_ayahs as ma', 'ma.id', '=', 'map.mushaf_ayah_id')
            ->join('ayahs as a', 'a.id', '=', 'map.ayah_id')
            ->join('qiraat_differences as d', function ($join) {
                $join->on('d.qiraat_reading_id', '=', 'ma.qiraat_reading_id')
                    ->on('d.surah', '=', 'a.surah_id')
                    ->on('d.ayah', '=', 'a.number_in_surah');
            })
            ->whereIn('map.mushaf_ayah_id', $mushafAyahIds)
            ->select('map.mushaf_ayah_id', 'd.hafs_text', 'd.qiraat_text', 'd.qiraat_options')
            ->get();

        $diffWordIdsByAyah = [];

        foreach ($diffRows as $diff) {
            $ayahId = (int) $diff->mushaf_ayah_id;
            $words = $wordsByAyah[$ayahId] ?? [];
            if (empty($words)) {
                continue;
            }

            $needles = [
                (string) ($diff->qiraat_text ?? ''),
                (string) ($diff->qiraat_options ?? ''),
                (string) ($diff->hafs_text ?? ''),
            ];

            foreach ($needles as $needleText) {
                $needle = $this->normalizeDiffText($needleText);
                if ($needle === '') {
                    continue;
                }

                $span = $this->findSpanInNormalizedWords($words, $needle);
                if ($span === null) {
                    continue;
                }

                [$start, $end] = $span;
                for ($i = $start; $i <= $end; $i++) {
                    if (isset($words[$i]['id'])) {
                        $diffWordIdsByAyah[$ayahId][(int) $words[$i]['id']] = true;
                    }
                }

                break;
            }
        }

        return $diffWordIdsByAyah;
    }

    private function findSpanInNormalizedWords(array $words, string $needle): ?array
    {
        $count = count($words);
        if ($count === 0 || $needle === '') {
            return null;
        }

        for ($start = 0; $start < $count; $start++) {
            $noSpace = '';
            for ($end = $start; $end < $count; $end++) {
                $noSpace .= $words[$end]['norm'] ?? '';
                if ($noSpace === $needle) {
                    return [$start, $end];
                }
                if (mb_strlen($noSpace) > mb_strlen($needle)) {
                    break;
                }
            }

            $withSpace = '';
            for ($end = $start; $end < $count; $end++) {
                $withSpace .= ($withSpace === '' ? '' : ' ') . ($words[$end]['norm'] ?? '');
                if ($withSpace === $needle) {
                    return [$start, $end];
                }
                if (mb_strlen($withSpace) > mb_strlen($needle)) {
                    break;
                }
            }
        }

        return null;
    }

    private function normalizeDiffText(string $text): string
    {
        return \normalizeArabicForSearch($text);
    }

    private function addClassToFirstSpan(string $html, string $className): string
    {
        $className = trim($className);
        if ($className === '') {
            return $html;
        }

        if (!preg_match('/<span\b([^>]*)>/iu', $html, $match, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $tag = $match[0][0];
        $offset = $match[0][1];
        $attrs = $match[1][0] ?? '';
        $safeClass = htmlspecialchars($className, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if (preg_match('/\sclass=(["\'])(.*?)\1/iu', $attrs, $classMatch)) {
            $existing = preg_split('/\s+/', trim(html_entity_decode($classMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: [];
            if (in_array($className, $existing, true)) {
                return $html;
            }

            $newClassAttr = 'class=' . $classMatch[1] . trim($classMatch[2] . ' ' . $safeClass) . $classMatch[1];
            $newTag = preg_replace('/\sclass=(["\'])(.*?)\1/iu', ' ' . $newClassAttr, $tag, 1);
        } else {
            $newTag = rtrim(substr($tag, 0, -1)) . ' class="' . $safeClass . '">';
        }

        if (!is_string($newTag) || $newTag === '') {
            return $html;
        }

        return substr_replace($html, $newTag, $offset, strlen($tag));
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
        static $arabicDigits = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

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
