<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function vault_setup_verify_logic(array $input): LogicResult {
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

	if (!$user->get('usr_password')) {
		return LogicResult::error(
			'Set an account password before enabling your vault - a vault holder always keeps password sign-in as a second factor.',
			['requires_password' => true]
		);
	}

	if (empty($input['acknowledged'])) {
		return LogicResult::error(
			'You must acknowledge that losing every unlocker (passkey, recovery codes, and passphrase) permanently loses everything sealed in your vault - there is no support-desk recovery.'
		);
	}

	$existing = new MultiUserEncryptionVault(['user_id' => $user->key, 'scope' => UserEncryptionVault::SCOPE_USER]);
	if ($existing->count_all() > 0) {
		return LogicResult::error('Your vault is already set up.');
	}

	$credential = $input['credential'] ?? null;
	if (!is_array($credential)) {
		return LogicResult::error('Missing passkey credential response.');
	}
	$passphrase = isset($input['passphrase']) ? (string)$input['passphrase'] : '';
	if ($passphrase !== '' && strlen($passphrase) < SealedBox::PASSPHRASE_MIN_CHARS) {
		return LogicResult::error('Your vault passphrase must be at least ' . SealedBox::PASSPHRASE_MIN_CHARS . ' characters.');
	}
	$code_count = isset($input['recovery_code_count']) ? (int)$input['recovery_code_count'] : 10;
	$code_count = max(5, min(20, $code_count));

	try {
		$service = new PasskeyService();
		[$derived_user, $passkey, $prf_output] = $service->verifyDerivation(json_encode($credential), 'vault-kek');
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}
	if ((int)$derived_user->key !== (int)$user->key) {
		return LogicResult::error('This passkey does not belong to your account.');
	}

	$box = new SealedBox();
	$keypair = $box->generateKeypair();
	$salt = $box->generateSalt();

	// One transaction for the vault row AND every wrapping: a vault must never
	// become visible with zero unlockers (setup would refuse to re-run against
	// it and no unlock could ever open it).
	$db = DbConnector::get_instance()->get_db_link();
	$recovery_codes = [];
	try {
		$db->beginTransaction();

		$vault = new UserEncryptionVault(NULL);
		$vault->set('uev_usr_user_id', $user->key);
		$vault->set('uev_scope', UserEncryptionVault::SCOPE_USER);
		$vault->set('uev_custody', UserEncryptionVault::CUSTODY_SERVER);
		$vault->set('uev_public_key', $keypair['public']);
		$vault->set('uev_salt', $salt);
		$vault->set('uev_key_generation', 1);
		$vault->save();

		$passkey_kek = $prf_output;
		UserEncryptionWrapping::createWrapped(
			$vault->key, UserEncryptionWrapping::TYPE_PASSKEY, $keypair['secret'], $passkey_kek,
			$passkey->key, $passkey->get('pkc_label'), 1
		);

		for ($i = 0; $i < $code_count; $i++) {
			$code = $box->generateRecoveryCode();
			$recovery_codes[] = $code;
			$kek = $box->kekFromRecoveryCode($code, $salt);
			UserEncryptionWrapping::createWrapped($vault->key, UserEncryptionWrapping::TYPE_RECOVERY, $keypair['secret'], $kek, null, null, 1, $salt);
		}

		if ($passphrase !== '') {
			$kek = $box->kekFromPassphrase($passphrase, $salt);
			UserEncryptionWrapping::createWrapped($vault->key, UserEncryptionWrapping::TYPE_PASSPHRASE, $keypair['secret'], $kek, null, null, 1, $salt);
		}

		$db->commit();
	} catch (Throwable $e) {
		if ($db->inTransaction()) {
			$db->rollBack();
		}
		error_log('Vault setup: could not persist the vault for user ' . $user->key . ': ' . $e->getMessage());
		return LogicResult::error('Could not create your vault - nothing was saved. Try again.');
	}

	VaultUnlock::open($user->key, $keypair['secret'], UserEncryptionVault::SCOPE_USER);

	$wrappings = new MultiUserEncryptionWrapping(['vault_id' => $vault->key]);
	$wrappings->load();
	$wrapping_rows = [];
	foreach ($wrappings as $w) {
		$wrapping_rows[] = [
			'id'             => (int)$w->key,
			'unlocker_type'  => $w->get('uew_unlocker_type'),
			'wrapped_secret' => $w->get('uew_wrapped_secret_key'),
			'salt'           => $w->get('uew_salt'),
			'key_generation' => (int)$w->get('uew_key_generation'),
		];
	}
	$key_file = [
		'vault_id'   => (int)$vault->key,
		'public_key' => $keypair['public'],
		'salt'       => $salt,
		'wrappings'  => $wrapping_rows,
	];

	return LogicResult::render([
		'vault_id'       => (int)$vault->key,
		'recovery_codes' => $recovery_codes,
		'key_file'       => $key_file,
	]);
}

function vault_setup_verify_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Complete Sealed Vault setup: generate the keypair, wrap it under the enrolling passkey/recovery codes/optional passphrase, and open the unlock window',
	];
}
?>
