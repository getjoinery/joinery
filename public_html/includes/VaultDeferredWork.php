<?php
/**
 * VaultDeferredWork — work that can only run while a user's vault is unlocked
 * (specs/in_window_deferred_work.md).
 *
 * The vault key is held for the browser session, so a process with no session
 * can never hold a window: VaultUnlock::secretKey() returns null for it by
 * construction. Anything that must read sealed content therefore cannot run
 * from cron — it has to run inside a web request carrying a live window for
 * that user. This class is where that work is scheduled.
 *
 * Features register a cheap "is there anything to do?" predicate and a drain
 * callable. The browser's presence beacon reports work_pending on each
 * heartbeat and fires a separate drain request when the answer is yes; the
 * drain never runs inside the heartbeat itself (see § The heartbeat stays fast
 * below).
 *
 * Registration order is meaningful: mail parsing must precede AI judging,
 * because an unparsed message has no fields to read. Order is the `order` each
 * consumer declares (VaultConsumers), which controls bootstrap load order.
 *
 * § The heartbeat stays fast. The keep-alive beat exists to hold the window
 * open and must never depend on a language model — the local provider's
 * timeout is measured in minutes, and a beat blocked that long would stack up
 * behind itself while the window it was meant to protect lapsed. So the beat
 * only answers hasWork(); the work happens in its own request.
 *
 * § Background work is not user activity. VaultUnlock::secretKey() normally
 * stamps the content-decrypt time the hardened idle cap measures from. A drain
 * decrypting on every beat would hold a window open indefinitely for someone
 * who walked away from an open tab, silently removing the idle cap. Every
 * drain therefore runs inside withBackgroundWork(), which suppresses activity
 * stamping for the duration — including for consumer code that reaches
 * VaultUnlock::secretKey() on its own.
 *
 * § A page waiting on a drain. A consumer registered with 'foreground' => true
 * holds work a page may be waiting on (the mail list showing sealed rows). A
 * foreground drain runs those consumers only, in a short slice, so the page
 * redraws every few seconds. A background drain runs everything, those
 * consumers first. A lead's first turn is an equal share, like anyone's; if it
 * completed something it carries on to the deadline, and if it completed nothing
 * it forfeits the lead for the rest of that drain and shares like any other. That
 * is the starvation guard: a lead that is progressing has a bounded backlog, and
 * one that is not, even one that fails slowly right up to its turn's end, gets no
 * more than anyone else. Leads are not fair to each other: while the first keeps
 * progressing, a second foreground consumer waits for it to finish.
 *
 * § Two lanes. A consumer is in the slice lane (the default) or the long lane
 * ('lane' => 'long'). Long-lane work may block on something outside the site,
 * a language model, for as long as one call takes. It never shares a request
 * with the slice lane: a slice drain runs slice consumers only, and a long
 * drain runs long consumers only, each in a request of its own, so nothing a
 * page waits on queues behind a model call. A long drain gives each long
 * consumer one turn, starts no new item after the slice, and never cuts off
 * an item in flight: it runs with no PHP time limit and survives the client
 * going away, so a paid model call always finishes and is saved. Parsing still
 * leads across lanes: a long consumer reports no work while any foreground
 * consumer reports work for that user, so a recipe never judges a half-parsed
 * backlog.
 *
 * @version 1.4.0 - two lanes: the long lane runs model work in its own request
 * @version 1.3.0 - a slow has_work answer is logged, naming the consumer
 * @version 1.2.0 - foreground consumers and drains; ms per consumer in each answer; an overrun turn is logged
 * @version 1.1.1 - comment wording: the hardened idle cap
 * @version 1.1.0 - drain callbacks receive the window's VaultKey, not bytes
 * @version 1.0.0
 */

require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));

class VaultDeferredWork {

	/** Fallback when the setting is absent. Seconds of wall clock per drain request. */
	const DEFAULT_SLICE_SECONDS = 10;

	/** Never hand a consumer less than this, however little of the slice is left. */
	const MIN_TURN_SECONDS = 1.0;

	/** The most a foreground drain runs, whatever the site's slice is. */
	const FOREGROUND_SLICE_SECONDS = 3;

	/** A turn this far past its end is logged: it is what a redraw was waiting on. */
	const OVERRUN_LOG_SECONDS = 2.0;

	const LANE_SLICE = 'slice';
	const LANE_LONG  = 'long';

	/** A has_work answer slower than this is logged: the heartbeat waits on it. */
	const SLOW_CHECK_LOG_SECONDS = 0.25;

