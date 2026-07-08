<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function passkey_revoke_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	// Revoking a passkey is a sensitive action (specs/mailbox_security_levels.md
	// § 5.5): re-confirm the second factor first. Returns a flag the client uses
	// to run the step-up ceremony and retry (API surface — no redirect).
	if ($session->user_has_second_factor($user) && !$session->has_recent_second_factor()) {
		return LogicResult::render(['second_factor_required' => true,
			'error' => 'Confirm your identity with your second factor, then try again.']);
	}

	$credential_id = (int)($input['credential_id'] ?? 0);

	// The vault's unlocker floor vetoes a revocation that would strand a
	// vault (< 1 remaining passkey wrapping AND < 3 unused recovery codes);
	// on success, the vault also cleans up the now-dead wrapping tied to
	// this credential.
	VaultUnlock::registerRevocationHooks();

	try {
		$service = new PasskeyService();
		$service->revoke($credential_id, $user);
	} catch (Exception $e) {
		// Covers both a not-found/not-owned passkey and a veto from a consumer's
		// unlocker floor (PasskeyRevocationVetoException) - either way the
		// message is safe to surface verbatim.
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(['revoked' => true]);
}

function passkey_revoke_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Revoke an enrolled passkey (may be refused if a consumer\'s unlocker floor requires it)',
	];
}
?>
