<?php
/** @joinery-test
 * name: joinery_ai_hosted_ai_credit
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * The site side of hosted AI credit (specs/hosted_ai_credit.md §3.4, WP3):
 *
 *  - the `hosted` endpoint is unavailable while joinery_ai_hosted_api_key is
 *    empty and available once it is set, serving GLM 5.3 Flash at its price;
 *  - utils/hosted_ai_settings.php sets the key, clears it on an empty push,
 *    refuses a malformed one, and never prints the key;
 *  - every request TrustedRouterProvider builds carries provider.zdr = true
 *  - the owner's privacy level sets the routing and the price (zero retention, cheapest first; or confidential)
 *    (no network: a mocked HTTP client records what was sent);
 *  - a 402 from it classifies as api_billing and is worded as the site's AI
 *    credit being used up, on the chat (operator and member) and on a
 *    recipe run's recorded error.
 *
 * Runs on the test database: the key is written as a real setting row there.
 *
 * Run: php tests/run.php test-db --filter=joinery_ai_hosted_ai_credit
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();

require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/TrustedRouterProvider.php'));
require_once(PathHelper::getIncludePath('utils/hosted_ai_settings.php'));

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

const JAI_HOSTED_MODEL = 'z-ai/glm-5.3-flash';

$original = get_setting_raw(HOSTED_AI_KEY_SETTING);
harness_defer(function () use ($original) {
	Setting::put(HOSTED_AI_KEY_SETTING, '');
	if ($original !== null && $original !== '') set_setting_raw(HOSTED_AI_KEY_SETTING, $original);
	AiEndpointRegistry::clearCache();
});

/** Run the node-side script's work on a JSON body; re-read the catalog after. */
function jai_push(string $raw): array {
	$r = hosted_ai_settings_apply($raw);
	AiEndpointRegistry::clearCache();
	return $r;
}

// ===========================================================================
section('The setting and the endpoint are declared');
// ===========================================================================

check(SettingsDeclarations::isDeclared(HOSTED_AI_KEY_SETTING), 'joinery_ai_hosted_api_key is declared');
check(SettingsDeclarations::isSecret(HOSTED_AI_KEY_SETTING) && SettingsDeclarations::isManaged(HOSTED_AI_KEY_SETTING),
	'it is a managed secret: sealed at rest, never on a form');

$endpoint = AiEndpointRegistry::endpoint('hosted');
check($endpoint !== null, 'the catalog declares a hosted endpoint');
check(($endpoint['label'] ?? '') === 'AI credit (included with your hosting)'
	&& ($endpoint['dialect'] ?? '') === 'openai'
	&& ($endpoint['base_url'] ?? '') === 'https://api.trustedrouter.com/v1'
	&& ($endpoint['api_key_setting'] ?? '') === HOSTED_AI_KEY_SETTING
	&& ($endpoint['trust'] ?? '') === 'trusted',
	'label, dialect, literal base URL, key setting and trust as specified', json_encode($endpoint));
check(stripos((string)($endpoint['trust_note'] ?? ''), 'zero retention') !== false && stripos((string)($endpoint['trust_note'] ?? ''), 'confidential') !== false,
	'its trust note names both privacy levels');
$declared = AiEndpointRegistry::modelsFor('hosted');
check(array_keys($declared) === [JAI_HOSTED_MODEL], 'it serves exactly GLM 5.3 Flash', json_encode(array_keys($declared)));

// ===========================================================================
section('No key: unavailable. A key: available');
// ===========================================================================

[$code, $out, $err] = jai_push('{"api_key":""}');
check($code === 0 && strpos($out, 'HOSTED_AI_SETTINGS=ok') === 0 && strpos($out, 'key=cleared') !== false,
	'an empty push clears the key and says so', $out . $err);
check(!AiEndpointRegistry::isAvailable('hosted'), 'with no key the hosted endpoint is not available');
check(AiEndpointRegistry::model(JAI_HOSTED_MODEL) === null, 'and its model is not in the catalog');

$fake = 'tr-test-' . bin2hex(random_bytes(12));
[$code, $out, $err] = jai_push(json_encode(['api_key' => $fake]));
check($code === 0 && strpos($out, 'HOSTED_AI_SETTINGS=ok') === 0 && strpos($out, 'key=set') !== false,
	'a push with a key sets it', $out . $err);
check(strpos($out . $err, $fake) === false, 'the script never prints the key');
check((string)Globalvars::get_instance()->get_setting(HOSTED_AI_KEY_SETTING) === $fake,
	'the stored setting reads back as the key');
check((string)get_setting_raw(HOSTED_AI_KEY_SETTING) !== $fake, 'and it is not stored in the clear');
check(AiEndpointRegistry::isAvailable('hosted'), 'with a key the hosted endpoint is available');

