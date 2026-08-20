<?php
require_once(PathHelper::getComposerAutoloadPath());
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderInterface.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderException.php'));

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;     // 4xx
use GuzzleHttp\Exception\ServerException;     // 5xx
use GuzzleHttp\Exception\ConnectException;    // network / connection refused

/**
 * One provider for every OpenAI-compatible runtime — Ollama, llama.cpp server,
 * vLLM, LM Studio — all of which expose /v1/chat/completions with tool-calling.
 * Choosing the OpenAI-compatible endpoint over Ollama's native /api/chat buys
 * portability across every common runtime for one class.
 *
 * This provider does the real translation: the runner speaks the canonical
 * (Anthropic-flavoured) block shape; this class converts canonical -> OpenAI
 * request and OpenAI response -> canonical, entirely inside the adapter. The
 * runner never sees the OpenAI wire format.
 *
 * The base class targets a local host, and is transport only — what a model
 * costs, what it can do and whether it is safe are the shipped catalog's
 * answers, not this class's. The thinking knob arrives as a resolved directive
 * and is expressed on the request's reasoning control. Remote
 * OpenAI-compatible services (e.g.
 * Fireworks) subclass this, reusing all the wire translation and overriding the
 * vendor-specific seams — id(), systemThinkingSuffix(), applyReasoning(),
 * unreachableMessage().
 */
class OpenAiCompatibleProvider implements LlmProviderInterface {

    /** How often, at most, to re-check the mid-stream abort predicate (a fresh DB
     *  read). ~0.4s keeps a user Cancel felt within acceptance's ~1s while sparing
     *  the DB a query per 8 KB chunk. */
    const ABORT_POLL_SECONDS = 0.4;

    /** @var string Base URL of the OpenAI-compatible server, e.g. http://localhost:11434/v1 */
    protected $base_url;

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

