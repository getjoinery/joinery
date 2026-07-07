<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function passkey_register_options_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	$service = new PasskeyService();

	// A session thief must not be able to add a persistent passkey quietly -
	// enrollment always demands proof beyond the session cookie. With at
	// least one credential enrolled, that proof is a fresh step-up assertion;
	// for the very first passkey (nothing to step up with yet) it is the
	// account password. Accounts with no password at all (e.g. OAuth-only)
	// have only the session as an anchor for the bootstrap enrollment.
	if (count($service->listCredentials($user)) > 0) {
		if (!$service->hasRecentStepUp()) {
			return LogicResult::error('Please re-confirm with an existing passkey before adding a new one.');
		}
	} elseif ($user->get('usr_password')) {
		$current_password = isset($input['current_password']) ? (string)$input['current_password'] : '';
		if ($current_password === '' || !$user->check_password($current_password)) {
			return LogicResult::error('Please confirm your current password to add your first passkey.');
		}
	}

	try {
		$options = $service->getRegistrationOptions($user, !empty($input['prf_capable_requested']));
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['options' => $options]);
}

function passkey_register_options_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Begin passkey enrollment (returns WebAuthn creation options); requires a recent step-up',
	];
}
?>
