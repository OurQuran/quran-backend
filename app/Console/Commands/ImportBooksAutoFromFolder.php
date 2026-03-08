<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportBooksAutoFromFolder extends Command
{
    protected $signature = 'books:import-auto
        {--dir= : Absolute directory path containing HTML files (default: storage/app/books)}
        {--split-level=2 : Split on heading level h<level> (2=h2, 3=h3, etc)}
        {--dry-run : don\'t write to database}
    ';

    protected $description = 'Auto-import HTML files into book_sections + book_section_refs (text-only with cite offsets), mapping book_id from filename, and splitting each HTML into multiple sections.';

    public function handle(): int
    {
        $dir = (string) ($this->option('dir') ?: storage_path('app/books'));
        $splitLevel = max(1, min(6, (int) $this->option('split-level')));
        $dryRun = (bool) $this->option('dry-run');

        if (!is_dir($dir)) {
            $this->error("Directory not found: {$dir}");
            return self::FAILURE;
        }

        $paths = glob(rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.html') ?: [];
        sort($paths, SORT_STRING);

        if (empty($paths)) {
            $this->warn("No .html files found in: {$dir}");
            return self::SUCCESS;
        }

        // Keyword -> seeded book name (MUST match DB exactly)
        $keywordToBookName = [
            'ابن كثير' => 'تفسير ابن كثير',
            'ابن عامر' => 'القراءات - ابن عامر',
            'ابو جعفر' => 'القراءات - أبو جعفر',
            'أبو جعفر' => 'القراءات - أبو جعفر',
            'ابو عمرو' => 'القراءات - أبو عمرو',
            'أبو عمرو' => 'القراءات - أبو عمرو',
            'حمزة' => 'القراءات - حمزة',
            'خلف العاشر' => 'القراءات - خلف العاشر',
            'شعبة' => 'القراءات - شعبة',
            'قالون' => 'القراءات - قالون',
            'كسائي' => 'القراءات - الكسائي',
            'الكسائي' => 'القراءات - الكسائي',
            'ورش' => 'القراءات - ورش',
            'يعقوب' => 'القراءات - يعقوب',
        ];

        // Load book_id map once
        $bookIdsByName = DB::table('books')->pluck('id', 'name')->all();

        // Next order per book
        $nextOrder = [];

        $this->info("Scanning: {$dir}");
        $this->info("Found: " . count($paths) . " HTML files; split-level=h{$splitLevel}" . ($dryRun ? " (dry-run)" : ""));

        $importedSections = 0;

        foreach ($paths as $absPath) {
            $base = pathinfo($absPath, PATHINFO_FILENAME);

            $bookName = $this->detectBookName($base, $keywordToBookName);
            if (!$bookName) {
                $this->warn("Skipping (no book match): {$absPath}");
                continue;
            }

            $bookId = $bookIdsByName[$bookName] ?? null;
            if (!$bookId) {
                $this->warn("Skipping (book not found in DB): '{$bookName}' for file {$absPath}");
                continue;
            }

            if (!isset($nextOrder[$bookId])) {
                $max = (int) (DB::table('book_sections')->where('book_id', $bookId)->max('order_no') ?? 0);
                $nextOrder[$bookId] = $max + 1;
            }

            $startOrderNo = $nextOrder[$bookId];

            $this->line("==> book_id={$bookId} ({$bookName}) start_order_no={$startOrderNo} file={$absPath}");

            if ($dryRun) {
                $count = $this->estimateChunks($absPath, $splitLevel);
                $this->line("    (dry-run) would import ~{$count} sections");
                $nextOrder[$bookId] += max(1, $count);
                $importedSections += max(1, $count);
                continue;
            }

            $inserted = $this->importSingleHtmlIntoDbMany($bookId, $startOrderNo, $absPath, $splitLevel);

            if ($inserted <= 0) {
                $nextOrder[$bookId] += 1;
            } else {
                $nextOrder[$bookId] += $inserted;
                $importedSections += $inserted;
            }
        }

        $this->info("✅ Done. Imported {$importedSections} sections.");
        return self::SUCCESS;
    }

    private function detectBookName(string $filename, array $keywordToBookName): ?string
    {
        // Prefer longest keyword match first
        uksort($keywordToBookName, fn($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));

        foreach ($keywordToBookName as $kw => $bookName) {
            if (mb_strpos($filename, $kw, 0, 'UTF-8') !== false) {
                return $bookName;
            }
        }
        return null;
    }

    private function estimateChunks(string $filePath, int $splitLevel): int
    {
        $html = @file_get_contents($filePath);
        if ($html === false) return 1;

        $tag = 'h' . $splitLevel;
        $count = preg_match_all('/<' . $tag . '\b/i', $html);
        return max(1, (int) $count);
    }

    /**
     * Parse one Pandoc HTML file and insert MANY book_sections rows by splitting on heading level,
     * excluding headings that are inside tables (to avoid splitting on glossary table headings).
     */
    private function importSingleHtmlIntoDbMany(int $bookId, int $startOrderNo, string $filePath, int $splitLevel): int
    {
        $html = file_get_contents($filePath);
        if ($html === false) {
            $this->error("Failed to read: {$filePath}");
            return 0;
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        // Base header from Pandoc title-block
        $baseHeader = '';
        $headerNode = $xpath->query('//*[@id="title-block-header"]')->item(0);
        if ($headerNode) {
            $baseHeader = $this->normalizeWhitespace($this->nodePlainText($headerNode));
        }

        // Global references map fnN -> ref_text
        $globalRefs = [];
        $footnoteLis = $xpath->query('//section[contains(@class,"footnotes")]//ol/li[@id]');
        foreach ($footnoteLis as $li) {
            /** @var \DOMElement $li */
            $id = $li->getAttribute('id');
            if (!preg_match('/^fn(\d+)$/', $id, $m)) continue;
            $refNo = (int) $m[1];

            $refText = $this->nodePlainText($li, ignoreSelectors: [
                './/a[contains(@class,"footnote-back")]',
            ]);

            $globalRefs[$refNo] = $this->normalizeWhitespace($refText);
        }

        $main = $xpath->query('//main')->item(0);
        $rootForBody = $main ?: $xpath->query('//body')->item(0);

        if (!$rootForBody) {
            $this->error("Could not find body content in HTML: {$filePath}");
            return 0;
        }

        $chunks = $this->splitIntoChunks($rootForBody, $splitLevel);

        $inserted = 0;
        $orderNo = $startOrderNo;

        foreach ($chunks as $chunk) {
            $citeOffsets = [];
            $bodyText = $this->extractNodesTextWithCites($chunk['nodes'], $citeOffsets);
            $bodyText = $this->normalizeWhitespace($bodyText);

            if ($bodyText === '') continue;

            $headerText = $baseHeader;
            if (!empty($chunk['heading_text'])) {
                $headerText = trim(($headerText ? ($headerText . "\n") : '') . $chunk['heading_text']);
            }
            $headerText = $this->normalizeWhitespace($headerText);

            // Only refs used in this chunk
            $refsUsed = [];
            foreach (array_keys($citeOffsets) as $refNo) {
                $refsUsed[$refNo] = $globalRefs[$refNo] ?? '';
            }
            ksort($refsUsed);

            DB::transaction(function () use ($bookId, $orderNo, $headerText, $bodyText, $refsUsed, $citeOffsets) {
                DB::table('book_sections')->updateOrInsert(
                    ['book_id' => $bookId, 'order_no' => $orderNo],
                    [
                        'header_text' => $headerText ?: null,
                        'body_text' => $bodyText,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                $section = DB::table('book_sections')
                    ->where('book_id', $bookId)
                    ->where('order_no', $orderNo)
                    ->first(['id']);

                $bookSectionId = (int) $section->id;

                DB::table('book_section_refs')->where('book_section_id', $bookSectionId)->delete();

                foreach ($refsUsed as $refNo => $refText) {
                    $offsets = $citeOffsets[$refNo] ?? [];
                    DB::table('book_section_refs')->insert([
                        'book_section_id' => $bookSectionId,
                        'ref_no' => (int) $refNo,
                        'ref_text' => $refText,
                        'cite_offsets' => json_encode(array_values($offsets), JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });

            $inserted++;
            $orderNo++;
        }

        if ($inserted === 0) {
            $this->warn("No chunks inserted for file: {$filePath} (try --split-level=3, or your DOCX may not have headings)");
        }

        return $inserted;
    }

    /**
     * Split root content into chunks by heading level h<splitLevel>.
     * Important: we only split on headings that are NOT inside <table> (to avoid glossary tables).
     */
    private function splitIntoChunks(\DOMNode $rootForBody, int $splitLevel): array
    {
        $splitTag = 'h' . $splitLevel;
        $chunks = [];

        $children = [];
        foreach ($rootForBody->childNodes as $child) {
            if ($child instanceof \DOMText && trim($child->textContent) === '') continue;
            $children[] = $child;
        }

        $current = ['heading_text' => null, 'nodes' => []];

        foreach ($children as $node) {
            if ($node instanceof \DOMElement) {

                // Skip title block if present
                if ($node->hasAttribute('id') && $node->getAttribute('id') === 'title-block-header') {
                    continue;
                }

                // Skip footnotes section
                if ($node->tagName === 'section' && $node->hasAttribute('class') && str_contains($node->getAttribute('class'), 'footnotes')) {
                    continue;
                }

                // Split only if:
                // - tag matches (h2/h3/...)
                // - NOT inside a table
                if (strtolower($node->tagName) === $splitTag && !$this->isInsideTag($node, 'table')) {
                    if (!empty($current['nodes'])) {
                        $chunks[] = $current;
                    }

                    $current = [
                        'heading_text' => $this->normalizeWhitespace($node->textContent ?? ''),
                        'nodes' => [],
                    ];

                    // do not include heading node in body
                    continue;
                }
            }

            $current['nodes'][] = $node;
        }

        if (!empty($current['nodes'])) {
            $chunks[] = $current;
        }

        if (empty($chunks)) {
            $chunks[] = ['heading_text' => null, 'nodes' => $children];
        }

        return $chunks;
    }

    private function isInsideTag(\DOMNode $node, string $tag): bool
    {
        $tag = strtolower($tag);
        $p = $node->parentNode;
        while ($p) {
            if ($p instanceof \DOMElement && strtolower($p->tagName) === $tag) {
                return true;
            }
            $p = $p->parentNode;
        }
        return false;
    }

    /**
     * Extract plain text from an array of nodes, converting footnote refs into [N]
     * and computing cite offsets relative to the resulting text.
     */
    private function extractNodesTextWithCites(array $nodes, array &$citeOffsets): string
    {
        $out = '';

        $walk = function (\DOMNode $node) use (&$walk, &$out, &$citeOffsets) {
            if ($node instanceof \DOMElement) {
                // Skip footnotes anywhere
                if ($node->tagName === 'section' && $node->hasAttribute('class') && str_contains($node->getAttribute('class'), 'footnotes')) {
                    return;
                }

                // Convert footnote refs into [N]
                if ($node->tagName === 'a' && $node->hasAttribute('class') && str_contains($node->getAttribute('class'), 'footnote-ref')) {
                    $href = $node->getAttribute('href');
                    if (preg_match('/#fn(\d+)/', $href, $m)) {
                        $refNo = (int) $m[1];
                        $marker = '[' . $refNo . ']';

                        $start = mb_strlen($out, 'UTF-8');
                        $out .= $marker;
                        $end = mb_strlen($out, 'UTF-8');

                        $citeOffsets[$refNo] ??= [];
                        $citeOffsets[$refNo][] = ['start' => $start, 'end' => $end];
                        return;
                    }
                }

                // Add newlines around block-ish elements
                if (in_array($node->tagName, ['p','div','section','header','main','article','h1','h2','h3','h4','h5','h6','li','table','tr'], true)) {
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
                if (in_array($node->tagName, ['p','div','section','header','main','article','li','table','tr'], true)) {
                    $out .= "\n";
                }
            }
        };

        foreach ($nodes as $n) {
            $walk($n);
            $out .= "\n";
        }

        return $out;
    }

    /**
     * Extract plain text from a node, with optional XPath selectors to ignore.
     */
    private function nodePlainText(\DOMNode $node, array $ignoreSelectors = []): string
    {
        $clone = $node->cloneNode(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->appendChild($dom->importNode($clone, true));
        $xp = new \DOMXPath($dom);

        foreach ($ignoreSelectors as $sel) {
            $nodes = $xp->query($sel);
            foreach ($nodes as $n) {
                $n->parentNode?->removeChild($n);
            }
        }

        return $dom->textContent ?? '';
    }

    private function normalizeWhitespace(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace("/[ \t]+/u", " ", $s);
        $s = preg_replace("/\n{3,}/u", "\n\n", $s);
        return trim($s);
    }
}
