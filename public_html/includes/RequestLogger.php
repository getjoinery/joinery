<?php
/**
 * RequestLogger - Lightweight utility for request logging and rate limiting.
 *
 * General-purpose: works for API calls, login attempts, registration,
 * password resets, or any site feature that needs logging or throttling.
 *
 * @version 1.3
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
		$dbconnector = DbConnector::get_instance();
		$db = $dbconnector->get_db_link();

		$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';

		$sql = "SELECT COUNT(*) as cnt FROM rql_request_logs
				WHERE rql_feature = ? AND rql_ip_address = ?
				AND rql_create_time > NOW() - INTERVAL '" . intval($window_seconds) . " seconds'";
		$params = [$feature, $ip];

		if ($success_filter !== null) {
			$sql .= " AND rql_was_success = ?";
			$params[] = $success_filter ? 'true' : 'false';
		}

		$stmt = $db->prepare($sql);
		$stmt->execute($params);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return ($row['cnt'] < $max_requests);
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
