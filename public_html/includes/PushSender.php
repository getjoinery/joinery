<?php
/**
 * PushSender — the shared sender for push notifications.
 *
 * Everything that alerts a member outside the site goes through here: Notify's
 * push channel, and any other trigger that calls send() or queue() itself.
 * PushSender owns three rules so no caller has to:
 *
 * - Who: a message goes to every destination the member registered
 *   (psh_push_destinations), each through the driver for its kind.
 * - What: the caller supplies the full text and, optionally, a generic text.
 *   A driver that may not carry readable text, and any message marked
 *   `protected` (sealed content), gets the generic text — decided here, in
 *   render(), never by the caller.
 * - Cleanup: retire() is the one path that removes a destination — sign-out on
 *   that browser, an app key revoked, a push service saying the destination is
 *   gone, repeated failures, a re-minted sending key.
 *
 * queue() holds messages until the request is over: under PHP-FPM the response
 * is finished first (fastcgi_finish_request), so nobody waits on a push
 * service; anywhere else they are sent at shutdown. A push that fails is not
 * retried — the in-app and email channels are the record; push is the nudge.
 *
 * Bookkeeping after a send (failure counts, retiring) is written directly, not
 * through save(): it runs after the response, possibly in a request that only
 * read, and must never fail the request it rode in on.
 *
 * Message fields: title, body, link (a site path or URL), tag (groups
 * notifications on the device: a newer one with the same tag replaces the
 * older), generic_title and generic_body (optional; the site name and
 * "You have a new notification." by default), protected (bool).
 *
 * See docs/push_notifications.md.
 *
 * @version 1.0.0
 */
class PushSender {

	/** Destinations one member may hold; registering another retires the least recently seen. */
	const MAX_PER_USER = 20;

	/** Failed sends in a row before a destination is retired. */
	const MAX_FAILURES = 5;

	/** Longest title and body sent; a push payload is capped near 4 KB. */
	const MAX_TITLE = 120;
	const MAX_BODY  = 400;

	/** @var PushDriver[]|null kind => driver */
	private static $drivers = null;

	/** @var array list of [user_id, message] waiting for the end of the request */
	private static $queue = array();

	/** @var bool the end-of-request send is registered */
	private static $flush_registered = false;

	// ── drivers ─────────────────────────────────────────────────────────────

	/** The driver for a destination kind, or null when this site has none for it. */
	public static function driver(string $kind): ?PushDriver {
		self::load_drivers();
		return self::$drivers[$kind] ?? null;
	}

	/**
	 * Add or replace the driver for its kind. The phone driver registers here;
	 * a test registers a fake.
	 */
	public static function register_driver(PushDriver $driver): void {
		self::load_drivers();
		self::$drivers[$driver->kind()] = $driver;
	}

	/** Back to the drivers core ships. */
	public static function reset_drivers(): void {
		self::$drivers = null;
	}

	private static function load_drivers(): void {
		if (self::$drivers === null) {
			self::$drivers = array(PushDestination::KIND_WEB => new WebPushDriver());
		}
	}

	// ── destinations ────────────────────────────────────────────────────────

