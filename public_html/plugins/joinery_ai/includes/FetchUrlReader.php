<?php
/**
 * FetchUrlReader — turns a fetched web page into the readable text the AI's
 * fetch_url tool returns, inside the parser jail (specs/parser_jail.md).
 *
 * A page the model asked for is a stranger's bytes, and libxml2 is the C
 * parser that opens it. So FetchUrlTool downloads (in the pool, with every
 * URL checked) and hands the body here through DocumentText::parseWith();
 * this class runs in the extraction subprocess as a user that holds no key,
 * no config and no database, and answers with JSON:
 *
 *   {"text": "...", "note": ""}   — note is a one-line remark when a
 *                                   reader-mode fallback fired
 *
 * Two modes, chosen by the caller in the options: `reader` (default) — the
 * main article as Markdown, escalating to the page's embedded data (JSON-LD,
 * OpenGraph) and then to the full flattened page when the visible walk comes
 * back thin; `full` — the whole page flattened to text.
 *
 * Depends on nothing but core: this file is loaded by path in a process that
 * resolves no plugin class.
 *
 * @version 1.0 - the DOM half of FetchUrlTool, moved behind the jail
 */

class FetchUrlReader implements SandboxParserInterface {

    const READER_FLOOR_CHARS = 200;   // below this, a reader tier is "thin" and we escalate

    public static function sandboxParse(string $bytes, array $options): string {
        $mode = strtolower(trim((string)($options['mode'] ?? 'reader')));
        if ($mode === 'full') {
            $text = self::collapseWhitespace(self::htmlToText($bytes));
            $note = '';
        } else {
            [$text, $note] = self::readerExtract($bytes);
        }
        $json = json_encode(array('text' => $text, 'note' => $note),
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '{"text":"","note":""}' : $json;
    }

    /**
     * Reader-mode three-tier escalation. Returns [text, note]. Each tier kicks
     * in only when the one above comes back below READER_FLOOR_CHARS (or the DOM
     * fails to parse). The model never picks a tier — it gets the best available
     * and a one-line note when a fallback fired.
     */
    private static function readerExtract(string $html): array {
        $doc = self::loadDom($html);

        // Tier 1: visible main-content walk. Harvest embedded data first, since
        // it lives in <script> tags the walk is about to strip.
        $title = '';
        $embedded = null;
        $markdown = '';
        if ($doc) {
            $title = self::extractTitle($doc);
            $embedded = self::harvestEmbeddedData($doc);
            $markdown = self::collapseWhitespace(self::domToReaderMarkdown($doc));
        }

        if (mb_strlen($markdown) >= self::READER_FLOOR_CHARS) {
            return [self::prefixTitle($title, $markdown), ''];
        }

        // Tier 2: embedded-data harvest (JSON-LD / framework JSON / OG meta).
        $harvested = self::collapseWhitespace(self::renderEmbedded($embedded));
        if (mb_strlen($harvested) >= self::READER_FLOOR_CHARS) {
            return [
                self::prefixTitle($title, $harvested),
                "\n…(visible page was empty; read from the page's embedded data)",
            ];
        }

        // Tier 3: full-page flatten — the same path mode="full" uses.
        $full = self::collapseWhitespace(self::htmlToText($html));
        return [
            self::prefixTitle($title, $full),
            "\n…(reader view found little content; showing full-page text)",
        ];
    }

    /**
     * Load attacker-controlled HTML into a DOMDocument. LIBXML_NONET prevents the
     * parser from fetching external DTDs/entities; the XML encoding hint forces
     * UTF-8 interpretation (the body is already converted to UTF-8 upstream).
     * Returns null when the markup is empty or unparseable.
     */
    private static function loadDom(string $html): ?DOMDocument {
        if (trim($html) === '') return null;
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok ? $doc : null;
    }

    private static function extractTitle(DOMDocument $doc): string {
        $nodes = $doc->getElementsByTagName('title');
        return $nodes->length > 0 ? trim($nodes->item(0)->textContent) : '';
    }

    private static function prefixTitle(string $title, string $body): string {
        $title = trim($title);
        $body = trim($body);
        if ($title === '') return $body;
        if ($body === '') return $title;
        return $title . "\n\n" . $body;
    }

    /**
     * Strip chrome, pick the main block, and walk it to Markdown. Mutates $doc.
     */
    private static function domToReaderMarkdown(DOMDocument $doc): string {
        self::removeTags($doc, [
            'script', 'style', 'noscript', 'nav', 'header', 'footer',
            'aside', 'form', 'iframe', 'svg', 'button',
        ]);
        self::removeJunkByAttr($doc);

        $main = self::pickMainBlock($doc);
        if (!$main) return '';
        return self::walkChildren($main);
    }

    private static function removeTags(DOMDocument $doc, array $tags): void {
        foreach ($tags as $tag) {
            // Snapshot the live NodeList — removing during iteration skips nodes.
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $node) {
                if ($node->parentNode) $node->parentNode->removeChild($node);
            }
        }
    }

