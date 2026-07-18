<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('includes/UrlSafetyValidator.php'));
require_once(PathHelper::getComposerAutoloadPath());

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Fetch a URL and return readable text.
 *
 * Every candidate URL — initial and every redirect target — passes through
 * UrlSafetyValidator before any network is touched. Defenses are layered: the
 * validator handles SSRF, Guzzle is configured with timeouts and a response
 * size cap, and we manually walk redirects (5 hops max) so the validator can
 * re-check each one.
 */
class FetchUrlTool implements RecipeToolInterface {

    const MAX_REDIRECTS = 5;
    const MAX_BODY_BYTES = 2 * 1024 * 1024;     // 2 MB raw download cap
    const MAX_OUTPUT_CHARS = 50000;              // cap returned text to keep tokens bounded
    const TIMEOUT = 15;
    const CONNECT_TIMEOUT = 5;
    const READER_FLOOR_CHARS = 200;              // below this, a reader tier is "thin" and we escalate

    public static function name(): string {
        return 'fetch_url';
    }

    public static function description(): string {
        return 'Fetch a URL and return its readable content. Two modes: '
             . 'mode="reader" (default) returns just the main article as clean '
             . 'Markdown — headings, lists, tables, and links survive; navigation, '
             . 'ads, cookie banners, scripts, and styles are removed. This is the '
             . 'token-cheap view and the right choice almost always. mode="full" '
             . 'returns the whole page flattened to plain text — use it only for '
             . 'pages that are not a single article (search results, link hubs, '
             . 'directory/index pages, dashboards) where the main-content view would '
             . 'discard what you need. Reader mode automatically falls back to a '
             . 'page\'s embedded data (JSON-LD / framework JSON / OpenGraph) for '
             . 'JavaScript-rendered pages, and then to full-page text, when the '
             . 'visible content is empty — you do not need to request that. Use this '
             . 'after web_search to read full page contents. Returns up to 50,000 '
             . 'characters. Only http(s) URLs to public hosts are allowed; private '
             . 'and local addresses are blocked for safety.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The full http(s) URL to fetch.',
                ],
                'mode' => [
                    'type' => 'string',
                    'enum' => ['reader', 'full'],
                    'description' => 'How to extract the page. "reader" (default): '
                        . 'main article only, as Markdown. "full": entire page as '
                        . 'flat text — only for non-article pages (search results, '
                        . 'link hubs, indexes).',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute(array $input, ToolContext $ctx) {
        $url = trim((string)($input['url'] ?? ''));
        if ($url === '') {
            return ['content' => 'fetch_url error: empty URL.', 'is_error' => true];
        }
        $mode = strtolower(trim((string)($input['mode'] ?? 'reader')));
        if ($mode !== 'full') $mode = 'reader';

        try {
            return $this->fetchWithRedirects($url, $mode);
        } catch (UnsafeUrlException $e) {
            return ['content' => 'fetch_url blocked: ' . $e->getMessage(), 'is_error' => true];
        } catch (Exception $e) {
            return ['content' => 'fetch_url error: ' . $e->getMessage(), 'is_error' => true];
        }
    }

    private function fetchWithRedirects(string $url, string $mode = 'reader') {
        $http = new Client([
            'timeout' => self::TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'allow_redirects' => false,    // we walk redirects manually
            'http_errors' => false,         // we handle non-2xx ourselves
        ]);

        $current_url = $url;
        $hops = 0;
        while ($hops <= self::MAX_REDIRECTS) {
            // Validate the URL and capture the exact IPs it resolved to.
            $pin = UrlSafetyValidator::checkAndResolve($current_url);

            $request_opts = [
                'headers' => [
                    'User-Agent' => 'Joinery AI Recipe Runner / fetch_url',
                    'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.8',
                ],
            ];
            // Pin the connection to the IPs just validated, so the fetch
            // cannot be DNS-rebound onto a private address between the
            // safety check and the connect. Curl still uses the real
            // hostname for SNI, the Host header, and certificate checks.
            // Requires the curl handler — Guzzle's default when ext-curl
            // is present.
            if (!empty($pin['ips'])) {
                $request_opts['curl'] = [
                    CURLOPT_RESOLVE => [
                        $pin['host'] . ':' . $pin['port'] . ':' . implode(',', $pin['ips']),
                    ],
                ];
            }

            try {
                $response = $http->get($current_url, $request_opts);
            } catch (RequestException $e) {
                throw new Exception('HTTP error: ' . $e->getMessage());
            }

            $status = $response->getStatusCode();

            if ($status >= 300 && $status < 400) {
                $location = $response->getHeaderLine('Location');
                if (!$location) {
                    throw new Exception("Redirect response with no Location header (status $status).");
                }
                $current_url = self::resolveRelative($current_url, $location);
                $hops++;
                continue;
            }

            if ($status >= 400) {
                throw new Exception("HTTP $status from $current_url");
            }

            return self::extractReadableBody($response, $current_url, $mode);
        }

        throw new Exception('Too many redirects (>' . self::MAX_REDIRECTS . ').');
    }

    private static function resolveRelative(string $base, string $location): string {
        if (preg_match('#^https?://#i', $location)) return $location;

        $parts = parse_url($base);
        if (!$parts) throw new UnsafeUrlException('Cannot resolve redirect against malformed base URL.');
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $authority = "$scheme://$host$port";

        if (strpos($location, '/') === 0) {
            return $authority . $location;
        }
        $path = $parts['path'] ?? '/';
        $dir = substr($path, 0, strrpos($path, '/') + 1);
        return $authority . $dir . $location;
    }

    private static function extractReadableBody($response, string $url, string $mode = 'reader'): string {
        $stream = $response->getBody();
        $body = '';
        while (!$stream->eof() && strlen($body) < self::MAX_BODY_BYTES) {
            $body .= $stream->read(8192);
        }
        $truncated_raw = !$stream->eof();
        $stream->close();

        $content_type = strtolower($response->getHeaderLine('Content-Type'));
        $is_html = strpos($content_type, 'html') !== false;

        $charset = self::detectCharset($content_type, $body);
        if ($charset && $charset !== 'utf-8') {
            $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
            if ($converted !== false) $body = $converted;
        }

        // Note carried into the output: raw-download cap is independent of mode.
        $note = '';
        if ($truncated_raw) {
            $note .= "\n…(raw body capped at " . self::MAX_BODY_BYTES . " bytes; later content not shown)";
        }

        // Non-HTML (JSON, plain text, CSV, …) bypasses extraction in either mode.
        if (!$is_html) {
            return self::cap("Source: $url\n\n", self::collapseWhitespace($body), $note);
        }

        if ($mode === 'full') {
            $text = self::collapseWhitespace(self::htmlToText($body));
            return self::cap("Source: $url\n\n", $text, $note);
        }

        // reader mode: visible DOM walk → embedded-data harvest → full strip.
        [$text, $tier_note] = self::readerExtract($body);
        return self::cap("Source: $url\n\n", $text, $tier_note . $note);
    }

    /**
     * Apply the output cap and assemble the final block. Truncation is marked
     * inside the text region; any tier/raw note follows it.
     */
    private static function cap(string $header, string $text, string $note): string {
        if (mb_strlen($text) > self::MAX_OUTPUT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_OUTPUT_CHARS)
                  . "\n…(truncated at " . self::MAX_OUTPUT_CHARS . " chars)";
        }
        return $header . $text . $note;
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

    private static function detectCharset(string $content_type, string $body): ?string {
        if (preg_match('#charset=([\w-]+)#i', $content_type, $m)) {
            return strtolower($m[1]);
        }
        if (preg_match('#<meta[^>]*charset=["\']?([\w-]+)#i', substr($body, 0, 4096), $m)) {
            return strtolower($m[1]);
        }
        return null;
    }

    private static function collapseWhitespace(string $text): string {
        $text = preg_replace("/[ \t]+/u", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        return trim($text);
    }

}
