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
    public function __construct(string $base_url, string $model, string $api_key = '',
            int $timeout = 300, ?Client $http = null) {
        $this->base_url = rtrim($base_url, '/');
        $this->model    = $model;
        $this->api_key  = $api_key;
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
     * post it, and translate the response back to canonical. Throws
     * LlmProviderException on transport/HTTP failure; a connection refused to
     * the configured base URL is surfaced with a local-specific message so the
     * runner can classify it as api_network_error.
     */
    public function createMessage(array $params): array {
        $body = $this->toOpenAiRequest($params);
        $url = $this->base_url . '/chat/completions';

        $headers = ['content-type' => 'application/json'];
        if ($this->api_key !== '') {
            $headers['authorization'] = 'Bearer ' . $this->api_key;
        }

        try {
            $response = $this->http->post($url, [
                'headers' => $headers,
                'json'    => $body,
            ]);
            $raw = (string)$response->getBody();
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new LlmProviderException('Local model returned non-JSON: ' . substr($raw, 0, 200));
            }
            return $this->toCanonicalResponse($decoded);
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

    // --- canonical -> OpenAI -----------------------------------------------

    private function toOpenAiRequest(array $params): array {
        $messages = [];

        // System: array of text blocks (cache_control ignored) -> one system
        // message prepended.
        $system_text = $this->flattenSystem($params['system'] ?? []);
        if ($system_text !== '') {
            $messages[] = ['role' => 'system', 'content' => $system_text];
        }

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
            'model'      => $params['model'] ?? $this->model,
            'messages'   => $messages,
            'max_tokens' => (int)($params['max_tokens'] ?? 4096),
            'stream'     => false,
        ];

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

    private function toCanonicalResponse(array $resp): array {
        $choice  = $resp['choices'][0] ?? [];
        $message = $choice['message'] ?? [];
        $finish  = $choice['finish_reason'] ?? 'stop';

        $content = [];

        // Final text. Strip <think>…</think> reasoning leakage; never use a
        // separate reasoning channel as the answer.
        $text = $this->stripReasoning((string)($message['content'] ?? ''));
        if ($text !== '') {
            $content[] = ['type' => 'text', 'text' => $text];
        }

        foreach (($message['tool_calls'] ?? []) as $tc) {
            $fn = $tc['function'] ?? [];
            // Malformed arguments must not crash the run: decode failure -> {}.
            // The tool's own input validation then produces a normal is_error
            // tool_result.
            $input = json_decode($fn['arguments'] ?? '', true);
            if (!is_array($input)) $input = [];
            $content[] = [
                'type'  => 'tool_use',
                'id'    => $tc['id'] ?? '',
                'name'  => $fn['name'] ?? '',
                'input' => $input,
            ];
        }

        $usage = $resp['usage'] ?? [];

        return [
            'stop_reason' => $this->mapStopReason($finish, $content),
            'content'     => $content,
            'usage'       => [
                'input_tokens'                => (int)($usage['prompt_tokens'] ?? 0),
                'output_tokens'               => (int)($usage['completion_tokens'] ?? 0),
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens'     => 0,
            ],
        ];
    }

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

    /** Remove <think>…</think> blocks some reasoning models emit inline. */
    private function stripReasoning(string $text): string {
        $cleaned = preg_replace('#<think>.*?</think>#is', '', $text);
        return trim($cleaned === null ? $text : $cleaned);
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
