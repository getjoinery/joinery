<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function passkey_register_verify_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);

	// Enrolling a passkey is a sensitive action (specs/mailbox_security_levels.md
	// § 5.5): the account's second factor must have been re-confirmed recently.
	// A no-op for a first passkey (no factor yet — the first-passkey ceremony
	// gates on the account password instead). API surface, so it returns a flag
	// the client uses to run the step-up ceremony and retry, not a redirect.
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	$user = new User($session->get_user_id(), TRUE);
	if ($session->user_has_second_factor($user) && !$session->has_recent_second_factor()) {
		return LogicResult::render(['second_factor_required' => true,
			'error' => 'Confirm your identity with your second factor, then try again.']);
	}

	$credential = $input['credential'] ?? null;
	if (!is_array($credential)) {
		return LogicResult::error('Missing passkey credential response.');
	}
	$label = isset($input['label']) ? trim($input['label']) : '';

	// Enrolling a first factor silently changes sign-in behavior (the account
	// starts being asked for it), so the moment the predicate flips is reported
	// for the page to say so (specs/second_factor_ux_coherence.md Change 3).
	$had_second_factor = $session->user_has_second_factor($user);

	try {
		$service = new PasskeyService();
		$passkey = $service->verifyRegistration(json_encode($credential), $label);
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render([
		'passkey' => $passkey->export_for_api(),
		'became_second_factor' => !$had_second_factor && $session->user_has_second_factor($user),
	]);
}

function passkey_register_verify_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Complete passkey enrollment and persist the new credential',
	];
}
?>
