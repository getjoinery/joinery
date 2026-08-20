<?php
/**
 * joinery_ai/test_connection — prove the configured AI provider answers.
 *
 * Resolves from the SAVED settings (no parameters, so the API key only ever
 * travels the normal settings-save path), runs the reachability probe (a real
 * check only for the local provider — cloud providers rely on the live call),
 * then a one-token message through the resolution's own dispatch door.
 * Returns the model id and round-trip time, or the transport/auth error.
 * (specs/setup_wizard.md § New backend work 4.)
 *
 * @version 1.0
 */

function test_connection_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	$session = SessionControl::get_instance();
	if ((int)$session->get_permission() < 10) {
		return LogicResult::error('Only the site owner can test the AI provider.');
	}

	require_once(PathHelper::getIncludePath('plugins/joinery_ai/includes/llm/LlmProviderFactory.php'));

	// Test whatever the platform would actually reach for right now: the
	// cheapest model that clears the weakest floor, under the site's own
	// selection policy. There is no "active provider" to test any more — the
	// endpoint a piece of work runs on is a consequence of what that work needs.
	try {
		$resolution = AiModelResolver::resolve(
			AiModelRequirementBuilder::forPurpose('the connection test'));
	} catch (Throwable $e) {
		return LogicResult::error($e->getMessage());
	}

	$probe = $resolution->provider()->reachabilityProbe();
	if ($probe !== null) {
		return LogicResult::error($probe);
	}

	$started = microtime(true);
	try {
		// Through the resolution's one dispatch door, not the raw provider —
		// so the test exercises the same path a run uses, including failover
		// to an approved sibling when the first choice will not load.
		$resolution->send(array(
			'max_tokens' => 1,
			'messages'   => array(array('role' => 'user', 'content' => 'ping')),
		), static function (string $d): void {});
	} catch (Throwable $e) {
		return LogicResult::error('The provider did not answer: ' . $e->getMessage());
	}

	return LogicResult::render(array(
		'ok'       => true,
		'model'    => $resolution->servedBy(),
		'label'    => $resolution->summary(),
		'endpoint' => $resolution->endpointKey(),
		'ms'       => (int)round((microtime(true) - $started) * 1000),
	));
}

function test_connection_logic_descriptor(): array {
	return [
		'description' => 'Test that the AI endpoint this install would reach for is up and answers a one-token message. Owner only.',
		'mutates'     => false,
		'requires_session' => true,
		'auth'        => [
			'requires_browser_session' => true,
		],
		'input'       => [],
	];
}
