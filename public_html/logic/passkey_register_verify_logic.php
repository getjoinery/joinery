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
		[$passkey, $prf_output_b64url] = $service->verifyRegistration(json_encode($credential), $label);
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}

	// The authenticator evaluated the vault context during creation, so the
	// credential can be activated without the separate ceremony. Best-effort:
	// the same guards as vault_add_passkey_verify, and any refusal leaves an
	// ordinary not-yet-activated passkey behind — never a failed enrollment.
	$vault_activated = false;
	if ($prf_output_b64url !== null) {
		$vault_activated = _passkey_register_try_vault_activation($user, $passkey, $prf_output_b64url);
	}

	return LogicResult::render([
		'passkey' => $passkey->export_for_api(),
		'vault_activated' => $vault_activated,
		'became_second_factor' => !$had_second_factor && $session->user_has_second_factor($user),
	]);
}

/**
 * Wrap the open vault's secret under the creation-time PRF output. Mirrors
 * vault_add_passkey_verify's guards (open window, no duplicate wrapping, one
 * live key generation); returns whether a wrapping was created. FALSE is a
 * normal outcome, not an error - the passkey stays enrolled and the Activate
 * action remains available.
 */
function _passkey_register_try_vault_activation($user, $passkey, string $prf_output_b64url): bool {
	try {
		$vault = UserEncryptionVault::loadForUser((int)$user->key);
		if (!$vault) {
			return false;
		}
		$secret_key = VaultUnlock::secretKey((int)$user->key, UserEncryptionVault::SCOPE_USER);
		if ($secret_key === null) {
			return false;
		}
		$existing = new MultiUserEncryptionWrapping([
			'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSKEY, 'credential_id' => $passkey->key,
		]);
		if ($existing->count_all() > 0) {
			return true;
		}
		if (count(UserEncryptionWrapping::liveGenerations((int)$vault->key)) > 1) {
			return false;
		}
		$prf_output = ParagonIE\ConstantTime\Base64UrlSafe::decodeNoPadding($prf_output_b64url);
		UserEncryptionWrapping::createWrapped(
			$vault->key, UserEncryptionWrapping::TYPE_PASSKEY, $secret_key, $prf_output,
			$passkey->key, $passkey->get('pkc_label'), (int)$vault->get('uev_key_generation')
		);
		return true;
	} catch (\Throwable $e) {
		error_log('passkey_register: creation-time vault activation failed for user '
			. $user->key . ' credential ' . $passkey->key . ': ' . $e->getMessage());
		return false;
	}
}

function passkey_register_verify_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Complete passkey enrollment and persist the new credential',
	];
}
?>
