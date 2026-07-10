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
 * @version 1.2
 */

/** Thrown by a consumer's decrypt path when it needs the vault open but the
 *  window is closed. Generic hooks (the File decrypt hook, the sealed-field
 *  model hook) catch this and surface "locked", never an error. */
class VaultLockedException extends Exception {}

class VaultUnlock {

	const DEFAULT_IDLE_MINUTES = 30;

	// Window end-event policy (specs/mailbox_security_levels.md § The Unlock Window).
	// Presence means "on Joinery": every page carries the vault-presence beacon
	// (assets/js/vault-presence.js) while a window is open, so moving between
	// site pages never ends the window — only a browser that is genuinely gone
	// (closed, asleep, machine off) goes stale. Beats arrive every ~25s from a
	// visible tab; hidden tabs keep beating at whatever cadence the browser's
	// background throttling allows (typically ~1/min, worst ~1/5min), so the
	// stale threshold sits above the worst throttle interval. Staleness is
	// hygiene, not a security boundary — the hard stops are session end,
	// IP change, credential events, and the per-level caps.
	//
	// A beat only proves presence — it can END a window early (staleness) but
	// never EXTENDS one: the idle TTL is refreshed exclusively by content
	// decrypts (secretKey()), so a visible-but-idle tab still locks at
	// vault_unlock_idle_minutes and re-unlocking is a one-tap prompt. This
	// asymmetry is deliberate: presence is cheap to fake for a resident
	// attacker and must never be what keeps a key in RAM.
	const HEARTBEAT_MAX_STALE_SECONDS = 300;
	// Per-level caps — the mail consumer's window policy, applied generically here
	// as numbers passed to open(). Fortress: end after 2h without a content decrypt
	// (idle) and unconditionally 24h after arming (absolute). Private: a 7-day
	// absolute backstop only. Defaults in code; they become settings only if tuning
	// is ever needed.
	const FORTRESS_IDLE_CAP_SECONDS = 7200;      // 2 hours
	const FORTRESS_ABSOLUTE_CAP_SECONDS = 86400; // 24 hours
	const PRIVATE_ABSOLUTE_CAP_SECONDS = 604800; // 7 days

	/** @var callable[] consulted, in registration order, by the rotation ceremony. */
	private static $reseal_callbacks = array();

	/** @var callable[] consulted, in registration order, whenever a window closes. */
	private static $wipe_callbacks = array();

	/** @var bool guards loadConsumerBootstraps() to once per request. */
	private static $consumer_bootstraps_loaded = false;

	/**
	 * Every consumer package's bootstrap file, keyed by the plugin name that
	 * guards it — plugin.json convention: 'plugins/{name}/includes/bootstrap.php'.
	 * A consumer's bootstrap registers its File decrypt hook (File::registerDecryptHook)
	 * and its onReseal()/onWipe() callbacks. Add a plugin name here when a new
	 * consumer package lands (mail was the first; chat is the second).
	 */
	const CONSUMER_PLUGINS = array('mailbox');

	/**
	 * Load each active consumer's bootstrap file — lazy, once per request, called
	 * by every code path that needs a consumer's hooks live: the File decrypt-hook
	 * resolution (File::serve_from_path()), the rotation ceremony (resealCallbacks()),
	 * and window-close (runWipeCallbacks()). Static callback registries reset at the
	 * start of every request (a fresh PHP execution context even under php-fpm
	 * worker reuse), so this must be called before any of those three read them —
	 * calling it from all three read sites, guarded to run once, means no call site
	 * has to remember the ordering.
	 */
	public static function loadConsumerBootstraps(): void {
		if (self::$consumer_bootstraps_loaded) {
			return;
		}
		self::$consumer_bootstraps_loaded = true;
		require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
		foreach (self::CONSUMER_PLUGINS as $plugin) {
			if (!PluginHelper::isPluginActive($plugin)) {
				continue;
			}
			$path = PathHelper::getIncludePath('plugins/' . $plugin . '/includes/bootstrap.php');
			if (file_exists($path)) {
				require_once($path);
			}
		}
	}

