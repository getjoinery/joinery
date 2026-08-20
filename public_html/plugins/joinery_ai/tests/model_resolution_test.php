<?php
/** @joinery-test
 * name: joinery_ai_model_resolution
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Capability-based model resolution
 * (specs/joinery_ai_model_capability_resolution.md).
 *
 * A recipe no longer names a model. It states what the work NEEDS — a
 * capability floor, how far the content may travel, whether reasoning is
 * required — and the resolver picks the cheapest catalog model that clears
 * every floor. This suite covers the properties that make that safe to do:
 *
 *  - the shipped catalog parses, and every model in it is well-formed;
 *  - a floor is a MINIMUM: anything at or above it qualifies;
 *  - local is preferred, always, so reaching a vendor is a consequence of a
 *    floor your own hardware could not meet;
 *  - failover never spends money — a free first choice can only fall back to
 *    another free one;
 *  - resolution is deterministic, so a recipe does not drift between runs;
 *  - it fails CLOSED: an unknown pin, an unparseable catalog, or a floor
 *    nothing clears is a refusal naming the gap, never a fall-through to
 *    whatever happens to be available;
 *  - one trust class answers both the chat warning and the sealed-egress gate,
 *    so they can no longer disagree about a model.
 *
 * Runs against a FIXTURE catalog, not the install's own, so a dev box with one
 * local model still exercises every cell.
 *
 * Run: php tests/run.php safe --filter=joinery_ai_model_resolution
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));

// ---------------------------------------------------------------------------
// A fixture catalog with one model per interesting shape. Written to a temp
// file and pointed at through the same reader the shipped one uses, so this
// tests the real path rather than a parallel one.
// ---------------------------------------------------------------------------

$tmp = sys_get_temp_dir() . '/joai_catalog_' . substr(md5(uniqid('', true)), 0, 8);
@mkdir($tmp, 0777, true);
harness_defer(function () use ($tmp) {
	foreach (glob($tmp . '/*') as $f) @unlink($f);
	@rmdir($tmp);
});

/** Swap the registry onto a fixture catalog for the duration of one closure. */
function with_catalog(array $endpoints, array $reference, callable $fn) {
	$dir = sys_get_temp_dir() . '/joai_catalog_run_' . substr(md5(uniqid('', true)), 0, 8);
	@mkdir($dir, 0777, true);
	file_put_contents($dir . '/ai_endpoints.json', json_encode(['endpoints' => $endpoints]));
	file_put_contents($dir . '/ai_model_reference.json', json_encode($reference));

	$prev = AiEndpointRegistry::useCatalogFiles(
		$dir . '/ai_endpoints.json', $dir . '/ai_model_reference.json');
	try {
		return $fn();
	} finally {
		AiEndpointRegistry::useCatalogFiles($prev[0], $prev[1]);
		AiEndpointRegistry::clearCache();
		foreach (glob($dir . '/*') as $f) @unlink($f);
		@rmdir($dir);
	}
}

$model = function (string $id, string $tier, array $extra = []): array {
	return array_merge([
		'id' => $id, 'label' => $id, 'tier' => $tier,
		'thinking' => 'optional', 'tools' => true, 'context' => 128000,
		'attachments' => ['vision' => false, 'document' => false],
	], $extra);
};

$FIXTURE = [
	[
		'key' => 'local', 'label' => 'Local', 'dialect' => 'openai',
		'base_url_setting' => 'joinery_ai_local_base_url', 'api_key_setting' => null,
		'trust' => 'local',
		'models' => [
			$model('local-small', 'basic'),
			$model('local-mid', 'capable', ['thinking' => 'none']),
		],
	],
	[
		'key' => 'fireworks', 'label' => 'Fireworks', 'dialect' => 'openai',
		'base_url_setting' => 'joinery_ai_fireworks_base_url',
		'api_key_setting' => 'joinery_ai_fireworks_api_key',
		'trust' => 'trusted',
		'models' => [
			$model('trusted-mid', 'capable', ['cost' => ['input' => 0.15, 'output' => 0.60]]),
			$model('trusted-top', 'frontier', ['cost' => ['input' => 1.40, 'output' => 4.40],
				'thinking' => 'always']),
		],
	],
	[
		'key' => 'anthropic', 'label' => 'Anthropic', 'dialect' => 'anthropic',
		'base_url' => 'https://example.invalid/v1/messages',
		'api_key_setting' => 'joinery_ai_anthropic_api_key',
		'trust' => 'cloud',
		'models' => [
			$model('cloud-mid', 'capable', ['cost' => ['input' => 1.00, 'output' => 5.00],
				'attachments' => ['vision' => true, 'document' => true],
				'defaults' => ['temperature' => 0.3, 'max_output_tokens' => 8000]]),
			$model('cloud-top', 'frontier', ['cost' => ['input' => 3.00, 'output' => 15.00],
				'attachments' => ['vision' => true, 'document' => true], 'context' => 200000]),
			$model('cloud-old', 'frontier', ['cost' => ['input' => 3.00, 'output' => 15.00],
				'retired' => true]),
		],
	],
];

