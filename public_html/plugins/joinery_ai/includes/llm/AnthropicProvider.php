<?php
require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderException.php'));

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;     // 4xx
use GuzzleHttp\Exception\ServerException;     // 5xx
use GuzzleHttp\Exception\ConnectException;    // network

/**
 * Kept for backward compatibility: any code that still throws or catches
 * AnthropicException continues to work, and it is-a LlmProviderException so the
 * runner's base-type catch handles it.
 */
class AnthropicException extends LlmProviderException {}

/**
 * Anthropic Messages API provider.
 *
 * The canonical IR is the Anthropic block shape, so this provider is a
 * near-passthrough: createMessage() posts the request body as-is and returns
 * the decoded response. The runner is responsible for assembling the request —
 * including placing cache_control breakpoints on the last system block and the
 * latest tool-result turn (max 2 of the API's 4 breakpoint slots).
 *
 * Retry policy (per spec): up to 2 retries on 5xx / transport errors with
 * 1s and 3s backoff. 4xx (auth, validation) fails immediately.
 */
class AnthropicProvider implements LlmProviderInterface {

    const API_URL = 'https://api.anthropic.com/v1/messages';
    const API_VERSION = '2023-06-01';

    const DEFAULT_MODEL = 'claude-haiku-4-5';

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

    /** Models offered to the recipe-edit dropdown. */
    const MODELS = [
        'claude-opus-4-7'   => 'Claude Opus 4.7 ($5/$25 per Mtok)',
        'claude-sonnet-4-6' => 'Claude Sonnet 4.6 ($3/$15 per Mtok)',
        'claude-haiku-4-5'  => 'Claude Haiku 4.5 ($1/$5 per Mtok)',
    ];

    /** @var string */
    private $api_key;

    /** @var Client */
    private $http;

    public function __construct(string $api_key, ?Client $http = null) {
        if (!$api_key) {
            throw new LlmProviderException(
                'Anthropic API key is empty. Configure joinery_ai_anthropic_api_key.'
            );
        }
        $this->api_key = $api_key;
        $this->http = $http ?: new Client([
            'timeout' => 120,
            'connect_timeout' => 10,
        ]);
    }

    public function id(): string {
        return 'anthropic';
    }

    /** General cloud provider — not classified private; triggers the chat warning. */
    public function isPrivate(): bool {
        return false;
    }

    public function models(): array {
        return self::MODELS;
    }

    public function defaultModel(): string {
        return self::DEFAULT_MODEL;
    }

    /** Inactivity (between-token) timeout for the streamed read, in seconds. */
    const READ_TIMEOUT = 120;

    /**
     * Stream a Messages API request. $params is the canonical request body, which
     * for Anthropic is already the native API body — the caller provides model,
     * max_tokens, system, messages, tools, etc. Text deltas are handed to
     * $onTextDelta as they arrive; the full canonical response is assembled from
     * the SSE stream and returned. Throws LlmProviderException on failure.
     */
    public function createMessageStreamed(array $params, callable $onTextDelta): array {
        $params['stream'] = true;

        // Translate the canonical thinking knob (['level'=>off|low|medium|high])
        // to Anthropic's extended-thinking field. temperature/top_p are canonical
        // Anthropic params and pass through as-is — except extended thinking
        // requires default sampling, so they're dropped when thinking is enabled.
        if (isset($params['thinking'])) {
            $level = $params['thinking']['level'] ?? 'off';
            unset($params['thinking']);
            $budget = ['low' => 1024, 'medium' => 4096, 'high' => 12000][$level] ?? 0;
            if ($budget > 0) {
                $params['thinking'] = ['type' => 'enabled', 'budget_tokens' => $budget];
                // Anthropic requires max_tokens > budget_tokens; leave room for the
                // reasoning budget plus a real answer.
                $need = $budget + 4096;
                if ((int)($params['max_tokens'] ?? 0) < $need) $params['max_tokens'] = $need;
                unset($params['temperature'], $params['top_p']);
            }
        }

        $headers = [
            'x-api-key'         => $this->api_key,
            'anthropic-version' => self::API_VERSION,
            'content-type'      => 'application/json',
        ];

        $delays = [0, 1, 3]; // first try is delay 0; then 1s, then 3s
        $last_error = null;
        $response = null;

        foreach ($delays as $delay) {
            if ($delay > 0) sleep($delay);
            try {
                $response = $this->http->post(self::API_URL, [
                    'headers'      => $headers,
                    'json'         => $params,
                    'stream'       => true,
                    'timeout'      => 0,                 // no total cap; bytes flow during a long turn
                    'read_timeout' => self::READ_TIMEOUT, // bound inactivity instead
                ]);
                break;
            } catch (ClientException $e) {
                // 4xx — auth, validation, rate-limit-pass-through. Don't retry.
                $body = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
                $msg = self::extractError($body) ?: $e->getMessage();
                throw new LlmProviderException("Anthropic 4xx: $msg", $e->getCode(), $e);
            } catch (ServerException $e) {
                // 5xx — retry
                $body = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
                $last_error = self::extractError($body) ?: $e->getMessage();
                $response = null;
                continue;
            } catch (ConnectException $e) {
                // Transport / DNS / timeout — retry
                $last_error = $e->getMessage();
                $response = null;
                continue;
            } catch (Exception $e) {
                // Anything else — don't retry; the failure mode is unclear.
                throw new LlmProviderException('Anthropic call failed: ' . $e->getMessage(), 0, $e);
            }
        }

        if ($response === null) {
            throw new LlmProviderException('Anthropic 5xx/transport after retries: ' . ($last_error ?? 'unknown'));
        }

        return $this->consumeStream($response->getBody(), $onTextDelta);
    }