	/**
	 * Unwrap and open the window for the current session. $caps carries the
	 * end-event policy for this window: ['idle'=>?seconds, 'absolute'=>?seconds,
	 * 'heartbeat'=>bool]. When null it is resolved from the user's max mail
	 * security level (capsForUser) — the mail consumer's window policy — so every
	 * existing caller gets the caps automatically. Records the arming metadata a
	 * read-time check enforces.
	 */
	public static function open(int $user_id, string $secret_key, string $scope = 'user', ?array $caps = null): void {
		if ($caps === null) {
			$caps = self::capsForUser($user_id);
		}
		$sid = self::currentSessionId();
		$now = time();
		apcu_store(self::apcuKey($sid, $user_id, $scope), $secret_key, self::idleSeconds());
		apcu_store(self::metaKey($sid, $user_id, $scope), array(
			'armed'      => $now,
			'content'    => $now,           // last content decrypt (idle cap basis)
			'hb'         => null,           // heartbeat: null until a surface monitors
			'idle_cap'   => $caps['idle'] ?? null,
			'abs_cap'    => $caps['absolute'] ?? null,
		), self::idleSeconds());
		self::touchWindowMarker($user_id, $scope);
	}

	/**
	 * The end-event caps for a user, by their highest mail security level
	 * (specs/mailbox_security_levels.md § The Unlock Window). Guarded: with the
	 * mailbox plugin absent it returns no caps, so this class stays generic.
	 *
	 * @return array{idle:?int, absolute:?int}
	 */
	public static function capsForUser(int $user_id): array {
		$domain_class = PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php');
		if (is_file($domain_class)) {
			require_once($domain_class);
			if (class_exists('InboundEmailDomain')) {
				try {
					$level = InboundEmailDomain::maxSecurityLevelForUser($user_id);
					if ($level === 'fortress') {
						return array('idle' => self::FORTRESS_IDLE_CAP_SECONDS, 'absolute' => self::FORTRESS_ABSOLUTE_CAP_SECONDS);
					}
					if ($level === 'private') {
						return array('idle' => null, 'absolute' => self::PRIVATE_ABSOLUTE_CAP_SECONDS);
					}
				} catch (\Throwable $e) {
					// Fail CLOSED: an error resolving the level must never grant an
					// uncapped window to a user who may have configured the strictest
					// policy — apply the Fortress caps and let a real Fortress user
					// see no difference, a lower-level user a tighter-than-usual
					// window until the fault clears.
					error_log('VaultUnlock::capsForUser: level lookup failed for user '
						. $user_id . ' - applying Fortress caps: ' . $e->getMessage());
					return array('idle' => self::FORTRESS_IDLE_CAP_SECONDS, 'absolute' => self::FORTRESS_ABSOLUTE_CAP_SECONDS);
				}
			}
		}
		return array('idle' => null, 'absolute' => null);
	}

	public static function isOpen(int $user_id, string $scope = 'user'): bool {
		$sid = self::currentSessionIdOrNull();
		if ($sid === null || !apcu_exists(self::apcuKey($sid, $user_id, $scope))) {
			return false;
		}
		return !self::endedByPolicy($sid, $user_id, $scope);
	}

	/**
	 * The in-window secret key, or null when locked. Every content read calls
	 * this and treats null as "locked" — a one-tap unlock prompt, never an
	 * error. Re-stores on every fetch (activity extension) and stamps the
	 * content-decrypt time the Fortress idle cap measures from.
	 */
	public static function secretKey(int $user_id, string $scope = 'user'): ?string {
		$sid = self::currentSessionIdOrNull();
		if ($sid === null) {
			return null; // no session (a CLI/cron reader) can never hold a window - locked, not an error
		}
		$key = self::apcuKey($sid, $user_id, $scope);
		$value = apcu_fetch($key, $success);
		if (!$success) {
			return null;
		}
		if (self::endedByPolicy($sid, $user_id, $scope)) {
			return null;
		}
		apcu_store($key, $value, self::idleSeconds());
		self::touchWindowMarker($user_id, $scope);
		self::stampMeta($sid, $user_id, $scope, 'content'); // this fetch IS a content decrypt
		return $value;
	}

