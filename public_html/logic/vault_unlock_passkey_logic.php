<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_unlock_passkey_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
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
		return LogicResult::error('Your vault is not set up yet.');
	}

	$credential = $input['credential'] ?? null;
	if (!is_array($credential)) {
		return LogicResult::error('Missing passkey credential response.');
	}

	try {
		$service = new PasskeyService();
		[$derived_user, $passkey, $prf_output] = $service->verifyDerivation(json_encode($credential), 'vault-kek');
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}
	if ((int)$derived_user->key !== (int)$user->key) {
		return LogicResult::error('This passkey does not belong to your account.');
	}

	$wrappings = new MultiUserEncryptionWrapping([
		'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSKEY, 'credential_id' => $passkey->key,
	]);
	$wrappings->load();
	if ($wrappings->count() === 0) {
		return LogicResult::error('This passkey does not unlock your vault.');
	}
	// Normally one live wrapping per credential. After a partial rotation
	// (re-seal failure) two generations are live — prefer the CURRENT
	// generation deterministically (new arrivals seal to it), falling back to
	// the lowest; either way the state converges when the rotation is re-run.
	$current_generation = (int)$vault->get('uev_key_generation');
	$wrapping = null;
	foreach ($wrappings as $w) {
		$generation = (int)$w->get('uew_key_generation');
		if ($generation === $current_generation) {
			$wrapping = $w;
			break;
		}
		if ($wrapping === null || $generation < (int)$wrapping->get('uew_key_generation')) {
			$wrapping = $w;
		}
	}

	try {
		$box = new SealedBox();
		$ad = UserEncryptionWrapping::adFor($vault->key, $wrapping->key);
		$secret_key = $box->unwrapKey($wrapping->get('uew_wrapped_secret_key'), $prf_output, $ad);
	} catch (Exception $e) {
		return LogicResult::error('Could not unlock your vault with this passkey.');
	}

	VaultUnlock::open($user->key, $secret_key, UserEncryptionVault::SCOPE_USER);

	return LogicResult::render(['unlocked' => true]);
}

function vault_unlock_passkey_logic_descriptor() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Complete unlocking the vault with a passkey',
	];
}
?>
