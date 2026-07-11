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

	$public_key = isset($input['public_key']) ? (string)$input['public_key'] : '';
	$salt       = isset($input['salt']) ? (string)$input['salt'] : '';
	$wrappings  = isset($input['wrappings']) && is_array($input['wrappings']) ? $input['wrappings'] : [];
	if ($public_key === '' || $salt === '') {
		return LogicResult::error('Missing vault key material.');
	}

	// Structural unlocker floor at birth: at least one everyday unlocker
	// (passkey or passphrase) so the vault is openable, and at least one
	// recovery wrapping so it is recoverable if the everyday unlocker is lost.
	$primary = 0; $recovery = 0;
	foreach ($wrappings as $w) {
		$t = isset($w['unlocker_type']) ? $w['unlocker_type'] : '';
		if ($t === UserEncryptionWrapping::TYPE_PASSKEY || $t === UserEncryptionWrapping::TYPE_PASSPHRASE) $primary++;
		if ($t === UserEncryptionWrapping::TYPE_RECOVERY) $recovery++;
	}
	if ($primary < 1) {
		return LogicResult::error('Your vault needs a passkey or a passphrase to unlock it.');
	}
	if ($recovery < 1) {
		return LogicResult::error('Your vault needs at least one recovery key.');
	}

	if (VaultClientCustody::loadVault($user_id, $scope)) {
		return LogicResult::error('Your vault is already set up.');
	}

	$db = DbConnector::get_instance()->get_db_link();
	try {
		$db->beginTransaction();

		$vault = new UserEncryptionVault(NULL);
		$vault->set('uev_usr_user_id', $user_id);
		$vault->set('uev_scope', $scope);
		$vault->set('uev_custody', UserEncryptionVault::CUSTODY_CLIENT);
		$vault->set('uev_public_key', $public_key);
		$vault->set('uev_salt', $salt);
		$vault->set('uev_kdf_params', VaultClientCustody::encodeKdfParams($input['kdf_params'] ?? null));
		$vault->set('uev_key_generation', 1);
		$vault->save();

		VaultClientCustody::persistWrappings($user_id, $vault, $wrappings);

		$db->commit();
	} catch (VaultClientCustodyException $e) {
		if ($db->inTransaction()) $db->rollBack();
		return LogicResult::error($e->getMessage());
	} catch (Throwable $e) {
		if ($db->inTransaction()) $db->rollBack();
		error_log('Client vault setup: could not persist for user ' . $user_id . ' scope ' . $scope . ': ' . $e->getMessage());
		return LogicResult::error('Could not create your vault - nothing was saved. Try again.');
	}

	return LogicResult::render(['set_up' => true, 'scope' => $scope, 'vault_id' => (int)$vault->key]);
}

function vault_client_setup_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Persist a new client-custody vault (public key + browser-produced opaque wrapping blobs) for a scope',
	];
}
?>
