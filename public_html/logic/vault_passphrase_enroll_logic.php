<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_passphrase_enroll_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
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

	if ($session->step_up_outstanding($user)) {
		return LogicResult::error('Please re-confirm with an existing passkey before adding a bypass phrase.');
	}

	$passphrase = isset($input['passphrase']) ? (string)$input['passphrase'] : '';
	if (strlen($passphrase) < SealedBox::PASSPHRASE_MIN_CHARS) {
		return LogicResult::error('Your bypass phrase must be at least ' . SealedBox::PASSPHRASE_MIN_CHARS . ' characters.');
	}

	// A wrapping must be tagged with a single truthful generation, and in a
	// partially-rotated vault the in-window secret's generation is ambiguous.
	if (count(UserEncryptionWrapping::liveGenerations((int)$vault->key)) > 1) {
		return LogicResult::error('Your vault has an unfinished key rotation. Run the rotation again to complete it, then add your bypass phrase again.');
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

	$box = new SealedBox();
	$salt = (string)$vault->get('uev_salt');
	$kek = $box->kekFromPassphrase($passphrase, $salt);
	$wrapping = UserEncryptionWrapping::reserve($vault->key, UserEncryptionWrapping::TYPE_PASSPHRASE, null, null, (int)$vault->get('uev_key_generation'), $salt);
	try {
		$opened = (new VaultCeremonies())->openWithUnlocker($user, $vault, $input['unlocker'] ?? null,
			[$wrapping->wrapEntry($kek)]);
	} catch (VaultCeremonyException $e) {
		$wrapping->soft_delete();
		return LogicResult::error($e->getMessage(), ['unlocker_required' => true]);
	}
	$wrapping->storeWrapped($opened['wrappings'][0]);
	foreach ($old_rows as $old) {
		$old->soft_delete();
	}

	return LogicResult::render(['enrolled' => true]);
}

function vault_passphrase_enroll_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Add (or replace) the optional vault bypass phrase unlocker; requires a recent step-up and a fresh unlocker (unlocker: {credential} from vault_unlock_options, {passphrase} or {code}) in the same request',
	];
}
?>
