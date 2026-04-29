<?php
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeToolInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/RecipeRunContext.php'));
require_once(PathHelper::getComposerAutoloadPath());

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Web search via Brave Search API (free tier).
 *
 * Brave was picked for v1 per spec — abstracted via WebSearchProvider would be
 * future-proofing for one provider. Swap by editing this file.
 */
class WebSearchTool implements RecipeToolInterface {

    const ENDPOINT = 'https://api.search.brave.com/res/v1/web/search';
    const MAX_RESULTS = 10;

    public static function name(): string {
        return 'web_search';
    }

    public static function description(): string {
        return 'Search the web for current information. Returns a list of '
             . 'results with title, URL, and a short snippet. Use for news, '
             . 'recent events, fact-checking, and finding pages worth fetching '
             . 'for full content with fetch_url.';
    }

    public static function inputSchema(): array {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query.',
                ],
                'count' => [
                    'type' => 'integer',
                    'description' => 'Number of results to return (1-10, default 5).',
                    'minimum' => 1,
                    'maximum' => 10,
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $input, RecipeRunContext $ctx) {
        $query = trim($input['query'] ?? '');
        if ($query === '') {
            return ['content' => 'web_search error: empty query.', 'is_error' => true];
        }
        $count = (int)($input['count'] ?? 5);
        if ($count < 1) $count = 1;
        if ($count > self::MAX_RESULTS) $count = self::MAX_RESULTS;

        $settings = Globalvars::get_instance();
        $api_key = $settings->get_setting('joinery_ai_brave_search_api_key');
        if (!$api_key) {
            return [
                'content' => 'web_search not configured: joinery_ai_brave_search_api_key is empty.',
                'is_error' => true,
            ];
        }

        $http = new Client(['timeout' => 15, 'connect_timeout' => 5]);
        try {
            $response = $http->get(self::ENDPOINT, [
                'headers' => [
                    'X-Subscription-Token' => $api_key,
                    'Accept'               => 'application/json',
                    'Accept-Encoding'      => 'gzip',
                ],
                'query' => [
                    'q'     => $query,
                    'count' => $count,
                ],
            ]);
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
            return [
                'content' => 'web_search HTTP error: ' . $e->getMessage()
                           . ($body ? ' — ' . substr($body, 0, 200) : ''),
                'is_error' => true,
            ];
        } catch (Exception $e) {
            return ['content' => 'web_search error: ' . $e->getMessage(), 'is_error' => true];
        }

        $body = (string)$response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return ['content' => 'web_search returned non-JSON response.', 'is_error' => true];
        }

        $results = $decoded['web']['results'] ?? [];
        if (!$results) {
            return 'web_search: no results for "' . $query . '".';
        }

        $lines = ['Search results for "' . $query . '":', ''];
        foreach (array_slice($results, 0, $count) as $i => $r) {
            $n = $i + 1;
            $title = trim($r['title'] ?? '');
            $url   = trim($r['url'] ?? '');
            $desc  = trim(strip_tags($r['description'] ?? ''));
            $lines[] = "$n. $title";
            $lines[] = "   URL: $url";
            if ($desc !== '') {
                if (mb_strlen($desc) > 300) $desc = mb_substr($desc, 0, 300) . '…';
                $lines[] = "   $desc";
            }
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

}
