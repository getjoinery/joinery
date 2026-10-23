<?php
/**
 * RequestLogger - Lightweight utility for request logging and rate limiting.
 *
 * General-purpose: works for API calls, login attempts, registration,
 * password resets, or any site feature that needs logging or throttling.
 *
 * Two kinds of limit share the log, and they count different units
 * (specs/ipv6_address_handling.md D3). An attempt limit stops a stranger
 * from guessing a secret, making accounts or having mail sent to someone, so
 * it counts by network (check_attempt_limit, attempt_limit_state): an IPv6
 * caller rotating through its /64 gets no fresh allowance per address. A
 * capacity limit stops one caller from using up the server, so it counts by
 * address (check_rate_limit, rate_limit_state): servers in one data center
 * share a /64, and counting them as one would let one busy node use up every
 * other node's allowance. The call site's choice of method says which kind
 * of limit it is.
 *
 * @version 1.10
 * @changelog 1.10 - person_rate_limit_state(): a person's count for one kind of credential (their
 *   browser, their devices, their app credentials), so one kind cannot spend another's budget;
 *   api_key_type() reads the kind this request's rows are stamped with
 * @changelog 1.9 - log() no longer forces SystemBase::$allow_get_mutation off afterwards: server_initiated_write
 *   restores the previous value, and the forced reset ended a caller's own wrapped region early
 * @changelog 1.8 - the per-person key types are read from ApiKey::PER_PERSON_TYPES; this file
 *   names no credential kind
 * @changelog 1.7 - address_rate_limit_state(): an address's count leaves out the rows of
 *   traffic metered per person (browser sessions, session keys, app credentials), so a
 *   person's own devices never fill the budget that guards their address against strangers
 * @changelog 1.6 - every row records its network (rql_ip_network); attempt limits count by it
 *   (check_attempt_limit, attempt_limit_state); username_key() is the one key a sign-in's
 *   per-account count uses
 * @changelog 1.5 - a limit can be keyed to any caller (rql_key, log option 'key'): the agent
 *   channel counts each node's requests by its node, since a multi-tenant host's sites share
 *   one address (specs/multi_tenant_docker_hosts.md S23)
 * @changelog 1.4 - rate_limit_state(): the count, whether it is within the limit, and how
 *   long until it is not — so a 429 can say when to try again; a limit can be keyed to a
 *   user (rql_usr_user_id) instead of the address
 * @changelog 1.3 - log(): withhold the note on a sealed-hot request, and never let a failed log write escape into the request it describes
 * @changelog 1.2 - API key type context: set_api_key_type() stamps every subsequent log row so audit queries can separate machine from session API traffic
 * @changelog 1.1 - log(): mark the RequestLog save as an intentional GET mutation (audit/rate-limit rows persist on any request method)
 */
require_once(PathHelper::getIncludePath('data/request_logs_class.php'));
require_once(PathHelper::getIncludePath('includes/SealedEgressGuard.php'));

class RequestLogger {

	/** @var string|null Authenticated API key type ('machine'/'session') stamped onto every log row for the rest of the request */
	private static $api_key_type = null;

	/**
	 * Set the API key type for the current request. Called once by apiv1.php
	 * after key authentication passes; every subsequent log() row carries it.
	 */
	public static function set_api_key_type($type) {
		self::$api_key_type = $type;
	}

	/** The kind this request's rows are stamped with ('browser', 'session', ...), or null before authentication. */
	public static function api_key_type(): ?string {
		return self::$api_key_type;
	}

