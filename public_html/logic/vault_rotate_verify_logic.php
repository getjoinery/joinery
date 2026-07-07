<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

/**
 * Vault key rotation. A wrapping can only be re-created from a KEK the
 * ceremony can re-derive right now: the authorizing passkey (its PRF output
 * is stable per credential+context, so it's available), a resupplied
 * passphrase, and fresh recovery codes (the vault mints both the code and its
 * KEK, so no interaction is needed). A passkey NOT presented to this
 * ceremony, or a passphrase not resupplied, cannot be re-wrapped — their old
 * wrappings would otherwise silently unwrap to the now-stale secret, so they
 * are invalidated rather than left dangling. The response lists what needs
 * re-adding.
 */
function vault_rotate_verify_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
	require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
	require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
	require_once(PathHelper::getIncludePath('includes/VaultHealth.php'));
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

	if (empty($input['acknowledged'])) {
		return LogicResult::error(
			'You must acknowledge that any passkey not used in this rotation, and your passphrase '
			. 'unless you re-enter it, will need to be re-added afterward - only unlockers presented '
			. 'here carry forward. Your recovery codes are always replaced.'
		);
	}

	$vault = UserEncryptionVault::loadForUser($user->key);
	if (!$vault) {
		return LogicResult::error('Your vault is not set up yet.');
	}

	$credential = $input['credential'] ?? null;
	if (!is_array($credential)) {
		return LogicResult::error('Missing passkey credential response.');
	}
	$passphrase = isset($input['passphrase']) ? (string)$input['passphrase'] : '';

	try {
		$service = new PasskeyService();
		[$derived_user, $passkey, $prf_output] = $service->verifyDerivation(json_encode($credential), 'vault-kek');
	} catch (Exception $e) {
		return LogicResult::error($e->getMessage());
	}
	if ((int)$derived_user->key !== (int)$user->key) {
		return LogicResult::error('This passkey does not belong to your account.');
	}

	$all_wrappings = new MultiUserEncryptionWrapping(['vault_id' => $vault->key]);
	$all_wrappings->load();

	$authorizing_wrapping = null;
	$dropped_passkeys = [];
	foreach ($all_wrappings as $wrapping) {
		if ($wrapping->get('uew_unlocker_type') === UserEncryptionWrapping::TYPE_PASSKEY) {
			if ((int)$wrapping->get('uew_pkc_credential_id') === (int)$passkey->key) {
				$authorizing_wrapping = $wrapping;
			} else {
				$dropped_passkeys[] = ['credential_id' => (int)$wrapping->get('uew_pkc_credential_id'), 'label' => $wrapping->get('uew_label')];
			}
		}
	}
	if (!$authorizing_wrapping) {
		return LogicResult::error('This passkey does not currently unlock your vault - add it first, then rotate.');
	}

	$box = new SealedBox();
	try {
		$ad = UserEncryptionWrapping::adFor($vault->key, $authorizing_wrapping->key);
		$old_secret_key = $box->unwrapKey($authorizing_wrapping->get('uew_wrapped_secret_key'), $prf_output, $ad);
	} catch (Exception $e) {
		return LogicResult::error('Could not verify your current vault key with this passkey.');
	}

	$keypair = $box->generateKeypair();
	$salt = $box->generateSalt();
	$new_generation = (int)$vault->get('uev_key_generation') + 1;

	// Crash-safety order: persist every new-generation wrapping AND flip the
	// uev row FIRST, while the old wrappings are still live. A crash anywhere
	// before the final soft-delete step leaves BOTH generations' wrappings
	// live and BOTH secrets recoverable - the old wrappings still unwrap the
	// old secret, and each wrapping's own uew_key_generation says which
	// secret it belongs to. Only after the new generation is durable do we
	// run consumer re-seal callbacks (old secret still in hand), and only
	// after those succeed do we retire the previous generation.
	$vault->set('uev_public_key', $keypair['public']);
	$vault->set('uev_salt', $salt);
	$vault->set('uev_key_generation', $new_generation);
	$vault->set('uev_updated_time', gmdate('Y-m-d H:i:s'));
	$vault->save();

	UserEncryptionWrapping::createWrapped(
		$vault->key, UserEncryptionWrapping::TYPE_PASSKEY, $keypair['secret'], $prf_output,
		$passkey->key, $passkey->get('pkc_label'), $new_generation
	);

	$recovery_codes = [];
	for ($i = 0; $i < 10; $i++) {
		$code = $box->generateRecoveryCode();
		$recovery_codes[] = $code;
		$kek = $box->kekFromRecoveryCode($code, $salt);
		UserEncryptionWrapping::createWrapped($vault->key, UserEncryptionWrapping::TYPE_RECOVERY, $keypair['secret'], $kek, null, null, $new_generation);
	}

	$passphrase_reenrolled = false;
	if ($passphrase !== '') {
		$kek = $box->kekFromPassphrase($passphrase, $salt);
		UserEncryptionWrapping::createWrapped($vault->key, UserEncryptionWrapping::TYPE_PASSPHRASE, $keypair['secret'], $kek, null, null, $new_generation);
		$passphrase_reenrolled = true;
	}

	// Consumer packages: re-seal callbacks MUST be idempotent and keyed on
	// each item's own per-item key-generation column (vs uev_key_generation) -
	// a retry after a partial crash has to skip items already flipped to the
	// new generation, not re-seal an already-current item.
	foreach (VaultUnlock::resealCallbacks() as $callback) {
		call_user_func($callback, (int)$user->key, $old_secret_key, $keypair['public'], $new_generation);
	}

	// Only now retire the previous generation - every consumer has confirmed
	// its content is re-sealed, so the old secret is no longer needed.
	foreach ($all_wrappings as $wrapping) {
		$wrapping->soft_delete();
	}

	VaultUnlock::open($user->key, $keypair['secret'], UserEncryptionVault::SCOPE_USER);

	$host_warnings = [];
	try {
		$host_warnings = array_values(array_filter(VaultHealth::runAll(), function ($w) { return $w['state'] !== 'verified'; }));
	} catch (Exception $e) {
		// advisory only
	}

	return LogicResult::render([
		'rotated'               => true,
		'key_generation'        => $new_generation,
		'recovery_codes'        => $recovery_codes,
		'passphrase_reenrolled' => $passphrase_reenrolled,
		'dropped_passkeys'      => $dropped_passkeys,
		'host_warnings'         => $host_warnings,
	]);
}

function vault_rotate_verify_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Complete vault key rotation: fresh keypair, every consumer re-seals its content, recovery codes replaced, other unlockers must be re-added',
	];
}
?>
