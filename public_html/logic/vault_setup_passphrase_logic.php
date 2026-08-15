<?php
/**
 * Bypass-phrase Sealed Vault setup — the compatibility fallback for an account
 * whose every enrolled passkey has failed a real derivation (docs/sealed_vault.md
 * § When a passkey cannot hold the key).
 *
 * This is deliberately NOT a choice. The eligibility question is asked of the
 * credentials, not the user, and asked again inside VaultCeremonies::setup()
 * so that hiding the button is never the only thing standing between an
 * account with a working passkey and a weaker unlocker.
 *
 * @version 1.1
 */

function vault_setup_passphrase_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/RequestLogger.php'));
	require_once(PathHelper::getIncludePath('includes/VaultCeremonies.php'));
	require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/passkeys_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	if (!RequestLogger::check_rate_limit('vault_setup_passphrase', 5, 900, false)) {
		return LogicResult::error('Too many attempts. Please wait a few minutes and try again.');
	}

	if (!$user->get('usr_password')) {
		return LogicResult::error(
			'Set an account password before enabling your vault - a vault holder always keeps password sign-in as a second factor.',
			['requires_password' => true]
		);
	}

	// Minting a vault under a memorized phrase is the one setup route with no
	// possession proof of its own (the passkey route's is the UV assertion),
	// so it demands the account's second factor: a session rider must not be
	// able to mint the vault and walk away holding its recovery codes. The
	// fallback cohort can always satisfy this — a PRF-incapable passkey still
	// steps up fine. As an API action this can't redirect, so it answers with
	// the flag and the client runs the ceremony first, then retries.
	if ($session->user_has_second_factor($user) && !$session->has_recent_second_factor()) {
		return LogicResult::render([
			'second_factor_required' => true,
			'error' => 'Confirm your identity with your second factor, then try again.',
		]);
	}

	if (empty($input['acknowledged'])) {
		return LogicResult::error(
			'You must acknowledge that losing your bypass phrase and recovery codes permanently loses everything sealed in your vault - there is no support-desk recovery.'
		);
	}

	// The gate. An account that has never tried a passkey, or holds one that
	// might still work, is sent back to the passkey route rather than handed
	// the weaker unlocker.
	if (!Passkey::userNeedsPassphraseFallback((int)$user->key)) {
		return LogicResult::error(
			'Your passkey can hold your encryption key, so it should - a bypass phrase alone is only for devices that cannot derive one.',
			['passkey_route_available' => true]
		);
	}

	$passphrase = (string)($input['passphrase'] ?? '');
	if (strlen($passphrase) < SealedBox::PASSPHRASE_MIN_CHARS) {
		return LogicResult::error('Your bypass phrase must be at least ' . SealedBox::PASSPHRASE_MIN_CHARS . ' characters.');
	}
	if ($passphrase !== (string)($input['passphrase_confirm'] ?? $passphrase)) {
		return LogicResult::error('Those two phrases do not match.');
	}

	$code_count = isset($input['recovery_code_count']) ? (int)$input['recovery_code_count'] : 10;

	try {
		$ceremonies = new VaultCeremonies();
		// Credential id 0 = no passkey wrapping; the ceremony re-checks
		// eligibility itself before honouring that.
		$result = $ceremonies->setup($user, 0, null, '', $passphrase, $code_count);
	} catch (VaultCeremonyException $e) {
		RequestLogger::log('vault_setup_passphrase', 'setup', false, ['user_id' => $user->key]);
		return LogicResult::error($e->getMessage());
	}
	RequestLogger::log('vault_setup_passphrase', 'setup', true, ['user_id' => $user->key]);

	return LogicResult::render([
		'vault_id'       => (int)$result['vault']->key,
		'recovery_codes' => $result['recovery_codes'],
		'key_file'       => $result['key_file'],
	]);
}

function vault_setup_passphrase_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Set up a Sealed Vault unlocked by a bypass phrase, for an account whose passkeys cannot derive a key',
		'input' => array(
			'passphrase'         => array('type' => 'string', 'required' => true,  'label' => 'Bypass phrase'),
			'passphrase_confirm' => array('type' => 'string', 'required' => false, 'label' => 'Confirm bypass phrase'),
			'acknowledged'       => array('type' => 'bool',   'required' => true,  'label' => 'Permanent-loss acknowledgement'),
		),
	];
}
?>