	/**
	 * Log a request.
	 *
	 * @param string $feature   Feature name (e.g. 'api', 'login', 'register')
	 * @param string $action    Specific operation (e.g. 'GET /api/v1/User/5', 'login_attempt')
	 * @param bool   $success   Whether the request succeeded
	 * @param array  $options   Optional fields: user_id, status_code, error_type, note, response_ms
	 */
	public static function log($feature, $action, $success = true, $options = array()) {
		$log = new RequestLog(NULL);
		$log->set('rql_feature', $feature);
		$log->set('rql_action', substr($action, 0, 100));
		$address = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
		$log->set('rql_ip_address', $address);
		$log->set('rql_ip_network', IpAddress::network($address));
		$log->set('rql_was_success', $success);

		if (isset($options['user_id']))     $log->set('rql_usr_user_id', $options['user_id']);
		if (isset($options['status_code'])) $log->set('rql_status_code', $options['status_code']);
		if (isset($options['error_type']))  $log->set('rql_error_type', $options['error_type']);
		if (isset($options['response_ms'])) $log->set('rql_response_ms', $options['response_ms']);
		if (isset($options['key']))         $log->set('rql_key', substr((string)$options['key'], 0, 64));
		if (self::$api_key_type !== null)   $log->set('rql_api_key_type', self::$api_key_type);
		// A page_probe request is logged as a probe, never as the throwaway
		// viewer's own activity: the viewer is deleted when the probe returns.
		if (class_exists('PageProbe', false) && PageProbe::active()) {
			$log->set('rql_api_key_type', 'page_probe');
		}

		// The note is free text a caller hands us — most often an exception
		// message. On a request that has opened sealed content, that message may
		// quote what was opened, and rql_request_logs has no way to protect it.
		// So the note is dropped rather than written: the guard's own first
		// preference (docs/sealed_vault.md), and it costs nothing that matters —
		// feature, action, status, error type and user are all facts about the
		// request rather than its content, and they still land.
		if (isset($options['note'])) {
			$log->set('rql_note', SealedEgressGuard::isHot()
				? 'note withheld: request opened sealed content'
				: substr($options['note'], 0, 255));
		}

		// A request-log row is an intentional persist on any request method
		// (GET reads are logged and rate-limited too).
		try {
			SystemBase::server_initiated_write(function () use ($log) { $log->save(); });
		} catch (Throwable $e) {
			// Logging is observation, never the work itself. A row that cannot be
			// written — a refused egress, a full disk, a lock — must not take down
			// the request it was only meant to describe, and must never replace a
			// real error with a failure to record it. Say so where saying so is
			// free, and let the caller carry on.
			error_log('RequestLogger: could not write ' . $feature . ' log row: ' . $e->getMessage());
		}
	}

	/**
	 * Check if a capacity limit has been exceeded.
	 * Counts rows matching feature + address within the given time window.
	 * A limit that guards an account is an attempt limit: check_attempt_limit().
	 *
	 * @param string    $feature        Feature name (e.g. 'api', 'login')
	 * @param int       $max_requests   Maximum allowed requests in the window
	 * @param int       $window_seconds Time window in seconds
	 * @param bool|null $success_filter null=count all, true=only successes, false=only failures
	 * @return bool     True if within limit, false if exceeded
	 */
	public static function check_rate_limit($feature, $max_requests, $window_seconds, $success_filter = null) {
		return self::rate_limit_state($feature, $max_requests, $window_seconds, $success_filter)['allowed'];
	}

