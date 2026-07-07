<?php
/**
 * VaultUnlock - the Sealed Vault's unlock window (docs/sealed_vault.md).
 *
 * One unlocker act (a passkey tap, a recovery code, a passphrase) unwraps a
 * user's vault secret key into APCu for a bounded, idle-extended window.
 * Every server-custody consumer of that scope shares the SAME window — mail
 * and chat both read `VaultUnlock::secretKey($user_id)` and both see it open
 * the instant either one unlocks. That is the whole UX point (one tap opens
 * everything) and the accepted cost (an attacker resident during the window
 * reads every consumer's in-window content, not just one) — see
 * docs/sealed_vault.md § One unlock opens everything.
 *
 * The window is keyed to the browser session (APCu key
 * `vault:{session_id}:{user_id}:{scope}`), so it never survives past the
 * session that opened it, and every read re-stores the value (activity
 * extension) so an active user is never dropped mid-window. Ending the
 * window before the idle timeout — an explicit "lock", a credential event, a
 * heartbeat/IP-change policy, a permission cap — is always a consumer
 * *policy* decision; this class only makes wiping callable (lock/lockAll).
 *
 * @version 1.0
 */

/** Thrown by a consumer's decrypt path when it needs the vault open but the
 *  window is closed. Generic hooks (the File decrypt hook, the sealed-field
 *  model hook) catch this and surface "locked", never an error. */
class VaultLockedException extends Exception {}

class VaultUnlock {

	const DEFAULT_IDLE_MINUTES = 30;

	/** @var callable[] consulted, in registration order, by the rotation ceremony. */
	private static $reseal_callbacks = array();

	/** @var callable[] consulted, in registration order, whenever a window closes. */
	private static $wipe_callbacks = array();

	/** Unwrap and open the window for the current session. */
	public static function open(int $user_id, string $secret_key, string $scope = 'user'): void {
		apcu_store(self::apcuKey(self::currentSessionId(), $user_id, $scope), $secret_key, self::idleSeconds());
	}

	public static function isOpen(int $user_id, string $scope = 'user'): bool {
		return (bool)apcu_exists(self::apcuKey(self::currentSessionId(), $user_id, $scope));
	}

	/**
	 * The in-window secret key, or null when locked. Every content read calls
	 * this and treats null as "locked" — a one-tap unlock prompt, never an
	 * error. Re-stores on every fetch (activity extension).
	 */
	public static function secretKey(int $user_id, string $scope = 'user'): ?string {
		$key = self::apcuKey(self::currentSessionId(), $user_id, $scope);
		$value = apcu_fetch($key, $success);
		if (!$success) {
			return null;
		}
		apcu_store($key, $value, self::idleSeconds());
		return $value;
	}

	/** Close the current session's window. */
	public static function close(int $user_id, string $scope = 'user'): void {
		self::lock($user_id, self::currentSessionId(), $scope);
	}

	/**
	 * Wipe a specific session's window — the generic surface consumer-policy
	 * events call (a credential event, a heartbeat/IP-change trigger, an
	 * explicit lock button). Not necessarily the calling session.
	 */
	public static function lock(int $user_id, string $session_id, string $scope = 'user'): void {
		apcu_delete(self::apcuKey($session_id, $user_id, $scope));
		self::runWipeCallbacks($user_id, $scope);
	}

	/** Wipe every scope's window, across every session, for a user (e.g. on password change). */
	public static function lockAll(int $user_id): void {
		if (class_exists('APCUIterator')) {
			$pattern = '/^vault:[^:]*:' . preg_quote((string)$user_id, '/') . ':[^:]*$/';
			foreach (new APCUIterator($pattern) as $entry) {
				apcu_delete($entry['key']);
			}
		}
		self::runWipeCallbacks($user_id, null);
	}

	/**
	 * Registers a callback consulted (in registration order) by the
	 * key-rotation ceremony (logic/vault_rotate_logic.php): re-seal the
	 * consumer's own per-item DEKs from the old secret key to the new public
	 * key, bumping its per-item key-generation. Mirrors
	 * PasskeyService::onPreRevoke()'s registry mechanism.
	 *
	 * Callback signature: function(int $user_id, string $old_secret_key,
	 * string $new_public_key, int $new_key_generation): void
	 */
	public static function onReseal(callable $callback): void {
		self::$reseal_callbacks[] = $callback;
	}

	/** @return callable[] */
	public static function resealCallbacks(): array {
		return self::$reseal_callbacks;
	}

	/**
	 * Wires the vault's unlocker floor and post-revoke wrapping cleanup into
	 * PasskeyService's revocation registries. Idempotent per request (each
	 * request is a fresh PHP process under php-fpm, so the static guard only
	 * protects against multiple calls within the same request). Called from
	 * logic/passkey_revoke_logic.php before it invokes PasskeyService::revoke().
	 */
	public static function registerRevocationHooks(): void {
		static $registered = false;
		if ($registered) {
			return;
		}
		$registered = true;
		require_once(PathHelper::getIncludePath('includes/PasskeyService.php'));
		PasskeyService::onPreRevoke(function (int $user_id, int $credential_id) {
			VaultUnlock::assertRevocationSafe($user_id, $credential_id);
		});
		PasskeyService::onPostRevoke(function (int $user_id, int $credential_id) {
			VaultUnlock::cleanupRevokedCredential($user_id, $credential_id);
		});
	}