    private static function removeJunkByAttr(DOMDocument $doc): void {
        $pattern = '#(nav|menu|sidebar|footer|header|comment|share|social|cookie'
                 . '|banner|promo|advert|related|recommend|popup|modal|newsletter'
                 . '|subscribe)#i';
        $xpath = new DOMXPath($doc);
        foreach (iterator_to_array($xpath->query('//*[@class or @id]')) as $node) {
            $attr = $node->getAttribute('class') . ' ' . $node->getAttribute('id');
            if ($attr !== ' ' && preg_match($pattern, $attr) && $node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }
    }

    /**
     * Prefer a semantic <main>/<article>; otherwise score block containers by
     * text length discounted by link density (a mostly-links div is a menu, not
     * prose). Falls back to <body>.
     */
    private static function pickMainBlock(DOMDocument $doc): ?DOMNode {
        $xpath = new DOMXPath($doc);

        foreach (['main', 'article'] as $tag) {
            $best = null; $best_len = -1;
            foreach ($xpath->query('//' . $tag) as $node) {
                $len = mb_strlen(trim($node->textContent));
                if ($len > $best_len) { $best_len = $len; $best = $node; }
            }
            if ($best && $best_len > 0) return $best;
        }

        $best = null; $best_score = 0.0;
        foreach ($xpath->query('//body//div | //body//section') as $node) {
            $score = self::scoreNode($node);
            if ($score > $best_score) { $best_score = $score; $best = $node; }
        }
        if ($best) return $best;

        $body = $xpath->query('//body')->item(0);
        return $body ?: $doc->documentElement;
    }

    private static function scoreNode(DOMNode $node): float {
        $text_len = mb_strlen(trim($node->textContent));
        if ($text_len === 0) return 0.0;
        $link_len = 0;
        if ($node instanceof DOMElement) {
            foreach ($node->getElementsByTagName('a') as $a) {
                $link_len += mb_strlen(trim($a->textContent));
            }
        }
        $density = min(1.0, $link_len / $text_len);
        return $text_len * (1.0 - $density);
    }

    /** Recursively emit Markdown for an element's children. */
    private static function walkChildren(DOMNode $node): string {
        $out = '';
        foreach ($node->childNodes as $child) {
            $out .= self::walkNode($child);
        }
        return $out;
    }

    private static function walkNode(DOMNode $node): string {
        if ($node->nodeType === XML_TEXT_NODE) {
            return preg_replace('/\s+/u', ' ', $node->nodeValue);
        }
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        $tag = strtolower($node->nodeName);
        switch ($tag) {
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                $level = (int)substr($tag, 1);
                $t = trim(self::walkChildren($node));
                return $t === '' ? '' : "\n\n" . str_repeat('#', $level) . ' ' . $t . "\n\n";
            case 'li':
                $t = trim(self::walkChildren($node));
                return $t === '' ? '' : "\n- " . $t;
            case 'a':
                $t = trim(self::walkChildren($node));
                if ($t === '') return '';
                $href = $node instanceof DOMElement ? trim($node->getAttribute('href')) : '';
                if ($href === '' || stripos($href, 'javascript:') === 0) return $t;
                return '[' . $t . '](' . $href . ')';
            case 'strong': case 'b':
                $t = trim(self::walkChildren($node));
                return $t === '' ? '' : '**' . $t . '**';
            case 'em': case 'i':
                $t = trim(self::walkChildren($node));
                return $t === '' ? '' : '*' . $t . '*';
            case 'br':
                return "\n";
            case 'img':
                return '';   // images dropped (alt text not worth the noise)
            case 'th': case 'td':
                return trim(self::walkChildren($node)) . ' | ';
            case 'tr':
                return "\n" . self::walkChildren($node);
            case 'p': case 'div': case 'section': case 'table': case 'ul':
            case 'ol': case 'blockquote': case 'pre': case 'figure': case 'figcaption':
                return "\n\n" . self::walkChildren($node) . "\n\n";
            default:
                return self::walkChildren($node);
        }
    }

    /**
     * Capture the page's structured-data blobs before the visible walk strips
     * the <script> tags they live in. Used only if the visible walk is thin.
     */
    private static function harvestEmbeddedData(DOMDocument $doc): array {
        $data = ['jsonld' => [], 'meta' => []];
        $xpath = new DOMXPath($doc);

        foreach ($xpath->query('//script[@type="application/ld+json"]') as $s) {
            $json = json_decode(trim($s->textContent), true);
            if (is_array($json)) $data['jsonld'][] = $json;
        }
        foreach ($xpath->query('//meta[@property or @name]') as $m) {
            $key = $m->getAttribute('property');
            if ($key === '') $key = $m->getAttribute('name');
            $content = $m->getAttribute('content');
            if ($key !== '' && $content !== '') {
                $data['meta'][strtolower($key)] = $content;
            }
        }
        return $data;
    }

    /**
     * Render harvested data as a Markdown body: prefer JSON-LD
     * articleBody/headline, then fall to an OpenGraph/meta summary.
     */
    private static function renderEmbedded(?array $data): string {
        if (!$data) return '';

        foreach ($data['jsonld'] ?? [] as $block) {
            $found = self::scanJsonLd($block);
            if ($found !== '') return $found;
        }

        $meta = $data['meta'] ?? [];
        $title = $meta['og:title'] ?? ($meta['title'] ?? '');
        $desc = $meta['og:description'] ?? ($meta['description'] ?? '');
        $parts = [];
        if ($title !== '') $parts[] = '# ' . $title;
        if ($desc !== '') $parts[] = $desc;
        return trim(implode("\n\n", $parts));
    }

    /** Recursively look for an article (headline + articleBody) in a JSON-LD node. */
    private static function scanJsonLd($node): string {
        if (!is_array($node)) return '';

        $headline = (isset($node['headline']) && is_string($node['headline'])) ? $node['headline'] : '';
        $body = (isset($node['articleBody']) && is_string($node['articleBody'])) ? $node['articleBody'] : '';
        if ($body !== '') {
            return ($headline !== '' ? '# ' . $headline . "\n\n" : '') . $body;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $found = self::scanJsonLd($value);
                if ($found !== '') return $found;
            }
        }
        return '';
    }

    private static function htmlToText(string $html): string {
        // Drop scripts and styles entirely (they contain noise but no readable text).
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $html);
        $html = preg_replace('#<noscript\b[^>]*>.*?</noscript>#is', '', $html);
        // Convert block-ish tags to newlines so paragraphs survive strip_tags.
        $html = preg_replace('#</?(p|div|br|h[1-6]|li|tr|article|section|header|footer|nav|main)\b[^>]*>#i', "\n", $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return $text;
    }

    private static function collapseWhitespace(string $text): string {
        $text = preg_replace("/[ \t]+/u", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }
}
