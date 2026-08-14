<?php
/**
 * joinery_ai/test_connection — prove the configured AI provider answers.
 *
 * Builds the provider from the SAVED settings (no parameters, so the API key
 * only ever travels the normal settings-save path), runs the reachability
 * probe (a real check only for the local provider — cloud providers rely on
 * the live call), then a one-token createMessage() on the default model.
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

	try {
		$provider = LlmProviderFactory::build();
	} catch (Throwable $e) {
		return LogicResult::error($e->getMessage());
	}

	$probe = $provider->reachabilityProbe();
	if ($probe !== null) {
		return LogicResult::error($probe);
	}

	$model = $provider->defaultModel();
	$started = microtime(true);
	try {
		$provider->createMessage(array(
			'model'      => $model,
			'max_tokens' => 1,
			'messages'   => array(array('role' => 'user', 'content' => 'ping')),
		));
	} catch (Throwable $e) {
		return LogicResult::error('The provider did not answer: ' . $e->getMessage());
	}

	return LogicResult::render(array(
		'ok'    => true,
		'model' => $model,
		'ms'    => (int)round((microtime(true) - $started) * 1000),
	));
}

function test_connection_logic_descriptor(): array {
	return [
		'description' => 'Test that the configured AI provider is reachable and answers a one-token message. Owner only.',
		'mutates'     => false,
		'requires_session' => true,
		'auth'        => [
			'requires_browser_session' => true,
		],
		'input'       => [],
	];
}
