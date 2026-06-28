<?php
require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderException.php'));

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;     // 4xx
use GuzzleHttp\Exception\ServerException;     // 5xx
use GuzzleHttp\Exception\ConnectException;    // network / connection refused

/**
 * One provider for every OpenAI-compatible local runtime — Ollama, llama.cpp
 * server, vLLM, LM Studio — all of which expose /v1/chat/completions with
 * tool-calling. Choosing the OpenAI-compatible endpoint over Ollama's native
 * /api/chat buys portability across every common local runtime for one class.
 *
 * This provider does the real translation: the runner speaks the canonical
 * (Anthropic-flavoured) block shape; this class converts canonical -> OpenAI
 * request and OpenAI response -> canonical, entirely inside the adapter. The
 * runner never sees the OpenAI wire format.
 *
 * Local inference is free, so estimateCost() always returns 0.0. No prompt
 * caching exists locally; cache_control on system blocks is ignored and every
 * call re-sends the full system prompt.
 */
class OpenAiCompatibleProvider implements LlmProviderInterface {

    /** @var string Base URL of the OpenAI-compatible server, e.g. http://localhost:11434/v1 */
    private $base_url;

    /** @var string Model id served by the host. */
    private $model;

    /** @var string Optional API key; Ollama ignores it, some servers require one. */
    private $api_key;

    /** @var Client */
    private $http;

    /**
     * @param string      $base_url  OpenAI-compatible base (no trailing /chat/completions)
     * @param string      $model     model id served by the host
     * @param string      $api_key   optional; sent as a Bearer token when non-empty
     * @param int         $timeout   per-call HTTP timeout (local generation is slow)
     * @param Client|null $http      injectable for tests
     */
    /** @var int Inactivity (between-token) timeout for the streamed read. */
    private $read_timeout;

    public function __construct(string $base_url, string $model, string $api_key = '',
            int $timeout = 300, ?Client $http = null) {
        $this->base_url = rtrim($base_url, '/');
        $this->model    = $model;
        $this->api_key  = $api_key;
        $this->read_timeout = $timeout;
        $this->http = $http ?: new Client([
            'timeout'         => $timeout,
            'connect_timeout' => 10,
        ]);
    }

    public function id(): string {
        return 'local';
    }

    public function defaultModel(): string {
        return $this->model;
    }

    /**
     * The single configured local model, labeled as free. The recipe-edit
     * dropdown also defensively appends a recipe's own stored model (see
     * edit.php) so switching providers never silently rewrites it.
     */
    public function models(): array {
        if ($this->model === '') return [];
        return [$this->model => "{$this->model} (local · free)"];
    }

    /** Local inference is free. */
    public function estimateCost(string $model, array $usage): float {
        return 0.0;
    }

