<?php
/**
 * VaultUnlock - the Sealed Vault's unlock window (docs/sealed_vault.md).
 *
 * One unlocker act (a passkey tap, a recovery code, a passphrase) unwraps a
 * user's vault secret key into a bounded, idle-extended window. What a
 * consumer gets back is a VaultKey — an object that opens per-item DEKs and
 * tells its public half, and has no getter for the bytes (specs/unseal_daemon.md
 * § The PHP seam). Today the bytes sit in the window store behind that object
 * (PoolVaultKey); the unseal daemon replaces the object, not the callers.
 * Every server-custody consumer of that scope shares the SAME window — mail
 * and chat both read `VaultUnlock::secretKey($user_id)` and both see it open
 * the instant either one unlocks. That is the whole UX point (one tap opens
 * everything) and the accepted cost (an attacker resident during the window
 * reads every consumer's in-window content, not just one) — see
 * docs/sealed_vault.md § One unlock opens everything.
 *
 * The window is keyed to the browser session (store key
 * `vault:{session_id}:{user_id}:{scope}`), so it never survives past the
 * session that opened it, and a read extends it (activity extension) so an
 * active user is never dropped mid-window.
 *
 * THE STORE. Every window entry (the key slot, its metadata `vaultmeta:`, a
 * borrowed slot's marker `vaultbrw:`, a close tombstone `vaultclosed:`) is a
 * RamStore entry bound to the PHP-FPM master's lifetime: JSON files in RAM,
 * which cannot hold an object, so nothing planted there runs when a later
 * request reads it; and a PHP-FPM restart or reload ends every window, a
 * deploy none (strategy/object_free_window_store.md). The open-window marker
 * cron reads (`vaultwin:`) is a plain entry, since cron has no PHP-FPM master.
 * A store that refuses (VaultStoreUnavailableException) means no window opens.
 * Within one request the slot and its metadata are read once and the window is
 * extended once (windowState(), account()); a file that changed since it was
 * read is read again, so the memo never hides a lock or a cap. Ending the
 * window before the idle timeout — an explicit "lock", a credential event, a
 * heartbeat/IP-change policy, a permission cap — is always a consumer
 * *policy* decision; this class only makes wiping callable (lock/lockAll).
 *
 * Every open and close is recorded to the event log through VaultAudit, which
 * is the only durable trace a window leaves — see docs/sealed_vault.md
 * § The audit log.
 *
 * BORROWED WINDOWS (specs/mail_apps_plugin.md § Core: the borrowed unlock). A
 * program signed in with an app credential (a mail app) has no browser session
 * and never unlocks anything itself. When the person unlocks on the website,
 * arm() also arms the same key into one slot per live app credential whose
 * purpose borrows the unlock (AppAccess), `vault:~apk{key_id}:{user_id}:user`,
 * with the same caps measured from that moment. A request authenticated by
 * that credential reads through its slot (useCredentialWindow(), set by
 * ApiAuth), so secretKey(), endedByPolicy(), the egress guard and the audit all
 * run unchanged. The program's checks count as activity (touchActivity()) up
 * to the absolute cap — the one deliberate exception to "presence never keeps
 * a key in RAM", bounded by that cap and shown to the person in the lock chip.
 * Lock on the website (lockBorrowed()), a credential event (lockAll()), and the
 * credential's revocation (lockCredentialWindow()) end it; signing out of one
 * browser does not.
 *
 * @version 1.27 - lockAll()'s close rows carry each daemon window's unwrap count, as lock()'s do
 * @version 1.26 - the unseal daemon (specs/unseal_daemon.md WP4): openKey() opens the window in the
 *   daemon where the install record says it runs here (a DaemonVaultKey: a handle, never the key), with
 *   the window's clocks; it retries once after three seconds, refuses as vault_service_unavailable, falls
 *   back to the pool after five minutes of no answer, and never while root holds it (vault_held). A key
 *   no window holds is closed when the request ends; lock() closes a handle nothing else holds, lockAll()
 *   each handle the user's slots name and this request's unarmed keys, one by one; a window the daemon ended reads as locked with the reason
 *   (daemon_restart, sentinel_drop). Reveal lists, openOnce(), and a chain's re-seal under its own scope.
 * @version 1.25 - lockAll() records how long each window it closes was open, from its metadata
 * @version 1.24 - windows live in RamStore, bound to the PHP-FPM master (strategy/
 *   object_free_window_store.md WP2): no APCu read of a window remains; lockAll() and
 *   lockBorrowed() list names; the open-window marker is a store entry; one read and one
 *   extension per request; arm() throws VaultStoreUnavailableException when the store refuses
 * @version 1.23 - lockAll() and lockBorrowed() iterate APCu by key only (APC_ITER_KEY): the
 *   default format rebuilds every entry's value, around ApcuStore's guard (unseal_daemon.md C4)
 * @version 1.22 - window metadata is read through ApcuStore::fetch() (unseal_daemon.md C4)
 * @version 1.21
 * @changelog 1.21 - VaultRotationOwedException and arm()'s old-key refusal removed: every key is X-Wing
 * @changelog 1.20 - noteClientContentRead(): serving content the browser opens counts as a content read
 * @changelog 1.19 - modelReseal() records the classes it covers (modelResealClasses) and
 *   sweepingPreviousKey() tells a re-seal callback it runs for the chain sweep (VaultChainSweep)
 * @changelog 1.18 - arm() refuses a vault still on an old X25519 key unless the open is a recovery
 *   (VaultRotationOwedException): the unlock goes to the key rotation instead
 *   (specs/post_quantum_sealing.md R7)
 * @changelog 1.17 - close()/lock() take $audit: false wipes a window without an
 *   audit row, for an account that no longer exists (SessionControl::logout).
 * @changelog 1.16 - Borrowed windows: arm() from the website also arms each
 *   borrowing app credential's slot; useCredentialWindow(), touchActivity(),
 *   lockBorrowed(), lockCredentialWindow(), borrowedWindows().
 * @changelog 1.15 - onClientRotationCommit()/clientRotationCommitRefusal(): a
 *   consumer can refuse a client-custody rotation's commit while retiring the
 *   old key would lose something it still receives.
 * @changelog 1.14 - onClientRotation()/clientRotationChanged(): a consumer hears a
 *   client-custody rotation begin and commit.
 * @changelog 1.13 - clientReseal()/clientResealsFor(): what a client-custody
 *   scope's browser-run rotation re-seals.
 * @changelog 1.12 - the last-passkey refusal names what the rule guards: sign-in to
 *   an account holding a vault. No second factor opens a vault.
 * @changelog 1.11 - VaultSealedForBrowserException: a row sealed to a
 *   client-custody scope, which no server code reads.
 * @changelog 1.10 - the short caps are HARDENED_*_CAP_SECONDS: they ride with
 *   an add-on (mail's sending lock or relay sealing), not with a level.
 * @changelog 1.9 - the seam: secretKey() returns a VaultKey (PoolVaultKey today),
 *   open() takes an unlocker plus a wrap list and is the ONLY producer of a
 *   wrapping (spec B1); openKey() is the same without arming a window, for a
 *   rotation's old-generation key and the recovery-code probe; arm() makes a
 *   key the session's window; onReseal callbacks receive the old generation's
 *   VaultKey.
 * @changelog 1.8 - open()/close()/lock()/lockAll() record the window's arming
 *   and its end (with the reason) via VaultAudit; an APCu expiry, which runs no
 *   code of its own, is noticed and logged by the next read of the owning
 *   session (observeClosed()).
 * @changelog 1.7 - loadConsumerBootstraps() delegates to PluginBootstraps, the
 *   loader for every plugin's top-level `bootstrap` key; same call sites, same
 *   invariant, generalized beyond vault consumers.
 * @changelog 1.6 - window caps fail CLOSED end to end: a declared consumer
 *   bootstrap missing on disk folds the hardened caps into capsForUser(), and
 *   onWindowCaps()'s fail-closed default is the hardened caps rather than
 *   "abstain".
 * @changelog 1.5 - lock() drops VaultCrypto's memoized item DEKs, so keys
 *   unwrapped under a window cannot outlive it.
 * @changelog 1.4 - offerableCredentialIds(): which passkeys a scope's vault
 *   ceremony offers — the ones holding a wrapping, else all but the incapable.
 */

/** Thrown by a consumer's decrypt path when it needs the vault open but the
 *  window is closed. Generic hooks (the File decrypt hook, the sealed-field
 *  model hook) catch this and surface "locked", never an error. */
class VaultLockedException extends Exception {}

/** Thrown by a server read of a row sealed to a CLIENT-custody scope
 *  (a `v2.edgeseal.{scope}.` key). Not "wait for the window": no server code
 *  reads this row, ever — only the browser holding the scope's secret does.
 *  The API export is the one place that catches it, to hand the stored
 *  ciphertext to that browser (SystemBase::export_for_api()). */
class VaultSealedForBrowserException extends RuntimeException {}

/** Thrown by arm() when the window store refuses on this host (RamStore): no
 *  window can open, and the reason is in the log and on the vault health page. */
class VaultStoreUnavailableException extends RuntimeException {}

/** Thrown by an unlock when the unseal daemon is installed here and did not
 *  answer, before the five-minute fallback (vault_service_unavailable). A
 *  restart takes seconds: the person tries again, nothing was used up. */
class VaultServiceUnavailableException extends VaultStoreUnavailableException {
	public function __construct(string $message = '') {
		parent::__construct($message !== '' ? $message : 'The vault service on this server is restarting. '
			. 'Wait a minute and unlock again; nothing was used up.');
	}
}

/** Thrown by an unlock while root holds this site's unseal daemon (the
 *  sentinel's drop and hold, vault_held): no vault opens here until it lifts. */
class VaultHeldException extends VaultStoreUnavailableException {
	public function __construct(string $message = '') {
		parent::__construct($message !== '' ? $message : 'Vaults on this server are on a security hold, so none can be '
			. 'unlocked here until the site\'s operator lifts it. Your passkeys and recovery codes are unaffected.');
	}
}