	/**
	 * @var array<string, array{has_work: callable, drain: callable, foreground: bool, lane: string}>
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
	 * @param callable $drain     fn(int $user_id, VaultKey $key, float $deadline): int
	 *                            — do work until $deadline (a microtime(true)
	 *                            value), return how many items were completed.
	 *                            It runs as the server's own write
	 *                            (server_initiated_write): the model check does
	 *                            not apply, so a drain that acts for a person
	 *                            asks that person's rules itself.
	 * @param array    $options   'foreground' => true marks work a page may be
	 *                            waiting on (§ A page waiting on a drain).
	 *                            'lane' => 'long' marks work that may block on a
	 *                            model (§ Two lanes). A page never waits on the
	 *                            long lane, so a long consumer cannot be foreground.
	 */
	public static function register(string $id, callable $has_work, callable $drain, array $options = array()): void {
		$lane = $options['lane'] ?? self::LANE_SLICE;
		if ($lane !== self::LANE_SLICE && $lane !== self::LANE_LONG) {
			throw new InvalidArgumentException('VaultDeferredWork: consumer ' . $id . ' names an unknown lane: ' . $lane);
		}
		$foreground = !empty($options['foreground']);
		if ($foreground && $lane === self::LANE_LONG) {
			throw new InvalidArgumentException('VaultDeferredWork: consumer ' . $id . ' cannot be both foreground and long');
		}
		self::$consumers[$id] = array(
			'has_work'   => $has_work,
			'drain'      => $drain,
			'foreground' => $foreground,
			'lane'       => $lane,
		);
	}

	/**
	 * Does any consumer have outstanding work for this user? Answered without
	 * touching the vault — this runs on every heartbeat, so a consumer whose
	 * predicate is expensive slows down every page on the site.
	 *
	 * A predicate that throws is treated as "no work" and logged: a broken
	 * consumer must not be able to break the heartbeat.
	 *
	 * Answers for one lane (§ Two lanes). The long lane answers no while any
	 * foreground consumer has work.
	 */
	public static function hasWork(int $user_id, string $lane = self::LANE_SLICE): bool {
		self::assertLane($lane);
		if ($user_id <= 0) {
			return false;
		}
		self::loadConsumers();
		$pending = self::pending($user_id, $lane === self::LANE_SLICE, $lane === self::LANE_LONG);
		return $pending[$lane];
	}

	/**
	 * Both lanes' answers in one pass, for the heartbeat: the foreground
	 * consumers are asked once and their answer serves both lanes.
	 *
	 * @return array{slice: bool, long: bool}
	 */
	public static function pendingByLane(int $user_id): array {
		if ($user_id <= 0) {
			return array(self::LANE_SLICE => false, self::LANE_LONG => false);
		}
		self::loadConsumers();
		$pending = self::pending($user_id, true, true);
		return array(self::LANE_SLICE => $pending[self::LANE_SLICE], self::LANE_LONG => $pending[self::LANE_LONG]);
	}

