<?php
/**
 * RequestLogger - Lightweight utility for request logging and rate limiting.
 *
 * General-purpose: works for API calls, login attempts, registration,
 * password resets, or any site feature that needs logging or throttling.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('data/request_logs_class.php'));

class RequestLogger {

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
		if (isset($options['note']))        $log->set('rql_note', substr($options['note'], 0, 255));
		if (isset($options['response_ms'])) $log->set('rql_response_ms', $options['response_ms']);

		$log->save();
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
	 * Called by the PurgeOldRequestLogs scheduled task.
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
