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
	if ($passphrase !== '' && strlen($passphrase) < SealedBox::PASSPHRASE_MIN_CHARS) {
		return LogicResult::error('Your vault passphrase must be at least ' . SealedBox::PASSPHRASE_MIN_CHARS . ' characters.');
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

	$all_wrappings = new MultiUserEncryptionWrapping(['vault_id' => $vault->key]);
	$all_wrappings->load();

	// Orphan cleanup: a wrapping tagged with a generation NEWER than the uev
	// row's can only come from a crash between persisting wrappings and
	// flipping the row (the ceremony's two-phase order below). Its keypair was
	// never advertised, so nothing is sealed to it — but left live it would
	// miscount in the unlocker floor and could hand an unlock a secret that
	// opens nothing. Retire them before choosing anything.
	$current_generation = (int)$vault->get('uev_key_generation');
	$live_wrappings = [];
	foreach ($all_wrappings as $wrapping) {
		if ((int)$wrapping->get('uew_key_generation') > $current_generation) {
			$wrapping->soft_delete();
			continue;
		}
		$live_wrappings[] = $wrapping;
	}

	// The authorizing wrapping is the presented credential's LOWEST-generation
	// live wrapping. Normally there is exactly one; after a partial rotation
	// failure both generations' wrappings are live, and the retry must unwrap
	// the OLDEST secret — that is the generation still holding un-resealed
	// content, and the one this ceremony drains.
	$authorizing_wrapping = null;
	foreach ($live_wrappings as $wrapping) {
		if ($wrapping->get('uew_unlocker_type') !== UserEncryptionWrapping::TYPE_PASSKEY
				|| (int)$wrapping->get('uew_pkc_credential_id') !== (int)$passkey->key) {
			continue;
		}
		if ($authorizing_wrapping === null
				|| (int)$wrapping->get('uew_key_generation') < (int)$authorizing_wrapping->get('uew_key_generation')) {
			$authorizing_wrapping = $wrapping;
		}
	}
	if (!$authorizing_wrapping) {
		return LogicResult::error('This passkey does not currently unlock your vault - add it first, then rotate.');
	}
	$old_generation = (int)$authorizing_wrapping->get('uew_key_generation');

	// The unlockers this rotation retires (everything in the drained
	// generation except the authorizing passkey) — surfaced so the user knows
	// what to re-add.
	$dropped_passkeys = [];
	foreach ($live_wrappings as $wrapping) {
		if ($wrapping->get('uew_unlocker_type') === UserEncryptionWrapping::TYPE_PASSKEY
				&& (int)$wrapping->get('uew_key_generation') === $old_generation
				&& (int)$wrapping->get('uew_pkc_credential_id') !== (int)$passkey->key) {
			$dropped_passkeys[] = ['credential_id' => (int)$wrapping->get('uew_pkc_credential_id'), 'label' => $wrapping->get('uew_label')];
		}
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

	// Crash-safety order: persist every new-generation WRAPPING first, and
	// flip the uev row (public key, salt, generation) only after — the whole
	// phase inside one transaction. The moment the flip is visible, content
	// seals to the new public key, so the new secret must already be
	// recoverable from durable wrappings; the reverse order would let a crash
	// orphan a live public key whose secret died with this request, and
	// everything sealed to it thereafter would be unrecoverable. A crash
	// anywhere in this phase rolls back to the untouched old state; a crash
	// after it leaves BOTH generations' wrappings live and BOTH secrets
	// recoverable. Only after the new generation is durable do we run
	// consumer re-seal callbacks (old secret still in hand), and only after
	// those succeed do we retire the previous generation.
	$db = DbConnector::get_instance()->get_db_link();
	$recovery_codes = [];
	$passphrase_reenrolled = false;
	try {
		$db->beginTransaction();

		UserEncryptionWrapping::createWrapped(
			$vault->key, UserEncryptionWrapping::TYPE_PASSKEY, $keypair['secret'], $prf_output,
			$passkey->key, $passkey->get('pkc_label'), $new_generation
		);

		for ($i = 0; $i < 10; $i++) {
			$code = $box->generateRecoveryCode();
			$recovery_codes[] = $code;
			$kek = $box->kekFromRecoveryCode($code, $salt);
			UserEncryptionWrapping::createWrapped($vault->key, UserEncryptionWrapping::TYPE_RECOVERY, $keypair['secret'], $kek, null, null, $new_generation, $salt);
		}

		if ($passphrase !== '') {
			$kek = $box->kekFromPassphrase($passphrase, $salt);
			UserEncryptionWrapping::createWrapped($vault->key, UserEncryptionWrapping::TYPE_PASSPHRASE, $keypair['secret'], $kek, null, null, $new_generation, $salt);
			$passphrase_reenrolled = true;
		}

		$vault->set('uev_public_key', $keypair['public']);
		$vault->set('uev_salt', $salt);
		$vault->set('uev_key_generation', $new_generation);
		$vault->set('uev_updated_time', gmdate('Y-m-d H:i:s'));
		$vault->save();

		$db->commit();
	} catch (Throwable $e) {
		if ($db->inTransaction()) {
			$db->rollBack();
		}
		error_log('Vault rotation: could not persist the new generation for user ' . $user->key . ': ' . $e->getMessage());
		return LogicResult::error('Key rotation could not start safely - nothing was changed and every unlocker you had still works. Try again.');
	}

	// Consumer packages: re-seal callbacks are scoped to the generation being
	// drained ($old_generation — the only one $old_secret_key can open) and
	// THROW on any failure (VaultUnlock::onReseal() contract). A failure here
	// must never reach the retirement step below: retiring wrappings whose
	// content is not fully re-sealed destroys the only path to that content.
	try {
		foreach (VaultUnlock::resealCallbacks() as $callback) {
			call_user_func($callback, (int)$user->key, $old_secret_key, $old_generation, $keypair['public'], $new_generation);
		}
	} catch (Throwable $e) {
		error_log('Vault rotation: consumer re-seal incomplete for user ' . $user->key . ': ' . $e->getMessage());
		return LogicResult::error(
			'Key rotation could not finish re-securing all of your content, so nothing was retired - '
			. 'every unlocker you had still works. Run the rotation again to complete it.'
		);
	}

	// Only now retire the drained generation - every consumer has confirmed
	// its content is re-sealed off it, so that secret is no longer needed.
	// Wrappings of any OTHER live generation (a partially-failed earlier
	// rotation) are left alone; a later rotation drains and retires them.
	foreach ($live_wrappings as $wrapping) {
		if ((int)$wrapping->get('uew_key_generation') === $old_generation) {
			$wrapping->soft_delete();
		}
	}

	VaultUnlock::open($user->key, $keypair['secret'], UserEncryptionVault::SCOPE_USER);

	// The key_file backup payload, same shape as setup's: the new generation's
	// wrapped-key rows plus the public key and salt they belong to.
	$live = new MultiUserEncryptionWrapping(['vault_id' => $vault->key]);
	$live->load();
	$wrapping_rows = [];
	foreach ($live as $w) {
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
		'rotated'               => true,
		'key_generation'        => $new_generation,
		'recovery_codes'        => $recovery_codes,
		'passphrase_reenrolled' => $passphrase_reenrolled,
		'dropped_passkeys'      => $dropped_passkeys,
		'key_file'              => $key_file,
	]);
}

function vault_rotate_verify_logic_api() {
	return [
		'requires_session' => true,
		'auth' => array('requires_browser_session' => true),
		'description' => 'Complete vault key rotation: fresh keypair, every consumer re-seals its content, recovery codes replaced, other unlockers must be re-added',
	];
}
?>
