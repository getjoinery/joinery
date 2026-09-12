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
 * No ceremony here ever holds the vault secret as bytes. Minting, unwrapping
 * and wrapping all happen inside VaultUnlock::openKey()/open() (the key
 * holder's `open` operation, specs/unseal_daemon.md § The ceremonies); what
 * comes back is a VaultKey the resealers use and the wrappings the reserved
 * rows store. Every later enrolment (add a passkey, enrol a phrase,
 * regenerate codes) is an open under a freshly presented unlocker with the
 * new wrappings in its wrap list — openWithUnlocker() below — so a wrapping
 * is produced only in the request that proved it may be (spec B1).
 *
 * @version 1.3 - keys flow as VaultKey objects; setup()/rotate() mint through
 *   VaultUnlock::openKey() with the full wrap list; openWithUnlocker() is the
 *   shared "fresh tap, then wrap" step the enrolment logic files call
 * @version 1.2
 * @changelog 1.1 - the reseal guard no longer refuses rotation for a plugin that
 *   was never activated on this instance (it holds nothing sealed); activation
 *   history keeps deactivated-after-use refusing.
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
		// Already-done wins over every other refusal: a replayed setup (double
		// click, stale tab) must hear "your vault is already set up", not a
		// capability verdict about a vault it is not creating.
		$existing = new MultiUserEncryptionVault(['user_id' => $user->key, 'scope' => UserEncryptionVault::SCOPE_USER]);
		if ($existing->count_all() > 0) {
			throw new VaultCeremonyException('Your vault is already set up.');
		}
		// A vault with no passkey wrapping is the compatibility fallback for an
		// account whose every credential has failed a real derivation. The
		// check lives HERE rather than in the caller so no route into the
		// ceremony — a logic action, a CLI tool, a future client — can create a
		// phrase-only vault for an account that could have used a passkey.
		$passkeyless = ($passkey_credential_id <= 0);
		if ($passkeyless) {
			require_once(PathHelper::getIncludePath('data/passkeys_class.php'));
			if (!Passkey::userNeedsPassphraseFallback((int)$user->key)) {
				throw new VaultCeremonyException(
					'Your passkey can hold this key, so it must — a bypass phrase alone is only for devices that cannot.');
			}
			if ($passphrase === '') {
				throw new VaultCeremonyException('A bypass phrase is required when no passkey can hold your key.');
			}
		}
		$code_count = max(5, min(20, $code_count));

		$salt = $this->box->generateSalt();

		$db = DbConnector::get_instance()->get_db_link();
		$recovery_codes = [];
		try {
			$db->beginTransaction();

			$vault = new UserEncryptionVault(NULL);
			$vault->set('uev_usr_user_id', $user->key);
			$vault->set('uev_scope', UserEncryptionVault::SCOPE_USER);
			$vault->set('uev_custody', UserEncryptionVault::CUSTODY_SERVER);
			$vault->set('uev_public_key', '');
			$vault->set('uev_salt', $salt);
			$vault->set('uev_key_generation', 1);
			$vault->save();

			// Reserve every wrapping row first (each AD binds the row's own id),
			// then mint the keypair with the whole wrap list in ONE open: the
			// secret is wrapped in the same call that created it and never
			// exists here as bytes.
			$rows = [];
			$wrap_under = [];
			if (!$passkeyless) {
				$row = UserEncryptionWrapping::reserve($vault->key, UserEncryptionWrapping::TYPE_PASSKEY,
					$passkey_credential_id, $passkey_label, 1);
				$rows[] = $row;
				$wrap_under[] = $row->wrapEntry($kek);
			}
			for ($i = 0; $i < $code_count; $i++) {
				$code = $this->box->generateRecoveryCode();
				$recovery_codes[] = $code;
				$row = UserEncryptionWrapping::reserve($vault->key, UserEncryptionWrapping::TYPE_RECOVERY, null, null, 1, $salt);
				$rows[] = $row;
				$wrap_under[] = $row->wrapEntry($this->box->kekFromRecoveryCode($code, $salt));
			}
			if ($passphrase !== '') {
				$row = UserEncryptionWrapping::reserve($vault->key, UserEncryptionWrapping::TYPE_PASSPHRASE, null, null, 1, $salt);
				$rows[] = $row;
				$wrap_under[] = $row->wrapEntry($this->box->kekFromPassphrase($passphrase, $salt));
			}

			$opened = VaultUnlock::openKey((int)$user->key, null, $wrap_under, UserEncryptionVault::SCOPE_USER);
			UserEncryptionWrapping::storeWrappings($rows, $opened['wrappings']);
			$vault->set('uev_public_key', $opened['key']->publicKey());
			$vault->save();

			$db->commit();
		} catch (Throwable $e) {
			if ($db->inTransaction()) {
				$db->rollBack();
			}
			error_log('Vault setup: could not persist the vault for user ' . $user->key . ': ' . $e->getMessage());
			throw new VaultCeremonyException('Could not create your vault - nothing was saved. Try again.');
		}

		if ($open_window) {
			VaultUnlock::arm($user->key, $opened['key'], UserEncryptionVault::SCOPE_USER, null, VaultAudit::VIA_SETUP);
		}

		return [
			'vault'          => $vault,
			'recovery_codes' => $recovery_codes,
			'key_file'       => $this->buildKeyFile((int)$vault->key, $opened['key']->publicKey(), $salt),
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
		$this->assertEveryResealerPresent();

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

		// The old generation's key: opened for this request, never armed as the
		// window — the resealers use it, then it is retired with its wrappings.
		try {
			$old_key = VaultUnlock::openKey((int)$user->key, $authorizing_wrapping->unlocker($kek), [],
				UserEncryptionVault::SCOPE_USER)['key'];
		} catch (Exception $e) {
			throw new VaultCeremonyException('Could not verify your current vault key with this passkey.');
		}

		if ($old_generation < $current_generation) {
			return $this->completePendingRotation($user, $vault, $live_wrappings, $old_key,
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

		$salt = $this->box->generateSalt();
		$new_generation = $current_generation + 1;

		// Crash-safety order: persist every new-generation WRAPPING first, and
		// flip the uev row only after — the whole phase inside one transaction.
		// The moment the flip is visible, content seals to the new public key,
		// so the new secret must already be recoverable from durable wrappings.
		// The new keypair is minted with its whole wrap list in one open, the
		// same shape as setup().
		$db = DbConnector::get_instance()->get_db_link();
		$recovery_codes = [];
		$passphrase_reenrolled = false;
		try {
			$db->beginTransaction();

			$rows = [];
			$wrap_under = [];
			$row = UserEncryptionWrapping::reserve($vault->key, UserEncryptionWrapping::TYPE_PASSKEY,
				$passkey_credential_id, $passkey_label, $new_generation);
			$rows[] = $row;
			$wrap_under[] = $row->wrapEntry($kek);

			for ($i = 0; $i < 10; $i++) {
				$code = $this->box->generateRecoveryCode();
				$recovery_codes[] = $code;
				$row = UserEncryptionWrapping::reserve($vault->key, UserEncryptionWrapping::TYPE_RECOVERY, null, null, $new_generation, $salt);
				$rows[] = $row;
				$wrap_under[] = $row->wrapEntry($this->box->kekFromRecoveryCode($code, $salt));
			}

			if ($passphrase !== '') {
				$row = UserEncryptionWrapping::reserve($vault->key, UserEncryptionWrapping::TYPE_PASSPHRASE, null, null, $new_generation, $salt);
				$rows[] = $row;
				$wrap_under[] = $row->wrapEntry($this->box->kekFromPassphrase($passphrase, $salt));
				$passphrase_reenrolled = true;
			}

			$opened = VaultUnlock::openKey((int)$user->key, null, $wrap_under, UserEncryptionVault::SCOPE_USER);
			UserEncryptionWrapping::storeWrappings($rows, $opened['wrappings']);
			$new_key = $opened['key'];

			$vault->set('uev_public_key', $new_key->publicKey());
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

		$this->drainAndRetire($user, $live_wrappings, $old_key, $old_generation, $new_key->publicKey(), $new_generation);

		if ($open_window) {
			VaultUnlock::arm($user->key, $new_key, UserEncryptionVault::SCOPE_USER, null, VaultAudit::VIA_ROTATE);
		}

		return [
			'rotated'                => true,
			'completed_pending'      => false,
			'key_generation'         => $new_generation,
			'recovery_codes'         => $recovery_codes,
			'regenerate_recommended' => false,
			'passphrase_reenrolled'  => $passphrase_reenrolled,
			'dropped_passkeys'       => $dropped_passkeys,
			'key_file'               => $this->buildKeyFile((int)$vault->key, $new_key->publicKey(), $salt),
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
			VaultKey $old_key, int $old_generation, int $current_generation,
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

		$this->drainAndRetire($user, $live_wrappings, $old_key, $old_generation,
			(string)$vault->get('uev_public_key'), $current_generation);

		// The presented credential's current-generation wrapping (created by the
		// interrupted attempt) opens with the same PRF output, putting the
		// current key in hand for the window and an optional passphrase
		// re-enrollment — one open, with the phrase's wrapping in its wrap list.
		// A credential without one (the retry used a different passkey)
		// completes the drain fine — it just can't open the window.
		$current_wrapping = null;
		foreach ($live_wrappings as $wrapping) {
			if ($wrapping->get('uew_unlocker_type') === UserEncryptionWrapping::TYPE_PASSKEY
					&& (int)$wrapping->get('uew_pkc_credential_id') === $passkey_credential_id
					&& (int)$wrapping->get('uew_key_generation') === $current_generation) {
				$current_wrapping = $wrapping;
				break;
			}
		}

		$current_key = null;
		$passphrase_reenrolled = false;
		if ($current_wrapping !== null) {
			$phrase_row = null;
			$wrap_under = [];
			if ($passphrase !== '') {
				$existing = new MultiUserEncryptionWrapping(['vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSPHRASE]);
				$existing->load();
				foreach ($existing as $wrapping) {
					$wrapping->soft_delete();
				}
				$salt = (string)$vault->get('uev_salt');
				$phrase_row = UserEncryptionWrapping::reserve($vault->key, UserEncryptionWrapping::TYPE_PASSPHRASE, null, null, $current_generation, $salt);
				$wrap_under[] = $phrase_row->wrapEntry($this->box->kekFromPassphrase($passphrase, $salt));
			}
			try {
				$opened = VaultUnlock::openKey((int)$user->key, $current_wrapping->unlocker($kek), $wrap_under,
					UserEncryptionVault::SCOPE_USER);
				$current_key = $opened['key'];
				if ($phrase_row !== null) {
					$phrase_row->storeWrapped($opened['wrappings'][0]);
					$passphrase_reenrolled = true;
				}
			} catch (Exception $e) {
				// leave the key null - completion still succeeded; an unfilled
				// phrase row opens nothing and is retired with the next enrolment
				if ($phrase_row !== null) {
					$phrase_row->soft_delete();
				}
			}
		}

		if ($open_window && $current_key !== null) {
			VaultUnlock::arm($user->key, $current_key, UserEncryptionVault::SCOPE_USER, null, VaultAudit::VIA_REENROLL);
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
	 * Refuse the rotation while any consumer that declared it stores sealed
	 * content has no re-seal callback in place.
	 *
	 * Without this the rotation completes, retires the old wrappings, and that
	 * consumer's content is gone — the one failure mode a platform cannot ask a
	 * developer to avoid by having read a doc. The check runs at the very START
	 * of rotate(), before the new generation is minted: the crash-safety ordering
	 * already makes any pre-retirement throw safe, but refusing before the mint
	 * costs nothing and avoids leaving even a benign pending rotation behind.
	 *
	 * Installed-but-INACTIVE plugins count. Deactivating a plugin removes its
	 * callbacks but not its sealed rows, so rotating past a deactivated consumer
	 * is exactly the same silent loss — a hole that predates the registry and is
	 * only checkable because a declaration is readable without loading the
	 * plugin.
	 *
	 * A plugin that was NEVER activated on this instance does not count. Its
	 * code ships in every tree, but with no activation it holds no sealed rows
	 * (often no tables), so refusing for it would make rotation permanently
	 * unavailable to members who never wanted the feature. Activation HISTORY
	 * draws the line, so deactivated-after-use still refuses.
	 */
	private function assertEveryResealerPresent(): void {
		require_once(PathHelper::getIncludePath('includes/VaultConsumers.php'));
		VaultUnlock::loadConsumerBootstraps();

		$blocking = array();
		foreach (VaultConsumers::unmetObligations(true) as $name => $missing) {
			if (in_array(VaultConsumers::OBLIGATION_RESEAL, $missing, true)) {
				$declaration = VaultConsumers::declaration($name);
				$is_inactive_plugin = ($declaration !== null && $declaration['plugin'] !== '' && !$declaration['active']);
				if ($is_inactive_plugin && !VaultConsumers::pluginEverActivated($declaration['plugin'])) {
					error_log('Vault rotation: consumer "' . $name . '" declares reseals but its plugin '
						. 'was never activated on this instance — it holds nothing sealed, so it does not '
						. 'block the rotation.');
					continue;
				}
				$blocking[$name] = $is_inactive_plugin;
			}
		}
		if (!$blocking) {
			return;
		}

		$inactive = array_keys(array_filter($blocking));
		$broken   = array_keys(array_filter($blocking, function ($is_inactive) { return !$is_inactive; }));

		error_log('Vault rotation refused: consumers declaring reseals with no callback registered - '
			. implode(', ', array_keys($blocking)));

		if ($inactive) {
			throw new VaultCeremonyException(
				'Rotating now would permanently lock the content held by a switched-off feature ('
				. implode(', ', $inactive) . '), because nothing is there to re-secure it. Nothing was '
				. 'changed. Switch it back on and rotate again, or remove it if you no longer want '
				. 'its content.');
		}
		throw new VaultCeremonyException(
			'Key rotation is unavailable right now: a feature holding your encrypted content ('
			. implode(', ', $broken) . ') is not able to re-secure it, and rotating would make that '
			. 'content permanently unreadable. Nothing was changed.');
	}

	/**
	 * Run every consumer's re-seal callback for the generation being drained,
	 * then retire that generation's wrappings. A callback throw aborts BEFORE
	 * retirement — nothing is retired, every unlocker still works, re-running
	 * the rotation completes it.
	 */
	private function drainAndRetire(User $user, array $live_wrappings, VaultKey $old_key,
			int $old_generation, string $target_public_key, int $target_generation): void {
		try {
			foreach (VaultUnlock::resealCallbacks() as $callback) {
				call_user_func($callback, (int)$user->key, $old_key, $old_generation, $target_public_key, $target_generation);
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

		$probe = $this->probeWrappings($vault, $wrappings, function (string $salt) use ($code) {
			return $this->box->kekFromRecoveryCode($code, $salt);
		}, 'Vault recovery unlock');
		if ($probe === null) {
			throw new VaultCeremonyException('Invalid or already-used recovery code.');
		}
		$matched = $probe['wrapping'];
		$key = $probe['key'];

		$this->consumeRecoveryCode($matched);

		// Kill-switch: end every window everywhere FIRST, then open one only for
		// this session. A stolen code evicts the thief's pre-existing windows.
		VaultUnlock::lockAll($user->key);
		if ($open_window) {
			VaultUnlock::arm($user->key, $key, UserEncryptionVault::SCOPE_USER, null, VaultAudit::VIA_RECOVERY);
		}

		$remaining = new MultiUserEncryptionWrapping([
			'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_RECOVERY, 'is_used' => false,
		]);

		return ['regenerate_recommended' => $remaining->count_all() < 3];
	}

	/**
	 * Unlock with the enrolled passphrase: returns the opened key (the shell
	 * arms the window). Derives one KEK per distinct recorded salt — the
	 * KDF is deliberately expensive, so never per wrapping.
	 */
	public function unlockWithPassphrase(User $user, UserEncryptionVault $vault, string $passphrase): VaultKey {
		$this->assertVaultOwnership($user, $vault);
		if ($passphrase === '') {
			throw new VaultCeremonyException('Enter your bypass phrase.');
		}

		$wrappings = new MultiUserEncryptionWrapping(['vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSPHRASE]);
		$wrappings->load();
		if ($wrappings->count() === 0) {
			throw new VaultCeremonyException('No bypass phrase is enrolled.');
		}

		$probe = $this->probeWrappings($vault, $wrappings, function (string $salt) use ($passphrase) {
			return $this->box->kekFromPassphrase($passphrase, $salt);
		}, 'Vault passphrase unlock');
		if ($probe === null) {
			throw new VaultCeremonyException('Incorrect bypass phrase.');
		}
		return $probe['key'];
	}

	/**
	 * Try a knowledge-factor unlocker against each candidate wrapping until one
	 * opens. $derive maps a wrapping's salt to the KEK (one derivation per
	 * distinct salt — the passphrase KDF is deliberately expensive). A
	 * malformed/unreadable salt skips that one wrapping instead of aborting
	 * the whole unlock — one bad row must not deny every other code. Unlike a
	 * failed open (the expected wrong-code case), a derivation failure means
	 * the ROW is damaged, so it is logged while the user still has working
	 * codes.
	 *
	 * @param iterable<UserEncryptionWrapping> $wrappings
	 * @return ?array{wrapping: UserEncryptionWrapping, key: VaultKey, wrappings: string[]} null when nothing opened
	 */
	private function probeWrappings(UserEncryptionVault $vault, iterable $wrappings, callable $derive,
			string $log_label, array $wrap_under = []): ?array {
		$keks = [];
		foreach ($wrappings as $wrapping) {
			$salt = (string)$wrapping->get('uew_salt');
			if ($salt === '') {
				$salt = (string)$vault->get('uev_salt'); // legacy row predating uew_salt
			}
			if (!array_key_exists($salt, $keks)) {
				try {
					$keks[$salt] = $derive($salt);
				} catch (Exception $e) {
					$keks[$salt] = null;
					error_log($log_label . ': skipping wrapping ' . (int)$wrapping->key
						. ' (vault ' . (int)$vault->key . ') - KEK derivation failed: ' . $e->getMessage());
				}
			}
			if ($keks[$salt] === null) {
				continue;
			}
			try {
				$opened = VaultUnlock::openKey((int)$vault->get('uev_usr_user_id'), $wrapping->unlocker($keks[$salt]),
					$wrap_under, (string)$vault->get('uev_scope'));
			} catch (Exception $e) {
				continue; // wrong code / phrase for this row - try the next
			}
			return ['wrapping' => $wrapping, 'key' => $opened['key'], 'wrappings' => $opened['wrappings']];
		}
		return null;
	}

	/**
	 * The shared enrolment step (specs/unseal_daemon.md B1): a wrapping is
	 * produced only in the request that presented a real unlocker, so every
	 * ceremony that adds an unlocker — another passkey, a bypass phrase, fresh
	 * recovery codes — takes one here, exactly as the unlock prompt would, and
	 * gets the new wrappings back from the same open. The window that results
	 * replaces the session's current one.
	 *
	 * $unlocker_input is what the browser sent under `unlocker`: one of
	 * ['credential' => WebAuthn PRF assertion from an enrolled passkey],
	 * ['passphrase' => string] or ['code' => string]. A recovery code used
	 * here is consumed (it was one-time) but does not end the user's other
	 * windows — that kill-switch belongs to the unlock path, where a stolen
	 * code is the threat; here the caller already holds a signed-in,
	 * stepped-up session.
	 *
	 * @param array $wrap_under the new wrappings' entries (UserEncryptionWrapping::wrapEntry())
	 * @return array{key: VaultKey, wrappings: string[]}
	 * @throws VaultCeremonyException with the message to show
	 */
	public function openWithUnlocker(User $user, UserEncryptionVault $vault, $unlocker_input, array $wrap_under,
			string $via = VaultAudit::VIA_REENROLL): array {
		$this->assertVaultOwnership($user, $vault);
		if (!is_array($unlocker_input)) {
			throw new VaultCeremonyException('Confirm with your passkey, bypass phrase or a recovery code to continue.');
		}

		if (isset($unlocker_input['credential']) && is_array($unlocker_input['credential'])) {
			require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
			try {
				$service = new PasskeyService();
				[$derived_user, $passkey, $prf_output] = $service->verifyDerivation(json_encode($unlocker_input['credential']), 'vault-kek');
			} catch (Exception $e) {
				throw new VaultCeremonyException($e->getMessage());
			}
			if ((int)$derived_user->key !== (int)$user->key) {
				throw new VaultCeremonyException('This passkey does not belong to your account.');
			}
			$candidates = new MultiUserEncryptionWrapping([
				'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSKEY, 'credential_id' => $passkey->key,
			]);
			$candidates->load();
			if ($candidates->count() === 0) {
				throw new VaultCeremonyException('This passkey does not unlock your vault - confirm with one that does.');
			}
			$probe = $this->probeWrappings($vault, $candidates, function (string $salt) use ($prf_output) {
				return $prf_output;
			}, 'Vault enrolment', $wrap_under);
			if ($probe === null) {
				throw new VaultCeremonyException('Could not unlock your vault with this passkey.');
			}
		} elseif (isset($unlocker_input['passphrase'])) {
			$passphrase = (string)$unlocker_input['passphrase'];
			if ($passphrase === '') {
				throw new VaultCeremonyException('Enter your bypass phrase.');
			}
			$candidates = new MultiUserEncryptionWrapping(['vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_PASSPHRASE]);
			$candidates->load();
			if ($candidates->count() === 0) {
				throw new VaultCeremonyException('No bypass phrase is enrolled.');
			}
			$probe = $this->probeWrappings($vault, $candidates, function (string $salt) use ($passphrase) {
				return $this->box->kekFromPassphrase($passphrase, $salt);
			}, 'Vault enrolment', $wrap_under);
			if ($probe === null) {
				throw new VaultCeremonyException('Incorrect bypass phrase.');
			}
		} elseif (isset($unlocker_input['code'])) {
			$code = (string)$unlocker_input['code'];
			if (trim($code) === '') {
				throw new VaultCeremonyException('Enter a recovery code.');
			}
			$candidates = new MultiUserEncryptionWrapping([
				'vault_id' => $vault->key, 'unlocker_type' => UserEncryptionWrapping::TYPE_RECOVERY, 'is_used' => false,
			]);
			$candidates->load();
			$probe = $this->probeWrappings($vault, $candidates, function (string $salt) use ($code) {
				return $this->box->kekFromRecoveryCode($code, $salt);
			}, 'Vault enrolment', $wrap_under);
			if ($probe === null) {
				throw new VaultCeremonyException('Invalid or already-used recovery code.');
			}
			$this->consumeRecoveryCode($probe['wrapping']);
		} else {
			throw new VaultCeremonyException('Confirm with your passkey, bypass phrase or a recovery code to continue.');
		}

		VaultUnlock::arm((int)$user->key, $probe['key'], UserEncryptionVault::SCOPE_USER, null, $via);
		return ['key' => $probe['key'], 'wrappings' => $probe['wrappings']];
	}

	/**
	 * Consume a recovery code ATOMICALLY. A load-then-save races: two
	 * concurrent requests presenting the same code both load it as
	 * is_used=false, both open, and both mark it used — double-unlocking from
	 * a single code. A conditional UPDATE guarded on is_used=false lets exactly
	 * one request win; a rowCount of 0 means another request already consumed
	 * it, which is an already-used code.
	 */
	private function consumeRecoveryCode(UserEncryptionWrapping $matched): void {
		$db = DbConnector::get_instance()->get_db_link();
		$consume = $db->prepare(
			'UPDATE ' . UserEncryptionWrapping::$tablename . '
			 SET uew_is_used = true, uew_used_time = :used_time
			 WHERE ' . UserEncryptionWrapping::$pkey_column . ' = :id AND uew_is_used = false');
		$consume->execute([':used_time' => gmdate('Y-m-d H:i:s'), ':id' => (int)$matched->key]);
		if ($consume->rowCount() !== 1) {
			throw new VaultCeremonyException('Invalid or already-used recovery code.');
		}
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
