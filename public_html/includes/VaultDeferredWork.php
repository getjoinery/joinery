<?php
/**
 * VaultDeferredWork — work that can only run while a user's vault is unlocked
 * (specs/in_window_deferred_work.md).
 *
 * The vault secret key lives in APCu keyed to the browser session, so a CLI
 * process can never hold a window: VaultUnlock::secretKey() returns null under
 * the CLI SAPI by construction. Anything that must read sealed content
 * therefore cannot run from cron — it has to run inside a web request carrying
 * a live window for that user. This class is where that work is scheduled.
 *
 * Features register a cheap "is there anything to do?" predicate and a drain
 * callable. The browser's presence beacon reports work_pending on each
 * heartbeat and fires a separate drain request when the answer is yes; the
 * drain never runs inside the heartbeat itself (see § The heartbeat stays fast
 * below).
 *
 * Registration order is meaningful: mail parsing must precede AI judging,
 * because an unparsed message has no fields to read. Order is set by
 * VaultUnlock::CONSUMER_PLUGINS, which controls bootstrap load order.
 *
 * § The heartbeat stays fast. The keep-alive beat exists to hold the window
 * open and must never depend on a language model — the local provider's
 * timeout is measured in minutes, and a beat blocked that long would stack up
 * behind itself while the window it was meant to protect lapsed. So the beat
 * only answers hasWork(); the work happens in its own request.
 *
 * § Background work is not user activity. VaultUnlock::secretKey() normally
 * stamps the content-decrypt time the Fortress idle cap measures from. A drain
 * decrypting on every beat would hold a window open indefinitely for someone
 * who walked away from an open tab, silently removing the idle cap. Every
 * drain therefore runs inside withBackgroundWork(), which suppresses activity
 * stamping for the duration — including for consumer code that reaches
 * VaultUnlock::secretKey() on its own.
 *
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

class VaultDeferredWork {

	/** Fallback when the setting is absent. Seconds of wall clock per drain request. */
	const DEFAULT_SLICE_SECONDS = 10;

	/** Never hand a consumer less than this, however little of the slice is left. */
	const MIN_TURN_SECONDS = 1.0;

	/**
	 * @var array<string, array{has_work: callable, drain: callable}>
	 *      Registration order is preserved and is the execution order.
	 */
	private static $consumers = array();

	/** Guards the activity-suppression wrapper against nesting surprises. */
	private static $in_background_work = false;

	/**
	 * Register one consumer. Called from a plugin's includes/bootstrap.php,
	 * which VaultUnlock::loadConsumerBootstraps() loads once per request.
	 *
	 * @param string   $id        stable identifier, appears in logs and locks
	 * @param callable $has_work  fn(int $user_id): bool — must be a cheap
	 *                            indexed query. Never decrypts, never calls a
	 *                            model; it runs on every heartbeat.
	 * @param callable $drain     fn(int $user_id, string $secret_key, float $deadline): int
	 *                            — do work until $deadline (a microtime(true)
	 *                            value), return how many items were completed.
	 */
	public static function register(string $id, callable $has_work, callable $drain): void {
		self::$consumers[$id] = array('has_work' => $has_work, 'drain' => $drain);
	}

	/** Registered consumer ids, in execution order. Mostly for tests and diagnostics. */
	public static function consumerIds(): array {
		self::loadConsumers();
		return array_keys(self::$consumers);
	}

	/**
	 * Does any consumer have outstanding work for this user? Answered without
	 * touching the vault — this runs on every heartbeat, so a consumer whose
	 * predicate is expensive slows down every page on the site.
	 *
	 * A predicate that throws is treated as "no work" and logged: a broken
	 * consumer must not be able to break the heartbeat.
	 */
	public static function hasWork(int $user_id): bool {
		if ($user_id <= 0) {
			return false;
		}
		self::loadConsumers();
		foreach (self::$consumers as $id => $consumer) {
			try {
				if (call_user_func($consumer['has_work'], $user_id) === true) {
					return true;
				}
			} catch (\Throwable $e) {
				error_log('VaultDeferredWork: has_work failed for consumer ' . $id
					. ' (user ' . $user_id . '): ' . $e->getMessage());
			}
		}
		return false;
	}

	/**
	 * Run one drain slice for this user. Returns a per-consumer tally of items
	 * completed, plus whether anything remains.
	 *
	 * Requires an open window in the CALLING session — the secret key is keyed
	 * to the browser session, so this is only ever reachable from a request
	 * that session made. A locked (or lapsed) window returns an empty tally
	 * rather than an error; the caller shows a locked state, never a failure.
	 *
	 * @return array{locked: bool, done: array<string,int>, more: bool}
	 */
	public static function drain(int $user_id, string $scope = null, float $budget_seconds = null): array {
		$scope = $scope ?? UserEncryptionVault::SCOPE_USER;
		$empty = array('locked' => true, 'done' => array(), 'more' => false);
		if ($user_id <= 0) {
			return $empty;
		}
		self::loadConsumers();
		if (empty(self::$consumers)) {
			return array('locked' => false, 'done' => array(), 'more' => false);
		}

		$budget = $budget_seconds !== null ? $budget_seconds : self::sliceSeconds();
		$deadline = microtime(true) + max(0.1, $budget);
		$done = array();

		// One suppression wrapper around the whole slice, so nested consumer
		// reads of secretKey() are covered too.
		$locked = true;
		self::withBackgroundWork(function () use ($user_id, $scope, $deadline, &$done, &$locked) {
			$secret = VaultUnlock::secretKey($user_id, $scope);
			if ($secret === null) {
				return;   // locked, lapsed, or ended by policy — nothing to do
			}
			$locked = false;
			self::runTurns($user_id, $secret, $deadline, $done);
		});

		if ($locked) {
			return $empty;
		}
		return array('locked' => false, 'done' => $done, 'more' => self::hasWork($user_id));
	}

	/**
	 * Round-robin the consumers that have work until the deadline passes or a
	 * full pass completes nothing.
	 *
	 * Each turn gets an equal share of the time remaining, so one slow consumer
	 * cannot spend the whole slice while another waits. The share is a floor,
	 * not a ceiling that can be enforced mid-item: an in-flight model call
	 * cannot be cut off cleanly, so a consumer checks the deadline BEFORE
	 * starting each item and a turn may overrun it by one item. That is
	 * accepted (specs/in_window_deferred_work.md § Keeping it small).
	 */
	private static function runTurns(int $user_id, string $secret, float $deadline, array &$done): void {
		while (microtime(true) < $deadline) {
			$active = self::activeConsumers($user_id);
			if (empty($active)) {
				return;
			}
			$progressed = 0;
			foreach ($active as $id) {
				if (microtime(true) >= $deadline) {
					return;
				}
				$remaining = $deadline - microtime(true);
				$turn_end = microtime(true) + max(self::MIN_TURN_SECONDS, $remaining / max(1, count($active)));
				$turn_end = min($turn_end, $deadline);
				$progressed += self::runOneTurn($id, $user_id, $secret, $turn_end, $done);
			}
			if ($progressed === 0) {
				return;   // a full pass did nothing — stop rather than spin
			}
		}
	}

	/**
	 * One consumer's turn, under an advisory lock so two tabs drilling the same
	 * queue cannot double-process. A lock already held means another request is
	 * mid-drain: skip, don't wait.
	 *
	 * A consumer that throws is logged and skipped. It stays registered and is
	 * retried on the next drain — one broken feature never stalls the others.
	 */
	private static function runOneTurn(string $id, int $user_id, string $secret, float $turn_end, array &$done): int {
		if (!self::tryLock($id, $user_id)) {
			return 0;
		}
		try {
			$count = (int)call_user_func(self::$consumers[$id]['drain'], $user_id, $secret, $turn_end);
			if ($count > 0) {
				$done[$id] = ($done[$id] ?? 0) + $count;
			}
			return $count;
		} catch (\Throwable $e) {
			error_log('VaultDeferredWork: drain failed for consumer ' . $id
				. ' (user ' . $user_id . '): ' . $e->getMessage());
			return 0;
		} finally {
			self::releaseLock($id, $user_id);
		}
	}

	/** Consumer ids reporting work, in registration order. */
	private static function activeConsumers(int $user_id): array {
		$active = array();
		foreach (self::$consumers as $id => $consumer) {
			try {
				if (call_user_func($consumer['has_work'], $user_id) === true) {
					$active[] = $id;
				}
			} catch (\Throwable $e) {
				error_log('VaultDeferredWork: has_work failed for consumer ' . $id
					. ' (user ' . $user_id . '): ' . $e->getMessage());
			}
		}
		return $active;
	}

	/**
	 * Suppress activity stamping for the duration of $fn — see § Background
	 * work is not user activity. Restores the previous state even if $fn
	 * throws, and tolerates nesting.
	 */
	public static function withBackgroundWork(callable $fn) {
		$was = self::$in_background_work;
		self::$in_background_work = true;
		VaultUnlock::setActivitySuppressed(true);
		try {
			return $fn();
		} finally {
			self::$in_background_work = $was;
			VaultUnlock::setActivitySuppressed($was);
		}
	}

	/** True while a drain slice is running in this request. */
	public static function inBackgroundWork(): bool {
		return self::$in_background_work;
	}

	/** Seconds per drain request, from settings with a code fallback. */
	private static function sliceSeconds(): float {
		$configured = (float)Globalvars::get_instance()->get_setting('vault_deferred_work_slice_seconds');
		return $configured > 0 ? $configured : (float)self::DEFAULT_SLICE_SECONDS;
	}

	/** Consumer bootstraps register on load; this is the same lazy load the vault hooks use. */
	private static function loadConsumers(): void {
		VaultUnlock::loadConsumerBootstraps();
	}

	/**
	 * Advisory lock key for (consumer, user). Postgres takes two int4s; the
	 * consumer id hashes into the first and the user id fills the second, so
	 * two consumers for the same user never collide.
	 */
	private static function lockKeys(string $id, int $user_id): array {
		// crc32 returns an unsigned 32-bit value; fold it into signed int4 range.
		$k1 = (int)(crc32('vaultdrain:' . $id) & 0x7FFFFFFF);
		return array($k1, $user_id);
	}

	private static function tryLock(string $id, int $user_id): bool {
		list($k1, $k2) = self::lockKeys($id, $user_id);
		try {
			$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT pg_try_advisory_lock(?, ?)');
			$q->execute(array($k1, $k2));
			return (bool)$q->fetchColumn();
		} catch (\Throwable $e) {
			error_log('VaultDeferredWork: advisory lock failed for ' . $id . ': ' . $e->getMessage());
			return false;   // fail closed: no lock, no work
		}
	}

	private static function releaseLock(string $id, int $user_id): void {
		list($k1, $k2) = self::lockKeys($id, $user_id);
		try {
			$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT pg_advisory_unlock(?, ?)');
			$q->execute(array($k1, $k2));
		} catch (\Throwable $e) {
			// A session-scoped lock dies with the connection, so a failed
			// unlock is a leak bounded by the request, not a stuck queue.
			error_log('VaultDeferredWork: advisory unlock failed for ' . $id . ': ' . $e->getMessage());
		}
	}

	/** Test seam: drop all registrations so a test can register its own. */
	public static function resetForTests(): void {
		self::$consumers = array();
	}
}
?>