	/**
	 * Heartbeat from a visible mail surface (specs/mailbox_security_levels.md
	 * § The Unlock Window). Marks the window monitored and fresh; once monitored,
	 * a read that finds the heartbeat stale beyond the grace interval ends it.
	 * Returns false when there is no window to beat (so the client stops).
	 */
	public static function heartbeat(int $user_id, string $scope = 'user'): bool {
		$sid = self::currentSessionIdOrNull();
		if ($sid === null || !apcu_exists(self::apcuKey($sid, $user_id, $scope))) {
			return false;
		}
		if (self::endedByPolicy($sid, $user_id, $scope)) {
			return false;
		}
		self::stampMeta($sid, $user_id, $scope, 'hb');
		self::touchWindowMarker($user_id, $scope);
		return true;
	}

	/** APCu key for a window's arming/heartbeat metadata (companion to apcuKey). */
	private static function metaKey(string $session_id, int $user_id, string $scope): string {
		return 'vaultmeta:' . $session_id . ':' . $user_id . ':' . $scope;
	}

	/** Update one metadata timestamp field to now, preserving the rest + TTL. */
	private static function stampMeta(string $sid, int $user_id, string $scope, string $field): void {
		$mkey = self::metaKey($sid, $user_id, $scope);
		$meta = apcu_fetch($mkey, $ok);
		if (!$ok || !is_array($meta)) {
			return;
		}
		$meta[$field] = time();
		apcu_store($mkey, $meta, self::idleSeconds());
	}

	/**
	 * True when a policy end-event (absolute cap, Fortress idle cap, or a stale
	 * heartbeat) has fired for this window — a READ-TIME check, no cron. Wipes the
	 * window when it fires so the caller sees it closed. No metadata → no policy
	 * (a legacy window or a non-capped consumer is never force-ended here).
	 */
	private static function endedByPolicy(string $sid, int $user_id, string $scope): bool {
		$meta = apcu_fetch(self::metaKey($sid, $user_id, $scope), $ok);
		if (!$ok || !is_array($meta)) {
			return false;
		}
		$now = time();
		$ended = false;
		if (!empty($meta['abs_cap']) && ($now - (int)($meta['armed'] ?? $now)) > (int)$meta['abs_cap']) {
			$ended = true;
		} elseif (!empty($meta['idle_cap']) && ($now - (int)($meta['content'] ?? $now)) > (int)$meta['idle_cap']) {
			$ended = true;
		} elseif (isset($meta['hb']) && $meta['hb'] !== null
				&& ($now - (int)$meta['hb']) > self::HEARTBEAT_MAX_STALE_SECONDS) {
			$ended = true;
		}
		if ($ended) {
			self::lock($user_id, $sid, $scope);
		}
		return $ended;
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
		apcu_delete(self::metaKey($session_id, $user_id, $scope));
		self::runWipeCallbacks($user_id, $scope);
	}

	/** Wipe every scope's window, across every session, for a user (e.g. on password change). */
	public static function lockAll(int $user_id): void {
		// APCUIterator's constructor THROWS where APCu is disabled (a CLI
		// process without apc.enable_cli) — and a disabled store holds no
		// windows to wipe, so skipping it is correct, not lossy.
		if (class_exists('APCUIterator') && function_exists('apcu_enabled') && apcu_enabled()) {
			// Both the window keys (vault:) and their metadata (vaultmeta:).
			$pattern = '/^vault(?:meta)?:[^:]*:' . preg_quote((string)$user_id, '/') . ':[^:]*$/';
			foreach (new APCUIterator($pattern) as $entry) {
				apcu_delete($entry['key']);
			}
		}
		foreach (glob('/dev/shm/vault_window_' . $user_id . '_*') ?: array() as $marker) {
			@unlink($marker); // every scope's window is gone — the markers with them
		}
		self::runWipeCallbacks($user_id, null);
	}