	/**
	 * Register (or refresh) a destination for a member. An endpoint already
	 * registered — to this member or, after a sign-out and a different sign-in
	 * on the same browser, to someone else — becomes this member's: whoever
	 * holds the browser is the one alerted.
	 *
	 * @param string      $kind         a PushDestination kind with a driver
	 * @param array       $input        the client's registration fields (the driver checks them)
	 * @param string|null $device_token the browser's device cookie, so signing out there retires it
	 * @param int|null    $api_key_id   the app key a phone registered under
	 * @throws InvalidArgumentException when the kind has no driver or the input is refused
	 */
	public static function register(int $user_id, string $kind, array $input, ?string $device_token = null, ?int $api_key_id = null): PushDestination {
		$driver = self::driver($kind);
		if ($driver === null) {
			throw new InvalidArgumentException('This site cannot send push notifications of that kind.');
		}
		$checked = $driver->register($input);

		$destination = PushDestination::for_endpoint($checked['endpoint']);
		if ($destination === null) {
			$destination = new PushDestination(null);
			$destination->set('psh_endpoint', $checked['endpoint']);
		}
		$destination->set('psh_usr_user_id', $user_id);
		$destination->set('psh_kind', $kind);
		$destination->set('psh_details', $checked['details']);
		$destination->set('psh_device_hash', $device_token !== null && $device_token !== '' ? hash('sha256', $device_token) : null);
		$destination->set('psh_apk_api_key_id', $api_key_id);
		$destination->set('psh_failure_count', 0);
		$destination->set('psh_last_seen_time', gmdate('Y-m-d H:i:s'));
		$destination->save();

		// Keep a member's list bounded: the least recently seen go first.
		$q = DbConnector::GetPreparedStatement(
			'SELECT psh_push_destination_id FROM psh_push_destinations
			  WHERE psh_usr_user_id = ?
			  ORDER BY psh_last_seen_time DESC NULLS LAST, psh_push_destination_id DESC
			  OFFSET ' . (int)self::MAX_PER_USER);
		$q->execute(array($user_id));
		$excess = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
		if ($excess) {
			self::retire($excess, 'the member registered more than ' . self::MAX_PER_USER . ' destinations');
		}
		return $destination;
	}

	/** The member's destination at this endpoint, or null. */
	public static function destination_for(int $user_id, string $endpoint): ?PushDestination {
		$destination = PushDestination::for_endpoint($endpoint);
		if ($destination === null || (int)$destination->get('psh_usr_user_id') !== $user_id) {
			return null;
		}
		return $destination;
	}

	/**
	 * The one cleanup path. Tells each destination's driver, then removes the
	 * rows. Returns how many were removed.
	 *
	 * @param int[] $destination_ids
	 */
	public static function retire(array $destination_ids, string $reason): int {
		$ids = array_values(array_unique(array_filter(array_map('intval', $destination_ids))));
		if (!$ids) {
			return 0;
		}
		$removed = 0;
		$dblink = DbConnector::get_instance()->get_db_link();
		foreach ($ids as $id) {
			try {
				$destination = new PushDestination($id, true);
				$driver = self::driver((string)$destination->get('psh_kind'));
				if ($driver !== null) {
					$driver->retired($destination, $reason);
				}
			} catch (Throwable $e) {
				// Already gone, or the driver failed to hear it: the row still goes.
			}
			$q = $dblink->prepare('DELETE FROM psh_push_destinations WHERE psh_push_destination_id = ?');
			$q->execute(array($id));
			$removed += $q->rowCount();
		}
		return $removed;
	}

	/** Signing out on a browser retires that browser's destination. */
	public static function retire_device(int $user_id, string $device_token, string $reason = 'signed out on this browser'): int {
		if ($device_token === '') {
			return 0;
		}
		return self::retire(self::ids_where('psh_usr_user_id = ? AND psh_device_hash = ?', array($user_id, hash('sha256', $device_token))), $reason);
	}

	/** Every destination registered under an app key, when the key is revoked. */
	public static function retire_for_api_key(int $api_key_id, string $reason = 'its app key was revoked'): int {
		return self::retire(self::ids_where('psh_apk_api_key_id = ?', array($api_key_id)), $reason);
	}

	/** Every destination of one kind (the web driver's sending key was re-minted). */
	public static function retire_kind(string $kind, string $reason): int {
		return self::retire(self::ids_where('psh_kind = ?', array($kind)), $reason);
	}

	/** Every destination a member holds. */
	public static function retire_for_user(int $user_id, string $reason): int {
		return self::retire(self::ids_where('psh_usr_user_id = ?', array($user_id)), $reason);
	}

	private static function ids_where(string $where, array $params): array {
		$q = DbConnector::GetPreparedStatement('SELECT psh_push_destination_id FROM psh_push_destinations WHERE ' . $where);
		$q->execute($params);
		return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
	}

	// ── the browser's device cookie ─────────────────────────────────────────

	/** Names the browser a destination was registered from, so signing out there retires it. */
	const DEVICE_COOKIE = 'joinery_push_device';

	/** This browser's device token, or null when it has none. */
	public static function device_token(): ?string {
		$token = (string)($_COOKIE[self::DEVICE_COOKIE] ?? '');
		return preg_match('/^[0-9a-f]{64}$/', $token) ? $token : null;
	}

	/** This browser's device token, giving it one (a cookie for five years) if it has none. */
	public static function issue_device_token(): string {
		$token = self::device_token();
		if ($token === null) {
			$token = bin2hex(random_bytes(32));
			$_COOKIE[self::DEVICE_COOKIE] = $token;
			if (!headers_sent()) {
				setcookie(self::DEVICE_COOKIE, $token, array(
					'expires'  => time() + 5 * 365 * 86400,
					'path'     => '/',
					// Push needs a secure page in the first place.
					'secure'   => true,
					'httponly' => true,
					'samesite' => 'Lax',
				));
			}
		}
		return $token;
	}

	// ── sending ─────────────────────────────────────────────────────────────

	/**
	 * Hold a message for a member until the end of this request. Cheap: nothing
	 * is read until the send, and a member with no destinations costs one row
	 * in one query for the whole request.
	 */
	public static function queue(int $user_id, array $message): void {
		if ($user_id <= 0) {
			return;
		}
		self::$queue[] = array($user_id, $message);
		if (!self::$flush_registered) {
			self::$flush_registered = true;
			register_shutdown_function(array(self::class, 'send_after_response'));
		}
	}

	/** Messages waiting for the end of the request (tests read it). */
	public static function queued(): array {
		return self::$queue;
	}

	/** Drop what is waiting without sending it. */
	public static function clear_queue(): void {
		self::$queue = array();
	}

	/**
	 * Send everything queued now. Returns the outcome per destination id
	 * (see send()).
	 */
	public static function flush(): array {
		$queue = self::$queue;
		self::$queue = array();
		$outcomes = array();
		foreach ($queue as $item) {
			try {
				$outcomes += self::send(array($item[0]), $item[1]);
			} catch (Throwable $e) {
				error_log('[PushSender] send to user ' . (int)$item[0] . ' failed: ' . $e->getMessage());
			}
		}
		return $outcomes;
	}

	/** The shutdown hook: finish the response first where the server allows it, then send. */
	public static function send_after_response(): void {
		if (!self::$queue) {
			return;
		}
		if (PHP_SAPI === 'fpm-fcgi' && function_exists('fastcgi_finish_request')) {
			if (session_status() === PHP_SESSION_ACTIVE) {
				session_write_close();
			}
			fastcgi_finish_request();
		}
		try {
			self::flush();
		} catch (Throwable $e) {
			error_log('[PushSender] flush failed: ' . $e->getMessage());
		}
	}

	/**
	 * Send one message to every destination these members hold, now.
	 *
	 * @param int[] $user_ids
	 * @param int|null $only_destination_id  send to just this one (the "send a test" button)
	 * @return array  destination id => ['result' => PushDriver::RESULT_*, 'reason' => string]
	 */
	public static function send(array $user_ids, array $message, ?int $only_destination_id = null): array {
		$user_ids = array_values(array_unique(array_filter(array_map('intval', $user_ids))));
		if (!$user_ids) {
			return array();
		}
		$placeholders = implode(',', array_fill(0, count($user_ids), '?'));
		$params = $user_ids;
		$sql = 'SELECT psh_push_destination_id FROM psh_push_destinations WHERE psh_usr_user_id IN (' . $placeholders . ')';
		if ($only_destination_id !== null) {
			$sql .= ' AND psh_push_destination_id = ?';
			$params[] = $only_destination_id;
		}
		$q = DbConnector::GetPreparedStatement($sql);
		$q->execute($params);
		$ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
		if (!$ids) {
			return array();
		}

		// One batch per driver.
		$by_kind = array();
		foreach ($ids as $id) {
			$destination = new PushDestination($id, true);
			$by_kind[(string)$destination->get('psh_kind')][] = $destination;
		}

		$outcomes = array();
		foreach ($by_kind as $kind => $destinations) {
			$driver = self::driver($kind);
			if ($driver === null || !$driver->available()) {
				continue;
			}
			$payload = self::render($message, $driver);
			$deliveries = array();
			foreach ($destinations as $destination) {
				$deliveries[] = array('destination' => $destination, 'message' => $payload);
			}
			try {
				$results = $driver->send($deliveries);
			} catch (Throwable $e) {
				$results = array();
				foreach ($destinations as $destination) {
					$results[(int)$destination->key] = array('result' => PushDriver::RESULT_FAILED, 'reason' => $e->getMessage());
				}
			}
			$outcomes += $results;
		}

		self::record($outcomes);
		return $outcomes;
	}

	/**
	 * What a driver is handed: the full text, or the generic text when the
	 * message is protected or the driver may not carry readable text.
	 *
	 * @return array ['title', 'body', 'link', 'tag']
	 */
	public static function render(array $message, PushDriver $driver): array {
		$generic = !empty($message['protected']) || !$driver->carriesText();
		if ($generic) {
			$title = (string)($message['generic_title'] ?? '');
			if ($title === '') {
				$title = (string)Globalvars::get_instance()->get_setting('site_name');
			}
			$body = (string)($message['generic_body'] ?? '');
			if ($body === '') {
				$body = 'You have a new notification.';
			}
		} else {
			$title = (string)($message['title'] ?? '');
			$body  = (string)($message['body'] ?? '');
		}
		return array(
			'title' => mb_substr(trim($title), 0, self::MAX_TITLE),
			'body'  => mb_substr(trim($body), 0, self::MAX_BODY),
			'link'  => (string)($message['link'] ?? ''),
			'tag'   => mb_substr((string)($message['tag'] ?? ''), 0, 64),
		);
	}

	/** Write what each send came to: a success clears the count; gone or too many failures retires. */
	private static function record(array $outcomes): void {
		if (!$outcomes) {
			return;
		}
		$dblink = DbConnector::get_instance()->get_db_link();
		$now = gmdate('Y-m-d H:i:s');
		$retire = array();
		foreach ($outcomes as $id => $outcome) {
			$id = (int)$id;
			switch ($outcome['result']) {
				case PushDriver::RESULT_SENT:
					$q = $dblink->prepare('UPDATE psh_push_destinations SET psh_failure_count = 0, psh_last_sent_time = ? WHERE psh_push_destination_id = ?');
					$q->execute(array($now, $id));
					break;
				case PushDriver::RESULT_GONE:
					$retire[$outcome['reason']][] = $id;
					break;
				default:
					$q = $dblink->prepare('UPDATE psh_push_destinations SET psh_failure_count = psh_failure_count + 1 WHERE psh_push_destination_id = ? RETURNING psh_failure_count');
					$q->execute(array($id));
					$count = (int)$q->fetchColumn();
					if ($count >= self::MAX_FAILURES) {
						$retire['it failed ' . $count . ' times in a row (last: ' . $outcome['reason'] . ')'][] = $id;
					} else {
						error_log('[PushSender] destination ' . $id . ' failed: ' . $outcome['reason']);
					}
			}
		}
		foreach ($retire as $reason => $ids) {
			self::retire($ids, $reason);
		}
	}
}
