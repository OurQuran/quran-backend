<?php

namespace App\Console\Commands\Books;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportHtmlBooksFromFolder extends Command
{
    protected $signature = 'books:import-html-folder
        {--dir= : Directory containing HTML files (default: <project_root>/html_files)}
        {--split-level=1 : Split on heading level h<level> (1=h1, 2=h2, etc.)}
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

        $this->info("Scanning: {$dir}");
        $this->info(
            'Found: ' . count($paths) . " HTML files; split-level=h{$splitLevel}"
            . ($dryRun ? ' (dry-run)' : '')
            . ($force ? ' (--force)' : '')
        );

        $totalSections = 0;

        foreach ($paths as $absPath) {
            $count = $this->processFile($absPath, $dir, $splitLevel, $dryRun, $force);
            if ($count >= 0) {
                $totalSections += $count;
            }
        }

        $this->info("Done. Imported {$totalSections} sections.");
        return self::SUCCESS;
    }

    // -------------------------------------------------------------------------

    private function processFile(
        string $filePath,
        string $htmlDir,
        int $splitLevel,
        bool $dryRun,
        bool $force
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
        $existing = DB::table('books')->where('name', $bookName)->first(['id']);
        if ($existing) {
            $bookId = (int) $existing->id;
            $this->line("    Found book id={$bookId}");
        } else {
            $bookId = (int) DB::table('books')->insertGetId([
                'name' => $bookName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->line("    Created book id={$bookId}");
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
            $bodyText = $this->normalizeWhitespace(
                $this->extractPlainText($chunk['nodes'], $citeOffsets)
            );

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
                        'heading_text' => $this->normalizeWhitespace($node->textContent ?? ''),
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
     * - Converts footnote-ref anchors to [N] markers and records their byte offsets
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

                // Block-level: open newline
                if (in_array($node->tagName, [
                    'p', 'div', 'section', 'header', 'main', 'article',
                    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
                    'li', 'table', 'tr',
                ], true)) {
                    $out .= "\n";
                }
            }

            if ($node->nodeType === XML_TEXT_NODE) {
                $out .= $node->nodeValue;
                return;
            }

            foreach ($node->childNodes as $child) {
                $walk($child);
            }

            if ($node instanceof \DOMElement) {
                // Block-level: close newline
                if (in_array($node->tagName, [
                    'p', 'div', 'section', 'header', 'main', 'article',
                    'li', 'table', 'tr',
                ], true)) {
                    $out .= "\n";
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
}