$REFERENCE = [
	'ladder' => [
		['max_params_b' => 4, 'tier' => 'basic'],
		['max_params_b' => 32, 'tier' => 'capable'],
		['max_params_b' => null, 'tier' => 'frontier'],
	],
	'models' => [
		['match' => 'measured:9b*', 'tier' => 'capable', 'basis' => 'measured', 'evidence' => 'fixture'],
		['match' => 'downgraded:70b*', 'tier' => 'basic', 'basis' => 'research', 'evidence' => 'fixture'],
	],
];

// Every endpoint configured, so the matrix is about floors rather than keys.
harness_set_setting_mem('joinery_ai_anthropic_api_key', 'fixture-key');
harness_set_setting_mem('joinery_ai_fireworks_api_key', 'fixture-key');
harness_set_setting_mem('joinery_ai_local_base_url', 'http://127.0.0.1:1/v1');
harness_set_setting_mem('joinery_ai_selection_policy', 'prefer_local');

/** Resolve inside the fixture catalog and return the chosen model id, or ''. */
function pick(AiModelRequirement $req, array $endpoints, array $reference): string {
	return with_catalog($endpoints, $reference, function () use ($req) {
		$r = AiModelResolver::tryResolve($req);
		return $r === null ? '' : $r->modelId();
	});
}

$req = function (): AiModelRequirement {
	return AiModelRequirement::make()->withPurpose('the test');
};

// ===========================================================================
section('A. The SHIPPED catalog is well-formed');
// ===========================================================================

// This one runs against the real files: a catalog that will not parse stops
// every recipe on the install, so it is worth failing here rather than there.
AiEndpointRegistry::clearCache();
$shipped_ok = true;
$shipped_why = '';
try {
	$endpoints = AiEndpointRegistry::endpoints();
} catch (Throwable $e) {
	$shipped_ok = false; $shipped_why = $e->getMessage(); $endpoints = [];
}
check($shipped_ok, 'ai_endpoints.json parses', $shipped_why);
check(count($endpoints) > 0, 'and declares at least one endpoint');

$seen_ids = [];
$bad = [];
foreach ($endpoints as $key => $endpoint) {
	if (!in_array((string)($endpoint['trust'] ?? ''), AiModelRequirement::TRUST_CLASSES, true)) {
		$bad[] = "$key: trust '" . (string)($endpoint['trust'] ?? '') . "' is not a trust class";
	}
	if (!in_array((string)($endpoint['dialect'] ?? ''), ['openai', 'anthropic'], true)) {
		$bad[] = "$key: dialect '" . (string)($endpoint['dialect'] ?? '') . "' has no provider";
	}
	foreach ((array)($endpoint['models'] ?? []) as $m) {
		$id = (string)($m['id'] ?? '');
		if ($id === '') { $bad[] = "$key: a model with no id"; continue; }
		if (isset($seen_ids[$id])) $bad[] = "$id is served by two endpoints ($seen_ids[$id], $key)";
		$seen_ids[$id] = $key;
		if (!in_array((string)($m['tier'] ?? ''), AiModelRequirement::TIERS, true)) {
			$bad[] = "$id: tier '" . (string)($m['tier'] ?? '') . "' is not a rung";
		}
		if (!in_array((string)($m['thinking'] ?? ''), ['none', 'optional', 'always'], true)) {
			$bad[] = "$id: thinking '" . (string)($m['thinking'] ?? '') . "' is not a capability";
		}
	}
}
check(count($bad) === 0, 'every shipped model declares a valid tier, thinking and trust',
	implode('; ', $bad));