    /**
     * Translate the canonical request to an OpenAI chat-completions request,
     * stream it, and translate the response back to canonical. Answer-text
     * deltas fire $onTextDelta as they arrive; inline <think>…</think> reasoning
     * is filtered out of both the stream and the final text. Throws
     * LlmProviderException on transport/HTTP failure; a connection refused to
     * the configured base URL is surfaced with a local-specific message so the
     * caller can classify it as api_network_error.
     */
    public function createMessageStreamed(array $params, callable $onTextDelta): array {
        $body = $this->toOpenAiRequest($params);
        $url = $this->base_url . '/chat/completions';

        $headers = ['content-type' => 'application/json'];
        if ($this->api_key !== '') {
            $headers['authorization'] = 'Bearer ' . $this->api_key;
        }

        try {
            $response = $this->http->post($url, [
                'headers'      => $headers,
                'json'         => $body,
                'stream'       => true,
                'timeout'      => 0,                  // local generation can run long
                'read_timeout' => $this->read_timeout, // bound inactivity instead
            ]);
            return $this->consumeStream($response->getBody(), $onTextDelta);
        } catch (ConnectException $e) {
            // Connection refused / DNS / timeout — the local server isn't reachable.
            throw new LlmProviderException(
                "Local model server not reachable at {$this->base_url} — is Ollama running? "
                . '(connection error)', 0, $e);
        } catch (ClientException $e) {
            $resp = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
            throw new LlmProviderException('Local model 4xx: ' . ($this->extractError($resp) ?: $e->getMessage()),
                $e->getCode(), $e);
        } catch (ServerException $e) {
            $resp = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
            throw new LlmProviderException('Local model 5xx: ' . ($this->extractError($resp) ?: $e->getMessage()),
                $e->getCode(), $e);
        } catch (LlmProviderException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new LlmProviderException('Local model call failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** Blocking convenience: stream with a no-op sink and return the full result. */
    public function createMessage(array $params): array {
        return $this->createMessageStreamed($params, static function (string $d): void {});
    }

    /**
     * Read the OpenAI-compatible SSE stream and assemble the canonical response.
     * Each `data:` line is a chat-completion chunk; `data: [DONE]` ends it. Text
     * deltas pass through the think-filter before reaching $onTextDelta and the
     * accumulated answer; tool-call argument fragments accumulate per index.
     */
    private function consumeStream($body, callable $onTextDelta): array {
        $text = '';
        $tool_calls = [];   // index => ['id'=>,'name'=>,'args'=>'']
        $finish = 'stop';
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0];
        $think = ['in' => false, 'carry' => '']; // <think> filter state

        $buffer = '';
        while (!$body->eof()) {
            $buffer .= $body->read(8192);
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = rtrim(substr($buffer, 0, $pos), "\r");
                $buffer = substr($buffer, $pos + 1);
                if (strncmp($line, 'data:', 5) !== 0) continue; // skip blanks / comments
                $payload = ltrim(substr($line, 5));
                if ($payload === '' || $payload === '[DONE]') continue;

                $chunk = json_decode($payload, true);
                if (!is_array($chunk)) continue;

                $choice = $chunk['choices'][0] ?? null;
                if (is_array($choice)) {
                    $delta = $choice['delta'] ?? [];
                    if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
                        $clean = $this->thinkPush($think, $delta['content']);
                        if ($clean !== '') { $text .= $clean; $onTextDelta($clean); }
                    }
                    foreach (($delta['tool_calls'] ?? []) as $tc) {
                        $idx = (int)($tc['index'] ?? 0);
                        if (!isset($tool_calls[$idx])) $tool_calls[$idx] = ['id' => '', 'name' => '', 'args' => ''];
                        if (isset($tc['id'])) $tool_calls[$idx]['id'] = (string)$tc['id'];
                        $fn = $tc['function'] ?? [];
                        if (isset($fn['name']))      $tool_calls[$idx]['name'] .= (string)$fn['name'];
                        if (isset($fn['arguments'])) $tool_calls[$idx]['args'] .= (string)$fn['arguments'];
                    }
                    if (!empty($choice['finish_reason'])) $finish = (string)$choice['finish_reason'];
                }
                if (isset($chunk['usage']) && is_array($chunk['usage'])) {
                    $usage['prompt_tokens']     = (int)($chunk['usage']['prompt_tokens'] ?? $usage['prompt_tokens']);
                    $usage['completion_tokens'] = (int)($chunk['usage']['completion_tokens'] ?? $usage['completion_tokens']);
                }
            }
        }
        $tail = $this->thinkFlush($think);
        if ($tail !== '') { $text .= $tail; $onTextDelta($tail); }

        // Assemble canonical content: a text block (if any) then tool_use blocks.
        $content = [];
        $text = trim($text);
        if ($text !== '') $content[] = ['type' => 'text', 'text' => $text];

        ksort($tool_calls);
        foreach ($tool_calls as $tc) {
            $input = json_decode($tc['args'] === '' ? '{}' : $tc['args'], true);
            if (!is_array($input)) $input = [];
            $content[] = ['type' => 'tool_use', 'id' => $tc['id'], 'name' => $tc['name'], 'input' => $input];
        }

        return [
            'stop_reason' => $this->mapStopReason($finish, $content),
            'content'     => $content,
            'usage'       => [
                'input_tokens'                => $usage['prompt_tokens'],
                'output_tokens'               => $usage['completion_tokens'],
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens'     => 0,
            ],
        ];
    }

