<?php
/** @joinery-test
 * name: joinery_ai_llm_provider_resilience
 * tier: safe
 * env: any
 * needs: []
 * timeout: 60
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/OpenAiCompatibleProvider.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/AnthropicProvider.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/FireworksProvider.php'));

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

/** A minimal canonical request the provider can translate. */
function jai_min_params(): array {
    return [
        'model'    => 'test-model',
        'max_tokens' => 32,
        'system'   => [],
        'messages' => [['role' => 'user', 'content' => 'hi']],
    ];
}

/** A Client whose single response is $resp (streaming body preserved). */
function jai_mock_client(Response $resp): Client {
    return new Client(['handler' => HandlerStack::create(new MockHandler([$resp]))]);
}

// ---------------------------------------------------------------------------
section('Reachability probe (Piece B)');

// A closed port: the probe must fail fast with an unreachable message, not stall.
$t0 = microtime(true);
$closed = new OpenAiCompatibleProvider('http://127.0.0.1:1/v1', 'm', '');
$msg = $closed->reachabilityProbe();
$elapsed = microtime(true) - $t0;
check(is_string($msg) && $msg !== '', 'closed-port probe returns an unreachable message', (string)$msg);
check(stripos($msg ?? '', 'not reachable') !== false,
    'probe message classifies as network error',
    'classify=' . LlmProviderException::classify(new LlmProviderException((string)$msg)));
check($elapsed < 5, 'closed-port probe fails fast (< 5s)', sprintf('%.2fs', $elapsed));

// Cloud providers advertise no probe (null) — their own call path handles transport.
check((new AnthropicProvider('test-key'))->reachabilityProbe() === null,
    'Anthropic provider probe is null (cloud, no pre-flight)');
check((new FireworksProvider('test-key'))->reachabilityProbe() === null,
    'Fireworks provider probe is null (cloud, no pre-flight)');

// ---------------------------------------------------------------------------
section('First-token timeout (Piece C)');

// A stream that sends headers (200) then stalls: no body ever arrives. Modelled
// with a socket pair whose write end is never written. The read must time out on
// the first-token bound and surface as api_no_response — fast, not the full
// per-call timeout.
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
check(is_array($pair) && isset($pair[0]), 'socket pair created for stall simulation');
$stall_body = Utils::streamFor($pair[0]);
$stall_client = jai_mock_client(new Response(200, ['Content-Type' => 'text/event-stream'], $stall_body));

// A 300s per-call timeout but a 2s first-token bound: the stall must trip the 2s
// bound, proving the two phases are independent.
$stall_provider = new class('http://mock/v1', 'test-model', '', 300, $stall_client) extends OpenAiCompatibleProvider {
    protected function firstTokenTimeoutSeconds(): int { return 2; }
};

$t0 = microtime(true);
$threw = false; $code = '';
try {
    $stall_provider->createMessage(jai_min_params());
} catch (LlmProviderException $e) {
    $threw = true; $code = LlmProviderException::classify($e);
}
$elapsed = microtime(true) - $t0;
if (is_resource($pair[1])) fclose($pair[1]);

check($threw, 'stalled first token throws a provider exception');
check($code === 'api_no_response', 'first-token timeout classifies as api_no_response', 'code=' . $code);
check($elapsed >= 1.5 && $elapsed < 8, 'fires on the first-token bound, not the per-call timeout',
    sprintf('%.2fs', $elapsed));

// A well-behaved SSE stream must assemble correctly through the raw-resource read
// (proves the two-phase refactor didn't regress normal streaming, and that the
// bound relaxes once bytes arrive).
$sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hello\"}}]}\n\n"
     . "data: {\"choices\":[{\"delta\":{\"content\":\" world\"},\"finish_reason\":\"stop\"}]}\n\n"
     . "data: {\"choices\":[{\"delta\":{}}],\"usage\":{\"prompt_tokens\":5,\"completion_tokens\":2}}\n\n"
     . "data: [DONE]\n\n";
$ok_client = jai_mock_client(new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($sse)));
$ok_provider = new OpenAiCompatibleProvider('http://mock/v1', 'test-model', '', 300, $ok_client);

