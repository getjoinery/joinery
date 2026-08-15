<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_setup_verify_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('includes/VaultCeremonies.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	if (!$user->get('usr_password')) {
		return LogicResult::error(
			'Set an account password before enabling your vault - a vault holder always keeps password sign-in as a second factor.',
			['requires_password' => true]
		);
	}

	if (empty($input['acknowledged'])) {
		return LogicResult::error(
			'You must acknowledge that losing every unlocker (passkey and recovery codes) permanently loses everything sealed in your vault - there is no support-desk recovery.'
		);
	}

	$credential = $input['credential'] ?? null;
	if (!is_array($credential)) {
		return LogicResult::error('Missing passkey credential response.');
	}
	$passphrase = isset($input['passphrase']) ? (string)$input['passphrase'] : '';
	$code_count = isset($input['recovery_code_count']) ? (int)$input['recovery_code_count'] : 10;

	try {
		$service = new PasskeyService();
		[$derived_user, $passkey, $prf_output] = $service->verifyDerivation(json_encode($credential), 'vault-kek');
	} catch (PasskeyPrfUnsupportedException $e) {
		// The hardware-limit refusal, as a flag the client can branch on —
		// the wizard's fallback routing must not sniff the message text.
		return LogicResult::error($e->getMessage(), ['prf_unsupported' => true]);
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}
	if ((int)$derived_user->key !== (int)$user->key) {
		return LogicResult::error('This passkey does not belong to your account.');
	}

	try {
		$ceremonies = new VaultCeremonies();
		$result = $ceremonies->setup($user, (int)$passkey->key, $passkey->get('pkc_label'), $prf_output, $passphrase, $code_count);
	} catch (VaultCeremonyException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render([
		'vault_id'       => (int)$result['vault']->key,
		'recovery_codes' => $result['recovery_codes'],
		'key_file'       => $result['key_file'],
	]);
}

function vault_setup_verify_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Complete Sealed Vault setup: generate the keypair, wrap it under the enrolling passkey/recovery codes/optional passphrase, and open the unlock window',
	];
}
?>