    /** Blocking convenience: stream with a no-op sink and return the full result. */
    public function createMessage(array $params): array {
        return $this->createMessageStreamed($params, static function (string $d): void {});
    }

    /**
     * Read the Anthropic SSE stream and assemble the canonical response. Events
     * are JSON objects carrying a `type`; we switch on that rather than the
     * `event:` line. Text deltas fire $onTextDelta; tool_use input arrives as
     * input_json_delta fragments accumulated per content-block index.
     */
    private function consumeStream($body, callable $onTextDelta): array {
        $blocks = [];   // index => ['type'=>'text','text'=>..] | ['type'=>'tool_use','id'=>,'name'=>,'_json'=>'']
        $usage = ['input_tokens' => 0, 'output_tokens' => 0,
                  'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0];
        $stop_reason = null;

        $buffer = '';
        while (!$body->eof()) {
            $buffer .= $body->read(8192);
            // SSE events are separated by a blank line.
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                $this->handleEvent($event, $blocks, $usage, $stop_reason, $onTextDelta);
            }
        }
        if (trim($buffer) !== '') {
            $this->handleEvent($buffer, $blocks, $usage, $stop_reason, $onTextDelta);
        }

        // Finalize: decode accumulated tool_use input, order by block index.
        ksort($blocks);
        $content = [];
        foreach ($blocks as $b) {
            if ($b['type'] === 'tool_use') {
                $input = json_decode($b['_json'] === '' ? '{}' : $b['_json'], true);
                if (!is_array($input)) $input = [];
                $content[] = ['type' => 'tool_use', 'id' => $b['id'], 'name' => $b['name'], 'input' => $input];
            } else {
                $content[] = ['type' => 'text', 'text' => $b['text']];
            }
        }

        return [
            'stop_reason' => $stop_reason ?: 'end_turn',
            'content'     => $content,
            'usage'       => $usage,
        ];
    }

    /** Parse one SSE event block and fold it into the running state. */
    private function handleEvent(string $event, array &$blocks, array &$usage,
            &$stop_reason, callable $onTextDelta): void {
        // Collect data: lines (Anthropic sends one per event).
        $data = '';
        foreach (explode("\n", $event) as $line) {
            if (strncmp($line, 'data:', 5) === 0) {
                $data .= ltrim(substr($line, 5));
            }
        }
        if ($data === '') return;

        $d = json_decode($data, true);
        if (!is_array($d) || !isset($d['type'])) return;

        switch ($d['type']) {
            case 'message_start':
                $u = $d['message']['usage'] ?? [];
                $usage['input_tokens']                = (int)($u['input_tokens'] ?? 0);
                $usage['cache_creation_input_tokens'] = (int)($u['cache_creation_input_tokens'] ?? 0);
                $usage['cache_read_input_tokens']     = (int)($u['cache_read_input_tokens'] ?? 0);
                break;

            case 'content_block_start':
                $i = (int)($d['index'] ?? 0);
                $cb = $d['content_block'] ?? [];
                if (($cb['type'] ?? '') === 'tool_use') {
                    $blocks[$i] = ['type' => 'tool_use', 'id' => (string)($cb['id'] ?? ''),
                                   'name' => (string)($cb['name'] ?? ''), '_json' => ''];
                } else {
                    $blocks[$i] = ['type' => 'text', 'text' => (string)($cb['text'] ?? '')];
                }
                break;

            case 'content_block_delta':
                $i = (int)($d['index'] ?? 0);
                $delta = $d['delta'] ?? [];
                $dt = $delta['type'] ?? '';
                if ($dt === 'text_delta') {
                    $piece = (string)($delta['text'] ?? '');
                    if (!isset($blocks[$i])) $blocks[$i] = ['type' => 'text', 'text' => ''];
                    $blocks[$i]['text'] .= $piece;
                    if ($piece !== '') $onTextDelta($piece);
                } elseif ($dt === 'input_json_delta') {
                    if (!isset($blocks[$i])) $blocks[$i] = ['type' => 'tool_use', 'id' => '', 'name' => '', '_json' => ''];
                    $blocks[$i]['_json'] .= (string)($delta['partial_json'] ?? '');
                }
                break;

            case 'message_delta':
                if (isset($d['delta']['stop_reason'])) $stop_reason = $d['delta']['stop_reason'];
                if (isset($d['usage']['output_tokens'])) $usage['output_tokens'] = (int)$d['usage']['output_tokens'];
                break;

            case 'error':
                $err = $d['error'] ?? [];
                $msg = is_array($err) ? trim(($err['type'] ?? '') . ': ' . ($err['message'] ?? '')) : 'stream error';
                throw new LlmProviderException('Anthropic stream error: ' . $msg);
        }
    }

    /**
     * Estimate USD cost from a canonical usage block:
     *   { input_tokens, output_tokens, cache_creation_input_tokens?, cache_read_input_tokens? }
     */
    public function estimateCost(string $model, array $usage): float {
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

// Backward-compatibility: existing references to AnthropicClient resolve to the
// renamed provider. Removed once all references are migrated.
class_alias('AnthropicProvider', 'AnthropicClient');
