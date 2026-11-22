<?php

namespace App\Console\Commands\Books;

use App\Support\QiraatImportMaps;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportHtmlBooksFromFolder extends Command
{
    /** Separator between the cells of one table row in body_text (U+2502). The frontend splits on it. */
    public const CELL_SEP = ' │ ';

    protected $signature = 'books:import-html-folder
        {--dir= : Directory containing HTML files (default: <project_root>/html_files)}
        {--split-level=1 : Split on heading level h<level> (1=h1, 2=h2, etc.)}
        {--language=ku : Language of the books in this folder (ku or ar). The same book name can exist once per language}
        {--pdf-dir= : Directory under private storage containing PDFs named after each HTML file}
        {--force : Delete existing sections for matched books before importing}
        {--dry-run : Don\'t write to database}
    ';

    protected $description = 'Import HTML files into books + book_sections + book_section_refs. Uses <title> as book name, splits by heading level, stores plain text, footnote refs with offsets, and base64-encoded images.';

    public function handle(): int
    {
        $dir = rtrim((string) ($this->option('dir') ?: base_path('html_files')), DIRECTORY_SEPARATOR);
        $splitLevel = max(1, min(6, (int) $this->option('split-level')));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $language = strtolower(trim((string) $this->option('language')));
        $pdfDir = trim((string) $this->option('pdf-dir'), '/');

        if (!in_array($language, ['ku', 'ar'], true)) {
            $this->error("Invalid --language '{$language}'. Use ku or ar.");
            return self::FAILURE;
        }

        if (!is_dir($dir)) {
            $this->error("Directory not found: {$dir}");
            return self::FAILURE;
        }

        $paths = glob($dir . DIRECTORY_SEPARATOR . '*.html') ?: [];
        sort($paths, SORT_STRING);

        if (empty($paths)) {
            $this->warn("No .html files found in: {$dir}");
            return self::SUCCESS;
        }

        if ($pdfDir !== '') {
            if (!preg_match('~\Abooks(?:/[A-Za-z0-9_-]+)+\z~D', $pdfDir)) {
                $this->error('Invalid --pdf-dir. Use a relative directory such as books/layout-v2.');
                return self::FAILURE;
            }
            foreach ($paths as $path) {
                $pdf = $pdfDir . '/' . pathinfo($path, PATHINFO_FILENAME) . '.pdf';
                if (!is_file(storage_path('app/private/' . $pdf))) {
                    $this->error("Missing PDF for " . basename($path) . ": {$pdf}");
                    return self::FAILURE;
                }
            }
        }

        $this->info("Scanning: {$dir}");
        $this->info(
            'Found: ' . count($paths) . " HTML files; split-level=h{$splitLevel}; language={$language}"
            . ($dryRun ? ' (dry-run)' : '')
            . ($force ? ' (--force)' : '')
        );

        $totalSections = 0;
        $failed = false;

        foreach ($paths as $absPath) {
            $count = $this->processFile($absPath, $dir, $splitLevel, $dryRun, $force, $language, $pdfDir);
            if ($count >= 0) {
                $totalSections += $count;
            } else {
                $failed = true;
            }
        }

        $this->info("Done. Imported {$totalSections} sections.");
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function processFile(
        string $filePath,
        string $htmlDir,
        int $splitLevel,
        bool $dryRun,
        bool $force,
        string $language,
        string $pdfDir
    ): int {
        $html = @file_get_contents($filePath);
        if ($html === false) {
            $this->error("Cannot read: {$filePath}");
            return -1;
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Book name comes from <title>
        $titleNode = $xpath->query('//head/title')->item(0);
        $bookName = $titleNode ? trim($titleNode->textContent) : '';

        if ($bookName === '') {
            $this->warn("No <title> found in: {$filePath} — skipping.");
            return -1;
        }

        $bookName = $this->canonicalBookName($bookName, basename($filePath));

        $this->line("==> [{$bookName}]  {$filePath}");

        $body = $xpath->query('//body')->item(0);
        if (!$body) {
            $this->error("No <body> in: {$filePath}");
            return -1;
        }

        // Build global ref map: fnN -> ref_text (from <section class="footnotes">)
        $globalRefs = $this->extractGlobalRefs($xpath);

        $chunks = $this->splitIntoChunks($body, $splitLevel);

        if ($dryRun) {
            $this->line('    (dry-run) would import ' . count($chunks) . ' sections.');
            return count($chunks);
        }

        // Find or create book
        $existing = DB::table('books')
            ->where('name', $bookName)
            ->where('language', $language)
            ->first(['id', 'pdf_path']);
        if ($existing) {
            $bookId = (int) $existing->id;
            $this->line("    Found book id={$bookId} ({$language})");
        } else {
            $bookId = (int) DB::table('books')->insertGetId([
                'name' => $bookName,
                'language' => $language,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->line("    Created book id={$bookId}");
        }

        // Arabic editions ship a generated PDF: link it so the download button appears
        $pdfPath = $pdfDir !== ''
            ? $pdfDir . '/' . pathinfo($filePath, PATHINFO_FILENAME) . '.pdf'
            : (($language === 'ar' && empty($existing?->pdf_path) && isset(QiraatImportMaps::arabicBookPdfByName()[$bookName]))
                ? 'books/' . QiraatImportMaps::arabicBookPdfByName()[$bookName] : null);
        if ($pdfPath !== null && is_file(storage_path('app/private/' . $pdfPath))) {
            DB::table('books')->where('id', $bookId)->update(['pdf_path' => $pdfPath]);
            $this->line("    PDF linked: {$pdfPath}");
        }

        // The eight Arabic covers credit this preparer. Introduction writers on those
        // covers are not labelled as checkers, so do not assign them a checker role.
        if ($language === 'ar' && isset(QiraatImportMaps::arabicBookPdfByName()[$bookName])) {
            DB::table('book_people')->upsert(
                [['book_id' => $bookId, 'role' => 'author', 'name' => 'د. أحمد ضيف الله أبو سمهدانة',
                    'order_no' => 1, 'updated_at' => now(), 'created_at' => now()]],
                ['book_id', 'role', 'name'],
                ['order_no', 'updated_at']
            );
        }

        if ($language === 'ku') {
            foreach (QiraatImportMaps::seededBooks() as $metadata) {
                if ($metadata['name'] !== $bookName) {
                    continue;
                }
                foreach (['authors' => 'author', 'supervisors' => 'supervisor'] as $key => $role) {
                    foreach ($metadata[$key] ?? [] as $index => $name) {
                        DB::table('book_people')->upsert(
                            [['book_id' => $bookId, 'role' => $role, 'name' => $name,
                                'order_no' => $index + 1, 'created_at' => now(), 'updated_at' => now()]],
                            ['book_id', 'role', 'name'], ['order_no', 'updated_at']
                        );
                    }
                }
                break;
            }
        }

        // Optionally wipe existing sections (cascade deletes refs too)
        $existingCount = DB::table('book_sections')->where('book_id', $bookId)->count();
        if ($existingCount > 0 && $force) {
            DB::table('book_sections')->where('book_id', $bookId)->delete();
            $this->line("    Deleted {$existingCount} existing sections (--force).");
        }

        $inserted = 0;
        $orderNo = 1;

        foreach ($chunks as $chunk) {
            $citeOffsets = [];
            $rawText = $this->extractPlainText($chunk['nodes'], $citeOffsets);
            $bodyText = $this->normalizeWhitespace($rawText);
            foreach ($citeOffsets as $refNo => &$offsets) {
                foreach ($offsets as &$offset) {
                    // A non-whitespace sentinel keeps the prefix's trailing spaces and
                    // newlines while applying exactly the same normalization as the body.
                    $prefix = mb_substr($rawText, 0, $offset['start'], 'UTF-8');
                    $start = mb_strlen($this->normalizeWhitespace($prefix . "\u{200B}"), 'UTF-8') - 1;
                    $marker = '[' . $refNo . ']';
                    $end = $start + mb_strlen($marker, 'UTF-8');
                    if (mb_substr($bodyText, $start, $end - $start, 'UTF-8') !== $marker) {
                        throw new \RuntimeException("Unable to map footnote {$refNo} in {$filePath}");
                    }
                    $offset = ['start' => $start, 'end' => $end];
                }
                unset($offset);
            }
            unset($offsets);

            if ($bodyText === '') {
                continue;
            }

            // Resolve only refs referenced in this chunk
            $refsUsed = [];
            foreach (array_keys($citeOffsets) as $refNo) {
                $refsUsed[$refNo] = $globalRefs[$refNo] ?? '';
            }
            ksort($refsUsed);

            $images = $this->extractImages($chunk['nodes'], $htmlDir);

            DB::transaction(function () use (
                $bookId, $orderNo, $chunk, $bodyText, $images, $refsUsed, $citeOffsets
            ) {
                DB::table('book_sections')->updateOrInsert(
                    ['book_id' => $bookId, 'order_no' => $orderNo],
                    [
                        'header_text' => ($chunk['heading_text'] !== null && $chunk['heading_text'] !== '')
                            ? $chunk['heading_text']
                            : null,
                        'body_text'  => $bodyText,
                        'images'     => $images ? json_encode($images, JSON_UNESCAPED_UNICODE) : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                $section = DB::table('book_sections')
                    ->where('book_id', $bookId)
                    ->where('order_no', $orderNo)
                    ->first(['id']);

                $sectionId = (int) $section->id;

                // Replace refs for this section
                DB::table('book_section_refs')->where('book_section_id', $sectionId)->delete();

                foreach ($refsUsed as $refNo => $refText) {
                    DB::table('book_section_refs')->insert([
                        'book_section_id' => $sectionId,
                        'ref_no'          => (int) $refNo,
                        'ref_text'        => $refText,
                        'cite_offsets'    => json_encode(
                            array_values($citeOffsets[$refNo] ?? []),
                            JSON_UNESCAPED_UNICODE
                        ),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });

            $inserted++;
            $orderNo++;
        }

        $this->line("    Imported {$inserted} sections.");
        return $inserted;
    }

    // -------------------------------------------------------------------------

    /**
     * Parse <section class="footnotes"> and return [refNo => ref_text] map.
     */
    private function extractGlobalRefs(\DOMXPath $xpath): array
    {
        $refs = [];

        $footnoteLis = $xpath->query('//section[contains(@class,"footnotes")]//ol/li[@id]');
        foreach ($footnoteLis as $li) {
            /** @var \DOMElement $li */
            $id = $li->getAttribute('id');
            if (!preg_match('/^fn(\d+)$/', $id, $m)) {
                continue;
            }
            $refNo = (int) $m[1];

            // Remove the back-link anchor before extracting text
            $clone = $li->cloneNode(true);
            $cloneDom = new \DOMDocument('1.0', 'UTF-8');
            $cloneDom->appendChild($cloneDom->importNode($clone, true));
            $cloneXp = new \DOMXPath($cloneDom);
            foreach ($cloneXp->query('.//a[contains(@class,"footnote-back")]') as $backLink) {
                $backLink->parentNode?->removeChild($backLink);
            }

            $refs[$refNo] = $this->normalizeWhitespace($cloneDom->textContent ?? '');
        }

        return $refs;
    }

    /**
     * Split the <body> into chunks by the target heading level.
     * Skips: #title-block-header, .footnotes section, headings inside <table>.
     */
    private function splitIntoChunks(\DOMNode $body, int $splitLevel): array
    {
        $splitTag = 'h' . $splitLevel;
        $chunks   = [];

        $children = [];
        foreach ($body->childNodes as $child) {
            if ($child instanceof \DOMText && trim($child->textContent) === '') {
                continue;
            }
            $children[] = $child;
        }

        $current = ['heading_text' => null, 'nodes' => []];

        foreach ($children as $node) {
            if ($node instanceof \DOMElement) {
                // Skip Pandoc title block
                if ($node->getAttribute('id') === 'title-block-header') {
                    continue;
                }

                // Skip footnotes section
                if ($node->tagName === 'section'
                    && str_contains($node->getAttribute('class'), 'footnotes')
                ) {
                    continue;
                }

                // Split on matching heading not inside a <table>
                if (strtolower($node->tagName) === $splitTag
                    && !$this->isInsideTag($node, 'table')
                ) {
                    if (!empty($current['nodes'])) {
                        $chunks[] = $current;
                    }
                    $current = [
                        'heading_text' => trim((string) preg_replace('/\s+/u', ' ', $node->textContent ?? '')),
                        'nodes'        => [],
                    ];
                    continue;
                }
            }

            $current['nodes'][] = $node;
        }

        if (!empty($current['nodes'])) {
            $chunks[] = $current;
        }

        // Fallback: single chunk containing all children
        if (empty($chunks)) {
            $chunks[] = ['heading_text' => null, 'nodes' => $children];
        }

        return $chunks;
    }

    /**
     * Walk DOM nodes and return plain text.
     * - Skips <img> (handled separately)
     * - Skips .footnotes section
     * - Converts footnote-ref anchors to [N] markers and records Unicode character offsets
     * - Adds newlines around block-level elements
     *
     * @param \DOMNode[] $nodes
     * @param array      $citeOffsets  [refNo => [{start, end}, ...]]  (populated in-place)
     */
    private function extractPlainText(array $nodes, array &$citeOffsets): string
    {
        $out      = '';
        $imgOrder = 0;
        $walk = function (\DOMNode $node) use (&$walk, &$out, &$citeOffsets, &$imgOrder): void {
            if ($node instanceof \DOMElement) {
                // Skip footnotes section
                if ($node->tagName === 'section'
                    && str_contains($node->getAttribute('class'), 'footnotes')
                ) {
                    return;
                }

                // Emit inline placeholder at the exact position of the image
                if ($node->tagName === 'img') {
                    $out .= "\n{{img" . (++$imgOrder) . "}}\n";
                    return;
                }

                // Convert footnote-ref anchors to inline [N] markers + record offsets
                if ($node->tagName === 'a'
                    && str_contains($node->getAttribute('class'), 'footnote-ref')
                ) {
                    $href = $node->getAttribute('href');
                    if (preg_match('/#fn(\d+)/', $href, $m)) {
                        $refNo  = (int) $m[1];
                        $marker = '[' . $refNo . ']';
                        $start  = mb_strlen($out, 'UTF-8');
                        $out   .= $marker;
                        $end    = mb_strlen($out, 'UTF-8');

                        $citeOffsets[$refNo] ??= [];
                        $citeOffsets[$refNo][] = ['start' => $start, 'end' => $end];
                        return;
                    }
                }

                // Table cells: a row stays on ONE line, cells separated by CELL_SEP, so the
                // viewer can rebuild the table. Anything block-level inside a cell is flattened.
                if (in_array($node->tagName, ['td', 'th'], true)) {
                    $prev = $node->previousSibling;
                    while ($prev && !($prev instanceof \DOMElement)) {
                        $prev = $prev->previousSibling;
                    }
                    if ($prev) {
                        $out .= self::CELL_SEP;
                    }
                } elseif (in_array($node->tagName, [
                    'p', 'div', 'section', 'header', 'main', 'article',
                    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                    'li', 'table', 'tr',
                ], true)) {
                    // Block-level: open newline (a space when inside a table cell)
                    $out .= ($node->tagName !== 'tr' && $node->tagName !== 'table'
                        && $this->isInsideCell($node)) ? ' ' : "\n";
                } elseif ($node->tagName === 'br') {
                    $out .= $this->isInsideCell($node) ? ' ' : "\n";
                }
            }

            if ($node->nodeType === XML_TEXT_NODE) {
                $value  = (string) $node->nodeValue;
                $parent = $node->parentNode instanceof \DOMElement ? strtolower($node->parentNode->tagName) : '';
                // Indentation between <tr>/<td> is not content
                if (trim($value) === '' && in_array($parent, ['table', 'thead', 'tbody', 'tfoot', 'tr'], true)) {
                    return;
                }
                // In HTML a newline inside text is plain whitespace (pandoc hard-wraps at ~72 cols);
                // real breaks come from block tags / <br>, otherwise sentences get cut in pieces.
                $out .= preg_replace('/[ \t]*\n[ \t]*/u', ' ', $value);
                return;
            }

            foreach ($node->childNodes as $child) {
                $walk($child);
            }

            if ($node instanceof \DOMElement) {
                // Block-level: close newline (a space when inside a table cell)
                if (in_array($node->tagName, [
                    'p', 'div', 'section', 'header', 'main', 'article',
                    'li', 'table', 'tr',
                ], true)) {
                    $out .= ($node->tagName !== 'tr' && $node->tagName !== 'table'
                        && $this->isInsideCell($node)) ? ' ' : "\n";
                }
            }
        };

        foreach ($nodes as $node) {
            $walk($node);
            $out .= "\n";
        }

        return $out;
    }

    /**
     * Walk DOM nodes, find every <img>, resolve path relative to $htmlDir,
     * and return [{order, filename, mime, data(base64)}] array.
     */
    private function extractImages(array $nodes, string $htmlDir): array
    {
        $images = [];
        $order  = 1;

        $walk = function (\DOMNode $node) use (&$walk, &$images, &$order, $htmlDir): void {
            if ($node instanceof \DOMElement && $node->tagName === 'img') {
                $src = $node->getAttribute('src');
                if ($src === '') {
                    return;
                }

                $imgPath = $htmlDir . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $src);

                if (!is_file($imgPath)) {
                    $this->warn("    Image not found: {$imgPath}");
                    return;
                }

                $ext  = strtolower(pathinfo($imgPath, PATHINFO_EXTENSION));
                $mime = match ($ext) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png'         => 'image/png',
                    'gif'         => 'image/gif',
                    'webp'        => 'image/webp',
                    default       => 'application/octet-stream',
                };

                $raw = file_get_contents($imgPath);
                if ($raw === false) {
                    $this->warn("    Cannot read image: {$imgPath}");
                    return;
                }

                $images[] = [
                    'order'    => $order,
                    'filename' => basename($imgPath),
                    'mime'     => $mime,
                    'data'     => base64_encode($raw),
                ];
                $order++;
                return;
            }

            foreach ($node->childNodes as $child) {
                $walk($child);
            }
        };

        foreach ($nodes as $node) {
            $walk($node);
        }

        return $images;
    }

    // -------------------------------------------------------------------------

    private function isInsideCell(\DOMNode $node): bool
    {
        return $this->isInsideTag($node, 'td') || $this->isInsideTag($node, 'th');
    }

    private function isInsideTag(\DOMNode $node, string $tag): bool
    {
        $tag = strtolower($tag);
        $p   = $node->parentNode;
        while ($p) {
            if ($p instanceof \DOMElement && strtolower($p->tagName) === $tag) {
                return true;
            }
            $p = $p->parentNode;
        }
        return false;
    }

    private function normalizeWhitespace(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[ \t]+/u', ' ', $s);
        $s = preg_replace('/\n{3,}/u', "\n\n", $s);
        return trim($s);
    }

    private function canonicalBookName(string $title, string $filename): string
    {
        $haystack = $this->normalizeBookKey($filename . ' ' . $title);

        foreach (QiraatImportMaps::booksByFilenameKeyword() as $keyword => $bookName) {
            if (str_contains($haystack, $this->normalizeBookKey($keyword))) {
                return $bookName;
            }
        }

        return trim($title);
    }

    private function normalizeBookKey(string $value): string
    {
        $value = \normalizeArabicForSearch($value);
        $value = str_replace(['ى', 'ي', 'ك', 'ک'], ['ی', 'ی', 'ک', 'ک'], $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim((string) $value);
    }
}
