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
	// The codes are made in the browser, which shows them; only the account
	// half of each code's KEK arrives (specs/one_vault_experience.md § R6).
	// A passkey that can hold the key means no passphrase (R8). The root vault
	// (`root`) is the browser's, made beside this one with the same codes and
	// the same tap's second output.
	try {
		VaultCeremonies::assertNoSecondPrfOutput($credential);
		$code_set = VaultCeremonies::codeSet($input['code_set'] ?? null);
		$root = is_array($input['root'] ?? null) ? $input['root'] : array();
	} catch (VaultCeremonyException $e) {
		return LogicResult::error($e->getMessage());
	}

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
		$result = $ceremonies->setup($user, (int)$passkey->key, $passkey->get('pkc_label'), $prf_output, '', $code_set, $root);
	} catch (VaultCeremonyException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render([
		'vault_id'       => (int)$result['vault']->key,
		'key_file'       => $result['key_file'],
	]);
}

function vault_setup_verify_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Complete Sealed Vault setup: generate the keypair, wrap it under the enrolling passkey and a browser-made recovery code set (account halves of each code KEK), and open the unlock window',
		'input' => [
			'acknowledged' => ['type' => 'bool', 'required' => true, 'label' => 'Acknowledge the consequences'],
			'credential' => ['type' => 'object', 'required' => true, 'label' => 'WebAuthn credential response'],
			'code_set' => ['type' => 'object', 'required' => true, 'label' => 'Browser-made code set {id, entries:[{index, kek}]} (account halves only)'],
			'root' => ['type' => 'object', 'required' => true, 'label' => 'The root vault the browser made: {public_key, salt, kdf_params, wrappings}'],
		],
	];
}
?>