$streamed = '';
$result = $ok_provider->createMessage(jai_min_params());
foreach (($result['content'] ?? []) as $block) {
    if (($block['type'] ?? '') === 'text') $streamed = $block['text'];
}
check($streamed === 'Hello world', 'normal SSE stream assembles text through raw-resource read', $streamed);
check((int)($result['usage']['input_tokens'] ?? 0) === 5 && (int)($result['usage']['output_tokens'] ?? 0) === 2,
    'usage tokens parsed from the stream',
    'in=' . ($result['usage']['input_tokens'] ?? '?') . ' out=' . ($result['usage']['output_tokens'] ?? '?'));

// An OpenAI-style host counts cached tokens inside prompt_tokens; the provider
// reports them outside input_tokens, as every provider does.
$cached_sse = "data: {\"choices\":[{\"delta\":{\"content\":\"Hi\"},\"finish_reason\":\"stop\"}]}\n\n"
     . "data: {\"choices\":[{\"delta\":{}}],\"usage\":{\"prompt_tokens\":1000,\"completion_tokens\":3,\"prompt_tokens_details\":{\"cached_tokens\":800}}}\n\n"
     . "data: [DONE]\n\n";
$cached_provider = new OpenAiCompatibleProvider('http://mock/v1', 'test-model', '', 300,
    jai_mock_client(new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($cached_sse))));
$cached = $cached_provider->createMessage(jai_min_params());
check((int)($cached['usage']['input_tokens'] ?? -1) === 200 && (int)($cached['usage']['cache_read_input_tokens'] ?? -1) === 800,
    'cached tokens are reported beside the uncached input, not inside it',
    'in=' . ($cached['usage']['input_tokens'] ?? '?') . ' cached=' . ($cached['usage']['cache_read_input_tokens'] ?? '?'));

// ---------------------------------------------------------------------------
section('Unconfigured provider is reported in its own words');

// A missing credential is not a transient server error: it classifies apart from
// every other failure, and its message survives to the row instead of being
// flattened into "try again in a moment" — a silent generic error here cost a
// full diagnosis once, because the only text naming the empty setting was thrown
// away at the point of failure.
$config_failures = [
    'anthropic'   => 'An Anthropic model is in use but joinery_ai_anthropic_api_key is empty. Set it on the Joinery AI settings page.',
    'fireworks'   => 'A Fireworks model is in use but joinery_ai_fireworks_api_key is empty. Set it on the Joinery AI settings page.',
    'local model' => 'A non-Anthropic model is in use but joinery_ai_local_model is empty. Set it to the model id served by your OpenAI-compatible host.',
];
foreach ($config_failures as $label => $message) {
    $e = new LlmProviderException($message);
    check(LlmProviderException::classify($e) === 'api_not_configured',
        "$label empty-setting failure classifies as api_not_configured",
        'code=' . LlmProviderException::classify($e));
    check(LlmProviderException::operatorMessage($e) === $message,
        "$label empty-setting message reaches the row verbatim");
}

// Network, timeout, rate-limit and server failures keep the friendly text alone:
// the provider's wording there names nothing to change, and the detail still
// reaches the worker log.
$generic_failures = [
    ['api_network_error',  0,   'The local model host is not reachable at http://127.0.0.1:1/v1'],
    ['api_no_response',    0,   'The model did not start responding within 60 seconds'],
    ['api_quota_exceeded', 429, 'Anthropic 4xx: rate_limit_error: Number of request tokens has exceeded your per-minute rate limit'],
    ['api_quota_exceeded', 0,   'Anthropic API 4xx: rate_limit_error'],
    ['api_server_error',   0,   'Anthropic API 5xx: overloaded_error'],
    ['api_server_error',   0,   'Anthropic stream error: overloaded_error: Overloaded'],
];
foreach ($generic_failures as [$expected_code, $status, $message]) {
    $e = new LlmProviderException($message, $status);
    check(LlmProviderException::classify($e) === $expected_code,
        "$message classifies as $expected_code", 'code=' . LlmProviderException::classify($e));
    check(LlmProviderException::operatorMessage($e) === LlmProviderException::friendlyMessage($expected_code),
        "$expected_code keeps its friendly message");
}

// ---------------------------------------------------------------------------
section('A refusal about the key, account, model or request says what the provider said');

