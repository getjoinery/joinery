<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_passphrase_remove_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
	require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('passkeys_enabled')) {
		return LogicResult::error('Passkeys are not enabled.');
	}

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$user = new User($session->get_user_id(), TRUE);

	$vault = UserEncryptionVault::loadForUser($user->key);
	if (!$vault) {
		return LogicResult::error('Set up your vault first.');
	}

	if ($session->step_up_outstanding($user)) {
		return LogicResult::error('Please re-confirm with an existing passkey before removing your bypass phrase.');
	}

	$existing = new MultiUserEncryptionWrapping(['vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSPHRASE]);
	$existing->load();
	if ($existing->count() === 0) {
		return LogicResult::error('No bypass phrase is enrolled.');
	}

	// A passphrase never counts toward the floor itself, but it can be the
	// vault's ONLY working unlocker if every passkey has since been revoked
	// and every recovery code consumed - the same floor that gates a passkey
	// revocation applies here too.
	try {
		VaultUnlock::assertWrappingDeleteSafe((int)$vault->key);
	} catch (RuntimeException $e) {
		return LogicResult::error(
			'Removing your bypass phrase would lock you out of your encrypted vault - add a '
			. 'vault-enrolled passkey, or make sure you have at least 3 unused recovery codes, '
			. 'before removing it.'
		);
	}

	// The root vault's half of the phrase goes with it (§ R7): one phrase.
	$db = DbConnector::get_instance()->get_db_link();
	$db->beginTransaction();
	try {
		foreach ($existing as $wrapping) {
			$wrapping->soft_delete();
		}
		VaultClientCustody::replaceRootPassphrase((int)$user->key, array());
		$db->commit();
	} catch (Throwable $e) {
		if ($db->inTransaction()) $db->rollBack();
		error_log('Passphrase removal failed for vault ' . (int)$vault->key . ': ' . $e->getMessage());
		return LogicResult::error($e instanceof VaultClientCustodyException ? $e->getMessage()
			: 'Could not remove your passphrase - nothing was changed. Try again.');
	}

	return LogicResult::render(['removed' => true]);
}

// @version 1.1 - removes the root vault's half of the phrase too
function vault_passphrase_remove_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Remove the vault passphrase (and the root vault\'s half of it); requires a recent step-up',
	];
}
?>
