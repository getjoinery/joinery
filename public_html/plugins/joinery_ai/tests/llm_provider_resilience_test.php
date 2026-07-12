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

harness_finish();