    /**
     * Streaming <think> filter. Feeds $piece through a state machine and returns
     * only the answer text outside <think>…</think>, holding back any partial
     * tag that straddles a chunk boundary in $state['carry'].
     */
    private function thinkPush(array &$state, string $piece): string {
        $state['carry'] .= $piece;
        $out = '';
        while (true) {
            if (!$state['in']) {
                $p = strpos($state['carry'], '<think>');
                if ($p !== false) {
                    $out .= substr($state['carry'], 0, $p);
                    $state['carry'] = substr($state['carry'], $p + 7);
                    $state['in'] = true;
                    continue;
                }
                $keep = $this->partialTagSuffix($state['carry'], '<think>');
                $emit = strlen($state['carry']) - $keep;
                $out .= substr($state['carry'], 0, $emit);
                $state['carry'] = substr($state['carry'], $emit);
                break;
            }
            $p = strpos($state['carry'], '</think>');
            if ($p !== false) {
                $state['carry'] = substr($state['carry'], $p + 8);
                $state['in'] = false;
                continue;
            }
            $keep = $this->partialTagSuffix($state['carry'], '</think>');
            $state['carry'] = substr($state['carry'], strlen($state['carry']) - $keep);
            break;
        }
        return $out;
    }

    /** Flush the filter at stream end: any leftover outside a think block is answer text. */
    private function thinkFlush(array &$state): string {
        $out = $state['in'] ? '' : $state['carry'];
        $state['carry'] = '';
        return $out;
    }

    /** Longest k (1..len-1) where the suffix of $s equals the prefix of $tag. */
    private function partialTagSuffix(string $s, string $tag): int {
        $max = min(strlen($s), strlen($tag) - 1);
        for ($k = $max; $k > 0; $k--) {
            if (substr($s, -$k) === substr($tag, 0, $k)) return $k;
        }
        return 0;
    }

    // --- canonical -> OpenAI -----------------------------------------------

    private function toOpenAiRequest(array $params): array {
        $messages = [];

        // System: array of text blocks (cache_control ignored) -> one system
        // message prepended. The thinking level is expressed to qwen3 via a
        // /think or /no_think control token appended to the system text — the
        // method Ollama-hosted qwen3 honors reliably. 'off' (the default) maps to
        // /no_think, which skips the reasoning pass entirely.
        $think = $params['thinking']['level'] ?? 'off';
        $think_token = ($think === 'off') ? '/no_think' : '/think';
        $system_text = trim($this->flattenSystem($params['system'] ?? []) . "\n" . $think_token);
        $messages[] = ['role' => 'system', 'content' => $system_text];

        foreach (($params['messages'] ?? []) as $msg) {
            $role = $msg['role'] ?? 'user';
            $content = $msg['content'] ?? '';

            // A plain string content (rare in canonical, but tolerate it).
            if (is_string($content)) {
                $messages[] = ['role' => $role, 'content' => $content];
                continue;
            }

            $this->appendCanonicalBlocks($messages, $role, $content);
        }

        $request = [
            'model'         => $params['model'] ?? $this->model,
            'messages'      => $messages,
            'max_tokens'    => (int)($params['max_tokens'] ?? 4096),
            'stream'        => true,
            'stream_options' => ['include_usage' => true], // final chunk carries usage
        ];
        if (isset($params['temperature'])) $request['temperature'] = (float)$params['temperature'];
        if (isset($params['top_p']))       $request['top_p'] = (float)$params['top_p'];

        if (!empty($params['tools'])) {
            $request['tools'] = array_map(function ($t) {
                return [
                    'type'     => 'function',
                    'function' => [
                        'name'        => $t['name'] ?? '',
                        'description' => $t['description'] ?? '',
                        'parameters'  => $t['input_schema'] ?? ['type' => 'object', 'properties' => (object)[]],
                    ],
                ];
            }, $params['tools']);
        }

        return $request;
    }