// A *_setting field naming a setting nothing declares is a misspelling that
// would read as "this endpoint is not configured" forever.
require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));
$undeclared = [];
foreach ($endpoints as $key => $endpoint) {
	foreach (['api_key_setting', 'base_url_setting', 'enabled_setting', 'models_setting'] as $field) {
		$name = $endpoint[$field] ?? null;
		if ($name === null || $name === '') continue;
		if (!SettingsDeclarations::isDeclared((string)$name)) $undeclared[] = "$key.$field -> $name";
	}
}
check(count($undeclared) === 0, 'every setting an endpoint names is declared', implode('; ', $undeclared));

$ref_ok = true; $ref_why = '';
try {
	AiEndpointRegistry::referenceEntryFor('anything');
} catch (Throwable $e) {
	$ref_ok = false; $ref_why = $e->getMessage();
}
check($ref_ok, 'ai_model_reference.json parses', $ref_why);

// ===========================================================================
section('B. A floor is a minimum, not a selector');
// ===========================================================================

check(pick($req()->withMinTier('basic')->withTrustFloor('local'), $FIXTURE, $REFERENCE) === 'local-small',
	'a basic floor takes the cheapest local model that clears it');
check(pick($req()->withMinTier('capable')->withTrustFloor('local'), $FIXTURE, $REFERENCE) === 'local-mid',
	'a capable floor skips the basic model and takes the capable one');
check(pick($req()->withMinTier('frontier')->withTrustFloor('local'), $FIXTURE, $REFERENCE) === '',
	'a frontier floor with no local frontier model refuses rather than settling');

// ===========================================================================
section('C. Local first, always');
// ===========================================================================

check(pick($req()->withMinTier('capable')->withTrustFloor('any'), $FIXTURE, $REFERENCE) === 'local-mid',
	'with everything configured, a capable requirement stays on the local box');
check(pick($req()->withMinTier('frontier')->withTrustFloor('any'), $FIXTURE, $REFERENCE) === 'trusted-top',
	'and reaches a vendor only for a floor local hardware cannot meet - the cheapest one that can');
check(pick($req()->withMinTier('frontier')->withTrustFloor('trusted'), $FIXTURE, $REFERENCE) === 'trusted-top',
	'a trusted floor accepts the trusted vendor');

// A cloud-only need still refuses under a local floor: the floor is a hard
// filter, not a preference the resolver may talk itself out of.
check(pick($req()->withVision(true)->withTrustFloor('local'), $FIXTURE, $REFERENCE) === '',
	'a vision requirement under a local floor refuses, rather than reaching cloud for it');
check(pick($req()->withVision(true)->withTrustFloor('any'), $FIXTURE, $REFERENCE) === 'cloud-mid',
	'and the same requirement with no floor takes the cheapest model that can read images');

// ===========================================================================
section('D. Failover never spends money');
// ===========================================================================

with_catalog($FIXTURE, $REFERENCE, function () use ($req) {
	$r = AiModelResolver::resolve($req()->withMinTier('basic')->withTrustFloor('any'));
	check($r->modelId() === 'local-small', 'a free first choice', $r->modelId());
	$paid = array_values(array_filter($r->candidates(), function ($c) { return !empty($c['cost']); }));
	check(count($paid) === 0,
		'carries no paid candidate at all, so a sleeping local host can never become a bill',
		implode(',', array_column($r->candidates(), 'id')));

	// The inverse: an expensive first choice may fall back to something cheaper.
	$r2 = AiModelResolver::resolve($req()->withMinTier('frontier')->withTrustFloor('any')
		->withPolicy(AiModelResolver::POLICY_BEST));
	$ceiling = AiModelResolver::rateOf($r2->entry());
	$over = array_values(array_filter($r2->candidates(),
		function ($c) use ($ceiling) { return AiModelResolver::rateOf($c) > $ceiling; }));
	check(count($over) === 0, 'and no candidate anywhere costs more than the first choice',
		implode(',', array_column($over, 'id')));
});

// ===========================================================================
section('E. Determinism');
// ===========================================================================

