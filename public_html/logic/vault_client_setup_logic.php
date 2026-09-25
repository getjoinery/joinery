<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * First-time setup of a client-custody vault scope. The browser generated the
 * keypair, derived every KEK, and wrapped the secret key under each unlocker;
 * this action just persists the public key + opaque wrappings in one
 * transaction (a vault must never exist with zero unlockers). Nothing here
 * touches a KEK, the secret key, or any plaintext.
 */
function vault_client_setup_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user_id = (int)$session->get_user_id();

	$scope = isset($input['scope']) ? (string)$input['scope'] : '';
	try {
		VaultClientCustody::assertClientScope($scope);
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	}

	if (empty($input['acknowledged'])) {
		return LogicResult::error(
			'You must acknowledge that losing every unlocker (passkey, recovery key, and passphrase) permanently loses everything in your vault - there is no support-desk recovery.'
		);
	}

	// The floor at birth and the root's pairing with the account vault's codes
	// live in createVault() (specs/one_vault_experience.md § R2, R4, R6).
	try {
		$vault = VaultClientCustody::createVault($user_id, $scope, $input);
	} catch (VaultClientCustodyException $e) {
		return LogicResult::error($e->getMessage());
	} catch (Throwable $e) {
		error_log('Client vault setup: could not persist for user ' . $user_id . ' scope ' . $scope . ': ' . $e->getMessage());
		return LogicResult::error('Could not create your vault - nothing was saved. Try again.');
	}

	// A vault needs a second factor on the account. One set up by passphrase
	// alone leaves a factorless account holding a vault: the re-enrollment gate
	// takes it from the next page (the setup ceremony said so beforehand).
	$session->forget_vault_posture();

	return LogicResult::render(['set_up' => true, 'scope' => $scope, 'vault_id' => (int)$vault->key]);
}

// @version 1.1 - the floor and the root's code pairing move to VaultClientCustody::createVault()
function vault_client_setup_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Persist a new client-custody vault (public key + browser-produced opaque wrapping blobs) for a scope',
		'input' => [
			'scope' => ['type' => 'string', 'required' => true, 'label' => 'Client-custody scope'],
			'acknowledged' => ['type' => 'bool', 'required' => true, 'label' => 'Acknowledge the consequences'],
			'public_key' => ['type' => 'string', 'required' => true, 'label' => 'Vault public key'],
			'salt' => ['type' => 'string', 'required' => false, 'label' => 'KDF salt'],
			'wrappings' => ['type' => 'array', 'required' => false, 'items' => ['type' => 'object'], 'label' => 'Browser-produced wrapping blobs'],
			'kdf_params' => ['type' => 'object', 'required' => false, 'label' => 'KDF parameters'],
		],
	];
}
?>