// Each is the shape AnthropicProvider / OpenAiCompatibleProvider throw: the HTTP
// status as the code, "<Provider> 4xx: <type>: <message>". The row reads the
// friendly sentence, then the provider's own words without the type prefix.
$worded_failures = [
    ['api_request_invalid',   400, 'Anthropic 4xx: invalid_request_error: This API key is not scoped to a workspace, so this request must include the anthropic-workspace-id header with the ID of the workspace to use.',
        'Anthropic', 'This API key is not scoped to a workspace'],
    ['api_auth_failed',       401, 'Anthropic 4xx: authentication_error: invalid x-api-key', 'Anthropic', 'invalid x-api-key'],
    ['api_auth_failed',       0,   'Anthropic API 4xx: authentication_error invalid x-api-key', 'Anthropic', 'authentication_error invalid x-api-key'],
    ['api_billing',           400, 'Anthropic 4xx: invalid_request_error: Your credit balance is too low to access the Anthropic API. Please go to Plans & Billing to upgrade or purchase credits.',
        'Anthropic', 'Your credit balance is too low'],
    ['api_billing',           429, 'Fireworks 4xx: insufficient_quota: You exceeded your current quota, please check your plan and billing details.',
        'Fireworks', 'You exceeded your current quota'],
    ['api_permission_denied', 403, 'Anthropic 4xx: permission_error: Your API key does not have permission to use the specified resource.',
        'Anthropic', 'does not have permission'],
    ['api_model_not_found',   404, 'Anthropic 4xx: not_found_error: model: claude-sonnet-9-9', 'Anthropic', 'model: claude-sonnet-9-9'],
    ['api_model_not_found',   0,   'Fireworks 4xx: The model accounts/fireworks/models/gone does not exist', 'Fireworks', 'does not exist'],
    ['api_request_too_large', 413, 'Anthropic 4xx: request_too_large: Request exceeds the maximum allowed number of bytes.', 'Anthropic', 'maximum allowed number of bytes'],
    ['api_request_too_large', 400, 'Anthropic 4xx: invalid_request_error: prompt is too long: 250000 tokens > 200000 maximum', 'Anthropic', 'prompt is too long'],
];
foreach ($worded_failures as [$expected_code, $status, $message, $provider, $words]) {
    $e = new LlmProviderException($message, $status);
    $row = LlmProviderException::operatorMessage($e);
    check(LlmProviderException::classify($e) === $expected_code,
        "$status " . substr($message, 0, 60) . "… classifies as $expected_code", 'code=' . LlmProviderException::classify($e));
    check(strpos($row, LlmProviderException::friendlyMessage($expected_code) . ' ' . $provider . ' said: “') === 0
        && strpos($row, $words) !== false && strpos($row, '_error:') === false,
        "$expected_code row carries the friendly text and $provider's words", $row);
}

// A message with no provider shape gets the friendly text alone, and an
// endless provider message is cut short.
check(LlmProviderException::operatorMessage(new LlmProviderException('something 4xx-ish happened', 400))
    === LlmProviderException::friendlyMessage('api_request_invalid'), 'no provider shape: friendly text alone');
$long = LlmProviderException::operatorMessage(new LlmProviderException('Anthropic 4xx: invalid_request_error: ' . str_repeat('x', 2000), 400));
check(mb_strlen($long) < 500 && substr($long, -strlen('…”')) === '…”', 'a long provider message is cut to a few hundred characters');

// End to end: the status a real response carries reaches classify().
$workspace_body = json_encode(['type' => 'error', 'error' => ['type' => 'invalid_request_error',
    'message' => 'This API key is not scoped to a workspace, so this request must include the anthropic-workspace-id header with the ID of the workspace to use.']]);
$refusing = new AnthropicProvider('test-key', AnthropicProvider::API_URL,
    jai_mock_client(new Response(400, ['Content-Type' => 'application/json'], $workspace_body)));
$caught = null;
try {
    $refusing->createMessage(jai_min_params());
} catch (LlmProviderException $e) {
    $caught = $e;
}
check($caught !== null && (int)$caught->getCode() === 400, 'a 400 from Anthropic throws with the status as its code',
    $caught ? 'code=' . $caught->getCode() : 'no throw');
check($caught !== null && strpos(LlmProviderException::operatorMessage($caught), 'Anthropic said: “This API key is not scoped to a workspace') !== false,
    'the chat row names the workspace problem', $caught ? LlmProviderException::operatorMessage($caught) : '');