	/**
	 * rate_limit_state() for the address a request came from, counting only
	 * the traffic an address limit exists to bound: machine keys, keyless
	 * and guest requests, and every failed or credential-less one (rows with
	 * no key type). Traffic metered per person is left out, or a household's
	 * own devices would fill their address's budget and lock each other out
	 * of every route the address limit guards.
	 *
	 * @return array{allowed:bool,count:int,retry_after:int}
	 */
	public static function address_rate_limit_state($feature, $max_requests, $window_seconds) {
		return self::count_state($feature, $max_requests, $window_seconds, null,
			"rql_ip_address = ? AND (rql_api_key_type IS NULL OR rql_api_key_type NOT IN ('"
				. implode("', '", ApiKey::PER_PERSON_TYPES) . "'))",
			isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0');
	}

	/**
	 * Where a caller stands against a limit: how many matching requests the
	 * window holds, whether one more is allowed, and — when it is not — how
	 * many seconds until the oldest of the rows that put it over the limit
	 * leaves the window, i.e. when the next request will be accepted. That
	 * number is what a 429 owes the caller: "try again in 4 minutes" is a
	 * wait; "rate limit exceeded" is a worry.
	 *
	 * Keyed to the address by default; pass $user_id to key the count to a
	 * signed-in user instead (rows carry rql_usr_user_id), which is the right
	 * scope for a browser session — several people behind one address are
	 * not one caller. Or pass $key to key it to any other caller the rows were
	 * logged with (the log option 'key'), such as an agent's node.
	 *
	 * @return array{allowed:bool,count:int,retry_after:int}
	 */
	public static function rate_limit_state($feature, $max_requests, $window_seconds, $success_filter = null, $user_id = null, $key = null) {
		if ($key !== null) {
			return self::count_state($feature, $max_requests, $window_seconds, $success_filter,
				'rql_key = ?', substr((string)$key, 0, 64));
		}
		if ($user_id !== null) {
			return self::count_state($feature, $max_requests, $window_seconds, $success_filter,
				'rql_usr_user_id = ?', intval($user_id));
		}
		return self::count_state($feature, $max_requests, $window_seconds, $success_filter,
			'rql_ip_address = ?', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0');
	}

	/**
	 * rate_limit_state() for one person's use of one kind of credential: the
	 * rows logged for that user under that key type. A person's browser,
	 * their devices together and their app credentials together are separate
	 * budgets, so a desktop's first sync of a large folder slows the sync
	 * without locking its person out of the site. It is one budget per kind,
	 * never per key: linking another device buys no more requests.
	 *
	 * @return array{allowed:bool,count:int,retry_after:int}
	 */
	public static function person_rate_limit_state($feature, $max_requests, $window_seconds, $user_id, string $key_type) {
		return self::count_state($feature, $max_requests, $window_seconds, null,
			'rql_usr_user_id = ? AND rql_api_key_type = ?', array(intval($user_id), $key_type));
	}

	/**
	 * Check if an attempt limit has been exceeded: the rows for this feature
	 * from the caller's network (IpAddress::network()) within the window.
	 * For limits that stop a stranger guessing a secret, making accounts or
	 * having mail sent to someone; see the class comment.
	 *
	 * @return bool True if within limit, false if exceeded
	 */
	public static function check_attempt_limit($feature, $max_requests, $window_seconds, $success_filter = null) {
		return self::attempt_limit_state($feature, $max_requests, $window_seconds, $success_filter)['allowed'];
	}

	/**
	 * rate_limit_state() for an attempt limit: counted by the caller's
	 * network instead of its address.
	 *
	 * @return array{allowed:bool,count:int,retry_after:int}
	 */
	public static function attempt_limit_state($feature, $max_requests, $window_seconds, $success_filter = null) {
		return self::count_state($feature, $max_requests, $window_seconds, $success_filter,
			'rql_ip_network = ?', IpAddress::network(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0'));
	}

	/**
	 * Who an attempt limit counted, in words for a refusal: "This address"
	 * for an IPv4 caller, "This network (2001:db8:1:2::/64)" for an IPv6 one,
	 * whose allowance it shares with the rest of its /64.
	 */
	public static function attempt_unit_label(): string {
		$network = IpAddress::network(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
		return strpos($network, '/') !== false ? 'This network (' . $network . ')' : 'This address';
	}

	/**
	 * The key a sign-in's per-account count is logged and counted under, for
	 * the username as typed (whether or not an account has it): the web
	 * sign-in and the app sign-in use this one key, so they count alike.
	 */
	public static function username_key(string $username): string {
		return 'user:' . substr(hash('sha256', strtolower(trim($username))), 0, 32);
	}

	/** The count behind every limit: rows for $feature matching $key_sql (one ? per value in $key) within the window. */
	private static function count_state($feature, $max_requests, $window_seconds, $success_filter, string $key_sql, $key): array {
		$db = DbConnector::get_instance()->get_db_link();
		$window_seconds = max(1, intval($window_seconds));
		$max_requests = max(1, intval($max_requests));

		$where = "rql_feature = ? AND " . $key_sql
			. " AND rql_create_time > NOW() - INTERVAL '" . $window_seconds . " seconds'";
		$params = array_merge(array($feature), is_array($key) ? array_values($key) : array($key));
		if ($success_filter !== null) {
			$where .= " AND rql_was_success = ?";
			$params[] = $success_filter ? 'true' : 'false';
		}

		$stmt = $db->prepare("SELECT COUNT(*) FROM rql_request_logs WHERE " . $where);
		$stmt->execute($params);
		$count = intval($stmt->fetchColumn());
		if ($count < $max_requests) {
			return array('allowed' => true, 'count' => $count, 'retry_after' => 0);
		}

		// The request is allowed again once the count drops below the limit:
		// when the (count - max + 1)-th oldest row in the window ages out.
		$stmt = $db->prepare("SELECT GREATEST(1, CEIL(EXTRACT(EPOCH FROM (rql_create_time + INTERVAL '"
			. $window_seconds . " seconds' - NOW()))))::int
			FROM rql_request_logs WHERE " . $where . "
			ORDER BY rql_create_time ASC OFFSET ? LIMIT 1");
		$stmt->execute(array_merge($params, array($count - $max_requests)));
		$retry_after = intval($stmt->fetchColumn());
		return array('allowed' => false, 'count' => $count, 'retry_after' => max(1, $retry_after));
	}

	/**
	 * Delete records older than the given number of days.
	 * Kept for callers that need an explicit purge; the scheduled sweep uses RequestLog::$retention_policy.
	 *
	 * @param int $days Records older than this many days are deleted
	 * @return int Number of rows deleted
	 */
	public static function cleanup($days = 90) {
		$dbconnector = DbConnector::get_instance();
		$db = $dbconnector->get_db_link();

		$sql = "DELETE FROM rql_request_logs WHERE rql_create_time < NOW() - (INTERVAL '1 day' * :days)";
		$stmt = $db->prepare($sql);
		$stmt->execute([':days' => intval($days)]);
		return $stmt->rowCount();
	}
}
?>
