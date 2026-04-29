<?php
require_once(PathHelper::getComposerAutoloadPath());

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;     // 4xx
use GuzzleHttp\Exception\ServerException;     // 5xx
use GuzzleHttp\Exception\ConnectException;    // network

class AnthropicException extends Exception {}

/**
 * Thin wrapper around Anthropic's Messages API.
 *
 * Intentionally dumb: takes a fully-formed request body, posts it, decodes the
 * response. The runner is responsible for assembling the request — including
 * placing cache_control breakpoints on the last system block and the latest
 * tool-result turn (max 2 of the API's 4 breakpoint slots).
 *
 * Retry policy (per spec): up to 2 retries on 5xx / transport errors with
 * 1s and 3s backoff. 4xx (auth, validation) fails immediately.
 */
class AnthropicClient {

    const API_URL = 'https://api.anthropic.com/v1/messages';
    const API_VERSION = '2023-06-01';

    /**
     * USD per 1,000,000 tokens. For run-cost estimation in the dashboard;
     * a few percent off is acceptable. Source: platform.claude.com pricing
     * cached 2026-04-15. Add new models here when they're enabled in the
     * model dropdown.
     *
     * Cache write (5-min TTL): 1.25× input. Cache read: 0.1× input.
     */
    const COST_PER_MTOKEN = [
        'claude-opus-4-7'   => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        'claude-opus-4-6'   => ['input' => 5.00, 'output' => 25.00, 'cache_write' => 6.25, 'cache_read' => 0.50],
        'claude-sonnet-4-6' => ['input' => 3.00, 'output' => 15.00, 'cache_write' => 3.75, 'cache_read' => 0.30],
        'claude-haiku-4-5'  => ['input' => 1.00, 'output' =>  5.00, 'cache_write' => 1.25, 'cache_read' => 0.10],
    ];

    /** @var string */
    private $api_key;

    /** @var Client */
    private $http;

    public function __construct(string $api_key, ?Client $http = null) {
        if (!$api_key) {
            throw new AnthropicException(
                'Anthropic API key is empty. Configure joinery_ai_anthropic_api_key.'
            );
        }
        $this->api_key = $api_key;
        $this->http = $http ?: new Client([
            'timeout' => 120,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Send a Messages API request. $params is the raw API body — the caller
     * provides model, max_tokens, system, messages, tools, etc. Returns the
     * decoded response array. Throws AnthropicException on failure.
     */
    public function createMessage(array $params): array {
        $headers = [
            'x-api-key'         => $this->api_key,
            'anthropic-version' => self::API_VERSION,
            'content-type'      => 'application/json',
        ];

        $delays = [0, 1, 3]; // first try is delay 0; then 1s, then 3s
        $last_error = null;

        foreach ($delays as $i => $delay) {
            if ($delay > 0) sleep($delay);
            try {
                $response = $this->http->post(self::API_URL, [
                    'headers' => $headers,
                    'json'    => $params,
                ]);
                $body = (string)$response->getBody();
                $decoded = json_decode($body, true);
                if (!is_array($decoded)) {
                    throw new AnthropicException('Anthropic returned non-JSON: ' . substr($body, 0, 200));
                }
                return $decoded;
            } catch (ClientException $e) {
                // 4xx — auth, validation, rate-limit-pass-through. Don't retry.
                $body = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
                $msg = self::extractError($body) ?: $e->getMessage();
                throw new AnthropicException("Anthropic 4xx: $msg", $e->getCode(), $e);
            } catch (ServerException $e) {
                // 5xx — retry
                $body = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
                $last_error = self::extractError($body) ?: $e->getMessage();
                continue;
            } catch (ConnectException $e) {
                // Transport / DNS / timeout — retry
                $last_error = $e->getMessage();
                continue;
            } catch (Exception $e) {
                // Anything else — don't retry; the failure mode is unclear.
                throw new AnthropicException('Anthropic call failed: ' . $e->getMessage(), 0, $e);
            }
        }

        throw new AnthropicException('Anthropic 5xx/transport after retries: ' . ($last_error ?? 'unknown'));
    }

    /**
     * Estimate USD cost from a usage block as returned by the API:
     *   { input_tokens, output_tokens, cache_creation_input_tokens?, cache_read_input_tokens? }
     */
    public static function estimateCost(string $model, array $usage): float {
        $rates = self::COST_PER_MTOKEN[$model] ?? null;
        if (!$rates) return 0.0;

        $input_uncached  = (int)($usage['input_tokens']                 ?? 0);
        $output          = (int)($usage['output_tokens']                ?? 0);
        $cache_write     = (int)($usage['cache_creation_input_tokens']  ?? 0);
        $cache_read      = (int)($usage['cache_read_input_tokens']      ?? 0);

        return (
            $input_uncached * $rates['input']
          + $output         * $rates['output']
          + $cache_write    * $rates['cache_write']
          + $cache_read     * $rates['cache_read']
        ) / 1000000.0;
    }

    private static function extractError(string $body): ?string {
        if ($body === '') return null;
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            return $decoded['error']['type'] . ': ' . $decoded['error']['message'];
        }
        return substr($body, 0, 200);
    }

}
