<?php

/**
 * drive_vault_status — the three facts a native sync client needs about the
 * caller's encrypted-folder vault, and nothing else.
 *
 * The full keyring view (vault_client_status) is deliberately browser-session
 * only: wrappings, salts, and KDF parameters are the material an unlock is
 * performed from, and unlocking belongs in the browser, where WebAuthn works
 * and where the user is present. A sync client never unlocks — it received its
 * vault key once, sealed to the device, during the device-link ceremony. What
 * it still needs to know is whether a drive vault exists at all, its public key
 * (to seal file keys for files it uploads), and the key generation (so it can
 * notice a rotation and stop trusting the key it holds).
 *
 * So this returns exactly those three, and is reachable with a session key.
 */

function drive_vault_status_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));

	$session = SessionControl::get_instance();
	$user_id = (int)$session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('You must be signed in.');
	}

	$scope = isset($input['scope']) && $input['scope'] !== '' ? (string)$input['scope'] : 'drive';
	if ($scope !== 'drive') {
		// Only the Drive vault is a sync client's business. The passwords scope
		// has no native consumer and is not widened by this action.
		return LogicResult::error('Unknown scope.');
	}

	try {
		$vault = VaultClientCustody::loadVault($user_id, $scope);
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	}

	if (!$vault) {
		return LogicResult::render(array(
			'ok'             => true,
			'scope'          => $scope,
			'set_up'         => false,
			'public_key'     => null,
			'key_generation' => 0,
		));
	}

	return LogicResult::render(array(
		'ok'             => true,
		'scope'          => $scope,
		'set_up'         => true,
		'public_key'     => $vault->get('uev_public_key'),
		'key_generation' => (int)$vault->get('uev_key_generation'),
	));
}

function drive_vault_status_logic_descriptor(): array {
	return array(
		'description'      => 'Whether the caller has an encrypted-folder (drive) vault, its public key, and its key generation — the lean probe native sync clients use. Carries no wrappings, salts, or KDF parameters: those are unlock material and stay on the browser-only vault_client_status action.',
		'requires_session' => true,
		'requires_setting' => 'drive_active',
		'mutates'          => false,
		'auth'             => array('capability' => 'read'),
		'input'            => array(
			'scope' => array('type' => 'string', 'required' => false, 'enum' => array('drive'), 'label' => 'Vault scope (drive)'),
		),
	);
}
?>