$entry = AiEndpointRegistry::model(JAI_HOSTED_MODEL);
check($entry !== null && $entry['endpoint'] === 'hosted' && $entry['trust'] === 'trusted' && $entry['tier'] === 'capable',
	'GLM 5.3 Flash: hosted, trusted, graded capable', json_encode($entry));
check($entry !== null && (float)$entry['cost']['input'] === 0.026375 && (float)$entry['cost']['output'] === 0.07385,
	'priced at $0.026375/M in and $0.07385/M out', json_encode($entry['cost'] ?? null));
$cost = AiModelResolution::costFor(JAI_HOSTED_MODEL,
	['input_tokens' => 1000000, 'output_tokens' => 1000000, 'cache_read_input_tokens' => 1000000]);
check(abs($cost - (0.026375 * 2 + 0.07385)) < 1e-9, 'a cached token costs the same as a new one', (string)$cost);

$directive = AiModelResolver::thinkingDirectiveFor($entry ?? [], AiModelRequirement::make());
check($directive['enabled'] === true && $directive['effort'] === 'low',
	'work that asks for no reasoning runs it at low', json_encode($directive));

$provider = LlmProviderFactory::forEndpoint('hosted');
check($provider instanceof TrustedRouterProvider && $provider->id() === 'hosted',
	'the factory builds a TrustedRouterProvider for it', get_class($provider));

[$code, , $err] = jai_push('{"api_key":"has a space"}');
check($code === 2 && (string)Globalvars::get_instance()->get_setting(HOSTED_AI_KEY_SETTING) === $fake,
	'a malformed key is refused and the stored one kept', $err);
[$code, , $err] = jai_push('{"key":"x"}');
check($code === 2, 'a body without api_key is refused', $err);

[$code, $out] = jai_push('{"api_key":""}');
check($code === 0 && !AiEndpointRegistry::isAvailable('hosted'), 'clearing it takes the endpoint out again', $out);

// ===========================================================================
section('Every request carries provider.zdr');
// ===========================================================================

$sse = 'data: ' . json_encode(['choices' => [['delta' => ['content' => 'ok'], 'finish_reason' => 'stop']]]) . "\n\n"
	. 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1]]) . "\n\n"
	. "data: [DONE]\n\n";
$sent = [];
$responses = [];
for ($i = 0; $i < 3; $i++) {
	$responses[] = new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($sse));
}
$stack = HandlerStack::create(new MockHandler($responses));
$stack->push(Middleware::history($sent));
$tr = new TrustedRouterProvider('test-key', TrustedRouterProvider::DEFAULT_BASE_URL,
	JAI_HOSTED_MODEL, 30, new Client(['handler' => $stack]));

$base = ['model' => JAI_HOSTED_MODEL, 'max_tokens' => 16, 'system' => [['type' => 'text', 'text' => 'sys']],
	'messages' => [['role' => 'user', 'content' => 'hi']]];
