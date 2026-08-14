<?php

/**
 * Thin HTTP client for the persona browser service (the Node+Playwright reader
 * running on the Mac — see specs/persona_browser_service.md). Reads its endpoint
 * and bearer token from settings and calls the service over the tailnet.
 *
 * The service opens a hand-logged-in Firefox profile headlessly and returns the
 * raw markup of each captured post plus the images it cached; this class
 * shuttles requests, runs the markup through FacebookFeedExtractor, and
 * normalizes the result into one of a few states the caller cares about.
 */
class PersonaBrowserClient {

    private $endpoint;
    private $token;

    public function __construct() {
        $settings = Globalvars::get_instance();
        $this->endpoint = rtrim((string)$settings->get_setting('persona_browser_endpoint'), '/');
        $this->token = (string)$settings->get_setting('persona_browser_token');
    }

    public function is_configured(): bool {
        return $this->endpoint !== '' && $this->token !== '';
    }

    private function http() {
        require_once(PathHelper::getComposerAutoloadPath());
        return new \GuzzleHttp\Client(['timeout' => 120, 'connect_timeout' => 5]);
    }

    /**
     * Fetch the feed for a persona.
     *
     * @return array{state:string, items:array, stories:array, url:?string, error:?string}
     *   state is one of: 'ok', 'needs_login', 'not_configured', 'unreachable'.
     *   Each item carries the full extractor shape and each story the tray-teaser
     *   shape (see FacebookFeedExtractor's docblocks); media[] are the service's
     *   cached image filenames. The service returns raw post markup;
     *   FacebookFeedExtractor turns it into these items.
     */
    public function get_feed(string $persona = 'facebook'): array {
        if (!$this->is_configured()) {
            return ['state' => 'not_configured', 'items' => [], 'stories' => [], 'url' => null, 'error' => null];
        }

        try {
            $response = $this->http()->post($this->endpoint . '/content', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type'  => 'application/json',
                ],
                'body' => json_encode(['persona' => $persona]),
            ]);
        } catch (\Exception $e) {
            return ['state' => 'unreachable', 'items' => [], 'stories' => [], 'url' => null, 'error' => $e->getMessage()];
        }

        $decoded = json_decode((string)$response->getBody(), true);
        if (!is_array($decoded)) {
            return ['state' => 'unreachable', 'items' => [], 'stories' => [], 'url' => null, 'error' => 'non-JSON response'];
        }
        if (!empty($decoded['needsLogin']) || empty($decoded['loggedIn'])) {
            return ['state' => 'needs_login', 'items' => [], 'stories' => [], 'url' => $decoded['url'] ?? null, 'error' => null];
        }

        $posts = array_values(array_filter((array)($decoded['posts'] ?? []), 'is_string'));
        $media = is_array($decoded['media'] ?? null) ? $decoded['media'] : [];
        $items = FacebookFeedExtractor::extract($posts, $media);
        $stories = FacebookFeedExtractor::extractStories($posts, $media);

        return ['state' => 'ok', 'items' => $items, 'stories' => $stories, 'url' => $decoded['url'] ?? null, 'error' => null];
    }

    /**
     * Download one cached media file from the service to a local path.
     * Returns true on success. Skips (returns true) if the destination exists.
     */
    public function fetch_media(string $file, string $dest_path): bool {
        if (!$this->is_configured()) return false;
        $file = basename($file);
        if ($file === '' || strpos($file, '..') !== false) return false;
        if (file_exists($dest_path)) return true;

        try {
            $response = $this->http()->get($this->endpoint . '/media/' . rawurlencode($file), [
                'headers' => ['Authorization' => 'Bearer ' . $this->token],
            ]);
        } catch (\Exception $e) {
            return false;
        }
        if ($response->getStatusCode() !== 200) return false;
        return file_put_contents($dest_path, (string)$response->getBody()) !== false;
    }
}
