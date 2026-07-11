<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Mint WebAuthn PRF assertion options for a client-custody scope. The browser
 * runs the assertion and reads the PRF output LOCALLY as its KEK - it never
 * posts the output back (that would hand the server the key and defeat
 * zero-knowledge). There is no matching verify action for this reason; the
 * server provides only the challenge and the scope's PRF salt.
 */
function vault_client_prf_options_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	$scope = isset($input['scope']) ? (string)$input['scope'] : '';
	try {
		$context = VaultClientCustody::contextForScope($scope);
		$service = new PasskeyService();
		$options = $service->getDerivationOptions($user, $context);
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['options' => $options]);
}

function vault_client_prf_options_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'WebAuthn PRF assertion options for a client-custody scope (browser derives the KEK locally; the output is never sent back)',
	];
}
?>