$variants = [
	'a plain request' => $base,
	'with reasoning on' => $base + ['thinking' => ['enabled' => true, 'effort' => 'low', 'level' => 'low']],
	'with tools' => $base + ['tools' => [['name' => 'search_docs', 'description' => 'd',
		'input_schema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]]]],
];
foreach ($variants as $what => $params) {
	$answer = $tr->createMessage($params);
	$body = json_decode((string)end($sent)['request']->getBody(), true);
	check(($body['provider']['zdr'] ?? null) === true, "$what carries provider.zdr = true", json_encode($body['provider'] ?? null));
	check(($answer['content'][0]['text'] ?? '') === 'ok', "$what still reads the answer");
}
$last = end($sent)['request'];
check((string)$last->getUri() === 'https://api.trustedrouter.com/v1/chat/completions'
	&& $last->getHeaderLine('authorization') === 'Bearer test-key',
	'sent to TrustedRouter with the site key as a Bearer token', (string)$last->getUri());
$body = json_decode((string)$sent[1]['request']->getBody(), true);
check(($body['reasoning_effort'] ?? null) === 'low', 'the reasoning effort rides beside it', json_encode($body['reasoning_effort'] ?? null));

$plain_sent = [];
$plain_stack = HandlerStack::create(new MockHandler([new Response(200, [], Utils::streamFor($sse))]));
$plain_stack->push(Middleware::history($plain_sent));
(new OpenAiCompatibleProvider('http://mock/v1', 'm', '', 30, new Client(['handler' => $plain_stack])))->createMessage($base);
$plain_body = json_decode((string)$plain_sent[0]['request']->getBody(), true);
check(!array_key_exists('provider', $plain_body), 'a plain OpenAI-compatible host is sent no provider field');

// ===========================================================================
section('The privacy level the owner picks: its routing and its price');
// ===========================================================================

jai_push('{"api_key":"' . $fake . '"}');
$level_sent = [];
$level_stack = HandlerStack::create(new MockHandler([
	new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($sse)),
	new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($sse)),
	new Response(200, ['Content-Type' => 'text/event-stream'], Utils::streamFor($sse)),
]));
$level_stack->push(Middleware::history($level_sent));
$level_tr = new TrustedRouterProvider('test-key', TrustedRouterProvider::DEFAULT_BASE_URL,
	JAI_HOSTED_MODEL, 30, new Client(['handler' => $level_stack]));
$one_million = ['input_tokens' => 1000000, 'output_tokens' => 1000000];

foreach (array(
	'' => array('not chosen yet', array('zdr' => true, 'sort' => 'price'), 0.026375 + 0.07385),
	'zero_retention' => array('zero retention', array('zdr' => true, 'sort' => 'price'), 0.026375 + 0.07385),
	'confidential' => array('confidential', array('min_privacy' => 'confidential', 'zdr' => true), 0.2432 + 0.7902),
) as $value => $expect) {
	list($what, $fields, $price) = $expect;
	harness_set_setting_mem('joinery_ai_hosted_privacy', $value);
	AiEndpointRegistry::clearCache();
	$level_tr->createMessage($base);
	$body = json_decode((string)end($level_sent)['request']->getBody(), true);
	$sent_fields = (array)($body['provider'] ?? array());
	ksort($sent_fields);
	ksort($fields);
	check($sent_fields === $fields, "$what: the request asks for " . json_encode($fields), json_encode($body['provider'] ?? null));
	$cost = AiModelResolution::costFor(JAI_HOSTED_MODEL, $one_million);
	check(abs($cost - $price) < 1e-9, "$what: a million tokens each way is costed at that level's price", (string)$cost);
}
harness_set_setting_mem('joinery_ai_hosted_privacy', 'nonsense');
AiEndpointRegistry::clearCache();
check(AiEndpointRegistry::privacyLevel('hosted')['key'] === 'zero_retention', 'a value naming no level is the default');

$declaration = SettingsDeclarations::get('joinery_ai_hosted_privacy');
$levels = array_keys(AiEndpointRegistry::privacyLevels('hosted'));
check($declaration !== null && ($declaration['type'] ?? '') === 'radio'
	&& array_keys((array)$declaration['options']) === $levels
	&& array_keys((array)($declaration['option_descriptions'] ?? array())) === $levels,
	'the setting offers exactly the catalog\'s levels, each with its sentence', json_encode($declaration['options'] ?? null));
check(!array_filter(SettingsDeclarations::schemaErrors(), function ($e) { return strpos($e, 'joinery_ai_hosted_privacy') !== false; }),
	'and its declaration is well formed');

harness_set_setting_mem('joinery_ai_hosted_privacy', '');
jai_push('{"api_key":""}');
AiEndpointRegistry::clearCache();

// ===========================================================================
section('Out of credit: a 402 is api_billing, in the hosted words');
// ===========================================================================

$billing = HandlerStack::create(new MockHandler([new Response(402, ['Content-Type' => 'application/json'],
	json_encode(['error' => ['message' => 'Key spending limit reached', 'type' => 'insufficient_credits']]))]));
$tr402 = new TrustedRouterProvider('test-key', TrustedRouterProvider::DEFAULT_BASE_URL,
	JAI_HOSTED_MODEL, 30, new Client(['handler' => $billing]));
$caught = null;
try {
	$tr402->createMessage($base);
} catch (LlmProviderException $e) {
	$caught = $e;
}
check($caught !== null, 'a 402 throws an LlmProviderException');
if ($caught !== null) {
	check(LlmProviderException::classify($caught) === 'api_billing', 'it classifies as api_billing', $caught->getMessage());
	check(LlmProviderException::isHostedCredit($caught), 'and is known to come from the hosted endpoint');
	$op = LlmProviderException::operatorMessage($caught);
	check(strpos($op, 'AI credit is used up') !== false && strpos($op, 'Buy more AI credit') !== false
		&& strpos($op, 'own AI provider key') !== false,
		'the administrator is told the credit is used up, where to buy more, and the own-key way out', $op);
	check(stripos($op, 'with the provider') === false, 'and is not sent to a provider dashboard', $op);
	$member = LlmProviderException::memberMessage($caught);
	check(strpos($member, 'AI credit is used up') !== false && strpos($member, 'Key spending limit') === false,
		'a member is told the credit is used up, without the provider words', $member);
	$recorded = LlmProviderException::recordedError($caught);
	check(strpos($recorded, '[api_billing] ') === 0 && strpos($recorded, 'Buy more AI credit') !== false,
		'a recipe run records the code and the hosted words', $recorded);
}

$other = new LlmProviderException('Fireworks 4xx: insufficient_quota: You exceeded your current quota', 429);
check(LlmProviderException::classify($other) === 'api_billing' && !LlmProviderException::isHostedCredit($other)
	&& strpos(LlmProviderException::operatorMessage($other), 'Buy more AI credit') === false,
	'another provider\'s billing refusal keeps its own wording');
check(LlmProviderException::recordedError($other) === '[api_billing] ' . $other->getMessage(),
	'and records exactly as before');

harness_finish();