$runs = [];
for ($i = 0; $i < 5; $i++) {
	$runs[] = pick($req()->withMinTier('capable')->withTrustFloor('any'), $FIXTURE, $REFERENCE);
}
check(count(array_unique($runs)) === 1, 'the same requirement resolves identically every time',
	implode(',', array_unique($runs)));

// ===========================================================================
section('F. Fail closed');
// ===========================================================================

// A pin below the floor is a MISTAKE and is refused - not routed around.
with_catalog($FIXTURE, $REFERENCE, function () use ($req) {
	$refused = false; $msg = '';
	try {
		AiModelResolver::resolve($req()->withMinTier('frontier')->withPin('local-small'));
	} catch (LlmProviderException $e) {
		$refused = true; $msg = $e->getMessage();
	}
	check($refused, 'a pin below the floor is refused');
	check(stripos($msg, 'local-small') !== false, 'and the refusal names it', $msg);

	// ...but only against a floor SOMEBODY STATED. The platform's last-resort
	// guess still filters an unstated recipe onto something sensible; it does
	// not veto the one thing the operator did say. Otherwise every hand-made
	// agent recipe on a box with no frontier model would refuse its own pin.
	$r0 = AiModelResolver::resolve(
		$req()->withFallbackMinTier('frontier')->withPin('local-small'));
	check($r0->modelId() === 'local-small',
		'a fallback floor does not veto an explicit pin', $r0->modelId());
	check($r0->substitutionNote() === '',
		'and nothing is reported as a substitution, because nothing was substituted');

	// The safety case is unchanged: a job that STATES its floor still refuses.
	$still_refused = false;
	try {
		AiModelResolver::resolve($req()->withMinTier('capable')->withPin('local-small'));
	} catch (LlmProviderException $e) { $still_refused = true; }
	check($still_refused, 'while a floor a job stated still refuses a pin below it');

	// Same distinction on the unpinned path: an assumed floor nothing meets
	// RELAXES to the most capable model available rather than making the whole
	// feature unusable. Agent mode assumes `frontier`; a box topping out at
	// `capable` must still be able to run an agent recipe.
	$r_relax = AiModelResolver::resolve(
		$req()->withFallbackMinTier('frontier')->withTrustFloor('local'));
	check($r_relax->modelId() === 'local-mid',
		'an assumed floor nothing meets relaxes to the most capable available',
		$r_relax->modelId());
	check(stripos($r_relax->substitutionNote(), 'most capable model') !== false,
		'and says so, so the compromise is never silent', $r_relax->substitutionNote());

	// A STATED floor nothing meets still refuses — someone asked for something
	// this install cannot do, and they need to know.
	$stated_refused = false;
	try {
		AiModelResolver::resolve($req()->withMinTier('frontier')->withTrustFloor('local'));
	} catch (LlmProviderException $e) { $stated_refused = true; }
	check($stated_refused, 'while a stated floor nothing meets still refuses');

	// A pin this install cannot reach is an AVAILABILITY fact, not a mistake:
	// the requirement is still enough to run on, and the run says what happened.
	$r = AiModelResolver::resolve($req()->withMinTier('basic')->withPin('nothing-serves-this'));
	check($r->modelId() === 'local-small', 'an unreachable pin falls back to the requirement',
		$r->modelId());
	check($r->substitutionNote() !== '', 'and the substitution is recorded, never silent',
		$r->substitutionNote());

	// A retired model is resolvable for cost history but never chosen anew.
	$r2 = AiModelResolver::resolve($req()->withMinTier('frontier')->withTrustFloor('any')
		->withPolicy(AiModelResolver::POLICY_BEST));
	check($r2->modelId() !== 'cloud-old', 'a retired model is never selected for new work',
		$r2->modelId());

	// A requirement nothing clears refuses with the gap named, so a
	// misconfigured install is answerable.
	$msg2 = '';
	try {
		AiModelResolver::resolve($req()->withMinTier('frontier')->withTrustFloor('local'));
	} catch (LlmProviderException $e) { $msg2 = $e->getMessage(); }
	check(stripos($msg2, 'frontier') !== false && stripos($msg2, 'hardware') !== false,
		'an unsatisfiable floor refuses with the gap and the fix named', $msg2);
});

