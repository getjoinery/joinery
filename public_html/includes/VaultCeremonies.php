<?php
/**
 * VaultCeremonies - the Sealed Vault ceremony cores (docs/sealed_vault.md).
 *
 * The logic shells (logic/vault_*_logic.php) own WHO may run a ceremony —
 * the settings gate, session, acknowledgments, rate limits, 2FA step-up,
 * WebAuthn verification, credential ownership — and translate
 * VaultCeremonyException into LogicResult errors. This class owns WHAT each
 * ceremony does, so tests can drive the full state machine with a synthetic
 * 32-byte KEK standing in for a passkey's PRF output (which cannot be
 * produced in CLI or by the browser's virtual authenticator).
 *
 * Every VaultCeremonyException message is written to be shown to the user
 * verbatim.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

class VaultCeremonyException extends Exception {}

class VaultCeremonies {

	/** @var SealedBox */
	private $box;

	public function __construct() {
		$this->box = new SealedBox();
	}

	/**
	 * First-time setup: generate the keypair, persist the vault row and every
	 * wrapping (one transaction — a vault must never exist with zero
	 * unlockers), open the window.
	 *
	 * @return array{vault:UserEncryptionVault, recovery_codes:string[], key_file:array}
	 */
	public function setup(User $user, int $passkey_credential_id, ?string $passkey_label, string $kek,
			string $passphrase = '', int $code_count = 10, bool $open_window = true): array {
		if ($passphrase !== '' && strlen($passphrase) < SealedBox::PASSPHRASE_MIN_CHARS) {
			throw new VaultCeremonyException('Your bypass phrase must be at least ' . SealedBox::PASSPHRASE_MIN_CHARS . ' characters.');
		}
		$code_count = max(5, min(20, $code_count));

		$existing = new MultiUserEncryptionVault(['user_id' => $user->key, 'scope' => UserEncryptionVault::SCOPE_USER]);
		if ($existing->count_all() > 0) {
			throw new VaultCeremonyException('Your vault is already set up.');
		}

		$keypair = $this->box->generateKeypair();
		$salt = $this->box->generateSalt();

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

			UserEncryptionWrapping::createWrapped(
				$vault->key, UserEncryptionWrapping::TYPE_PASSKEY, $keypair['secret'], $kek,
				$passkey_credential_id, $passkey_label, 1
			);

			for ($i = 0; $i < $code_count; $i++) {
				$code = $this->box->generateRecoveryCode();
				$recovery_codes[] = $code;
				$code_kek = $this->box->kekFromRecoveryCode($code, $salt);
				UserEncryptionWrapping::createWrapped($vault->key, UserEncryptionWrapping::TYPE_RECOVERY, $keypair['secret'], $code_kek, null, null, 1, $salt);
			}

			if ($passphrase !== '') {
				$pass_kek = $this->box->kekFromPassphrase($passphrase, $salt);
				UserEncryptionWrapping::createWrapped($vault->key, UserEncryptionWrapping::TYPE_PASSPHRASE, $keypair['secret'], $pass_kek, null, null, 1, $salt);
			}

			$db->commit();
		} catch (Throwable $e) {
			if ($db->inTransaction()) {
				$db->rollBack();
			}
			error_log('Vault setup: could not persist the vault for user ' . $user->key . ': ' . $e->getMessage());
			throw new VaultCeremonyException('Could not create your vault - nothing was saved. Try again.');
		}

		if ($open_window) {
			VaultUnlock::open($user->key, $keypair['secret'], UserEncryptionVault::SCOPE_USER);
		}

		return [
			'vault'          => $vault,
			'recovery_codes' => $recovery_codes,
			'key_file'       => $this->buildKeyFile((int)$vault->key, $keypair['public'], $salt),
		];
	}

	/**
	 * Key rotation. Two modes, decided by the authorizing wrapping's
	 * generation (the presented credential's LOWEST live one):
	 *
	 * - Equal to the vault's current generation (the normal case): mint a new
	 *   generation — persist its wrappings then flip the vault row, one
	 *   transaction; drain consumers off the old secret; retire the old
	 *   generation's wrappings.
	 *
	 * - Below the vault's current generation (an interrupted rotation left
	 *   two live generations): COMPLETE the pending rotation instead of
	 *   minting another — drain the old generation to the vault's EXISTING
	 *   current key, then retire it. Minting here would leave the vault
	 *   permanently split across two generations (each pass retires one and
	 *   creates one), with every unlock able to read only half the content.
	 *
	 * @return array{rotated:bool, completed_pending:bool, key_generation:int,
	 *   recovery_codes:string[], regenerate_recommended:bool,
	 *   passphrase_reenrolled:bool, dropped_passkeys:array, key_file:array}
	 */
	public function rotate(User $user, UserEncryptionVault $vault, int $passkey_credential_id, ?string $passkey_label,
			string $kek, string $passphrase = '', bool $open_window = true): array {
		if ($passphrase !== '' && strlen($passphrase) < SealedBox::PASSPHRASE_MIN_CHARS) {
			throw new VaultCeremonyException('Your bypass phrase must be at least ' . SealedBox::PASSPHRASE_MIN_CHARS . ' characters.');
		}

		$all_wrappings = new MultiUserEncryptionWrapping(['vault_id' => $vault->key]);
		$all_wrappings->load();

		// Orphan cleanup: a wrapping tagged with a generation NEWER than the uev
		// row's can only come from a crash between persisting wrappings and
		// flipping the row. Its keypair was never advertised, so nothing is
		// sealed to it — but left live it would miscount in the unlocker floor
		// and could hand an unlock a secret that opens nothing.
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
		// live wrapping — after a partial failure both generations are live, and
		// the ceremony must unwrap the OLDEST secret, the one still holding
		// un-resealed content.
		$authorizing_wrapping = null;
		foreach ($live_wrappings as $wrapping) {
			if ($wrapping->get('uew_unlocker_type') !== UserEncryptionWrapping::TYPE_PASSKEY
					|| (int)$wrapping->get('uew_pkc_credential_id') !== $passkey_credential_id) {
				continue;
			}
			if ($authorizing_wrapping === null
					|| (int)$wrapping->get('uew_key_generation') < (int)$authorizing_wrapping->get('uew_key_generation')) {
				$authorizing_wrapping = $wrapping;
			}
		}
		if (!$authorizing_wrapping) {
			throw new VaultCeremonyException('This passkey does not currently unlock your vault - add it first, then rotate.');
		}
		$old_generation = (int)$authorizing_wrapping->get('uew_key_generation');

		try {
			$ad = UserEncryptionWrapping::adFor((int)$vault->key, $authorizing_wrapping->key);
			$old_secret_key = $this->box->unwrapKey($authorizing_wrapping->get('uew_wrapped_secret_key'), $kek, $ad);
		} catch (Exception $e) {
			throw new VaultCeremonyException('Could not verify your current vault key with this passkey.');
		}

		if ($old_generation < $current_generation) {
			return $this->completePendingRotation($user, $vault, $live_wrappings, $old_secret_key,
				$old_generation, $current_generation, $passkey_credential_id, $kek, $passphrase, $open_window);
		}

		// --- Normal mode: mint a new generation ---

		$dropped_passkeys = [];
		foreach ($live_wrappings as $wrapping) {
			if ($wrapping->get('uew_unlocker_type') === UserEncryptionWrapping::TYPE_PASSKEY
					&& (int)$wrapping->get('uew_key_generation') === $old_generation
					&& (int)$wrapping->get('uew_pkc_credential_id') !== $passkey_credential_id) {
				$dropped_passkeys[] = ['credential_id' => (int)$wrapping->get('uew_pkc_credential_id'), 'label' => $wrapping->get('uew_label')];
			}
		}

		$keypair = $this->box->generateKeypair();
		$salt = $this->box->generateSalt();
		$new_generation = $current_generation + 1;

		// Crash-safety order: persist every new-generation WRAPPING first, and
		// flip the uev row only after — the whole phase inside one transaction.
		// The moment the flip is visible, content seals to the new public key,
		// so the new secret must already be recoverable from durable wrappings.
		$db = DbConnector::get_instance()->get_db_link();
		$recovery_codes = [];
		$passphrase_reenrolled = false;
		try {
			$db->beginTransaction();

			UserEncryptionWrapping::createWrapped(
				$vault->key, UserEncryptionWrapping::TYPE_PASSKEY, $keypair['secret'], $kek,
				$passkey_credential_id, $passkey_label, $new_generation
			);

			for ($i = 0; $i < 10; $i++) {
				$code = $this->box->generateRecoveryCode();
				$recovery_codes[] = $code;
				$code_kek = $this->box->kekFromRecoveryCode($code, $salt);
				UserEncryptionWrapping::createWrapped($vault->key, UserEncryptionWrapping::TYPE_RECOVERY, $keypair['secret'], $code_kek, null, null, $new_generation, $salt);
			}

			if ($passphrase !== '') {
				$pass_kek = $this->box->kekFromPassphrase($passphrase, $salt);
				UserEncryptionWrapping::createWrapped($vault->key, UserEncryptionWrapping::TYPE_PASSPHRASE, $keypair['secret'], $pass_kek, null, null, $new_generation, $salt);
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
			throw new VaultCeremonyException('Key rotation could not start safely - nothing was changed and every unlocker you had still works. Try again.');
		}

		$this->drainAndRetire($user, $live_wrappings, $old_secret_key, $old_generation, $keypair['public'], $new_generation);

		if ($open_window) {
			VaultUnlock::open($user->key, $keypair['secret'], UserEncryptionVault::SCOPE_USER);
		}

		return [
			'rotated'                => true,
			'completed_pending'      => false,
			'key_generation'         => $new_generation,
			'recovery_codes'         => $recovery_codes,
			'regenerate_recommended' => false,
			'passphrase_reenrolled'  => $passphrase_reenrolled,
			'dropped_passkeys'       => $dropped_passkeys,
			'key_file'               => $this->buildKeyFile((int)$vault->key, $keypair['public'], $salt),
		];
	}

	/**
	 * Completion mode: the vault row already advertises the current
	 * generation (persisted durably by the interrupted attempt); what remains
	 * is the drain and retirement. No new keypair, no new wrappings, no salt
	 * change. The current generation's recovery codes exist but were never
	 * shown to the user (the interrupted attempt errored before displaying
	 * them), so the response recommends regenerating codes.
	 */
	private function completePendingRotation(User $user, UserEncryptionVault $vault, array $live_wrappings,
			string $old_secret_key, int $old_generation, int $current_generation,
			int $passkey_credential_id, string $kek, string $passphrase, bool $open_window): array {

		// A credential whose only live wrapping is in the drained generation has
		// no path forward (its PRF output is not present here) — report it
		// dropped, exactly like normal mode does.
		$has_current = [];
		foreach ($live_wrappings as $wrapping) {
			if ($wrapping->get('uew_unlocker_type') === UserEncryptionWrapping::TYPE_PASSKEY
					&& (int)$wrapping->get('uew_key_generation') === $current_generation) {
				$has_current[(int)$wrapping->get('uew_pkc_credential_id')] = true;
			}
		}
		$dropped_passkeys = [];
		foreach ($live_wrappings as $wrapping) {
			if ($wrapping->get('uew_unlocker_type') === UserEncryptionWrapping::TYPE_PASSKEY
					&& (int)$wrapping->get('uew_key_generation') === $old_generation
					&& empty($has_current[(int)$wrapping->get('uew_pkc_credential_id')])) {
				$dropped_passkeys[] = ['credential_id' => (int)$wrapping->get('uew_pkc_credential_id'), 'label' => $wrapping->get('uew_label')];
			}
		}

		$this->drainAndRetire($user, $live_wrappings, $old_secret_key, $old_generation,
			(string)$vault->get('uev_public_key'), $current_generation);

		// The presented credential's current-generation wrapping (created by the
		// interrupted attempt) unwraps with the same PRF output, putting the
		// current secret in hand for the window and an optional passphrase
		// re-enrollment. A credential without one (the retry used a different
		// passkey) completes the drain fine — it just can't open the window.
		$current_secret = null;
		foreach ($live_wrappings as $wrapping) {
			if ($wrapping->get('uew_unlocker_type') !== UserEncryptionWrapping::TYPE_PASSKEY
					|| (int)$wrapping->get('uew_pkc_credential_id') !== $passkey_credential_id
					|| (int)$wrapping->get('uew_key_generation') !== $current_generation) {
				continue;
			}
			try {
				$ad = UserEncryptionWrapping::adFor((int)$vault->key, $wrapping->key);
				$current_secret = $this->box->unwrapKey($wrapping->get('uew_wrapped_secret_key'), $kek, $ad);
			} catch (Exception $e) {
				// leave null - completion still succeeded
			}
			break;
		}

		$passphrase_reenrolled = false;
		if ($passphrase !== '' && $current_secret !== null) {
			$existing = new MultiUserEncryptionWrapping(['vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSPHRASE]);
			$existing->load();
			foreach ($existing as $wrapping) {
				$wrapping->soft_delete();
			}
			$salt = (string)$vault->get('uev_salt');
			$pass_kek = $this->box->kekFromPassphrase($passphrase, $salt);
			UserEncryptionWrapping::createWrapped($vault->key, UserEncryptionWrapping::TYPE_PASSPHRASE, $current_secret, $pass_kek, null, null, $current_generation, $salt);
			$passphrase_reenrolled = true;
		}

		if ($open_window && $current_secret !== null) {
			VaultUnlock::open($user->key, $current_secret, UserEncryptionVault::SCOPE_USER);
		}

		return [
			'rotated'                => true,
			'completed_pending'      => true,
			'key_generation'         => $current_generation,
			'recovery_codes'         => [],
			'regenerate_recommended' => true,
			'passphrase_reenrolled'  => $passphrase_reenrolled,
			'dropped_passkeys'       => $dropped_passkeys,
			'key_file'               => $this->buildKeyFile((int)$vault->key, (string)$vault->get('uev_public_key'), (string)$vault->get('uev_salt')),
		];
	}

	/**
	 * Run every consumer's re-seal callback for the generation being drained,
	 * then retire that generation's wrappings. A callback throw aborts BEFORE
	 * retirement — nothing is retired, every unlocker still works, re-running
	 * the rotation completes it.
	 */
	private function drainAndRetire(User $user, array $live_wrappings, string $old_secret_key,
			int $old_generation, string $target_public_key, int $target_generation): void {
		try {
			foreach (VaultUnlock::resealCallbacks() as $callback) {
				call_user_func($callback, (int)$user->key, $old_secret_key, $old_generation, $target_public_key, $target_generation);
			}
		} catch (Throwable $e) {
			error_log('Vault rotation: consumer re-seal incomplete for user ' . $user->key . ': ' . $e->getMessage());
			throw new VaultCeremonyException(
				'Key rotation could not finish re-securing all of your content, so nothing was retired - '
				. 'every unlocker you had still works. Run the rotation again to complete it.'
			);
		}

		foreach ($live_wrappings as $wrapping) {
			if ((int)$wrapping->get('uew_key_generation') === $old_generation) {
				$wrapping->soft_delete();
			}
		}
	}

	/**
	 * Unlock with a one-time recovery code. Kill-switch semantics: a consumed
	 * code first ends EVERY open window everywhere, then opens one only for
	 * the current session. Each wrapping's KEK derives from its own recorded
	 * salt, so codes from a not-yet-drained generation keep working.
	 *
	 * @return array{regenerate_recommended:bool}
	 */
	/**
	 * Defense in depth: the (User, vault) pair must belong together. The logic
	 * shells load the caller's own vault, but nothing here re-checked it — so a
	 * bug that passed a mismatched pair would open user A's window with vault B's
	 * secret. Assert ownership at the ceremony boundary too.
	 */
	private function assertVaultOwnership(User $user, UserEncryptionVault $vault): void {
		if ((int)$vault->get('uev_usr_user_id') !== (int)$user->key) {
			throw new VaultCeremonyException('Vault does not belong to this user.');
		}
	}

	public function unlockWithRecoveryCode(User $user, UserEncryptionVault $vault, string $code, bool $open_window = true): array {
		$this->assertVaultOwnership($user, $vault);
		if (trim($code) === '') {
			throw new VaultCeremonyException('Enter a recovery code.');
		}

		$wrappings = new MultiUserEncryptionWrapping([
			'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_RECOVERY, 'is_used' => false,
		]);
		$wrappings->load();

		$keks = [];
		$secret_key = null;
		$matched = null;
		foreach ($wrappings as $wrapping) {
			$salt = (string)$wrapping->get('uew_salt');
			if ($salt === '') {
				$salt = (string)$vault->get('uev_salt'); // legacy row predating uew_salt
			}
			// A malformed/unreadable salt skips that one wrapping instead of
			// aborting the whole unlock — one bad row must not deny every other
			// recovery code. Unlike a failed unwrap below (the expected wrong-code
			// case), a derivation failure means the ROW is damaged, so log it
			// while the user still has working codes.
			if (!array_key_exists($salt, $keks)) {
				try {
					$keks[$salt] = $this->box->kekFromRecoveryCode($code, $salt);
				} catch (Exception $e) {
					$keks[$salt] = null;
					error_log('Vault recovery unlock: skipping wrapping ' . (int)$wrapping->key
						. ' (vault ' . (int)$vault->key . ') - KEK derivation failed: ' . $e->getMessage());
				}
			}
			if ($keks[$salt] === null) {
				continue;
			}
			try {
				$ad = UserEncryptionWrapping::adFor((int)$vault->key, $wrapping->key);
				$secret_key = $this->box->unwrapKey($wrapping->get('uew_wrapped_secret_key'), $keks[$salt], $ad);
				$matched = $wrapping;
				break;
			} catch (Exception $e) {
				continue; // wrong code for this row - try the next
			}
		}

		if (!$matched) {
			throw new VaultCeremonyException('Invalid or already-used recovery code.');
		}

		// Consume the code ATOMICALLY. A load-then-save (the previous approach)
		// races: two concurrent requests presenting the same code both load it as
		// is_used=false, both unwrap, and both mark it used — double-unlocking from
		// a single code. A conditional UPDATE guarded on is_used=false lets exactly
		// one request win; a rowCount of 0 means another request already consumed
		// it, which is an already-used code.
		$db = DbConnector::get_instance()->get_db_link();
		$consume = $db->prepare(
			'UPDATE ' . UserEncryptionWrapping::$tablename . '
			 SET uew_is_used = true, uew_used_time = :used_time
			 WHERE ' . UserEncryptionWrapping::$pkey_column . ' = :id AND uew_is_used = false');
		$consume->execute([':used_time' => gmdate('Y-m-d H:i:s'), ':id' => (int)$matched->key]);
		if ($consume->rowCount() !== 1) {
			throw new VaultCeremonyException('Invalid or already-used recovery code.');
		}

		// Kill-switch: end every window everywhere FIRST, then open one only for
		// this session. A stolen code evicts the thief's pre-existing windows.
		VaultUnlock::lockAll($user->key);
		if ($open_window) {
			VaultUnlock::open($user->key, $secret_key, UserEncryptionVault::SCOPE_USER);
		}

		$remaining = new MultiUserEncryptionWrapping([
			'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_RECOVERY, 'is_used' => false,
		]);

		return ['regenerate_recommended' => $remaining->count_all() < 3];
	}

	/**
	 * Unlock with the enrolled passphrase: returns the secret key (the shell
	 * opens the window). Derives one KEK per distinct recorded salt — the
	 * KDF is deliberately expensive, so never per wrapping.
	 */
	public function unlockWithPassphrase(User $user, UserEncryptionVault $vault, string $passphrase): string {
		$this->assertVaultOwnership($user, $vault);
		if ($passphrase === '') {
			throw new VaultCeremonyException('Enter your bypass phrase.');
		}

		$wrappings = new MultiUserEncryptionWrapping(['vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSPHRASE]);
		$wrappings->load();
		if ($wrappings->count() === 0) {
			throw new VaultCeremonyException('No bypass phrase is enrolled.');
		}

		$keks = [];
		foreach ($wrappings as $wrapping) {
			$salt = (string)$wrapping->get('uew_salt');
			if ($salt === '') {
				$salt = (string)$vault->get('uev_salt'); // legacy row predating uew_salt
			}
			// Same rule as unlockWithRecoveryCode: a malformed/unreadable salt
			// skips that one wrapping, never aborts the whole unlock, and gets
			// logged because a derivation failure means the row is damaged.
			if (!array_key_exists($salt, $keks)) {
				try {
					$keks[$salt] = $this->box->kekFromPassphrase($passphrase, $salt);
				} catch (Exception $e) {
					$keks[$salt] = null;
					error_log('Vault passphrase unlock: skipping wrapping ' . (int)$wrapping->key
						. ' (vault ' . (int)$vault->key . ') - KEK derivation failed: ' . $e->getMessage());
				}
			}
			if ($keks[$salt] === null) {
				continue;
			}
			try {
				$ad = UserEncryptionWrapping::adFor((int)$vault->key, $wrapping->key);
				return $this->box->unwrapKey($wrapping->get('uew_wrapped_secret_key'), $keks[$salt], $ad);
			} catch (Exception $e) {
				continue;
			}
		}

		throw new VaultCeremonyException('Incorrect bypass phrase.');
	}

	/** The backup payload setup and rotation hand the client to download. */
	private function buildKeyFile(int $vault_id, string $public_key, string $salt): array {
		$wrappings = new MultiUserEncryptionWrapping(['vault_id' => $vault_id]);
		$wrappings->load();
		$rows = [];
		foreach ($wrappings as $w) {
			$rows[] = [
				'id'             => (int)$w->key,
				'unlocker_type'  => $w->get('uew_unlocker_type'),
				'wrapped_secret' => $w->get('uew_wrapped_secret_key'),
				'salt'           => $w->get('uew_salt'),
				'key_generation' => (int)$w->get('uew_key_generation'),
			];
		}
		return [
			'vault_id'   => $vault_id,
			'public_key' => $public_key,
			'salt'       => $salt,
			'wrappings'  => $rows,
		];
	}
}
?>
