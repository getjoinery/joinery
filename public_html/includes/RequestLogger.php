<?php
/**
 * RequestLogger - Lightweight utility for request logging and rate limiting.
 *
 * General-purpose: works for API calls, login attempts, registration,
 * password resets, or any site feature that needs logging or throttling.
 *
 * @version 1.4
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
		$log->set('rql_ip_address', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0');
		$log->set('rql_was_success', $success);

		if (isset($options['user_id']))     $log->set('rql_usr_user_id', $options['user_id']);
		if (isset($options['status_code'])) $log->set('rql_status_code', $options['status_code']);
		if (isset($options['error_type']))  $log->set('rql_error_type', $options['error_type']);
		if (isset($options['response_ms'])) $log->set('rql_response_ms', $options['response_ms']);
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
		} finally {
			SystemBase::$allow_get_mutation = false;
		}
	}

	/**
	 * Check if a rate limit has been exceeded.
	 * Counts rows matching feature + IP within the given time window.
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
	 * not one caller.
	 *
	 * @return array{allowed:bool,count:int,retry_after:int}
	 */
	public static function rate_limit_state($feature, $max_requests, $window_seconds, $success_filter = null, $user_id = null) {
		$db = DbConnector::get_instance()->get_db_link();
		$window_seconds = max(1, intval($window_seconds));
		$max_requests = max(1, intval($max_requests));

		if ($user_id !== null) {
			$key_sql = 'rql_usr_user_id = ?';
			$key = intval($user_id);
		} else {
			$key_sql = 'rql_ip_address = ?';
			$key = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
		}
		$where = "rql_feature = ? AND " . $key_sql
			. " AND rql_create_time > NOW() - INTERVAL '" . $window_seconds . " seconds'";
		$params = [$feature, $key];
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
