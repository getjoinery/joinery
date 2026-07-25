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

// Every other class keeps the friendly text — the provider's own wording there is
// third-party and not actionable, and the detail still reaches the worker log.
$generic_failures = [
    'api_network_error'   => 'The local model host is not reachable at http://127.0.0.1:1/v1',
    'api_no_response'     => 'The model did not start responding within 60 seconds',
    'api_auth_failed'     => 'Anthropic API 4xx: authentication_error invalid x-api-key',
    'api_quota_exceeded'  => 'Anthropic API 4xx: rate_limit_error',
    'api_request_invalid' => 'Anthropic API 4xx: invalid_request_error bad model',
    'api_server_error'    => 'Anthropic API 5xx: overloaded_error',
];
foreach ($generic_failures as $expected_code => $message) {
    $e = new LlmProviderException($message);
    check(LlmProviderException::classify($e) === $expected_code,
        "$message classifies as $expected_code", 'code=' . LlmProviderException::classify($e));
    check(LlmProviderException::operatorMessage($e) === LlmProviderException::friendlyMessage($expected_code),
        "$expected_code keeps its friendly message");
}

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