require_once(PathHelper::getIncludePath('includes/VaultKey.php'));
require_once(PathHelper::getIncludePath('includes/PoolVaultKey.php'));
require_once(PathHelper::getIncludePath('includes/DaemonVaultKey.php'));

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
	// Window caps — the mail consumer's window policy, applied generically here
	// as numbers passed to open(). Hardened (a member holds a resource with an
	// add-on whose protection depends on the window being closed — mail's sending
	// lock or relay sealing, docs/sealed_vault.md): end after 2h without a content
	// decrypt (idle) and unconditionally 24h after arming (absolute). Private: a
	// 7-day absolute backstop only. Defaults in code; they become settings only if
	// tuning is ever needed.
	const HARDENED_IDLE_CAP_SECONDS = 7200;      // 2 hours
	const HARDENED_ABSOLUTE_CAP_SECONDS = 86400; // 24 hours
	const PRIVATE_ABSOLUTE_CAP_SECONDS = 604800; // 7 days

	/** @var callable[] consulted, in registration order, by the rotation ceremony. */
	private static $reseal_callbacks = array();

	/** @var array<int,string[]> spl_object_id of a modelReseal() callback => the classes it re-seals */
	private static $model_reseal_classes = array();

	/** True while VaultChainSweep runs the re-seal callbacks for a retired key (sweepingPreviousKey). */
	private static $sweeping_previous_key = false;

	/** @var callable[] consulted, in registration order, whenever a window closes. */
	private static $wipe_callbacks = array();

	/** @var array<int,array{provider:callable, fail_closed:array}> window-length policy, see onWindowCaps(). */
	private static $window_cap_providers = array();

	/**
	 * @var bool While set, secretKey() hands back the key WITHOUT counting as
	 * user activity — no TTL re-store, no marker touch, no content stamp.
	 *
	 * Deferred work (VaultDeferredWork) decrypts on a timer the user did not
	 * initiate. Left as ordinary activity it would hold a window open forever
	 * for someone who walked away from an open tab, so the hardened idle cap —
	 * which measures from the last content decrypt — would stop existing. The
	 * flag is set for the duration of a drain slice only, via
	 * VaultDeferredWork::withBackgroundWork(). Every other policy check still
	 * runs, so a suppressed read still fails closed on a lapsed window.
	 */
	private static $activity_suppressed = false;

	/**
	 * @var array{key_id:int, user_id:int}|null Set by ApiAuth when the request
	 * authenticated with an app credential whose purpose borrows the unlock: the
	 * request's window is that credential's slot, not a browser session's.
	 */
	private static $credential_window = null;

	/**
	 * The session-id position of a credential's slot. `~` cannot appear in a
	 * PHP session id, so no browser session can name a credential's slot.
	 */
	const CREDENTIAL_SLOT_PREFIX = '~apk';

	/**
	 * @var array<string, array> the windows this request has read, by slot key:
	 *   key (PoolVaultKey or DaemonVaultKey), slot_id and meta_id (RamStore::info() ids of the
	 *   files as read), meta, accounted (when this process last extended it),
	 *   seen (when it was last asked for: an entry not asked for in
	 *   ACCOUNT_EVERY_SECONDS is dropped, so a long process does not keep keys
	 *   of windows that closed elsewhere).
	 */
	private static $memo = array();

	/** A request extends a window at most once in this many seconds (account()). */
	const ACCOUNT_EVERY_SECONDS = 60;

	/**
	 * How long the unseal daemon must have gone unanswered (as this site saw
	 * it) before an unlock takes the pool path (spec § the ladder, rung 3).
	 */
	const POOL_RUNG_SECONDS = 300;

	/** Store entry: when this site first found its daemon not answering; gone while it answers. */
	const DAEMON_DOWN_KEY = 'unsealdown';

	/** Microseconds an unlock waits before its one retry of a daemon that did not answer. Tests shorten it. */
	public static $daemon_retry_us = 3000000;

	/** @var ?array tests: the install record to use instead of this site's ('pool' forces the pool) */
	private static $daemon_override = null;

	/** @var array<string,DaemonVaultKey> daemon keys opened this request that no window holds, by id */
	private static $unarmed = array();

	/** Whether the shutdown closer of $unarmed is registered. */
	private static $closer_registered = false;

	/** Whether this process already logged that it holds keys in the pool. */
	private static $pool_logged = false;

	/**
	 * Load every plugin bootstrap and core consumer bootstrap — lazy, once per
	 * request, called by every code path that needs a consumer's hooks live: the
	 * File decrypt-hook resolution (File::serve_from_path()), the rotation
	 * ceremony (resealCallbacks()), window-close (runWipeCallbacks()), the
	 * window-cap fold (capsForUser()), and the non-vault registries
	 * (UploadPurposeRegistry, MailIdentityGuard, the Direct hooks).
	 *
	 * The mechanism lives in PluginBootstraps (docs/plugin_developer_guide.md
	 * § Bootstrap): who loads comes from the top-level `bootstrap` key in each
	 * active plugin's plugin.json plus the core entries in vault_consumers.json;
	 * order comes from `vaultConsumer.order`; each load is attributed for the
	 * obligation accounting. This wrapper exists so vault code paths need not
	 * know the loader moved — the two names are the same load.
	 */
	public static function loadConsumerBootstraps(): void {
		require_once(PathHelper::getIncludePath('includes/PluginBootstraps.php'));
		PluginBootstraps::load();
	}

	/**
	 * Unwrap (or mint) a vault key WITHOUT making it the session's window —
	 * the key holder's `open` operation on its own. Two uses need exactly this:
	 * the rotation ceremony, whose old-generation key must be usable by every
	 * resealer and must never become the window, and the recovery-code and
	 * bypass-phrase probes, which try each wrapping until one opens.
	 *
	 * $unlocker is ['wrapped', 'kek', 'ad'] — the row's wrapping, the KEK the
	 * presented credential derived, and that row's AD
	 * (UserEncryptionWrapping::unlocker()) — or null to mint a fresh keypair
	 * (setup, rotation). $wrap_under is a list of ['kek', 'ad'] entries
	 * (UserEncryptionWrapping::wrapEntry()); the returned `wrappings` sit
	 * under the same keys, in order. This call is the ONLY way a wrapping is
	 * ever produced (specs/unseal_daemon.md B1): the secret is wrapped only in
	 * the request that presented a real unlocker for it, or that minted it.
	 *
	 * $user_id and $scope tag the key for the holder; the pool path needs
	 * neither, the daemon path files the window under them. $reveal is a list
	 * of sealed chain KEKs, with an unlocker only; each comes back under
	 * `revealed` as ['kek' => ?string, 'scope' => string] (the rotation's
	 * re-seal of the chain). $caps sets the daemon window's clocks (null: the
	 * user's, capsForUser()).
	 *
	 * Where the daemon runs for this site the key is a DaemonVaultKey: the
	 * window is the daemon's, and one no session's window takes (arm()) is
	 * closed when the request ends. Elsewhere it is a PoolVaultKey.
	 *
	 * @return array{key: VaultKey, wrappings: string[], revealed: array}
	 * @throws RuntimeException when the unlocker does not open the wrapping
	 * @throws VaultServiceUnavailableException|VaultHeldException (both VaultStoreUnavailableException)
	 */
	public static function openKey(int $user_id, ?array $unlocker, array $wrap_under = array(),
			string $scope = 'user', array $reveal = array(), ?array $caps = null, bool $rescope_chain = true): array {
		// A chain written before the previous-key scope existed is re-sealed under
		// it by the first open that presents an unlocker (spec B2): the reveal
		// list hands its KEKs over in this request only.
		$rescope = array();
		if ($rescope_chain && $unlocker !== null && !$reveal && $scope === UserEncryptionVault::SCOPE_USER && $user_id > 0) {
			$rescope = VaultPreviousKeys::unscoped($user_id);
			$reveal = $rescope;
		}
		$rec = self::daemonRecord();
		if ($rec === null) {
			self::notePoolPath('the unseal daemon is not installed for this site, or this process is not its pool user');
			$opened = PoolVaultKey::open($unlocker, $wrap_under, $reveal);
		} else {
			$opened = self::openInDaemon($rec, $user_id, $unlocker, $wrap_under, $scope, $reveal, $caps);
		}
		if ($rescope) {
			VaultPreviousKeys::rescope($user_id, $rescope, $opened['revealed'], $opened['key']->publicKey());
			foreach (array_keys($rescope) as $slot) {
				unset($opened['revealed'][$slot]);
			}
		}
		return $opened;
	}

	/**
	 * The ladder's first and third rungs at an open (spec § When the daemon is
	 * missing or down): held refuses; a daemon that does not answer is asked
	 * once more after $daemon_retry_us; then the open refuses as
	 * vault_service_unavailable until the daemon has been silent for
	 * POOL_RUNG_SECONDS, after which it takes the pool path.
	 */
	private static function openInDaemon(array $rec, int $user_id, ?array $unlocker, array $wrap_under,
			string $scope, array $reveal, ?array $caps): array {
		if (UnsealDaemon::held()) {
			throw new VaultHeldException();
		}
		[$idle, $absolute] = self::daemonClocks($user_id, $caps);
		for ($attempt = 0; ; $attempt++) {
			try {
				$opened = DaemonVaultKey::open($rec['socket'], $user_id, $scope, $idle, $absolute, $unlocker, $wrap_under, $reveal);
				break;
			} catch (DaemonUnreachableException $e) {
				// The retry is for a restart; a daemon already silent past the
				// pool rung has outlived one, so the open goes straight on.
				$down = self::daemonDownSince();
				$past_rung = $down !== null && time() - $down >= self::POOL_RUNG_SECONDS;
				if ($attempt === 0 && !$past_rung) {
					usleep(self::$daemon_retry_us);
					continue;
				}
				$down_since = self::noteDaemonDown();
				if (time() - $down_since >= self::POOL_RUNG_SECONDS && !UnsealDaemon::held()) {
					self::notePoolPath('the unseal daemon has not answered since ' . gmdate('c', $down_since), true);
					return PoolVaultKey::open($unlocker, $wrap_under, $reveal);
				}
				throw new VaultServiceUnavailableException();
			} catch (DaemonBusyException $e) {
				if ($attempt === 0) {
					usleep(self::$daemon_retry_us);
					continue;
				}
				throw new VaultServiceUnavailableException('The vault service on this server is busy. Wait a moment and unlock again; nothing was used up.');
			}
		}
		RamStore::delete(self::DAEMON_DOWN_KEY, RamStore::LIFETIME);
		self::$unarmed[$opened['key']->id()] = $opened['key'];
		self::registerCloser();
		return $opened;
	}

	/**
	 * openKey() for a request that needs the open's wrappings or revealed KEKs
	 * and no key: a retired generation wrapped into the chain, the rotation's
	 * reveal, a probe. The daemon's window, if one opened, is closed before
	 * this returns.
	 *
	 * @return array{wrappings: string[], revealed: array, public: string}
	 */
	public static function openOnce(int $user_id, ?array $unlocker, array $wrap_under = array(),
			array $reveal = array(), string $scope = 'user'): array {
		$opened = self::openKey($user_id, $unlocker, $wrap_under, $scope, $reveal, null, false);
		self::release($opened['key']);
		return array('wrappings' => $opened['wrappings'], 'revealed' => $opened['revealed'], 'public' => $opened['key']->publicKey());
	}

	/** Close a key openKey() returned that no window will hold. Nothing to do for a pool key. */
	public static function release(VaultKey $key): void {
		if ($key instanceof DaemonVaultKey && !$key->isArmed()) {
			unset(self::$unarmed[$key->id()]);
			$key->close();
		}
	}

	/**
	 * This site's daemon as this process may use it: the install record, when
	 * the installer wrote one and this process is the pool user it serves
	 * (any other process, a shell as root or a test user, holds keys in the
	 * pool as it always did). Null otherwise.
	 */
	public static function daemonRecord(): ?array {
		if (self::$daemon_override !== null) {
			return self::$daemon_override === 'pool' ? null : self::$daemon_override;
		}
		$rec = UnsealDaemon::installRecord();
		if ($rec === null) {
			return null;
		}
		$euid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
		return $euid === $rec['allow_uid'] ? $rec : null;
	}

	/** Tests: use $record (socket, allow_uid) as the install record, 'pool' for none, null for the site's own. */
	public static function useDaemonForTests($record): void {
		if (PHP_SAPI !== 'cli') {
			throw new RuntimeException('VaultUnlock::useDaemonForTests() is for tests run from the command line.');
		}
		self::$daemon_override = $record;
		DaemonVaultKey::resetClientsForTests();
		self::$memo = array();
	}

	/** When this site first found its daemon silent (now, if this is the first time). */
	private static function noteDaemonDown(): int {
		$since = RamStore::get(self::DAEMON_DOWN_KEY, $ok, RamStore::LIFETIME);
		if ($ok && is_int($since) && $since <= time()) {
			return $since;
		}
		$now = time();
		RamStore::set(self::DAEMON_DOWN_KEY, $now, 86400, RamStore::LIFETIME);
		return $now;
	}

	/** Since when this site has found its daemon silent, or null while it answers. */
	public static function daemonDownSince(): ?int {
		$since = RamStore::get(self::DAEMON_DOWN_KEY, $ok, RamStore::LIFETIME);
		return ($ok && is_int($since)) ? $since : null;
	}

	/** The pool path's log line: once per process, or every time for the fallback. */
	private static function notePoolPath(string $why, bool $fallback = false): void {
		if ($fallback) {
			error_log('VaultUnlock: a vault key is held in the PHP pool, not the unseal daemon: ' . $why);
			return;
		}
		if (!self::$pool_logged && UnsealDaemon::installRecord() === null && PHP_SAPI !== 'cli') {
			self::$pool_logged = true;
			error_log('VaultUnlock: vault keys are held in the PHP pool on this site: ' . $why);
		}
	}

	/**
	 * The daemon's two clocks for a window of $user_id: one idle clock, the
	 * smaller of the idle setting and the hardened idle cap, and the absolute
	 * cap (0: the daemon's own seven-day ceiling).
	 *
	 * @return array{0:int,1:int}
	 */
	private static function daemonClocks(int $user_id, ?array $caps): array {
		if ($caps === null) {
			$caps = self::capsForUser($user_id);
		}
		$idle = self::idleSeconds();
		if (!empty($caps['idle'])) {
			$idle = min($idle, (int)$caps['idle']);
		}
		$absolute = empty($caps['absolute']) ? 0 : (int)$caps['absolute'];
		return array(max(60, min($idle, self::PRIVATE_ABSOLUTE_CAP_SECONDS)), max(0, min($absolute, self::PRIVATE_ABSOLUTE_CAP_SECONDS)));
	}

	/**
	 * Close, when the request ends, every daemon key it opened that no window
	 * holds (a rotation's old generation, a probe). Two stages: a drain this
	 * request runs after its answer is registered as a shutdown function too,
	 * and must finish with the old key first.
	 */
	private static function registerCloser(): void {
		if (self::$closer_registered) {
			return;
		}
		self::$closer_registered = true;
		register_shutdown_function(function () {
			register_shutdown_function(function () {
				foreach (self::$unarmed as $id => $key) {
					unset(self::$unarmed[$id]);
					if (!$key->isArmed()) {
						$key->close();
					}
				}
				self::$closer_registered = false;
			});
		});
	}

	/**
	 * Make $key this session's window for (user, scope). $caps carries the
	 * end-event policy for this window: ['idle'=>?seconds, 'absolute'=>?seconds,
	 * 'heartbeat'=>bool]. When null it is resolved from the user's max mail
	 * security level (capsForUser) — the mail consumer's window policy — so every
	 * existing caller gets the caps automatically. Records the arming metadata a
	 * read-time check enforces.
	 */
	public static function arm(int $user_id, VaultKey $key, string $scope = 'user', ?array $caps = null,
			string $via = VaultAudit::VIA_UNKNOWN): void {
		if (!$key instanceof PoolVaultKey && !$key instanceof DaemonVaultKey) {
			throw new RuntimeException('VaultUnlock::arm: a window holds a pool key or a daemon handle.');
		}
		if ($caps === null) {
			$caps = self::capsForUser($user_id);
		}
		$sid = self::currentSessionId();
		$now = time();
		self::storeWindow($key, $sid, $user_id, $scope, array(
			'armed'      => $now,
			'content'    => $now,           // last content decrypt (idle cap basis)
			'hb'         => null,           // heartbeat: null until a surface monitors
			'idle_cap'   => $caps['idle'] ?? null,
			'abs_cap'    => $caps['absolute'] ?? null,
		));
		self::touchWindowMarker($user_id, $scope);
		self::rememberOpen($user_id, $scope, $via, $now);
		VaultAudit::opened($user_id, $scope, $via, $caps, $sid);

		// A person's unlock on the website also opens their app credentials'
		// borrowed windows. Only the server-custody `user` scope is borrowed,
		// and only from a person's own unlock — never from inside a borrowed
		// window, which can arm nothing.
		if ($scope === 'user' && self::$credential_window === null) {
			self::armBorrowers($user_id, $key, $caps, $now);
		}
	}

	/**
	 * Write a window's key slot and metadata. Throws, leaving no window, when
	 * the store refuses: an unlock then reports the vault service unavailable.
	 */
	private static function storeWindow(VaultKey $key, string $sid, int $user_id, string $scope, array $meta): void {
		$slot = self::slotKey($sid, $user_id, $scope);
		unset(self::$memo[$slot]);
		$replaced = self::slotKeyIn($slot);
		if (!RamStore::set($slot, $key->slotValue(), self::idleSeconds(), RamStore::LIFETIME)
				|| !RamStore::set(self::metaKey($sid, $user_id, $scope), $meta, self::idleSeconds(), RamStore::LIFETIME)) {
			RamStore::delete($slot, RamStore::LIFETIME);
			throw new VaultStoreUnavailableException(self::storeMessage(RamStore::status()));
		}
		if ($key instanceof DaemonVaultKey) {
			$key->markArmed();
			unset(self::$unarmed[$key->id()]);
		}
		// A re-unlock replaces the slot's window: the daemon's old one goes
		// when nothing else holds it.
		if ($replaced instanceof DaemonVaultKey && !($key instanceof DaemonVaultKey && $key->sameWindow($replaced))) {
			self::releaseHandle($user_id, $replaced);
		}
	}

	/** The key a slot holds (a pool key or a daemon handle), or null. Reads; deletes nothing. */
	private static function slotKeyIn(string $slot): ?VaultKey {
		$value = RamStore::get($slot, $ok, RamStore::LIFETIME);
		if (!$ok || !is_string($value)) {
			return null;
		}
		if (strncmp($value, DaemonVaultKey::SLOT_TAG, strlen(DaemonVaultKey::SLOT_TAG)) === 0) {
			$rec = self::daemonRecord();
			return $rec === null ? null : DaemonVaultKey::fromSlotValue($value, $rec['socket']);
		}
		return PoolVaultKey::fromSlotValue($value);
	}

	/**
	 * Close a daemon window no slot of $user_id holds any more (borrowed slots
	 * share their person's handle). Returns the daemon's [closed, unwraps], or
	 * null when something still holds it or the daemon did not answer.
	 */
	private static function releaseHandle(int $user_id, DaemonVaultKey $key): ?array {
		$pattern = '/^vault:[^:]*:' . preg_quote((string)$user_id, '/') . ':[^:]*$/';
		foreach (RamStore::keys('vault:', RamStore::LIFETIME) as $name) {
			if (!preg_match($pattern, $name)) {
				continue;
			}
			$value = RamStore::get($name, $ok, RamStore::LIFETIME);
			$other = ($ok && is_string($value)) ? DaemonVaultKey::fromSlotValue($value, '') : null;
			if ($other !== null && $other->sameWindow($key)) {
				return null;
			}
		}
		return $key->close();
	}

	/**
	 * The daemon no longer holds $key's window (DaemonVaultKey saw
	 * unknown_handle): close every slot this request read that held it, with
	 * the reason it ended, so the read reads as locked and the audit says why.
	 * A slot that names another window by now (the session unlocked again
	 * since this request read it) is that window's, and stays.
	 */
	public static function windowEnded(DaemonVaultKey $key): void {
		unset(self::$unarmed[$key->id()]);
		$reason = null;
		foreach (self::$memo as $slot => $state) {
			if (!($state['key'] instanceof DaemonVaultKey) || !$state['key']->sameWindow($key)
					|| !preg_match('/^vault:([^:]*):(\d+):([^:]*)$/', $slot, $m)) {
				continue;
			}
			unset(self::$memo[$slot]);
			$now = self::slotKeyIn($slot);
			if (!($now instanceof DaemonVaultKey) || !$now->sameWindow($key)) {
				continue;
			}
			$reason = $reason ?? self::daemonEndReason($key);
			self::lock((int)$m[2], $m[1], $m[3], $reason);
		}
	}

	/**
	 * Why the daemon no longer holds a window: root's drop and hold, a
	 * restart since it opened, or the daemon's own clocks.
	 */
	private static function daemonEndReason(DaemonVaultKey $key): string {
		$rec = self::daemonRecord();
		$f = $rec === null ? array() : DaemonVaultKey::client($rec['socket'])->status()['fields'];
		if (($f['ok'] ?? false) === true) {
			if (($f['held'] ?? false) === true) {
				return VaultAudit::REASON_SENTINEL_DROP;
			}
			if (isset($f['uptime']) && (int)$f['uptime'] < time() - $key->openedAt()) {
				return VaultAudit::REASON_DAEMON_RESTART;
			}
			return VaultAudit::REASON_IDLE_EXPIRED;
		}
		return VaultAudit::REASON_DAEMON_RESTART;
	}

	/**
	 * Throw VaultStoreUnavailableException when no window could be stored here.
	 * A ceremony that spends or writes something before it arms (a recovery
	 * code, a rotation, a new vault) asks first, so a host whose store refuses
	 * turns it away before anything is used up.
	 */
	public static function assertStoreUsable(): void {
		$status = RamStore::status();
		if (!$status['ok']) {
			throw new VaultStoreUnavailableException(self::storeMessage($status));
		}
		// The daemon, where it runs here: held refuses, and a daemon that does
		// not answer refuses until the pool rung, so nothing is spent on an
		// unlock that cannot open (a recovery code above all).
		$rec = self::daemonRecord();
		if ($rec === null) {
			return;
		}
		if (UnsealDaemon::held()) {
			throw new VaultHeldException();
		}
		for ($attempt = 0; $attempt < 2; $attempt++) {
			$f = DaemonVaultKey::client($rec['socket'])->status()['fields'];
			if (($f['ok'] ?? false) === true) {
				RamStore::delete(self::DAEMON_DOWN_KEY, RamStore::LIFETIME);
				if (($f['held'] ?? false) === true) {
					throw new VaultHeldException();
				}
				return;
			}
			$down = self::daemonDownSince();
			if ($down !== null && time() - $down >= self::POOL_RUNG_SECONDS) {
				break; // past the rung: no retry, as at an open
			}
			if ($attempt === 0) {
				usleep(self::$daemon_retry_us);
			}
		}
		if (time() - self::noteDaemonDown() < self::POOL_RUNG_SECONDS) {
			throw new VaultServiceUnavailableException();
		}
	}

	private static function storeMessage(array $status): string {
		return 'The vault cannot be unlocked on this server right now: '
			. ($status['ok'] ? 'its memory store could not be written' : 'its memory store is refused (' . $status['reason'] . ')')
			. '. The site operator can see the reason on the vault health check.';
	}

	/**
	 * Arm $key into the slot of each live app credential of $user_id whose
	 * purpose borrows the unlock, with the same caps measured from $now.
	 * Re-arming (a second website unlock) restarts each slot's caps.
	 */
	private static function armBorrowers(int $user_id, VaultKey $key, array $caps, int $now): void {
		// A borrowed window is kept open by a program's checks, so the absolute
		// cap is the only thing that ends it while the program is running. A
		// person whose caps carry none (no consumer asks for one — no Private
		// mailbox) has nothing a program needs to borrow: no slot at all,
		// rather than one bounded by nothing.
		if (empty($caps['absolute'])) {
			return;
		}
		foreach (self::borrowingCredentials($user_id) as $credential) {
			$sid = self::CREDENTIAL_SLOT_PREFIX . $credential['id'];
			// The person's own window is open already. A borrowed slot the store
			// will not take is skipped, not the unlock undone: that program asks
			// again and gets the locked answer, as it would after an expiry.
			try {
				self::storeWindow($key, $sid, $user_id, 'user', array(
					'armed'    => $now,
					'content'  => $now,
					'hb'       => null,             // no heartbeat ever applies to a borrowed slot
					'idle_cap' => $caps['idle'] ?? null,
					'abs_cap'  => $caps['absolute'] ?? null,
				));
			} catch (VaultStoreUnavailableException $e) {
				error_log('VaultUnlock::armBorrowers: app credential ' . $credential['id'] . ' of user ' . $user_id
					. ' not armed: ' . $e->getMessage());
				continue;
			}
			// Remembered so an expiry nobody saw happen is still written down by
			// the credential's next request (observeClosed()).
			if (!RamStore::set(self::borrowMarkerKey($sid, $user_id, 'user'), $now,
					max(self::idleSeconds(), (int)($caps['absolute'] ?? 0)) + self::CLOSE_TOMBSTONE_SECONDS, RamStore::LIFETIME)) {
				error_log('VaultUnlock::armBorrowers: no armed marker for app credential ' . $credential['id'] . ' of user '
					. $user_id . ': its expiry will not reach the audit log');
			}
			VaultAudit::opened($user_id, 'user', VaultAudit::VIA_APP_BORROW, $caps, $sid);
		}
	}

	/**
	 * The live app credentials of $user_id that borrow the unlock.
	 *
	 * @return array<int, array{id:int, name:string, purpose:string}>
	 */
	private static function borrowingCredentials(int $user_id): array {
		$purposes = AppAccess::borrowingPurposes();
		if (!$purposes) {
			return array();
		}
		$keys = new MultiApiKey(array('user_id' => $user_id, 'app_credential' => true, 'deleted' => false));
		$out = array();
		foreach ($keys as $api_key) {
			if (!$api_key->get('apk_is_active') || !in_array($api_key->purpose(), $purposes, true)) {
				continue;
			}
			$out[] = array(
				'id'      => (int)$api_key->key,
				'name'    => (string)$api_key->get('apk_name'),
				'purpose' => (string)$api_key->purpose(),
			);
		}
		return $out;
	}

	/**
	 * Make this request read through an app credential's borrowed slot instead
	 * of a browser session's window. Called by ApiAuth once the credential has
	 * authenticated and its purpose is known to borrow; for the rest of the
	 * request every VaultUnlock read is that slot's.
	 */
	public static function useCredentialWindow(int $key_id, int $user_id): void {
		self::$credential_window = array('key_id' => $key_id, 'user_id' => $user_id);
	}

	/** Go back to the browser session's window. Tests only: a request never changes credential. */
	public static function leaveCredentialWindowForTests(): void {
		if (PHP_SAPI !== 'cli') {
			throw new RuntimeException('VaultUnlock::leaveCredentialWindowForTests() is for tests run from the command line.');
		}
		self::$credential_window = null;
	}

	/** The app credential whose borrowed slot this request reads, or null. */
	public static function credentialWindowKeyId(): ?int {
		return self::$credential_window === null ? null : self::$credential_window['key_id'];
	}

	/**
	 * A borrowing credential's check that decrypted nothing (a mail app asking
	 * "anything new?") still counts as activity: refresh the slot's idle time
	 * and content stamp as a content decrypt would (decision 2 in
	 * specs/mail_apps_plugin.md). Every policy check still runs first, so the
	 * absolute cap ends the window whatever the program does.
	 *
	 * Refuses to run anywhere but a borrowing credential's request: a browser's
	 * window is kept alive by content decrypts alone, and a beacon proving only
	 * presence must never extend it.
	 *
	 * @return bool whether the window is open
	 * @throws RuntimeException outside a borrowing credential's request
	 */
	public static function touchActivity(int $user_id, string $scope = 'user'): bool {
		if (self::$credential_window === null || self::$credential_window['user_id'] !== $user_id) {
			throw new RuntimeException('VaultUnlock::touchActivity: only a borrowing app credential\'s request may count its checks as activity.');
		}
		$sid = self::currentSessionIdOrNull();
		if (self::windowState($sid, $user_id, $scope) === null) {
			self::observeClosed($user_id, $scope);
			return false;
		}
		if (self::endedByPolicy($sid, $user_id, $scope)) {
			return false;
		}
		return self::account($sid, $user_id, $scope);
	}

	/**
	 * Every open borrowed window of $user_id, for the lock chip's
	 * "Also unlocked for: Laptop (Mail apps), until Oct 18" line. Reports
	 * without extending anything.
	 *
	 * @return array<int, array{key_id:int, name:string, purpose:string, label:string, ends_at:?int}>
	 *   ends_at is the absolute cap's end (unix time), or null when uncapped.
	 */
	public static function borrowedWindows(int $user_id): array {
		$out = array();
		foreach (self::borrowingCredentials($user_id) as $credential) {
			$sid = self::CREDENTIAL_SLOT_PREFIX . $credential['id'];
			if (!RamStore::exists(self::slotKey($sid, $user_id, 'user'), RamStore::LIFETIME)) {
				continue;
			}
			$meta = RamStore::get(self::metaKey($sid, $user_id, 'user'), $ok, RamStore::LIFETIME);
			$ends_at = null;
			if ($ok && is_array($meta)) {
				$now = time();
				if (!empty($meta['abs_cap']) && ($now - (int)$meta['armed']) > (int)$meta['abs_cap']) {
					continue; // past its cap; the next read closes it
				}
				if (!empty($meta['abs_cap'])) {
					$ends_at = (int)$meta['armed'] + (int)$meta['abs_cap'];
				}
			}
			$out[] = array(
				'key_id'  => $credential['id'],
				'name'    => $credential['name'],
				'purpose' => $credential['purpose'],
				'label'   => AppAccess::label($credential['purpose']),
				'ends_at' => $ends_at,
			);
		}
		return $out;
	}

	/**
	 * End every borrowed window of $user_id — the website's Lock button, which
	 * means "lock my mail", wherever it is being read.
	 */
	public static function lockBorrowed(int $user_id, string $reason = VaultAudit::REASON_EXPLICIT_LOCK): void {
		$pattern = '/^vault:' . preg_quote(self::CREDENTIAL_SLOT_PREFIX, '/') . '(\d+):'
			. preg_quote((string)$user_id, '/') . ':([^:]*)$/';
		$slots = array();
		foreach (RamStore::keys('vault:' . self::CREDENTIAL_SLOT_PREFIX, RamStore::LIFETIME | RamStore::INCLUDE_EXPIRED) as $name) {
			if (preg_match($pattern, $name, $m)) {
				$slots[] = array(self::CREDENTIAL_SLOT_PREFIX . $m[1], $m[2]);
			}
		}
		foreach ($slots as $slot) {
			self::lock($user_id, $slot[0], $slot[1], $reason);
		}
	}

	/** End one app credential's borrowed window (the credential was revoked). */
	public static function lockCredentialWindow(int $user_id, int $key_id,
			string $reason = VaultAudit::REASON_APP_REVOKED): void {
		self::lock($user_id, self::CREDENTIAL_SLOT_PREFIX . $key_id, 'user', $reason);
	}

	/** Store key remembering that a borrowed slot was armed (see observeClosed()). */
	private static function borrowMarkerKey(string $session_id, int $user_id, string $scope): string {
		return 'vaultbrw:' . $session_id . ':' . $user_id . ':' . $scope;
	}

	/**
	 * The ordinary unlock: openKey() then arm() — unwrap under the presented
	 * unlocker (or mint), wrap under $wrap_under, and make the result the
	 * current session's window. Returns what openKey() returns, so an
	 * enrolment stores the wrappings it asked for and a consumer can use the
	 * key at once.
	 *
	 * @return array{key: VaultKey, wrappings: string[]}
	 */
	public static function open(int $user_id, ?array $unlocker, array $wrap_under = array(),
			string $scope = 'user', ?array $caps = null, string $via = VaultAudit::VIA_UNKNOWN): array {
		if ($caps === null) {
			$caps = self::capsForUser($user_id);
		}
		$opened = self::openKey($user_id, $unlocker, $wrap_under, $scope, array(), $caps);
		try {
			self::arm($user_id, $opened['key'], $scope, $caps, $via);
		} catch (Throwable $e) {
			self::release($opened['key']);
			throw $e;
		}
		return $opened;
	}

	/**
	 * Register a provider of end-event caps for the shared server-custody window.
	 *
	 * Every server-custody consumer shares one window, so its length is a
	 * platform-wide decision that any consumer may have an opinion about. A
	 * consumer registers from its bootstrap; core knows nothing about what the
	 * opinion is based on.
	 *
	 * @param callable $provider          fn(int $user_id): array{idle:?int, absolute:?int}
	 * @param array    $fail_closed_caps  contributed instead when $provider throws.
	 *   Fail CLOSED is the rule: an error resolving a policy must never hand an
	 *   uncapped window to someone who may have configured the strictest one, so a
	 *   provider declares the caps its own failure should imply. The DEFAULT is
	 *   therefore the hardened caps — the strictest policy the platform knows — so
	 *   a consumer that registers without thinking about its failure mode gets the
	 *   safe direction, not an uncapped window. A provider whose failure genuinely
	 *   implies no cap must say so explicitly with array('idle' => null,
	 *   'absolute' => null).
	 */
	public static function onWindowCaps(callable $provider, array $fail_closed_caps = array(
			'idle' => self::HARDENED_IDLE_CAP_SECONDS,
			'absolute' => self::HARDENED_ABSOLUTE_CAP_SECONDS)): void {
		self::$window_cap_providers[] = array('provider' => $provider, 'fail_closed' => $fail_closed_caps);
	}

	/**
	 * The end-event caps for a user: every registered provider folded by taking
	 * the STRICTEST value per field — the minimum non-null idle, the minimum
	 * non-null absolute.
	 *
	 * Strictest-wins is the only defensible fold. One shared window cannot honor
	 * two different lengths, and a member who configured a tight window on any
	 * consumer expressed a preference about their unlock window as a whole.
	 *
	 * With no providers registered the window is uncapped, which is the correct
	 * answer for an instance whose consumers all have no window policy — but ONLY
	 * when every declared bootstrap actually loaded. A declared bootstrap missing
	 * on disk (a partial deploy) may have been the one carrying the strictest
	 * policy, so its absence folds in the hardened caps rather than silently
	 * widening anyone's window: a member already under them sees no difference, anyone
	 * else gets a tighter-than-usual window until the deploy is fixed.
	 *
	 * @return array{idle:?int, absolute:?int}
	 */
	public static function capsForUser(int $user_id): array {
		self::loadConsumerBootstraps();

		$caps = array('idle' => null, 'absolute' => null);
		if (!empty(PluginBootstraps::notLoaded())) {
			$caps = array(
				'idle'     => self::HARDENED_IDLE_CAP_SECONDS,
				'absolute' => self::HARDENED_ABSOLUTE_CAP_SECONDS,
			);
		}
		foreach (self::$window_cap_providers as $entry) {
			try {
				$contribution = call_user_func($entry['provider'], $user_id);
			} catch (\Throwable $e) {
				error_log('VaultUnlock::capsForUser: a window-cap provider failed for user '
					. $user_id . ' - applying its fail-closed caps: ' . $e->getMessage());
				$contribution = $entry['fail_closed'];
			}
			if (!is_array($contribution)) {
				continue;
			}
			foreach (array('idle', 'absolute') as $field) {
				$value = $contribution[$field] ?? null;
				if ($value === null) {
					continue;
				}
				$value = (int)$value;
				if ($caps[$field] === null || $value < $caps[$field]) {
					$caps[$field] = $value;
				}
			}
		}
		return $caps;
	}

	public static function isOpen(int $user_id, string $scope = 'user'): bool {
		$sid = self::currentSessionIdOrNull();
		if ($sid === null) {
			return false;
		}
		if (self::windowState($sid, $user_id, $scope) === null) {
			self::observeClosed($user_id, $scope);
			return false;
		}
		return !self::endedByPolicy($sid, $user_id, $scope);
	}

	/**
	 * The in-window vault key, or null when locked. Every content read calls
	 * this and treats null as "locked" — a one-tap unlock prompt, never an
	 * error. Extends the window (activity extension) and stamps the
	 * content-decrypt time the hardened idle cap measures from, once per
	 * request (account()).
	 *
	 * The key comes back as a VaultKey: hand it to VaultCrypto::openItemDek()
	 * or read publicKey(); there is nothing else to do with it. Code that only
	 * needs to know whether the window is open asks isOpen() instead.
	 */
	public static function secretKey(int $user_id, string $scope = 'user'): ?VaultKey {
		$sid = self::currentSessionIdOrNull();
		if ($sid === null) {
			return null; // no session (a CLI/cron reader) can never hold a window - locked, not an error
		}
		$state = self::windowState($sid, $user_id, $scope);
		if ($state === null) {
			self::observeClosed($user_id, $scope);
			return null;
		}
		$key = $state['key'];
		if (self::endedByPolicy($sid, $user_id, $scope)) {
			return null;
		}
		// Deferred work reads the key on a timer the user did not initiate, so
		// it must not extend the window or restart the idle cap. Every check
		// above still applied — suppression skips the accounting, never the
		// policy (see $activity_suppressed).
		if (!self::$activity_suppressed && !self::account($sid, $user_id, $scope)) { // this fetch IS a content decrypt
			return null;
		}
		// Every open of sealed content needs this key first, so this is where the
		// hot-turn rule learns whose content the process could be holding. It does
		// not arm the rule — enrolment and rotation fetch keys without reading
		// anything — VaultCrypto::openField() does that.
		require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));
		SealedEgressGuard::noteScopeOpened($user_id, $scope);
		return $key;
	}

	/**
	 * The in-window key without counting a content read: for a collection's
	 * DEK prefetch (SystemBase::prefetchSealedDeks()), which opens keys ahead
	 * of the reads that will count. Every policy check still runs. Null when
	 * locked.
	 */
	public static function peekKey(int $user_id, string $scope = 'user'): ?VaultKey {
		$sid = self::currentSessionIdOrNull();
		if ($sid === null || self::windowState($sid, $user_id, $scope) === null) {
			return null;
		}
		if (self::endedByPolicy($sid, $user_id, $scope)) {
			return null;
		}
		$state = self::windowState($sid, $user_id, $scope);
		if ($state === null) {
			return null;
		}
		SealedEgressGuard::noteScopeOpened($user_id, $scope);
		return $state['key'];
	}

	/**
	 * A read of content the browser opens (the password vault's entries,
	 * Fortress mail, a Fortress Drive folder) is a content read the person
	 * asked for, the same thing secretKey() counts: with one clock
	 * (docs/sealed_vault.md § The lock chip) the browser-held vaults live as
	 * long as the account vault's window, so someone using only those keeps
	 * that window open. The action serving such rows calls this; it never
	 * opens anything itself.
	 *
	 * The same accounting as secretKey(), after the same policy checks: a
	 * browser session only (a borrowing credential refreshes through
	 * touchActivity()), and not under background work. Returns false when there
	 * is no open window to refresh.
	 */
	public static function noteClientContentRead(int $user_id): bool {
		if (self::$credential_window !== null || self::$activity_suppressed) {
			return false;
		}
		$sid = self::currentSessionIdOrNull();
		if ($sid === null) {
			return false;
		}
		$scope = UserEncryptionVault::SCOPE_USER;
		if (self::windowState($sid, $user_id, $scope) === null) {
			self::observeClosed($user_id, $scope);
			return false;
		}
		if (self::endedByPolicy($sid, $user_id, $scope)) {
			return false;
		}
		return self::account($sid, $user_id, $scope);
	}

	/**
	 * Set by VaultDeferredWork::withBackgroundWork() around a drain slice —
	 * see $activity_suppressed. Not for general use: anything a user actually
	 * asked for is activity and should extend their window.
	 */
	public static function setActivitySuppressed(bool $suppressed): void {
		self::$activity_suppressed = $suppressed;
	}

	/** True while background work is reading the key without counting as activity. */
	public static function isActivitySuppressed(): bool {
		return self::$activity_suppressed;
	}

	/**
	 * Heartbeat from a visible mail surface (specs/mailbox_security_levels.md
	 * § The Unlock Window). Marks the window monitored and fresh; once monitored,
	 * a read that finds the heartbeat stale beyond the grace interval ends it.
	 * Returns false when there is no window to beat (so the client stops).
	 */
	public static function heartbeat(int $user_id, string $scope = 'user'): bool {
		$sid = self::currentSessionIdOrNull();
		if ($sid === null) {
			return false;
		}
		// The beat is normally what NOTICES an expiry: it is the only thing
		// still asking once the human has gone, so it closes the audit trail
		// within one beat interval rather than whenever someone next visits.
		if (self::windowState($sid, $user_id, $scope) === null) {
			self::observeClosed($user_id, $scope);
			return false;
		}
		if (self::endedByPolicy($sid, $user_id, $scope)) {
			return false;
		}
		self::stampMeta($sid, $user_id, $scope, 'hb');
		self::touchWindowMarker($user_id, $scope);
		return true;
	}

	/** Store key for a window's arming/heartbeat metadata (companion to slotKey). */
	private static function metaKey(string $session_id, int $user_id, string $scope): string {
		return 'vaultmeta:' . $session_id . ':' . $user_id . ':' . $scope;
	}

	/**
	 * The open window for (session, user, scope), as this request last read it:
	 * ['key' => PoolVaultKey, 'meta' => ?array, ...], or null when there is
	 * none. Each call costs two stats; a slot or metadata file that is not the
	 * one read before (a lock, a re-arm, another request's stamp) is read
	 * again, so what comes back is always the current window.
	 */
	private static function windowState(?string $sid, int $user_id, string $scope): ?array {
		if ($sid === null) {
			return null;
		}
		$slot = self::slotKey($sid, $user_id, $scope);
		$now = time();
		foreach (self::$memo as $other => $entry) {
			if ($other !== $slot && $now - $entry['seen'] >= self::ACCOUNT_EVERY_SECONDS) {
				unset(self::$memo[$other]);
			}
		}
		$slot_info = RamStore::info($slot, RamStore::LIFETIME);
		if ($slot_info === null) {
			unset(self::$memo[$slot]);
			return null;
		}
		$state = self::$memo[$slot] ?? null;
		if ($state === null || $state['slot_id'] !== $slot_info['id']) {
			$value = RamStore::get($slot, $ok, RamStore::LIFETIME);
			$key = null;
			if ($ok && is_string($value) && strncmp($value, DaemonVaultKey::SLOT_TAG, strlen(DaemonVaultKey::SLOT_TAG)) === 0) {
				// A daemon window: only a process the daemon answers can use it, and
				// a slot another process cannot use is not this one's to delete.
				$rec = self::daemonRecord();
				if ($rec === null) {
					unset(self::$memo[$slot]);
					return null;
				}
				$key = DaemonVaultKey::fromSlotValue($value, $rec['socket']);
			} elseif ($ok && is_string($value)) {
				$key = PoolVaultKey::fromSlotValue($value);
			}
			if ($key === null) {
				if ($ok) {
					RamStore::delete($slot, RamStore::LIFETIME);
				}
				unset(self::$memo[$slot]);
				return null;
			}
			$state = array('key' => $key, 'slot_id' => $slot_info['id'], 'meta_id' => null, 'meta' => null, 'accounted' => 0);
		}
		$mkey = self::metaKey($sid, $user_id, $scope);
		$meta_info = RamStore::info($mkey, RamStore::LIFETIME);
		$meta_id = $meta_info === null ? null : $meta_info['id'];
		if ($meta_id !== $state['meta_id'] || ($meta_id !== null && $state['meta'] === null)) {
			$meta = $meta_id === null ? null : RamStore::get($mkey, $ok, RamStore::LIFETIME);
			$state['meta'] = ($meta_id !== null && $ok && is_array($meta)) ? $meta : null;
			$state['meta_id'] = $meta_id;
		}
		$state['seen'] = $now;
		self::$memo[$slot] = $state;
		return $state;
	}

	/**
	 * Count a content read: extend the slot, the open-window marker and the
	 * metadata to a full idle time, and stamp the content time the idle cap
	 * measures from. Once per request (within ACCOUNT_EVERY_SECONDS), unless
	 * the stored content stamp is older than this request's last extension:
	 * then something wrote it back, and the read counts again.
	 */
	private static function account(string $sid, int $user_id, string $scope): bool {
		$slot = self::slotKey($sid, $user_id, $scope);
		$state = self::$memo[$slot] ?? null;
		if ($state === null) {
			return false;
		}
		$now = time();
		$content = (int)($state['meta']['content'] ?? 0);
		if ($state['accounted'] > 0 && $now - $state['accounted'] < self::ACCOUNT_EVERY_SECONDS
				&& $content >= $state['accounted']) {
			return true;
		}
		// The daemon's idle clock moves with this one (one clock): a window the
		// daemon has ended is closed by the touch, and reads as locked.
		if ($state['key'] instanceof DaemonVaultKey && !$state['key']->touch()) {
			return false;
		}
		RamStore::touch($slot, self::idleSeconds(), RamStore::LIFETIME);
		self::touchWindowMarker($user_id, $scope);
		self::stampMeta($sid, $user_id, $scope, 'content');
		if (isset(self::$memo[$slot])) {
			self::$memo[$slot]['accounted'] = $now;
		}
		return true;
	}

	/** Update one metadata timestamp field to now, preserving the rest, for a full idle time. */
	private static function stampMeta(string $sid, int $user_id, string $scope, string $field): void {
		$state = self::windowState($sid, $user_id, $scope);
		if ($state === null || !is_array($state['meta'])) {
			return;
		}
		$meta = $state['meta'];
		$meta[$field] = time();
		$mkey = self::metaKey($sid, $user_id, $scope);
		if (!RamStore::set($mkey, $meta, self::idleSeconds(), RamStore::LIFETIME)) {
			return;
		}
		$slot = self::slotKey($sid, $user_id, $scope);
		$info = RamStore::info($mkey, RamStore::LIFETIME);
		self::$memo[$slot]['meta'] = $meta;
		self::$memo[$slot]['meta_id'] = $info === null ? null : $info['id'];
	}

	/**
	 * True when a policy end-event (absolute cap, hardened idle cap, or a stale
	 * heartbeat) has fired for this window — a READ-TIME check, no cron. Wipes the
	 * window when it fires so the caller sees it closed. No metadata → no policy
	 * (a legacy window or a non-capped consumer is never force-ended here).
	 */
	private static function endedByPolicy(string $sid, int $user_id, string $scope): bool {
		$state = self::windowState($sid, $user_id, $scope);
		$meta = $state === null ? null : $state['meta'];
		if (!is_array($meta)) {
			return false;
		}
		$now = time();
		$reason = null;
		if (!empty($meta['abs_cap']) && ($now - (int)($meta['armed'] ?? $now)) > (int)$meta['abs_cap']) {
			$reason = VaultAudit::REASON_ABSOLUTE_CAP;
		} elseif (!empty($meta['idle_cap']) && ($now - (int)($meta['content'] ?? $now)) > (int)$meta['idle_cap']) {
			$reason = VaultAudit::REASON_IDLE_CAP;
		} elseif (isset($meta['hb']) && $meta['hb'] !== null
				&& ($now - (int)$meta['hb']) > self::HEARTBEAT_MAX_STALE_SECONDS) {
			$reason = VaultAudit::REASON_HEARTBEAT_STALE;
		}
		if ($reason !== null) {
			self::lock($user_id, $sid, $scope, $reason);
			return true;
		}
		return false;
	}

	/** Close the current session's window. */
	public static function close(int $user_id, string $scope = 'user',
			string $reason = VaultAudit::REASON_EXPLICIT_LOCK, bool $audit = true): void {
		self::lock($user_id, self::currentSessionId(), $scope, $reason, $audit);
	}

	/**
	 * Wipe a specific session's window — the generic surface consumer-policy
	 * events call (a credential event, a heartbeat/IP-change trigger, an
	 * explicit lock button). Not necessarily the calling session.
	 *
	 * $audit false wipes the window without writing its close to the audit
	 * log: for an account that no longer exists, whose log went with it, a row
	 * would name a user that is not there.
	 */
	public static function lock(int $user_id, string $session_id, string $scope = 'user',
			string $reason = VaultAudit::REASON_EXPLICIT_LOCK, bool $audit = true): void {
		$slot = self::slotKey($session_id, $user_id, $scope);
		$meta = RamStore::get(self::metaKey($session_id, $user_id, $scope), $had_meta, RamStore::LIFETIME);
		$was_open = RamStore::exists($slot, RamStore::LIFETIME);
		$held = $was_open ? self::slotKeyIn($slot) : null;

		unset(self::$memo[$slot]);
		RamStore::delete($slot, RamStore::LIFETIME);
		RamStore::delete(self::metaKey($session_id, $user_id, $scope), RamStore::LIFETIME);
		RamStore::delete(self::borrowMarkerKey($session_id, $user_id, $scope), RamStore::LIFETIME);
		// The daemon's window ends with the last slot holding it (a borrowed
		// slot shares its person's), and says how many reads it served.
		$daemon = $held instanceof DaemonVaultKey ? self::releaseHandle($user_id, $held) : null;
		// Wiping the window has to take the DEKs unwrapped under it as well, or a
		// process that locked mid-request would still hold keys that open content.
		// Cleared wholesale, not per user: this runs once at lock, and rebuilding
		// another owner's entries costs one unwrap per row.
		if (class_exists('VaultCrypto', false)) {
			VaultCrypto::forgetItemDeks();
		}
		// Only a window that was actually open produces an audit row — lock() is
		// called defensively on paths where there may be nothing to close, and a
		// log full of closes for windows that never opened teaches nobody anything.
		if ($was_open) {
			$armed = ($had_meta && is_array($meta)) ? (int)($meta['armed'] ?? 0) : 0;
			if ($audit) {
				VaultAudit::closed($user_id, $scope, $reason, $session_id,
					$armed > 0 ? time() - $armed : null, $daemon === null ? null : $daemon['unwraps']);
			}
			self::noteClosed($user_id, $session_id, $scope, $reason);
		}
		self::runWipeCallbacks($user_id, $scope);
	}

	/**
	 * Wipe every scope's window, across every session, for a user (e.g. on
	 * password change). $keep is a key this request opened and is about to
	 * make the window (a recovery unlock ends every other window first): its
	 * daemon window is spared.
	 */
	public static function lockAll(int $user_id, string $reason = VaultAudit::REASON_CREDENTIAL_EVENT,
			?VaultKey $keep = null): void {
		$closed = array();
		// The window keys (vault:), their metadata (vaultmeta:), and a borrowed
		// slot's armed marker (vaultbrw:), found by name: no entry is opened.
		// Expired ones too: a slot past its expiry still holds the key until a
		// sweep zeroes it, and a credential event must not walk past it.
		$pattern = '/^vault(?:meta|brw)?:[^:]*:' . preg_quote((string)$user_id, '/') . ':[^:]*$/';
		$window = '/^vault:([^:]*):' . preg_quote((string)$user_id, '/') . ':([^:]*)$/';
		$names = array_values(array_filter(RamStore::keys('vault', RamStore::LIFETIME | RamStore::INCLUDE_EXPIRED),
			function ($name) use ($pattern) { return (bool)preg_match($pattern, $name); }));
		$handles = array();
		foreach ($names as $name) {
			if (preg_match($window, $name) && ($held = self::slotKeyIn($name)) instanceof DaemonVaultKey) {
				$handles[$held->id()] = $held;
			}
		}
		// One row per (session, scope) rather than a single summary: a
		// credential event that closes three devices at once should read as
		// three windows ending, which is what it was. An expired slot ended by
		// expiry, not by this event: wiped, not reported. How long each was
		// open comes from its metadata, read before anything is deleted.
		foreach ($names as $name) {
			if (preg_match($window, $name, $m) && RamStore::info($name, RamStore::LIFETIME) !== null) {
				$meta = RamStore::get('vaultmeta:' . $m[1] . ':' . $user_id . ':' . $m[2], $ok, RamStore::LIFETIME);
				$armed = ($ok && is_array($meta) && is_int($meta['armed'] ?? null)) ? $meta['armed'] : null;
				$held = self::slotKeyIn($name);
				$closed[] = array($m[1], $m[2], $armed === null ? null : time() - $armed,
					$held instanceof DaemonVaultKey ? $held->id() : null);
			}
		}
		foreach ($names as $name) {
			RamStore::delete($name, RamStore::LIFETIME);
		}
		self::$memo = array();
		// The daemon's windows the slots named, and this request's own unarmed
		// keys, one by one. Not the daemon's close-by-user: that would also end
		// a key another request holds without a window (a rotation's drain of
		// the old generation, running after its answer), and a handle no slot
		// names is reachable by nothing; it ends on its own clocks.
		$rec = self::daemonRecord();
		$unwraps = array();
		if ($rec !== null) {
			foreach ($handles + array_filter(self::$unarmed, function ($k) use ($user_id) { return $k->userId() === $user_id; })
					as $id => $key) {
				if (!($keep instanceof DaemonVaultKey && $key->sameWindow($keep))) {
					unset(self::$unarmed[$id]);
					$answer = $key->close();
					if ($answer !== null) {
						$unwraps[$id] = $answer['unwraps'];
					}
				}
			}
		}
		foreach (RamStore::keys('vaultwin:' . $user_id . ':') as $marker) {
			RamStore::delete($marker); // every scope's window is gone — the markers with them
		}
		foreach ($closed as $pair) {
			VaultAudit::closed($user_id, $pair[1], $reason, $pair[0], $pair[2],
				$pair[3] === null ? null : ($unwraps[$pair[3]] ?? null));
			self::noteClosed($user_id, $pair[0], $pair[1], $reason);
		}
		self::runWipeCallbacks($user_id, null);
	}

	/**
	 * True if ANY session currently holds an open window for this user/scope —
	 * unlike isOpen(), not scoped to the calling session. For a consumer's
	 * passive-close sweep task (e.g. plugins/mailbox/tasks/
	 * InboundMailboxSearchIndex::sweepWorkingCopies()): a disposable working copy tied to $user_id
	 * can be reclaimed once no session's window still covers it.
	 *
	 * The signal is the open-window marker, a plain store entry, NOT the window
	 * itself: the sweep runs under cron, which has no PHP-FPM master and so
	 * never sees the web's lifetime-bound entries. The marker (no secret, just
	 * existence and an expiry stamped by arm() and each content read) sits at
	 * the top of the site's store, where every process of the web user finds
	 * it. A single-session lock() leaves the marker (another session may hold a
	 * window), so it can outlive an explicit lock by up to one idle interval —
	 * the sweep is at worst delayed, never wrong.
	 */
	public static function hasAnyOpenWindow(int $user_id, string $scope = 'user'): bool {
		RamStore::get(self::windowMarkerKey($user_id, $scope), $open); // an expired marker is reclaimed
		return $open;
	}

	/** The cross-process open-window marker (see hasAnyOpenWindow()). */
	private static function windowMarkerKey(int $user_id, string $scope): string {
		$scope_safe = preg_replace('/[^a-z0-9_]/i', '_', $scope);
		return 'vaultwin:' . $user_id . ':' . $scope_safe;
	}

	/** Extend the marker to the window's current expiry (now + idle TTL). */
	private static function touchWindowMarker(int $user_id, string $scope): void {
		$marker = self::windowMarkerKey($user_id, $scope);
		if (!RamStore::touch($marker, self::idleSeconds())) {
			RamStore::set($marker, 1, self::idleSeconds());
		}
	}

	// ---- Audit bookkeeping (docs/sealed_vault.md § The audit log) ----
	//
	// A window can end three ways, and only two of them run any code: an
	// explicit lock and a policy cap both go through lock(), which writes the
	// row itself. The third — the store entry simply aging out — happens with
	// nothing to hook. So the closing row for an expiry has to be
	// written by whoever NOTICES, which means the session has to remember that
	// it had a window at all. That is what these three helpers carry.

	/** How long a close is remembered for the benefit of the session that owned it. */
	const CLOSE_TOMBSTONE_SECONDS = 900;

	/** This session armed a window; remember enough to attribute its end. */
	private static function rememberOpen(int $user_id, string $scope, string $via, int $armed): void {
		if (session_status() !== PHP_SESSION_ACTIVE) {
			return;
		}
		$_SESSION['vault_windows'][$user_id . ':' . $scope] = array('armed' => $armed, 'via' => $via);
	}

	/** Drop this session's record of a window, returning it if there was one. */
	private static function forgetOpen(int $user_id, string $scope): ?array {
		if (session_status() !== PHP_SESSION_ACTIVE) {
			return null;
		}
		$key = $user_id . ':' . $scope;
		$record = $_SESSION['vault_windows'][$key] ?? null;
		unset($_SESSION['vault_windows'][$key]);
		return is_array($record) ? $record : null;
	}

	/**
	 * A close has been logged. When the locking session is not the one that
	 * owns the window — lockAll() on a password change, a consumer policy event
	 * reaching across sessions — leave a tombstone, so when the owning session
	 * next looks and finds its key gone it reports what actually happened
	 * instead of assuming it idled out.
	 */
	private static function noteClosed(int $user_id, string $session_id, string $scope, string $reason): void {
		if ($session_id === self::currentSessionIdOrNull()) {
			self::forgetOpen($user_id, $scope);
			return;
		}
		RamStore::set(self::tombstoneKey($session_id, $user_id, $scope), $reason, self::CLOSE_TOMBSTONE_SECONDS, RamStore::LIFETIME);
	}

	/**
	 * This session's window key is gone. If something already logged the close,
	 * consume its tombstone and stay quiet; otherwise this is the store entry
	 * aging out, which nothing else will ever report.
	 *
	 * Costs nothing on the common path: a session that never opened a window has
	 * no record to find, and the check is an isset() on $_SESSION.
	 */
	private static function observeClosed(int $user_id, string $scope): void {
		if (self::$credential_window !== null) {
			// A borrowed slot has no PHP session to remember it by; arming left a
			// marker in the store instead. Present means armed and not yet
			// reported closed.
			$sid = self::currentSessionIdOrNull();
			$marker = self::borrowMarkerKey($sid, $user_id, $scope);
			$armed = RamStore::get($marker, $had_marker, RamStore::LIFETIME);
			if (!$had_marker) {
				return;
			}
			RamStore::delete($marker, RamStore::LIFETIME);
			$tombstone = self::tombstoneKey($sid, $user_id, $scope);
			if (RamStore::exists($tombstone, RamStore::LIFETIME)) {
				RamStore::delete($tombstone, RamStore::LIFETIME);
				return;
			}
			VaultAudit::closed($user_id, $scope, VaultAudit::REASON_IDLE_EXPIRED, $sid,
				is_int($armed) ? time() - $armed : null);
			return;
		}
		if (session_status() !== PHP_SESSION_ACTIVE
				|| !isset($_SESSION['vault_windows'][$user_id . ':' . $scope])) {
			return;
		}
		$sid = self::currentSessionIdOrNull();
		$record = self::forgetOpen($user_id, $scope);
		if ($sid !== null) {
			$tombstone = self::tombstoneKey($sid, $user_id, $scope);
			if (RamStore::exists($tombstone, RamStore::LIFETIME)) {
				RamStore::delete($tombstone, RamStore::LIFETIME);
				return; // lock()/lockAll() already wrote the row, with the real reason
			}
		}
		VaultAudit::closed($user_id, $scope, VaultAudit::REASON_IDLE_EXPIRED, $sid,
			$record ? time() - (int)$record['armed'] : null);
	}

	/** Marks a close for a session that was not the one doing the locking. */
	private static function tombstoneKey(string $session_id, int $user_id, string $scope): string {
		return 'vaultclosed:' . $session_id . ':' . $user_id . ':' . $scope;
	}

	/**
	 * Registers a callback consulted (in registration order) by the
	 * key-rotation ceremony (logic/vault_rotate_verify_logic.php): re-seal the
	 * consumer's own per-item DEKs from the old secret key to the new public
	 * key, bumping its per-item key-generation. Mirrors
	 * PasskeyService::onPreRevoke()'s registry mechanism.
	 *
	 * Callback signature: function(int $user_id, VaultKey $old_key,
	 * int $old_key_generation, string $new_public_key,
	 * int $new_key_generation): void
	 *
	 * Contract (docs/sealed_vault.md § Key rotation): re-seal EXACTLY the
	 * items whose per-item generation equals $old_key_generation — that is the
	 * generation $old_key belongs to, the only one it can open. Attempt
	 * every item, then THROW if any failed: the ceremony retires a
	 * generation's wrappings only when its consumers confirmed the drain, so a
	 * swallowed failure here would destroy the only path to that content.
	 */
	public static function onReseal(callable $callback): void {
		self::$reseal_callbacks[] = $callback;
		require_once(PathHelper::getIncludePath('includes/VaultConsumers.php'));
		VaultConsumers::noteRegistration(VaultConsumers::OBLIGATION_RESEAL);
	}

	/**
	 * A ready-made onReseal callback for the ordinary case: re-seal every sealed
	 * row of these models that sits on the draining generation.
	 *
	 *   VaultUnlock::onReseal(VaultUnlock::modelReseal([MailboxContact::class]));
	 *
	 * The sealed-field model hook already knows the four column names and how
	 * ownership resolves, so nearly every consumer's hand-written callback was
	 * the same loop with different table names in it. A consumer that also seals
	 * material outside model columns (mailbox's DKIM keys, Drive's blob keys)
	 * keeps its own callback and may register this one alongside.
	 *
	 * Fail-loud per the onReseal contract: every row is attempted, then any
	 * failure throws so the ceremony refuses to retire the old wrappings.
	 *
	 * @param string[] $model_classes SystemBase subclasses declaring $sealed_fields
	 */
	public static function modelReseal(array $model_classes): callable {
		$callback = function (int $user_id, VaultKey $old_key, int $old_key_generation,
				string $new_public_key, int $new_key_generation) use ($model_classes) {
			$attempted = 0;
			$failed = 0;
			$names = array();
			foreach ($model_classes as $class) {
				$result = $class::resealRows($user_id, $old_key, $old_key_generation,
					$new_public_key, $new_key_generation);
				$attempted += $result['attempted'];
				$failed += $result['failed'];
				if ($result['failed'] > 0) {
					$names[] = $class;
				}
			}
			if ($failed > 0) {
				throw new RuntimeException(
					'Vault reseal: ' . $failed . ' of ' . $attempted . ' sealed rows across '
					. implode(', ', $names) . ' could not be re-sealed; the old key generation '
					. 'must not be retired.');
			}
		};
		self::$model_reseal_classes[spl_object_id($callback)] = array_values($model_classes);
		return $callback;
	}

	/**
	 * The model classes a registered callback re-seals, when modelReseal() made
	 * it; null for a consumer's own callback. The chain sweep re-seals these in
	 * batches against its deadline instead of in one call.
	 */
	public static function modelResealClasses(callable $callback): ?array {
		return is_object($callback) ? (self::$model_reseal_classes[spl_object_id($callback)] ?? null) : null;
	}

	/**
	 * Whether the re-seal callbacks are running for the chain sweep: $old_key is
	 * a retired key from the vault's chain, not the key a rotation is retiring,
	 * so material its callback does not select by generation may already be on
	 * the current key. Such material that $old_key does not open is not a
	 * failure then; it has already moved.
	 */
	public static function sweepingPreviousKey(): bool {
		return self::$sweeping_previous_key;
	}

	/** Run $fn with sweepingPreviousKey() true. VaultChainSweep only. */
	public static function asPreviousKeySweep(callable $fn) {
		$was = self::$sweeping_previous_key;
		self::$sweeping_previous_key = true;
		try {
			return $fn();
		} finally {
			self::$sweeping_previous_key = $was;
		}
	}

	/** @var array<string,array{classes:string[],scripts:string[]}> client-custody resealers by scope */
	private static $client_reseals = array();

	/**
	 * Register what a CLIENT-custody scope's rotation must re-seal for this
	 * consumer. Only the browser holds that scope's secret, so the server cannot
	 * re-seal anything itself; this tells the rotation ceremony what to do:
	 *
	 *   - $model_classes: SystemBase models whose rows seal to the scope
	 *     (sealScopeForWrite). vault_client_reseal_rows pages their rows and the
	 *     browser re-seals each DEK through vault_row_reseal.
	 *   - $scripts: browser modules (public_html-relative) that register a
	 *     JoinerySealed.onReseal(scope, fn) hook for keys kept outside those
	 *     models. The page running the rotation loads them.
	 *
	 * Satisfies the consumer's `client_reseals` obligation for $scope.
	 *
	 *   VaultUnlock::clientReseal('acme_notes', array(AcmeNote::class));
	 */
	public static function clientReseal(string $scope, array $model_classes, array $scripts = array()): void {
		$entry = self::$client_reseals[$scope] ?? array('classes' => array(), 'scripts' => array());
		$entry['classes'] = array_values(array_unique(array_merge($entry['classes'], $model_classes)));
		$entry['scripts'] = array_values(array_unique(array_merge($entry['scripts'], $scripts)));
		self::$client_reseals[$scope] = $entry;
		require_once(PathHelper::getIncludePath('includes/VaultConsumers.php'));
		VaultConsumers::noteClientReseal($scope);
	}

	/** What a client-custody scope's rotation re-seals, from every loaded consumer. */
	public static function clientResealsFor(string $scope): array {
		self::loadConsumerBootstraps();
		return self::$client_reseals[$scope] ?? array('classes' => array(), 'scripts' => array());
	}

	/** Forget test-registered client resealers. Tests only. */
	public static function resetClientResealsForTests(): void {
		self::$client_reseals = array();
	}

	/** @var array<string,callable[]> client-custody rotation listeners by scope */
	private static $client_rotation_callbacks = array();

	/**
	 * Be told when a rotation of client-custody $scope begins and when it
	 * commits: $callback(int $user_id, string $phase), $phase 'begin' or
	 * 'commit', called after the change is stored. For a consumer that
	 * publishes the scope's sealing key elsewhere (mail's relay routing map),
	 * which changes at begin (UserEncryptionVault::sealingPublicKey() turns to
	 * the pending key) and again at commit.
	 */
	public static function onClientRotation(string $scope, callable $callback): void {
		self::$client_rotation_callbacks[$scope][] = $callback;
	}

	/**
	 * Tell $scope's rotation listeners. The rotation is already stored, so a
	 * listener that fails is logged and never undoes or refuses it.
	 */
	public static function clientRotationChanged(int $user_id, string $scope, string $phase): void {
		self::loadConsumerBootstraps();
		foreach (self::$client_rotation_callbacks[$scope] ?? array() as $callback) {
			try {
				$callback($user_id, $phase);
			} catch (\Throwable $e) {
				error_log('Client vault rotation listener (' . $scope . ', ' . $phase . ') failed: ' . $e->getMessage());
			}
		}
	}

	/** @var array<string,callable[]> client-custody rotation commit guards by scope */
	private static $client_rotation_guards = array();

	/**
	 * Be asked before a rotation of client-custody $scope commits:
	 * $guard(int $user_id) returns null to allow it, or a sentence saying why
	 * the old key cannot retire yet. For a consumer that has handed the old
	 * public key to something that may still seal to it (mail's relay).
	 */
	public static function onClientRotationCommit(string $scope, callable $guard): void {
		self::$client_rotation_guards[$scope][] = $guard;
	}

	/**
	 * Why $scope's rotation may not commit now, or null. A guard that fails
	 * refuses: retiring a key something still seals to loses what arrives.
	 */
	public static function clientRotationCommitRefusal(int $user_id, string $scope): ?string {
		self::loadConsumerBootstraps();
		foreach (self::$client_rotation_guards[$scope] ?? array() as $guard) {
			try {
				$why = $guard($user_id);
			} catch (\Throwable $e) {
				error_log('Client vault rotation guard (' . $scope . ') failed: ' . $e->getMessage());
				$why = 'The old key could not be checked as safe to retire. Try again in a minute.';
			}
			if (is_string($why) && $why !== '') {
				return $why;
			}
		}
		return null;
	}

	/** @return callable[] */
	public static function resealCallbacks(): array {
		self::loadConsumerBootstraps();
		return self::$reseal_callbacks;
	}

	/**
	 * Whether any passkey wrapping exists for this user's vault in this scope.
	 * False on a phrase-only vault (the compatibility fallback), where a
	 * passkey ceremony can never produce an unlock — the unlock and rotate
	 * option actions refuse with a pointer to the phrase instead of minting a
	 * ceremony that only lists credentials unable to open anything.
	 */
	public static function hasPasskeyRoute(int $user_id, string $scope = 'user'): bool {
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));
		$vault = UserEncryptionVault::loadForUser($user_id, $scope);
		if (!$vault) {
			return false;
		}
		$wrappings = new MultiUserEncryptionWrapping([
			'vault_id' => $vault->key,
			'unlocker_type' => UserEncryptionWrapping::TYPE_PASSKEY,
		]);
		return $wrappings->count_all() > 0;
	}

	/**
	 * Which passkeys a vault ceremony for this scope should put in front of the
	 * user. One rule, and it reads the same way for every caller:
	 *
	 *   Offer the credentials that hold a wrapping for this scope's vault. If
	 *   none do, this is an enrollment — offer everything except the ones known
	 *   to be incapable of ever unlocking a vault.
	 *
	 * The two halves answer different questions, and the first is much the
	 * stronger. "Which credentials hold a wrapping for this vault" is a stored
	 * fact about this vault, not an inference from what an authenticator said at
	 * registration — so unlock and rotate, the paths where a wrong answer means
	 * someone cannot reach their own sealed content, never consult
	 * Passkey::vault_capability() at all. Capability only decides where there is
	 * nothing to intersect with, which is first-time setup.
	 *
	 * A partially-rotated vault holds wrappings across generations; the offer is
	 * their union, because any of them still opens the vault.
	 *
	 * Client-custody scopes work without being special-cased: the server cannot
	 * read those KEKs, but it does store each scope's wrapping rows tagged with
	 * the credential id (VaultClientCustody::insertOpaqueWrapping()), so it knows
	 * WHICH credentials unlock the scope without knowing WHAT they unlock.
	 *
	 * @return int[] internal pkc row ids, or an empty array meaning "no opinion"
	 *   — never "offer nothing". getDerivationOptions() turns empty back into
	 *   every live credential, which is the safe direction: an empty
	 *   allowCredentials on the unlock path is a vault lockout.
	 */
	public static function offerableCredentialIds(int $user_id, string $scope = 'user'): array {
		require_once(PathHelper::getIncludePath('data/passkey_credentials_class.php'));
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
		require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));

		$vault = UserEncryptionVault::loadForUser($user_id, $scope);
		if ($vault) {
			$wrappings = new MultiUserEncryptionWrapping([
				'vault_id' => $vault->key,
				'unlocker_type' => UserEncryptionWrapping::TYPE_PASSKEY,
			]);
			$wrappings->load();
			$ids = [];
			foreach ($wrappings as $wrapping) {
				$credential_id = (int)$wrapping->get('uew_pkc_passkey_credential_id');
				if ($credential_id) {
					$ids[$credential_id] = true;
				}
			}
			if ($ids) {
				return array_keys($ids);
			}
		}

		// No wrappings: an enrollment. Drop only the credentials the platform
		// KNOWS can never derive a secret, so the browser stops listing keys it
		// would then refuse. `unknown` stays on offer — the ceremony is the real
		// capability test.
		$creds = new MultiPasskey(['user_id' => $user_id]);
		$creds->load();
		$ids = [];
		foreach ($creds as $passkey) {
			if ($passkey->vault_capability() !== Passkey::VAULT_INCAPABLE) {
				$ids[] = (int)$passkey->key;
			}
		}
		return $ids;
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
		PasskeyService::onPreRevoke(function (int $user_id, int $credential_id, array $context = []) {
			VaultUnlock::assertRevocationSafe($user_id, $credential_id, $context);
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
	 *
	 * $context carries ['admin_reset' => true] on the superadmin factor-reset
	 * path (PasskeyService::adminRevoke()). The floor above ignores it - a
	 * stranded client-custody vault is unrecoverable data loss and no
	 * authority overrides it. The possession-factor invariant below honors it:
	 * an administrator may knowingly remove a user's last factor, because the
	 * vault re-enrollment gate in SessionControl forces a replacement at that
	 * user's next page load.
	 */
	public static function assertRevocationSafe(int $user_id, int $credential_id, array $context = []): void {
		require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

		// Check EVERY scope's vault, not just the server-custody 'user' one — a
		// passkey enrolls a wrapping in each scope it unlocks (mail/chat, drive,
		// passwords), and revoking it must not strand any of them. The
		// client-custody password vault is the crown jewels; being the last
		// unlocker there is exactly the case the floor exists to catch.
		$vaults = new MultiUserEncryptionVault(['user_id' => $user_id]);
		$vaults->load();
		foreach ($vaults as $vault) {
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

		// Possession-factor invariant (the other edge is the disable-2FA gate
		// in security_logic): an account holding a vault always keeps a second
		// way to sign in. Revoking the last live passkey while TOTP is off would
		// leave it signing in with a password alone. This guards sign-in; no
		// second factor ever opens the vault. Skipped on the administrative reset
		// path: kept strictly there it deadlocks a user who lost every factor,
		// and the re-enrollment gate closes the exposure at their next page load.
		if ($vaults->count() && empty($context['admin_reset'])) {
			require_once(PathHelper::getIncludePath('data/users_class.php'));
			require_once(PathHelper::getIncludePath('data/passkey_credentials_class.php'));
			$user = new User($user_id, TRUE);
			if (!$user->has_totp_enabled()) {
				$live = new MultiPasskey(['user_id' => $user_id, 'deleted' => false]);
				$live->load();
				$remaining = 0;
				foreach ($live as $pk) {
					if ((int)$pk->key !== $credential_id) {
						$remaining++;
					}
				}
				if ($remaining === 0) {
					throw new PasskeyRevocationVetoException(
						'This is your last passkey and two-factor authentication is off - revoking it '
						. 'would leave you signing in with a password alone, and an account with an '
						. 'encrypted vault needs a second way to sign in. Enable two-factor '
						. 'authentication or add another passkey first.'
					);
				}
			}
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
	 *
	 * $exclude_wrapping_id excludes one wrapping ROW from the count - the row
	 * about to be deleted, whatever its type. Without it, removing a recovery
	 * wrapping would count the doomed row toward its own floor (exactly 3
	 * codes and no passkey would pass, then leave 2).
	 */
	public static function assertWrappingDeleteSafe(int $vault_id, ?int $exclude_credential_id = null, ?int $exclude_wrapping_id = null): void {
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
			if ($exclude_wrapping_id !== null && (int)$wrapping->key === $exclude_wrapping_id) {
				continue;
			}
			$type = $wrapping->get('uew_unlocker_type');
			if ($type === UserEncryptionWrapping::TYPE_PASSKEY) {
				$cred_id = (int)$wrapping->get('uew_pkc_passkey_credential_id');
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

		$wrappings = new MultiUserEncryptionWrapping(['credential_id' => $credential_id, 'include_reserved' => true]);
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
		require_once(PathHelper::getIncludePath('includes/VaultConsumers.php'));
		VaultConsumers::noteRegistration(VaultConsumers::OBLIGATION_CACHES);
	}

	private static function runWipeCallbacks(int $user_id, ?string $scope): void {
		self::loadConsumerBootstraps();
		foreach (self::$wipe_callbacks as $callback) {
			call_user_func($callback, $user_id, $scope);
		}
	}

	/** Store key for a window's key slot. */
	private static function slotKey(string $session_id, int $user_id, string $scope): string {
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

	/**
	 * Drop every registration and let the bootstraps load again. Tests only —
	 * a test that activates a plugin or writes a declaration needs the next
	 * loadConsumerBootstraps() to see it.
	 */
	public static function resetForTests(): void {
		self::$reseal_callbacks = array();
		self::$wipe_callbacks = array();
		self::$window_cap_providers = array();
		self::$activity_suppressed = false;
		self::$credential_window = null;
		self::$memo = array();
		self::$unarmed = array();
		if (session_status() === PHP_SESSION_ACTIVE) {
			unset($_SESSION['vault_windows']);
		}
		require_once(PathHelper::getIncludePath('includes/PluginBootstraps.php'));
		PluginBootstraps::resetForTests();
	}

	/** The session id, or null where none exists (a CLI/cron process). Readers
	 *  treat null as locked; only open() demands a real session. */
	private static function currentSessionIdOrNull(): ?string {
		if (self::$credential_window !== null) {
			return self::CREDENTIAL_SLOT_PREFIX . self::$credential_window['key_id'];
		}
		SessionControl::get_instance();
		$session_id = session_id();
		return $session_id ?: null;
	}
}
?>