    /** Concatenate canonical system text blocks into a single string. */
    private function flattenSystem($system): string {
        if (is_string($system)) return $system;
        if (!is_array($system)) return '';
        $parts = [];
        foreach ($system as $block) {
            if (is_string($block)) {
                $parts[] = $block;
            } elseif (is_array($block) && ($block['type'] ?? '') === 'text') {
                $parts[] = (string)($block['text'] ?? '');
            }
        }
        return implode("\n\n", array_filter($parts, fn($p) => $p !== ''));
    }

    /**
     * Convert one canonical message's content blocks into one or more OpenAI
     * messages, appending them to $messages. Assistant text + tool_use collapse
     * into a single assistant message; each user tool_result becomes its own
     * role:"tool" message.
     */
    private function appendCanonicalBlocks(array &$messages, string $role, array $blocks): void {
        $text_parts = [];
        $tool_calls = [];
        $tool_results = [];

        foreach ($blocks as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'text') {
                $text_parts[] = (string)($block['text'] ?? '');
            } elseif ($type === 'tool_use') {
                $tool_calls[] = [
                    'id'       => $block['id'] ?? '',
                    'type'     => 'function',
                    'function' => [
                        'name'      => $block['name'] ?? '',
                        'arguments' => json_encode($block['input'] ?? new stdClass()),
                    ],
                ];
            } elseif ($type === 'tool_result') {
                $tool_results[] = $block;
            }
        }

        if ($role === 'assistant') {
            $assistant = ['role' => 'assistant'];
            $text = implode('', $text_parts);
            // OpenAI requires content present; null is allowed alongside tool_calls.
            $assistant['content'] = $text !== '' ? $text : null;
            if ($tool_calls) {
                $assistant['tool_calls'] = $tool_calls;
            }
            $messages[] = $assistant;
            return;
        }

        // user role: emit any plain text, then each tool_result as a tool message.
        $text = implode('', $text_parts);
        if ($text !== '') {
            $messages[] = ['role' => 'user', 'content' => $text];
        }
        foreach ($tool_results as $tr) {
            $messages[] = [
                'role'         => 'tool',
                'tool_call_id' => $tr['tool_use_id'] ?? '',
                'content'      => $this->flattenToolResultContent($tr),
            ];
        }
    }

    /**
     * Canonical tool_result content can be a string or an array of blocks.
     * Flatten to a string; OpenAI has no error flag, so is_error prefixes
     * "ERROR: ".
     */
    private function flattenToolResultContent(array $tr): string {
        $content = $tr['content'] ?? '';
        $text = '';
        if (is_string($content)) {
            $text = $content;
        } elseif (is_array($content)) {
            $parts = [];
            foreach ($content as $c) {
                if (is_string($c)) {
                    $parts[] = $c;
                } elseif (is_array($c) && ($c['type'] ?? '') === 'text') {
                    $parts[] = (string)($c['text'] ?? '');
                }
            }
            $text = implode("\n", $parts);
        }
        if (!empty($tr['is_error'])) {
            $text = 'ERROR: ' . $text;
        }
        return $text;
    }

    // --- OpenAI -> canonical -----------------------------------------------

    /**
     * Map OpenAI finish_reason to a canonical stop_reason. "length" maps to
     * end_turn so the runner treats whatever partial text exists as the answer.
     * If the model emitted tool calls we always report tool_use regardless of
     * finish_reason, since some servers report "stop" alongside tool_calls.
     */
    private function mapStopReason(string $finish, array $content): string {
        foreach ($content as $b) {
            if (($b['type'] ?? '') === 'tool_use') return 'tool_use';
        }
        if ($finish === 'tool_calls') return 'tool_use';
        return 'end_turn'; // stop, length, or anything else
    }

    private function extractError(string $body): ?string {
        if ($body === '') return null;
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['error'])) {
            if (is_array($decoded['error']) && isset($decoded['error']['message'])) {
                return (string)$decoded['error']['message'];
            }
            if (is_string($decoded['error'])) {
                return $decoded['error'];
            }
        }
        return substr($body, 0, 200);
    }

}
