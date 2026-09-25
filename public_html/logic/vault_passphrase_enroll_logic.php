<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_passphrase_enroll_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));
	require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('includes/VaultCeremonies.php'));
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

	// A passphrase is only for an account whose passkeys cannot hold a key
	// (specs/one_vault_experience.md § R8); recovery codes cover a lost device.
	if (!VaultClientCustody::passphraseAllowed((int)$user->key)) {
		return LogicResult::error('Your passkey can hold your key, so a passphrase is not offered. '
			. 'Your recovery codes are what open your vault if you lose your passkey.');
	}

	if ($session->step_up_outstanding($user)) {
		return LogicResult::error('Please re-confirm with an existing passkey before changing your passphrase.');
	}

	// The browser ran the slow derivation; only the account half of the
	// phrase's KEK arrives (§ R7).
	try {
		$kek = VaultCeremonies::decodeKek($input['passphrase_kek'] ?? '');
	} catch (VaultCeremonyException $e) {
		return LogicResult::error($e->getMessage());
	}
	if ($kek === '') {
		return LogicResult::error('Enter your new passphrase.');
	}

	// A wrapping must be tagged with a single truthful generation, and in a
	// partially-rotated vault the in-window secret's generation is ambiguous.
	if (count(UserEncryptionWrapping::liveGenerations((int)$vault->key)) > 1) {
		return LogicResult::error('Your vault has an unfinished key rotation. Run the rotation again to complete it, then change your passphrase.');
	}

	// The phrase's wrapping is produced only under a fresh tap of an unlocker
	// the vault already has, in this same request (specs/unseal_daemon.md B1).
	// The old phrase stays live until the new one is stored, so it can itself
	// be the unlocker that replaces it.
	$existing = new MultiUserEncryptionWrapping(['vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSPHRASE]);
	$existing->load();
	$old_rows = [];
	foreach ($existing as $wrapping) {
		$old_rows[] = $wrapping;
	}

	// The root vault's half of the same phrase, replaced in the same
	// transaction: one phrase opens everything (§ R7), so both change or
	// neither does.
	$root_passphrase = isset($input['root_passphrase']) && is_array($input['root_passphrase']) ? $input['root_passphrase'] : null;
	if ($root_passphrase === null) {
		return LogicResult::error('Unlock your vault on this page first, so your new passphrase opens all of it.', ['root_required' => true]);
	}

	$db = DbConnector::get_instance()->get_db_link();
	$db->beginTransaction();
	try {
		$wrapping = UserEncryptionWrapping::reserve($vault->key, UserEncryptionWrapping::TYPE_PASSPHRASE, null, null, (int)$vault->get('uev_key_generation'));
		$opened = (new VaultCeremonies())->openWithUnlocker($user, $vault, $input['unlocker'] ?? null,
			[$wrapping->wrapEntry($kek)]);
		$wrapping->storeWrapped($opened['wrappings'][0]);
		foreach ($old_rows as $old) {
			$old->soft_delete();
		}
		VaultClientCustody::replaceRootPassphrase((int)$user->key, array($root_passphrase));
		$db->commit();
	} catch (VaultCeremonyException $e) {
		if ($db->inTransaction()) $db->rollBack();
		return LogicResult::error($e->getMessage(), ['unlocker_required' => true]);
	} catch (VaultClientCustodyException $e) {
		if ($db->inTransaction()) $db->rollBack();
		return LogicResult::error($e->getMessage());
	} catch (Throwable $e) {
		if ($db->inTransaction()) $db->rollBack();
		error_log('Passphrase enrol: could not store the phrase for vault ' . (int)$vault->key . ': ' . $e->getMessage());
		return LogicResult::error('Could not change your passphrase - nothing was changed. Try again.');
	}

	return LogicResult::render(['enrolled' => true]);
}

// @version 1.1 - R8 gate; the browser's KEK; the root vault's half replaced with it
function vault_passphrase_enroll_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Replace the vault passphrase, for an account whose passkeys cannot hold a key; requires a recent step-up and a fresh unlocker (unlocker: {credential} from vault_unlock_options, {passphrase_kek} or {code_kek}) in the same request. Only the account half of the new phrase\'s KEK is sent',
		'input' => [
			'passphrase_kek' => ['type' => 'password', 'required' => true, 'label' => 'Account half of the new passphrase KEK (base64url, 32 bytes)'],
			'unlocker' => ['type' => 'object', 'required' => false, 'label' => 'Fresh unlocker: {credential} from vault_unlock_options, {passphrase_kek} or {code_kek}'],
			'root_passphrase' => ['type' => 'object', 'required' => true, 'label' => 'The root vault\'s wrapping of the same phrase {unlocker_type: passphrase, wrapped_secret_key, salt}'],
		],
	];
}
?>