    /**
     * A short GET {base_url}/models. Any HTTP answer (even a 4xx/5xx) proves the
     * host is up — we only care that the TCP/HTTP layer is alive, not that the
     * models endpoint is perfectly healthy — so it returns null. A ConnectException
     * (connection refused / DNS / connect-timeout to a sleeping Tailscale peer)
     * returns the unreachable message so the turn fails in a couple of seconds
     * instead of stalling the full streaming call. Any other error is treated as
     * reachable-enough, leaving a precise diagnosis to the real call.
     */
    public function reachabilityProbe(): ?string {
        try {
            $this->http->get($this->base_url . '/models', [
                'connect_timeout' => 2,
                'timeout'         => 3,
                'http_errors'     => false,
            ]);
            return null;
        } catch (ConnectException $e) {
            return $this->unreachableMessage();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Time-to-first-token bound for the streamed read: how long to wait for the
     * model to *start* responding before giving up, separate from the between-token
     * inactivity bound (the per-call timeout). A cold or overloaded local model can
     * sit silent for a while before its first token; this fails that fast and
     * legibly rather than waiting out the full per-call timeout. 0 disables the
     * tighter first phase (the per-call timeout governs the whole read). Remote
     * subclasses override to 0 — a cloud API's first token is prompt.
     */
    protected function firstTokenTimeoutSeconds(): int {
        $v = (int)Globalvars::get_instance()->get_setting('joinery_ai_local_first_token_timeout_seconds');
        return $v > 0 ? $v : 0;
    }

    /** Message for a first-token timeout; phrased so classify() reads it as a network error. */
    protected function firstTokenTimeoutMessage(): string {
        return $this->providerLabel() . ' did not start responding in time — the model may be '
            . 'loading or the host is overloaded (first-token timeout).';
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
    public function createMessageStreamed(array $params, callable $onTextDelta,
            ?callable $shouldAbort = null): array {
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
            return $this->consumeStream($response->getBody(), $onTextDelta,
                $this->firstTokenTimeoutSeconds(), $shouldAbort);
        } catch (ConnectException $e) {
            // Connection refused / DNS / timeout — the server isn't reachable.
            // Keep "not reachable" in the message so classify() reads it as a
            // network error.
            throw new LlmProviderException($this->unreachableMessage(), 0, $e);
        } catch (ClientException $e) {
            $resp = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
            throw new LlmProviderException($this->providerLabel() . ' 4xx: ' . ($this->extractError($resp) ?: $e->getMessage()),
                $e->getCode(), $e);
        } catch (ServerException $e) {
            $resp = $e->hasResponse() ? (string)$e->getResponse()->getBody() : '';
            throw new LlmProviderException($this->providerLabel() . ' 5xx: ' . ($this->extractError($resp) ?: $e->getMessage()),
                $e->getCode(), $e);
        } catch (LlmProviderException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new LlmProviderException($this->providerLabel() . ' call failed: ' . $e->getMessage(), 0, $e);
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
     *
     * $shouldAbort (when set) is polled at most every ABORT_POLL_SECONDS between
     * chunks; the first true return breaks the read, the finally closes the raw
     * socket (freeing the model runner), and the canonical response comes back
     * with stop_reason 'aborted' and whatever text had streamed.
     */
    private function consumeStream($body, callable $onTextDelta, int $firstTokenTimeout = 0,
            ?callable $shouldAbort = null): array {
        $text = '';
        $tool_calls = [];   // index => ['id'=>,'name'=>,'args'=>'']
        $finish = 'stop';
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'cached_tokens' => 0];
        $think = ['in' => false, 'carry' => '']; // <think> filter state
        $aborted = false;
        $last_abort_check = 0.0;

        // Drive the raw socket so the first-token wait can be bounded tighter than
        // the between-token read timeout. detach() yields the underlying PHP stream
        // resource; fread() reads are byte-identical to the PSR-7 stream's read().
        $res = (is_object($body) && method_exists($body, 'detach')) ? $body->detach()
             : (is_resource($body) ? $body : null);
        if (!is_resource($res)) {
            throw new LlmProviderException($this->providerLabel() . ' returned no readable stream (network error).', 0);
        }
        // Phase 1: wait at most $firstTokenTimeout for the model to *start* (a cold
        // or overloaded local model can sit silent). 0 disables the tighter phase.
        $started = false;
        stream_set_timeout($res, max(1, $firstTokenTimeout > 0 ? $firstTokenTimeout : (int)$this->read_timeout));

        $buffer = '';
        try {
            while (!feof($res)) {
                // Cooperative mid-generation cancel: poll the abort predicate at
                // most every ABORT_POLL_SECONDS so a per-chunk check never hammers
                // the DB. On the first true, stop reading — the finally closes the
                // socket and frees the model runner.
                if ($shouldAbort !== null) {
                    $now = microtime(true);
                    if ($now - $last_abort_check >= self::ABORT_POLL_SECONDS) {
                        $last_abort_check = $now;
                        if ($shouldAbort()) { $aborted = true; break; }
                    }
                }
                $data = fread($res, 8192);
                $meta = stream_get_meta_data($res);
                if (($data === '' || $data === false) && !empty($meta['timed_out'])) {
                    if (!$started) throw new LlmProviderException($this->firstTokenTimeoutMessage(), 0);
                    throw new LlmProviderException($this->providerLabel()
                        . ' stopped responding mid-stream (connection timeout).', 0);
                }
                if ($data === '' || $data === false) continue;
                if (!$started) {
                    // Phase 2: first bytes arrived — relax to the full between-token
                    // timeout so a long generation is never cut mid-answer.
                    $started = true;
                    stream_set_timeout($res, max(1, (int)$this->read_timeout));
                }
                $buffer .= $data;
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
                        // Standard OpenAI cached-prompt count (Fireworks sends it; Ollama
                        // doesn't). prompt_tokens already includes these.
                        $usage['cached_tokens'] = (int)($chunk['usage']['prompt_tokens_details']['cached_tokens'] ?? $usage['cached_tokens']);
                    }
                }
            }
        } finally {
            if (is_resource($res)) fclose($res);
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
            'stop_reason' => $aborted ? 'aborted' : $this->mapStopReason($finish, $content),
            'content'     => $content,
            'usage'       => [
                'input_tokens'                => $usage['prompt_tokens'],
                'output_tokens'               => $usage['completion_tokens'],
                'cache_creation_input_tokens' => 0,
                'cache_read_input_tokens'     => $usage['cached_tokens'],
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
        // message prepended. A provider may append a control suffix to the
        // system text via systemThinkingSuffix() — empty here, because the
        // thinking level rides the request-level reasoning control instead
        // (see applyReasoning()); only a subclass whose runtime still honors an
        // in-prompt token returns one.
        $directive = is_array($params['thinking'] ?? null)
            ? $params['thinking'] : ['enabled' => false, 'effort' => null];
        $system_text = $this->flattenSystem($params['system'] ?? []);
        $suffix = $this->systemThinkingSuffix($directive);
        if ($suffix !== '') {
            $system_text = trim($system_text . "\n" . $suffix);
        }
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

        $this->applyReasoning($request, $directive);

        return $request;
    }

    /**
     * Control suffix appended to the system message for the thinking level.
     * Empty for the local host: current Ollama qwen3 templates gate thinking on
     * the request-level reasoning control (see applyReasoning), not on a
     * prompt-embedded /think or /no_think token — that soft switch is parsed
     * out of the template, so emitting it only pollutes the system prompt with
     * a stray literal the model reads as instruction text. Subclasses may
     * override if their runtime still honors an in-prompt token.
     */
    protected function systemThinkingSuffix(array $directive): string {
        return '';
    }

    /**
     * Apply the request-level reasoning control. Ollama's OpenAI-compatible
     * endpoint maps `reasoning_effort` onto the model's thinking channel, so
     * this is the local equivalent of the native `think` field: a directive
     * with thinking off sends 'none' (the strongest suppression the runtime
     * offers), otherwise the resolved effort flows through. A reasoning-first model like qwen3:4b may still
     * emit reasoning even at 'none' (relocated inline rather than in a <think>
     * block); the <think> filter and the JSON extractor downstream handle both
     * shapes. A non-reasoning model or a runtime that ignores the field is
     * unaffected — an unknown field is dropped by the endpoint.
     */
    protected function applyReasoning(array &$request, array $directive): void {
        $request['reasoning_effort'] = empty($directive['enabled'])
            ? 'none' : (string)($directive['effort'] ?: 'low');
    }

    /**
     * Human label for this provider in raw error messages — keeps recipe Run
     * History and logs accurate ("Local model" vs "Fireworks"). The 4xx/5xx
     * tokens that classify() keys on are added separately, so overriding this is
     * safe.
     */
    protected function providerLabel(): string {
        return 'Local model';
    }

    /** Message for an unreachable server; keep "not reachable" for classify(). */
    protected function unreachableMessage(): string {
        return "Local model server not reachable at {$this->base_url} — is Ollama running? "
            . '(connection error)';
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
        $image_parts = [];   // OpenAI multimodal image_url parts (user role only)

        foreach ($blocks as $block) {
            $type = $block['type'] ?? '';
            if ($type === 'text') {
                $text_parts[] = (string)($block['text'] ?? '');
            } elseif ($type === 'image') {
                // Canonical image block -> OpenAI image_url data URI. base64 sources
                // are what the attachment encoder emits; any other source shape is
                // skipped (this wire format has no URL-fetch or file-id door).
                $src = $block['source'] ?? [];
                if (is_array($src) && ($src['type'] ?? '') === 'base64'
                        && ($src['media_type'] ?? '') !== '' && ($src['data'] ?? '') !== '') {
                    $image_parts[] = [
                        'type'      => 'image_url',
                        'image_url' => ['url' => 'data:' . $src['media_type'] . ';base64,' . $src['data']],
                    ];
                }
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

        // user role: emit any plain text (+ inline images), then each tool_result
        // as a tool message. With images present, OpenAI requires the array
        // content shape mixing text and image_url parts; without them, a plain
        // string is sent as before.
        $text = implode('', $text_parts);
        if ($image_parts) {
            $parts = [];
            if ($text !== '') $parts[] = ['type' => 'text', 'text' => $text];
            foreach ($image_parts as $ip) $parts[] = $ip;
            $messages[] = ['role' => 'user', 'content' => $parts];
        } elseif ($text !== '') {
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