	/**
	 * The one pass behind hasWork(), pendingByLane() and a drain's answer.
	 * Foreground consumers are asked first, until one says yes; that answer is
	 * the slice lane's yes and the long lane's no. Otherwise the rest of the
	 * lanes asked for are asked in registration order, each until one says yes.
	 *
	 * @return array{foreground: bool, slice: bool, long: bool}
	 */
	private static function pending(int $user_id, bool $want_slice, bool $want_long): array {
		$foreground = false;
		foreach (self::$consumers as $id => $consumer) {
			if ($consumer['foreground'] && self::askHasWork($id, $consumer, $user_id)) {
				$foreground = true;
				break;
			}
		}
		$out = array('foreground' => $foreground, self::LANE_SLICE => $foreground, self::LANE_LONG => false);
		$lanes = array();
		if ($want_slice && !$foreground) {
			$lanes[] = self::LANE_SLICE;
		}
		if ($want_long && !$foreground) {
			$lanes[] = self::LANE_LONG;
		}
		foreach ($lanes as $lane) {
			foreach (self::$consumers as $id => $consumer) {
				if ($consumer['foreground'] || $consumer['lane'] !== $lane) {
					continue;
				}
				if (self::askHasWork($id, $consumer, $user_id)) {
					$out[$lane] = true;
					break;
				}
			}
		}
		return $out;
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
	 * A foreground drain runs the foreground consumers only, in a slice of at most
	 * FOREGROUND_SLICE_SECONDS. With no foreground work it runs nothing, and the
	 * answer tells the client to fall back to background pacing.
	 *
	 * A long drain ($lane 'long', § Two lanes) gives each long consumer that has
	 * work one turn of the slice, and runs nothing else. It cannot be foreground.
	 *
	 * `ms` maps consumer id to the milliseconds its turns took in this slice.
	 * `more` is whether a slice-lane consumer still reports work, `long_more`
	 * whether a long-lane one does, and `foreground_more` whether a foreground
	 * consumer does.
	 *
	 * @return array{locked: bool, done: array<string,int>, more: bool, long_more: bool, foreground_more: bool, ms: array<string,int>}
	 */
	public static function drain(int $user_id, ?string $scope = null, ?float $budget_seconds = null, bool $foreground = false, string $lane = self::LANE_SLICE): array {
		self::assertLane($lane);
		if ($foreground && $lane === self::LANE_LONG) {
			throw new InvalidArgumentException('VaultDeferredWork: a long drain cannot be foreground');
		}
		$scope = $scope ?? UserEncryptionVault::SCOPE_USER;
		$empty = array('locked' => true, 'done' => array(), 'more' => false, 'long_more' => false, 'foreground_more' => false, 'ms' => array());
		if ($user_id <= 0) {
			return $empty;
		}
		self::loadConsumers();
		if (empty(self::$consumers)) {
			return array('locked' => false, 'done' => array(), 'more' => false, 'long_more' => false, 'foreground_more' => false, 'ms' => array());
		}
		if ($lane === self::LANE_LONG) {
			// A model call in flight always finishes (§ Two lanes): no PHP time
			// limit, and a browser that gave up waiting (a proxy's timeout) does
			// not stop it.
			ignore_user_abort(true);
			set_time_limit(0);
		}

		$budget = $budget_seconds !== null ? $budget_seconds : self::sliceSeconds();
		if ($foreground) {
			$budget = min($budget, (float)self::FOREGROUND_SLICE_SECONDS);
		}
		$deadline = microtime(true) + max(0.1, $budget);
		$slice = array('done' => array(), 'ms' => array(), 'overran' => array());

		// One suppression wrapper around the whole slice, so nested consumer
		// reads of secretKey() are covered too.
		$locked = true;
		$budget = max(0.1, $budget);
		self::withBackgroundWork(function () use ($user_id, $scope, $deadline, $budget, $foreground, $lane, &$slice, &$locked) {
			$key = VaultUnlock::secretKey($user_id, $scope);
			if ($key === null) {
				return;   // locked, lapsed, or ended by policy — nothing to do
			}
			$locked = false;
			if ($lane === self::LANE_LONG) {
				self::runLongTurns($user_id, $key, $budget, $slice);
			} else {
				self::runTurns($user_id, $key, $deadline, $foreground, $slice);
			}
		});

		if ($locked) {
			return $empty;
		}
		$pending = self::pending($user_id, true, true);
		return array(
			'locked'          => false,
			'done'            => $slice['done'],
			'more'            => $pending[self::LANE_SLICE],
			'long_more'       => $pending[self::LANE_LONG],
			'foreground_more' => $pending['foreground'],
			'ms'              => array_map('intval', array_map('round', $slice['ms'])),
		);
	}

	/**
	 * A long drain: one pass, one turn per long consumer with work, in
	 * registration order. Each turn ends a slice after it starts; the consumer
	 * checks that before each item and finishes the one in flight.
	 */
	private static function runLongTurns(int $user_id, VaultKey $key, float $budget, array &$slice): void {
		foreach (self::activeConsumers($user_id, false, self::LANE_LONG) as $id) {
			self::runOneTurn($id, $user_id, $key, microtime(true) + $budget, $slice);
		}
	}

	/**
	 * Round-robin the consumers that have work until the deadline passes or a
	 * full pass completes nothing.
	 *
	 * Each pass starts with the foreground consumers (§ A page waiting on a
	 * drain). The rest take equal shares of the time that leaves, so one slow
	 * consumer cannot spend the whole slice while another waits. The share is a
	 * floor, not a ceiling that can be enforced mid-item: an in-flight model call
	 * cannot be cut off cleanly, so a consumer checks the deadline BEFORE starting
	 * each item and a turn may overrun it by one item. That is accepted
	 * (specs/in_window_deferred_work.md § Keeping it small).
	 */
	private static function runTurns(int $user_id, VaultKey $key, float $deadline, bool $foreground_only, array &$slice): void {
		$forfeited = array();
		while (microtime(true) < $deadline) {
			$active = self::activeConsumers($user_id, $foreground_only, self::LANE_SLICE);
			if (empty($active)) {
				return;
			}
			$leads = array();
			$shared = array();
			foreach ($active as $id) {
				if (self::$consumers[$id]['foreground'] && empty($forfeited[$id])) {
					$leads[] = $id;
				} else {
					$shared[] = $id;
				}
			}
			$progressed = 0;
			foreach ($leads as $id) {
				if (microtime(true) >= $deadline) {
					return;
				}
				$remaining = $deadline - microtime(true);
				$share_end = min($deadline, microtime(true) + max(self::MIN_TURN_SECONDS, $remaining / count($active)));
				$count = self::runOneTurn($id, $user_id, $key, $share_end, $slice);
				if ($count === 0) {
					$forfeited[$id] = true;
				} else if (microtime(true) < $deadline) {
					$count += self::runOneTurn($id, $user_id, $key, $deadline, $slice);
				}
				$progressed += $count;
			}
			foreach ($shared as $id) {
				if (microtime(true) >= $deadline) {
					return;
				}
				$remaining = $deadline - microtime(true);
				$turn_end = microtime(true) + max(self::MIN_TURN_SECONDS, $remaining / count($shared));
				$turn_end = min($turn_end, $deadline);
				$progressed += self::runOneTurn($id, $user_id, $key, $turn_end, $slice);
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
	private static function runOneTurn(string $id, int $user_id, VaultKey $key, float $turn_end, array &$slice): int {
		if (!self::tryLock($id, $user_id)) {
			return 0;
		}
		$started = microtime(true);
		try {
			// A drain is the platform finishing work that waited for the vault
			// (a reseal, a backfill), not an act of the person whose page is open.
			$count = (int)SystemBase::server_initiated_write(function () use ($id, $user_id, $key, $turn_end) {
				return call_user_func(self::$consumers[$id]['drain'], $user_id, $key, $turn_end);
			});
			if ($count > 0) {
				$slice['done'][$id] = ($slice['done'][$id] ?? 0) + $count;
			}
			return $count;
		} catch (\Throwable $e) {
			error_log('VaultDeferredWork: drain failed for consumer ' . $id
				. ' (user ' . $user_id . '): ' . $e->getMessage());
			return 0;
		} finally {
			$ended = microtime(true);
			$slice['ms'][$id] = ($slice['ms'][$id] ?? 0) + ($ended - $started) * 1000;
			$overrun = $ended - $turn_end;
			// A long turn runs past its end by one model call by design, and a
			// streamed call has no total cap (only a bound on silence between
			// tokens), so there is no number past which it is wrong: the long
			// lane is not overrun-logged. Its time is in the answer's ms.
			if (self::$consumers[$id]['lane'] === self::LANE_SLICE
					&& $overrun > self::OVERRUN_LOG_SECONDS && empty($slice['overran'][$id])) {
				$slice['overran'][$id] = true;
				error_log('VaultDeferredWork: consumer ' . $id . ' overran its turn by '
					. round($overrun, 1) . ' s (user ' . $user_id . ')');
			}
			self::releaseLock($id, $user_id);
		}
	}

	/**
	 * Consumer ids in one lane reporting work, in registration order. The long
	 * lane reports none while a foreground consumer has work (§ Two lanes).
	 */
	private static function activeConsumers(int $user_id, bool $foreground_only, string $lane): array {
		if ($lane === self::LANE_LONG && self::pending($user_id, false, false)['foreground']) {
			return array();
		}
		$active = array();
		foreach (self::$consumers as $id => $consumer) {
			if ($consumer['lane'] !== $lane) {
				continue;
			}
			if ($foreground_only && !$consumer['foreground']) {
				continue;
			}
			if (self::askHasWork($id, $consumer, $user_id)) {
				$active[] = $id;
			}
		}
		return $active;
	}

	/**
	 * One consumer's has_work answer. A predicate that throws answers no and is
	 * logged. One slower than SLOW_CHECK_LOG_SECONDS is logged too: every
	 * heartbeat asks each consumer in turn until one says yes, so a slow
	 * predicate is what a slow heartbeat is waiting on.
	 */
	private static function askHasWork(string $id, array $consumer, int $user_id): bool {
		$started = microtime(true);
		try {
			$answer = call_user_func($consumer['has_work'], $user_id) === true;
		} catch (\Throwable $e) {
			error_log('VaultDeferredWork: has_work failed for consumer ' . $id
				. ' (user ' . $user_id . '): ' . $e->getMessage());
			$answer = false;
		}
		$took = microtime(true) - $started;
		if ($took > self::SLOW_CHECK_LOG_SECONDS) {
			error_log('VaultDeferredWork: consumer ' . $id . ' took ' . (int)round($took * 1000)
				. ' ms to answer whether it has work (user ' . $user_id . ')');
		}
		return $answer;
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

	private static function assertLane(string $lane): void {
		if ($lane !== self::LANE_SLICE && $lane !== self::LANE_LONG) {
			throw new InvalidArgumentException('VaultDeferredWork: unknown lane ' . $lane);
		}
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