// An unparseable catalog stops everything rather than falling through.
$broken_dir = sys_get_temp_dir() . '/joai_broken_' . substr(md5(uniqid('', true)), 0, 8);
@mkdir($broken_dir, 0777, true);
file_put_contents($broken_dir . '/ai_endpoints.json', '{ this is not json');
file_put_contents($broken_dir . '/ai_model_reference.json', '{}');
AiEndpointRegistry::clearCache();
$prev = AiEndpointRegistry::useCatalogFiles(
	$broken_dir . '/ai_endpoints.json', $broken_dir . '/ai_model_reference.json');
$broken_refused = false;
try {
	AiModelResolver::resolve($req());
} catch (LlmProviderException $e) {
	$broken_refused = true;
}
check($broken_refused, 'an unparseable catalog refuses rather than choosing "whatever is available"');
AiEndpointRegistry::useCatalogFiles($prev[0], $prev[1]);
AiEndpointRegistry::clearCache();
@unlink($broken_dir . '/ai_endpoints.json');
@unlink($broken_dir . '/ai_model_reference.json');
@rmdir($broken_dir);

// ===========================================================================
section('G. Availability follows the keys');
// ===========================================================================

harness_set_setting_mem('joinery_ai_anthropic_api_key', '');
check(pick($req()->withVision(true)->withTrustFloor('any'), $FIXTURE, $REFERENCE) === '',
	'clearing an endpoint key removes its models from resolution');
check(pick($req()->withMinTier('capable')->withTrustFloor('any'), $FIXTURE, $REFERENCE) === 'local-mid',
	'without erroring anywhere else');
harness_set_setting_mem('joinery_ai_anthropic_api_key', 'fixture-key');

// ===========================================================================
section('H. One trust class answers the warning and the gate');
// ===========================================================================

with_catalog($FIXTURE, $REFERENCE, function () {
	// Three surfaces used to ask three different questions about a model: the
	// composer asked the provider about its training policy, the Fortress pin
	// re-implemented the routing regex, and the sealed gate asked whether the
	// bytes left the box. Fireworks came out "private" and "cloud" at once. All
	// three now read one value, so the only property worth asserting is that
	// they agree with it.
	$disagreements = [];
	foreach (array_keys(AiEndpointRegistry::catalog()) as $id) {
		$trust = (string)AiEndpointRegistry::trustForModel($id);

		$is_local = ChatLevel::isLocalModel($id);            // the Fortress pin
		$warns    = ($trust === 'cloud');                     // the composer warning
		$sealed_local_refuses = !AiModelRequirement::trustSatisfies(  // the egress gate
			AiModelRequirement::TRUST_LOCAL, $trust);

		if ($is_local !== ($trust === 'local')) {
			$disagreements[] = "$id: Fortress pin disagrees with trust '$trust'";
		}
		if ($warns !== ($trust === 'cloud')) {
			$disagreements[] = "$id: composer warning disagrees with trust '$trust'";
		}
		if ($sealed_local_refuses !== ($trust !== 'local')) {
			$disagreements[] = "$id: sealed gate disagrees with trust '$trust'";
		}
	}
	check(count($disagreements) === 0,
		'the chat warning, the Fortress pin and the sealed gate read one trust value',
		implode('; ', $disagreements));

	// The distinction the old boolean could not express: a trusted vendor is
	// neither warned about nor allowed to read locally-consented sealed mail.
	check(AiModelRequirement::trustSatisfies(AiModelRequirement::TRUST_TRUSTED, 'trusted') === true
		&& AiModelRequirement::trustSatisfies(AiModelRequirement::TRUST_TRUSTED, 'cloud') === false
		&& AiModelRequirement::trustSatisfies(AiModelRequirement::TRUST_LOCAL, 'trusted') === false,
		'and a trusted floor sits genuinely between local and cloud');

	check(ChatLevel::isLocalModel('nothing-serves-this') === false,
		'and a model nothing classifies is NOT treated as local - the safe direction');
});

// ===========================================================================
section('I. The thinking directive');
// ===========================================================================

with_catalog($FIXTURE, $REFERENCE, function () use ($req) {
	// A model that cannot reason is excluded when reasoning is required. The
	// only capable local model in the fixture is the one that cannot, so this
	// refuses — which is the point: the exclusion is a filter, not a preference.
	$msg = '';
	try {
		AiModelResolver::resolve($req()->withMinTier('capable')->withTrustFloor('local')
			->withThinkingRequired(true));
	} catch (LlmProviderException $e) { $msg = $e->getMessage(); }
	check(stripos($msg, 'cannot reason') !== false,
		'a required-thinking floor excludes a model that cannot reason, and says so', $msg);
});