	/**
	 * The unlocker floor: refuse to strip the last live passkey wrapping from
	 * a vault unless at least 3 unused recovery codes remain. Throws
	 * PasskeyRevocationVetoException (defined in PasskeyService.php) to block
	 * the revocation; the message surfaces to the user verbatim.
	 */
	public static function assertRevocationSafe(int $user_id, int $credential_id): void {
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

		$vault = UserEncryptionVault::loadForUser($user_id, UserEncryptionVault::SCOPE_USER);
		if (!$vault) {
			return; // no vault - nothing to strand
		}

		try {
			self::assertWrappingDeleteSafe((int)$vault->key, $credential_id);
		} catch (RuntimeException $e) {
			throw new PasskeyRevocationVetoException(
				'Revoking this passkey would lock you out of your encrypted vault - add another '
				. 'vault-enrolled passkey, or make sure you have at least 3 unused recovery codes, '
				. 'before removing it.'
			);
		}
	}

	/**
	 * The unlocker floor's shared counting logic, used both by revocation
	 * (assertRevocationSafe(), excluding the credential being revoked from
	 * the passkey count) and by any other wrapping-delete path (e.g. removing
	 * the vault passphrase) that must not leave the vault with fewer than 1
	 * live passkey wrapping AND fewer than 3 unused recovery codes. Throws a
	 * plain RuntimeException — callers translate it to whatever exception
	 * type fits their surface.
	 *
	 * Only a passkey wrapping whose credential row is still live
	 * (pkc_delete_time IS NULL) counts - a wrapping left behind by a revoke
	 * that predates cleanupRevokedCredential() must not satisfy the floor.
	 */
	public static function assertWrappingDeleteSafe(int $vault_id, ?int $exclude_credential_id = null): void {
		require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));

		$wrappings = new MultiUserEncryptionWrapping(['vault_id' => $vault_id]);
		$wrappings->load();

		$dblink = DbConnector::get_instance()->get_db_link();
		$live_credential_stmt = $dblink->prepare(
			'SELECT 1 FROM pkc_passkey_credentials WHERE pkc_passkey_credential_id = ? AND pkc_delete_time IS NULL'
		);

		$remaining_passkeys = 0;
		$unused_recovery = 0;
		foreach ($wrappings as $wrapping) {
			$type = $wrapping->get('uew_unlocker_type');
			if ($type === UserEncryptionWrapping::TYPE_PASSKEY) {
				$cred_id = (int)$wrapping->get('uew_pkc_credential_id');
				if ($cred_id === $exclude_credential_id) {
					continue;
				}
				$live_credential_stmt->execute([$cred_id]);
				if ($live_credential_stmt->fetchColumn()) {
					$remaining_passkeys++;
				}
			}
			if ($type === UserEncryptionWrapping::TYPE_RECOVERY && !$wrapping->get('uew_is_used')) {
				$unused_recovery++;
			}
		}

		if ($remaining_passkeys < 1 && $unused_recovery < 3) {
			throw new RuntimeException('This would leave the vault with no working unlocker.');
		}
	}

	/**
	 * Post-revoke cleanup: a revoked passkey's vault wrapping is dead weight
	 * (its PRF output can never be re-derived from a revoked credential) and,
	 * left alive, would let the unlocker floor miscount it as a usable
	 * passkey. Soft-deletes every uew wrapping tied to the credential, across
	 * every scope's vault the user holds.
	 */
	public static function cleanupRevokedCredential(int $user_id, int $credential_id): void {
		require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));

		$wrappings = new MultiUserEncryptionWrapping(['credential_id' => $credential_id]);
		$wrappings->load();
		foreach ($wrappings as $wrapping) {
			$wrapping->soft_delete();
		}
	}

	/**
	 * Registers a callback consulted whenever a window closes (lock/lockAll)
	 * — a consumer clears a disposable in-window cache it built while the
	 * vault was open (e.g. a plaintext search index).
	 *
	 * Callback signature: function(int $user_id, ?string $scope): void
	 * ($scope is null for lockAll — every scope closed.)
	 */
	public static function onWipe(callable $callback): void {
		self::$wipe_callbacks[] = $callback;
	}

	private static function runWipeCallbacks(int $user_id, ?string $scope): void {
		foreach (self::$wipe_callbacks as $callback) {
			call_user_func($callback, $user_id, $scope);
		}
	}

	private static function apcuKey(string $session_id, int $user_id, string $scope): string {
		return 'vault:' . $session_id . ':' . $user_id . ':' . $scope;
	}

	private static function idleSeconds(): int {
		$settings = Globalvars::get_instance();
		$minutes = (int)($settings->get_setting('vault_unlock_idle_minutes') ?: self::DEFAULT_IDLE_MINUTES);
		return max(60, $minutes * 60);
	}

	/** Ensures the session exists (SessionControl starts an anonymous one pre-login). */
	private static function currentSessionId(): string {
		SessionControl::get_instance();
		$session_id = session_id();
		if (!$session_id) {
			throw new RuntimeException('VaultUnlock: a browser session is required.');
		}
		return $session_id;
	}
}
?>
