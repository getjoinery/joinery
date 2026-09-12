<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function passkey_register_verify_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('includes/VaultCeremonies.php'));

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
	// credential can be activated without the separate ceremony — when the
	// request also presents a fresh unlocker (specs/unseal_daemon.md B1).
	// Best-effort: the same guards as vault_add_passkey_verify, and any refusal
	// leaves an ordinary not-yet-activated passkey behind — never a failed
	// enrollment. A refusal's reason rides back for the page to show.
	$vault_activated = false;
	$vault_activation_error = null;
	if ($prf_output_b64url !== null && isset($input['unlocker'])) {
		[$vault_activated, $vault_activation_error] = _passkey_register_try_vault_activation(
			$user, $passkey, $prf_output_b64url, $input['unlocker']);
	}

	return LogicResult::render([
		'passkey' => $passkey->export_for_api(),
		'vault_activated' => $vault_activated,
		'vault_activation_error' => $vault_activation_error,
		'became_second_factor' => !$had_second_factor && $session->user_has_second_factor($user),
	]);
}

/**
 * Wrap the vault's secret under the creation-time PRF output, in an open under
 * the presented unlocker. Mirrors vault_add_passkey_verify's guards (no
 * duplicate wrapping, one live key generation); returns [activated, reason].
 * FALSE is a normal outcome, not an error - the passkey stays enrolled and the
 * Activate action remains available.
 */
function _passkey_register_try_vault_activation($user, $passkey, string $prf_output_b64url, $unlocker_input): array {
	try {
		$vault = UserEncryptionVault::loadForUser((int)$user->key);
		if (!$vault) {
			return [false, null];
		}
		$existing = new MultiUserEncryptionWrapping([
			'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSKEY, 'credential_id' => $passkey->key,
		]);
		if ($existing->count_all() > 0) {
			return [true, null];
		}
		if (count(UserEncryptionWrapping::liveGenerations((int)$vault->key)) > 1) {
			return [false, 'Your vault has an unfinished key rotation. Run the rotation again to complete it, then activate this passkey.'];
		}
		$prf_output = ParagonIE\ConstantTime\Base64UrlSafe::decodeNoPadding($prf_output_b64url);
		$wrapping = UserEncryptionWrapping::reserve(
			$vault->key, UserEncryptionWrapping::TYPE_PASSKEY,
			$passkey->key, $passkey->get('pkc_label'), (int)$vault->get('uev_key_generation')
		);
		try {
			$opened = (new VaultCeremonies())->openWithUnlocker($user, $vault, $unlocker_input,
				[$wrapping->wrapEntry($prf_output)]);
		} catch (VaultCeremonyException $e) {
			$wrapping->soft_delete();
			return [false, $e->getMessage()];
		}
		$wrapping->storeWrapped($opened['wrappings'][0]);
		return [true, null];
	} catch (\Throwable $e) {
		error_log('passkey_register: creation-time vault activation failed for user '
			. $user->key . ' credential ' . $passkey->key . ': ' . $e->getMessage());
		return [false, null];
	}
}

function passkey_register_verify_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Complete passkey enrollment and persist the new credential; with unlocker ({credential} from vault_unlock_options, {passphrase} or {code}) also activates it for the vault',
	];
}
?>