with_catalog($FIXTURE, $REFERENCE, function () use ($req) {
	// ...and NOT excluded when it is merely asked to think hard, because the
	// mismatch is worth stating rather than legislating.
	$r = AiModelResolver::resolve($req()->withMinTier('capable')->withTrustFloor('local')
		->withThinkingLevel('high'));
	check($r->modelId() === 'local-mid', 'a high level does not exclude a non-reasoning model', $r->modelId());
	check($r->thinkingDirective()['enabled'] === false,
		'the directive turns reasoning off for it, whatever the level said');
	check(count(array_filter($r->advisories(), function ($a) { return stripos($a, 'cannot reason') !== false; })) === 1,
		'and the edit page is told the level is ignored', implode(' | ', $r->advisories()));

	// An always-on model treats "off" as its lowest effort rather than being
	// refused over a knob.
	$r2 = AiModelResolver::resolve($req()->withMinTier('frontier')->withTrustFloor('trusted')
		->withThinkingLevel('off'));
	check($r2->modelId() === 'trusted-top', 'an always-reasoning model resolves under an off level', $r2->modelId());
	check($r2->thinkingDirective()['enabled'] === true
		&& $r2->thinkingDirective()['effort'] === 'low',
		'and runs at its lowest effort', json_encode($r2->thinkingDirective()));
});

// ===========================================================================
section('J. Context floors fail closed on an unknown window');
// ===========================================================================

// A host that reports no window at all — a vLLM or LM Studio behind the same
// OpenAI dialect, which the probe cannot ask.
$no_context = $FIXTURE;
foreach ($no_context[0]['models'] as $i => $_m) unset($no_context[0]['models'][$i]['context']);

with_catalog($no_context, $REFERENCE, function () use ($req) {
	$msg = '';
	try {
		AiModelResolver::resolve($req()->withMinTier('basic')->withTrustFloor('local')
			->withMinContext(64000));
	} catch (LlmProviderException $e) { $msg = $e->getMessage(); }
	check(stripos($msg, 'does not report a context window') !== false,
		'a host that will not say how much context it has fails the floor, naming the silence', $msg);
});

// ===========================================================================
section('K. Grading a model the operator serves themselves');
// ===========================================================================

with_catalog($FIXTURE, $REFERENCE, function () {
	check(AiEndpointRegistry::paramsFromTag('qwen3.6:35b-a3b-q4_K_M') === 35.0,
		'a tag with two parameter counts grades on the LARGER one');
	check(AiEndpointRegistry::paramsFromTag('qwen3.5:9b-nvfp4') === 9.0, 'a plain tag parses');
	check(AiEndpointRegistry::paramsFromTag('mystery') === null, 'and an unreadable one parses to nothing');

	check(AiEndpointRegistry::tierFromLadder('something:3b') === 'basic', 'the ladder grades a 3B basic');
	check(AiEndpointRegistry::tierFromLadder('something:14b') === 'capable', 'a 14B capable');
	check(AiEndpointRegistry::tierFromLadder('something:70b') === 'frontier', 'a 70B frontier');
	check(AiEndpointRegistry::tierFromLadder('mystery') === 'basic',
		'and only a tag with nothing readable falls to basic');

	// A named entry wins over the ladder, in both directions.
	check((AiEndpointRegistry::referenceEntryFor('measured:9b-q4')['basis'] ?? '') === 'measured',
		'a named reference entry matches by glob');
	check((AiEndpointRegistry::referenceEntryFor('downgraded:70b-x')['tier'] ?? '') === 'basic',
		'and overrides the ladder even downwards');
});

// ===========================================================================
section('L. Cost comes from the catalog, not from the recipe pin');
// ===========================================================================

