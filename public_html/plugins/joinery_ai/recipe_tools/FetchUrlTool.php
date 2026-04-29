<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/UrlSafetyValidator.php'));
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

    public static function name(): string {
        return 'fetch_url';
    }

    public static function description(): string {
        return 'Fetch a URL and return its readable text content. Strips HTML, '
             . 'scripts, and styles. Use this after web_search to read full page '
             . 'contents. Returns up to 50,000 characters of text. Only http(s) '
             . 'URLs to public hosts are allowed; private and local addresses are '
             . 'blocked for safety.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The full http(s) URL to fetch.',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute(array $input, RecipeRunContext $ctx) {
        $url = trim((string)($input['url'] ?? ''));
        if ($url === '') {
            return ['content' => 'fetch_url error: empty URL.', 'is_error' => true];
        }

        try {
            return $this->fetchWithRedirects($url);
        } catch (UnsafeUrlException $e) {
            return ['content' => 'fetch_url blocked: ' . $e->getMessage(), 'is_error' => true];
        } catch (Exception $e) {
            return ['content' => 'fetch_url error: ' . $e->getMessage(), 'is_error' => true];
        }
    }

    private function fetchWithRedirects(string $url) {
        $http = new Client([
            'timeout' => self::TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'allow_redirects' => false,    // we walk redirects manually
            'http_errors' => false,         // we handle non-2xx ourselves
        ]);

        $current_url = $url;
        $hops = 0;
        while ($hops <= self::MAX_REDIRECTS) {
            UrlSafetyValidator::check($current_url);

            try {
                $response = $http->get($current_url, [
                    'headers' => [
                        'User-Agent' => 'Joinery AI Recipe Runner / fetch_url',
                        'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.8',
                    ],
                ]);
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

            return self::extractReadableBody($response, $current_url);
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

    private static function extractReadableBody($response, string $url): string {
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

        if ($is_html) {
            $text = self::htmlToText($body);
        } else {
            $text = $body;
        }

        $text = self::collapseWhitespace($text);

        $note = '';
        if (mb_strlen($text) > self::MAX_OUTPUT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_OUTPUT_CHARS) . "\n…(truncated at " . self::MAX_OUTPUT_CHARS . " chars)";
        }
        if ($truncated_raw) {
            $note .= "\n…(raw body capped at " . self::MAX_BODY_BYTES . " bytes; later content not shown)";
        }

        return "Source: $url\n\n" . $text . $note;
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