	/**
	 * True if ANY session currently holds an open window for this user/scope —
	 * unlike isOpen(), not scoped to the calling session. For a consumer's
	 * passive-close sweep task (e.g. plugins/mailbox/tasks/
	 * SweepMailboxIndexTemp.php): a disposable working copy tied to $user_id
	 * can be reclaimed once no session's window still covers it.
	 *
	 * The signal is the /dev/shm window marker, NOT APCu: the sweep runs under
	 * the CLI cron SAPI, whose APCu segment is separate per process — the web
	 * workers' vault:* entries are invisible there. The marker file (no secret,
	 * just existence + a future mtime stamped by open()/secretKey()) is visible
	 * to every process on the host, exactly like the working copies it guards.
	 * A single-session lock() leaves the marker (another session may hold a
	 * window), so it can outlive an explicit lock by up to one idle interval —
	 * the sweep is at worst delayed, never wrong.
	 */
	public static function hasAnyOpenWindow(int $user_id, string $scope = 'user'): bool {
		$marker = self::windowMarkerPath($user_id, $scope);
		$mtime = @filemtime($marker);
		if ($mtime === false) {
			return false;
		}
		if ($mtime <= time()) {
			@unlink($marker); // expired — reclaim opportunistically
			return false;
		}
		return true;
	}

	/** The cross-process open-window marker (see hasAnyOpenWindow()). */
	private static function windowMarkerPath(int $user_id, string $scope): string {
		$scope_safe = preg_replace('/[^a-z0-9_]/i', '_', $scope);
		return '/dev/shm/vault_window_' . $user_id . '_' . $scope_safe;
	}

	/** Stamp the marker with the window's current expiry (mtime = now + idle TTL). */
	private static function touchWindowMarker(int $user_id, string $scope): void {
		@touch(self::windowMarkerPath($user_id, $scope), time() + self::idleSeconds());
	}

	/**
	 * Registers a callback consulted (in registration order) by the
	 * key-rotation ceremony (logic/vault_rotate_verify_logic.php): re-seal the
	 * consumer's own per-item DEKs from the old secret key to the new public
	 * key, bumping its per-item key-generation. Mirrors
	 * PasskeyService::onPreRevoke()'s registry mechanism.
	 *
	 * Callback signature: function(int $user_id, string $old_secret_key,
	 * int $old_key_generation, string $new_public_key,
	 * int $new_key_generation): void
	 *
	 * Contract (docs/sealed_vault.md § Key rotation): re-seal EXACTLY the
	 * items whose per-item generation equals $old_key_generation — that is the
	 * generation $old_secret_key belongs to, the only one it can open. Attempt
	 * every item, then THROW if any failed: the ceremony retires a
	 * generation's wrappings only when its consumers confirmed the drain, so a
	 * swallowed failure here would destroy the only path to that content.
	 */
	public static function onReseal(callable $callback): void {
		self::$reseal_callbacks[] = $callback;
	}

	/** @return callable[] */
	public static function resealCallbacks(): array {
		self::loadConsumerBootstraps();
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
		self::loadConsumerBootstraps();
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
		$session_id = self::currentSessionIdOrNull();
		if ($session_id === null) {
			throw new RuntimeException('VaultUnlock: a browser session is required.');
		}
		return $session_id;
	}

	/** The session id, or null where none exists (a CLI/cron process). Readers
	 *  treat null as locked; only open() demands a real session. */
	private static function currentSessionIdOrNull(): ?string {
		SessionControl::get_instance();
		$session_id = session_id();
		return $session_id ?: null;
	}
}
?>