with_catalog($FIXTURE, $REFERENCE, function () {
	$usage = ['input_tokens' => 1000000, 'output_tokens' => 1000000];
	check(abs(AiModelResolution::costFor('cloud-mid', $usage) - 6.00) < 0.001,
		'a paid model prices from its own declared rates',
		(string)AiModelResolution::costFor('cloud-mid', $usage));
	check(AiModelResolution::costFor('local-small', $usage) === 0.0,
		'a model with no declared cost is free');
	// The live defect this replaces: an UNPINNED recipe on a paid endpoint used
	// to record $0, because the cost lookup was keyed on the empty pin.
	check(AiModelResolution::costFor('', $usage) === 0.0
		&& AiModelResolution::costFor('cloud-top', $usage) > 0.0,
		'and costing the model that RAN is what stops an unpinned paid run recording nothing');
});

// ===========================================================================
section('M. An invalid requirement value refuses loudly');
// ===========================================================================

// A typo'd floor must never quietly become the permissive default. Ignoring a
// bad value used to demote a stated tier to the unstated fallback (the pin
// veto evaporated) and a bad trust floor to ANY — fail-open on the one field
// where that ships sealed content to a vendor.
$throws = function (callable $fn): bool {
	try { $fn(); return false; } catch (InvalidArgumentException $e) { return true; }
};
check($throws(function () { AiModelRequirement::make()->withMinTier('premium'); }),
	'a tier that is not a rung throws instead of being ignored');
check($throws(function () { AiModelRequirement::make()->withFallbackMinTier('premium'); }),
	'so does a fallback tier');
check($throws(function () { AiModelRequirement::make()->withTrustFloor('onbox'); }),
	'a trust floor that is not a class throws instead of relaxing to ANY');
check($throws(function () { AiModelRequirement::make()->tightenTrustFloor('onbox'); }),
	'tightening with one too');
check($throws(function () { AiModelRequirement::make()->withPolicy('fastest'); }),
	'and an unknown selection policy throws');
check(!$throws(function () { AiModelRequirement::make()->withMinTier('')->withTrustFloor(null); }),
	'while empty means "no opinion" and stays the easy path');

// Every registered pipeline job's declared floors must parse — a job's floor
// is code, and this is where a typo in one fails instead of at 2am on a node.
$bad_jobs = [];
foreach (array_keys(PipelineJobRegistry::all()) as $job_id) {
	try {
		$job = PipelineJobRegistry::get($job_id);
		AiModelRequirement::make()
			->withMinTier((string)$job->minTier())
			->withTrustFloor((string)$job->defaultTrustFloor());
	} catch (Throwable $e) {
		$bad_jobs[] = "$job_id: " . $e->getMessage();
	}
}
check(count($bad_jobs) === 0, 'every registered job declares parseable floors',
	implode('; ', $bad_jobs));

// ===========================================================================
section('N. A pin degrades sideways or up, never down');
// ===========================================================================

with_catalog($FIXTURE, $REFERENCE, function () use ($req) {
	// Nobody stated a floor, so the pin's own tier is the accepted level: a
	// pinned capable model must not fall back to a basic sibling.
	$r = AiModelResolver::resolve($req()->withFallbackMinTier('frontier')->withPin('local-mid'));
	check($r->modelId() === 'local-mid', 'an unstated floor never vetoes the pin', $r->modelId());
	$below = array_filter($r->candidates(), function ($c) {
		return AiModelRequirement::tierRank($c['tier']) < AiModelRequirement::tierRank('capable');
	});
	check(count($below) === 0,
		'and no fallback candidate sits below the pinned model\'s own tier',
		implode(',', array_column($r->candidates(), 'id')));

	// A pinned basic model may degrade UP to a free capable sibling.
	$r2 = AiModelResolver::resolve($req()->withFallbackMinTier('frontier')->withPin('local-small'));
	check(in_array('local-mid', array_column($r2->candidates(), 'id'), true),
		'a pinned basic model holds its free capable sibling as a fallback',
		implode(',', array_column($r2->candidates(), 'id')));

	// A STATED floor is the contract for candidates, exactly as when unpinned.
	$r3 = AiModelResolver::resolve($req()->withMinTier('capable')->withPin('trusted-mid'));
	$under = array_filter($r3->candidates(), function ($c) {
		return AiModelRequirement::tierRank($c['tier']) < AiModelRequirement::tierRank('capable');
	});
	check(count($under) === 0, 'under a stated floor every candidate clears it',
		implode(',', array_column($r3->candidates(), 'id')));
});

harness_finish();