// ---------------------------------------------------------------------------
section('A model that thinks adaptively: no budget, no sampling, thinking kept');

/** An Anthropic provider whose one streamed answer is $sse; records what it was sent. */
function jai_recording_anthropic(string $sse, array &$sent): AnthropicProvider {
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($sse)),
    ]));
    $stack->push(GuzzleHttp\Middleware::history($sent));
    return new AnthropicProvider('test-key', AnthropicProvider::API_URL, new Client(['handler' => $stack]));
}
$event = function (array $d): string { return 'event: ' . $d['type'] . "\ndata: " . json_encode($d) . "\n\n"; };
$thinking_sse = $event(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 10]]])
    . $event(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'thinking', 'thinking' => '', 'signature' => '']])
    . $event(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'thinking_delta', 'thinking' => '']])
    . $event(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'signature_delta', 'signature' => 'SIG-abc']])
    . $event(['type' => 'content_block_stop', 'index' => 0])
    . $event(['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'text', 'text' => '']])
    . $event(['type' => 'content_block_stop', 'index' => 1])
    . $event(['type' => 'content_block_start', 'index' => 2, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'search_docs']])
    . $event(['type' => 'content_block_delta', 'index' => 2, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"q":"x"}']])
    . $event(['type' => 'content_block_stop', 'index' => 2])
    . $event(['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 7]])
    . $event(['type' => 'message_stop']);

$sent = [];
$adaptive = AiModelResolution::forProvider(jai_recording_anthropic($thinking_sse, $sent), 'claude-sonnet-5-5',
    ['thinking' => 'always', 'thinking_style' => 'adaptive', 'sampling' => false]);
$params = jai_min_params();
$params['temperature'] = 0.3;
$params['top_p'] = 0.9;
$params['thinking'] = $adaptive->thinkingDirective();
$answer = $adaptive->send($params, static function (string $d): void {});
$body = json_decode((string)$sent[0]['request']->getBody(), true);
check(!array_key_exists('temperature', $body) && !array_key_exists('top_p', $body),
    'a model that refuses sampling settings is sent none', json_encode(array_intersect_key($body, ['temperature' => 1, 'top_p' => 1])));
check(($body['thinking'] ?? null) === ['type' => 'adaptive'], 'it is asked for adaptive thinking, with no budget', json_encode($body['thinking'] ?? null));
check(($body['output_config'] ?? null) === ['effort' => (string)$adaptive->thinkingDirective()['effort']],
    'at the thinking level the request asked for, as its effort', json_encode($body['output_config'] ?? null));
$types = array_map(fn($b) => $b['type'], $answer['content']);
check($types === ['thinking', 'tool_use'], 'the thinking block comes back ahead of the tool call, and no empty text block', json_encode($types));
check(($answer['content'][0]['signature'] ?? '') === 'SIG-abc', 'with its signature, to be sent back unchanged');

// A level of 'high' reaches the model as high effort, not the model's own default.
$sent = [];
$high = AiModelResolution::forProvider(jai_recording_anthropic($thinking_sse, $sent), 'claude-opus-5-5',
    ['thinking' => 'always', 'thinking_style' => 'adaptive', 'sampling' => false]);
$params = jai_min_params();
$params['thinking'] = ['enabled' => true, 'effort' => 'high', 'level' => 'high'];
$high->send($params, static function (string $d): void {});
$body = json_decode((string)$sent[0]['request']->getBody(), true);
check(($body['output_config']['effort'] ?? '') === 'high', 'a level of high runs an adaptive model at high effort', json_encode($body['output_config'] ?? null));

$sent = [];
$budgeted = AiModelResolution::forProvider(jai_recording_anthropic($thinking_sse, $sent), 'claude-sonnet-4-6', []);
$params = jai_min_params();
$params['temperature'] = 0.3;
$params['thinking'] = ['enabled' => true, 'effort' => 'medium', 'level' => 'medium'];
$budgeted->send($params, static function (string $d): void {});
$body = json_decode((string)$sent[0]['request']->getBody(), true);
check(($body['thinking']['type'] ?? '') === 'enabled' && (int)($body['thinking']['budget_tokens'] ?? 0) === 4096,
    'a model that takes a budget still gets one', json_encode($body['thinking'] ?? null));

// ---------------------------------------------------------------------------
section('A member\'s failed row never quotes the provider');

// The member chat shows aim_error to whoever owns the conversation. What a
// provider (or a proxy in front of it) says can carry a key fragment or an
// internal address, and the fix is the administrator's in any case.
$admin_sentence = LlmProviderException::memberMessageFor('api_auth_failed');
foreach ($worded_failures as [$expected_code, $status, $message, $provider, $words]) {
    $member = LlmProviderException::memberMessage(new LlmProviderException($message, $status));
    check(strpos($member, 'said:') === false && strpos($member, $words) === false,
        "$expected_code: a member's row carries none of $provider's words", $member);
}
check(LlmProviderException::memberMessage(new LlmProviderException('Anthropic 4xx: authentication_error: invalid x-api-key', 401)) === $admin_sentence
    && strpos($admin_sentence, 'settings page') === false && strpos($admin_sentence, 'administrator') !== false,
    'a failure only the administrator can fix says so, naming no settings page', $admin_sentence);
check(LlmProviderException::memberMessage(new LlmProviderException('Anthropic 4xx: invalid_request_error: prompt is too long: 250000 tokens > 200000 maximum', 400))
    === LlmProviderException::friendlyMessage('api_request_too_large'),
    'a failure the member can act on keeps its friendly sentence');
check(LlmProviderException::memberMessage(new LlmProviderException('Anthropic 4xx: invalid_request_error: messages.0.content.1.image: unsupported image format', 400))
    === LlmProviderException::friendlyMessage('api_request_invalid'),
    'a refusal that can be the member\'s own input keeps the neutral sentence, blaming no one');
check(ChatTurn::costOf(['model' => 'no-such-model-' . mt_rand(), 'input_tokens' => 1000, 'output_tokens' => 10]) === null,
    'a turn on a model the catalog does not know has no cost, so the cap counts it');
$known = array_key_first(AiEndpointRegistry::catalog());
if ($known === null) {
    harness_skip('a model this install serves is priced', 'no endpoint is configured here');
} else {
    check(is_float(ChatTurn::costOf(['model' => $known, 'input_tokens' => 1000, 'output_tokens' => 10])),
        'a model this install serves is priced (0 when it declares no cost)', $known);
}
check(LlmProviderException::memberMessage(new LlmProviderException('Local model API 5xx (502): upstream at http://10.0.0.5:8080 failed', 502))
    === LlmProviderException::friendlyMessage('api_server_error'), 'a server failure reads the same for everyone');

// 'model ... does not exist' is read from the provider's words: the label in
// front of a local host's message names a model on every failure.
check(LlmProviderException::classify(new LlmProviderException('Local model 4xx: not_found: file does not exist', 400)) === 'api_request_invalid',
    'the label\'s word model does not make a missing file a missing model',
    LlmProviderException::classify(new LlmProviderException('Local model 4xx: not_found: file does not exist', 400)));

// ---------------------------------------------------------------------------
section('Turn diagnostics survive the detached path');

// ChatTurn logs through ChatAsync::log because error_log() is discarded after
// fastcgi_finish_request closes the FastCGI stderr stream. If any of those call
// sites regress to error_log(), a failing turn goes undiagnosable again.
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatAsync.php'));
$log_path = PathHelper::getSiteRoot() . '/logs/joinery_ai_worker.log';
$size_before = file_exists($log_path) ? filesize($log_path) : 0;
$marker = 'harness-probe-' . bin2hex(random_bytes(6));
ChatAsync::log('[test] ' . $marker);
clearstatcache(true, $log_path);
check(file_exists($log_path) && filesize($log_path) > $size_before,
    'ChatAsync::log appends to the AI worker log', $log_path);
check(strpos((string)@file_get_contents($log_path), $marker) !== false,
    'the logged line is retrievable afterwards');

$turn_source = (string)@file_get_contents(
    PathHelper::getIncludePath('plugins/joinery_ai/includes/ChatTurn.php'));
check($turn_source !== '' && strpos($turn_source, 'error_log(') === false,
    'ChatTurn logs via ChatAsync::log only (no error_log on the detached path)');

harness_finish();
