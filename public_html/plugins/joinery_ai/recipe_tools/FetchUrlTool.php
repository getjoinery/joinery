<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/QueueableToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ProposedActionFacts.php'));
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
 *
 * The page's bytes are never opened here. This class downloads and converts
 * the charset; FetchUrlReader turns the HTML into text inside the parser jail
 * (DocumentText::parseWith(), specs/parser_jail.md), where libxml2 runs as a
 * user that holds nothing. Reader-mode escalation and the full-page flatten
 * both live there.
 *
 * @version 1.1 - the DOM work moves to FetchUrlReader, behind the jail
 *
 * Queueable (hot-turn egress approval): on a hot turn the URL itself is the
 * egress channel, so the call queues and the card shows the COMPLETE literal
 * URL — wrapped, never truncated, because smuggled data would sit in the
 * cut-off tail.
 */
class FetchUrlTool implements RecipeToolInterface, QueueableToolInterface {

    public function renderProposedAction(array $input): array {
        $url = trim((string)($input['url'] ?? ''));
        $host = '';
        if ($url !== '') {
            $parts = @parse_url($url);
            $host = is_array($parts) ? (string)($parts['host'] ?? '') : '';
        }
        $lines = ['Fetch a web page from ' . ($host !== '' ? $host : '(no host)')];
        $lines = array_merge($lines, ProposedActionFacts::verbatim('URL', $url));
        $mode = strtolower(trim((string)($input['mode'] ?? '')));
        if ($mode === 'full') $lines[] = 'Mode: full page text';
        return $lines;
    }

    const MAX_REDIRECTS = 5;
    const MAX_BODY_BYTES = 2 * 1024 * 1024;     // 2 MB raw download cap
    const MAX_OUTPUT_CHARS = 50000;              // cap returned text to keep tokens bounded
    const TIMEOUT = 15;
    const CONNECT_TIMEOUT = 5;

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

        // HTML opens in the jail: reader mode (visible DOM walk → embedded-data
        // harvest → full strip) and full mode both run in FetchUrlReader.
        $r = DocumentText::parseWith('FetchUrlReader', $body, ['mode' => $mode]);
        if ($r['status'] !== DocumentText::OK && $r['status'] !== DocumentText::EMPTY) {
            throw new Exception('the page could not be read: ' . (string)$r['detail']);
        }
        $answer = json_decode($r['text'], true);
        $text = is_array($answer) && isset($answer['text']) ? (string)$answer['text'] : '';
        $tier_note = is_array($answer) && isset($answer['note']) ? (string)$answer['note'] : '';
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
