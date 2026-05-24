<?php
/**
 * RunNodeUptimeChecks - Per-tick uptime check for managed nodes.
 *
 * For each managed node where uptime monitoring is enabled, runs the
 * configured check (api or http_status) and updates live state on the
 * node. Fires a "down" email on the up->down transition and a
 * "recovered" email on the down->up transition. One alert per
 * transition; no re-alerting while still down.
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class RunNodeUptimeChecks implements ScheduledTaskInterface {

	const TIMEOUT_SECONDS    = 10;
	const FAILURE_THRESHOLD  = 2;

	public function run(array $config): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

		$nodes = new MultiManagedNode([
			'deleted' => false,
		]);
		$nodes->load();

		$checked   = 0;
		$alerts    = 0;
		$skipped   = 0;
		$errors    = [];

		foreach ($nodes as $node) {
			if (!$node->get('mgn_enabled') || !$node->get('mgn_uptime_enabled')) {
				continue;
			}
			$site_url = trim((string)$node->get('mgn_site_url'));
			if ($site_url === '') {
				$skipped++;
				continue;
			}

			$result = $this->run_check($node);

			// 'skip' means we can't conclude up/down (e.g. api missing keys)
			if ($result['status'] === 'skip') {
				$skipped++;
				if (!empty($result['message'])) {
					$errors[] = "Node '" . $node->get('mgn_slug') . "': " . $result['message'];
				}
				continue;
			}

			$checked++;
			$transition = $this->apply_state($node, $result['ok']);
			$node->save();

			if ($transition === 'down' || $transition === 'recovered') {
				if ($this->send_alert($node, $transition, $result)) {
					$alerts++;
				}
			}
		}

		$message = sprintf('Checked %d node(s); %d alert(s) sent; %d skipped.', $checked, $alerts, $skipped);
		if (!empty($errors)) {
			$message .= ' Notes: ' . implode(' | ', array_slice($errors, 0, 5));
		}
		return ['status' => 'success', 'message' => $message];
	}

	/**
	 * Dispatch to the configured check type and return:
	 *   ['ok' => bool, 'message' => ?string, 'status' => 'done'|'skip']
	 */
	private function run_check($node): array {
		$type = $node->get('mgn_uptime_check_type') ?: 'api';
		// skip_joinery_checks forces http_status regardless of stored value
		if ($node->get('mgn_skip_joinery_checks')) {
			$type = 'http_status';
		}

		if ($type === 'http_status') {
			return $this->check_http_status($node);
		}
		return $this->check_api($node);
	}

	/**
	 * api check: reuse fetch_status_via_api. reason='transport' counts as
	 * down. 3xx responses also count as down — the API endpoint should
	 * never redirect; a 3xx means the request never reached the API
	 * handler (e.g. infrastructure-level HTTP->HTTPS redirect, possibly
	 * looping if CF is in Flexible mode). auth/body/non-3xx status all
	 * mean the server responded -> up. reason='config' is misconfig ->
	 * skip (don't fire a false down alert).
	 */
	private function check_api($node): array {
		$result = JobCommandBuilder::fetch_status_via_api($node, self::TIMEOUT_SECONDS);
		if ($result['ok']) {
			return ['ok' => true, 'message' => null, 'status' => 'done'];
		}
		$reason = isset($result['reason']) ? $result['reason'] : '';
		if ($reason === 'config') {
			return ['ok' => false, 'status' => 'skip', 'message' => 'api check selected but API keys not configured'];
		}
		if ($reason === 'transport') {
			return ['ok' => false, 'message' => $result['message'] ?? 'transport failure', 'status' => 'done'];
		}
		if ($reason === 'status') {
			// fetch_status_via_api stores the code in the message as "HTTP NNN"
			$code = 0;
			if (preg_match('/HTTP\s+(\d+)/', $result['message'] ?? '', $m)) {
				$code = (int)$m[1];
			}
			if ($code >= 300 && $code < 400) {
				return ['ok' => false, 'message' => 'unexpected redirect (HTTP ' . $code . ') — possible infrastructure misconfiguration', 'status' => 'done'];
			}
		}
		// auth / body / non-3xx status / anything else: server responded, treat as up
		return ['ok' => true, 'message' => null, 'status' => 'done'];
	}

	/**
	 * http_status check: plain GET to mgn_site_url. Any 2xx/3xx is up.
	 * Records mgn_last_status_check so the dashboard stays consistent.
	 */
	private function check_http_status($node): array {
		$health_url = trim((string)$node->get('mgn_health_check_url'));
		if ($health_url === '') {
			$site_url = rtrim((string)$node->get('mgn_site_url'), '/');
			$health_url = $site_url . '/';
		}
		$ch = curl_init($health_url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_NOBODY         => true,
			CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
			CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 5,
			CURLOPT_SSL_VERIFYPEER => $node->get('mgn_tls_insecure') ? false : true,
			CURLOPT_SSL_VERIFYHOST => $node->get('mgn_tls_insecure') ? 0 : 2,
		]);
		curl_exec($ch);
		$errno  = curl_errno($ch);
		$errmsg = curl_error($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$node->set('mgn_last_status_check', gmdate('Y-m-d H:i:s'));

		if ($errno) {
			return ['ok' => false, 'message' => $errmsg ?: ('curl errno ' . $errno), 'status' => 'done'];
		}
		if ($status >= 200 && $status < 400) {
			return ['ok' => true, 'message' => null, 'status' => 'done'];
		}
		return ['ok' => false, 'message' => 'HTTP ' . $status, 'status' => 'done'];
	}

	/**
	 * Apply the up/down state machine. Returns one of:
	 *   'down'       — just transitioned up -> down (fire down alert)
	 *   'recovered'  — just transitioned down -> up (fire recovered alert)
	 *   'no_change'  — no transition
	 */
	private function apply_state($node, bool $ok): string {
		$prev_status = $node->get('mgn_uptime_last_status');

		if ($ok) {
			$node->set('mgn_uptime_last_status', 'up');
			$node->set('mgn_uptime_consecutive_failures', 0);
			$node->set('mgn_uptime_down_since', NULL);
			return ($prev_status === 'down') ? 'recovered' : 'no_change';
		}

		$failures = (int)$node->get('mgn_uptime_consecutive_failures') + 1;
		$node->set('mgn_uptime_consecutive_failures', $failures);

		if ($failures >= self::FAILURE_THRESHOLD && $prev_status !== 'down') {
			$node->set('mgn_uptime_last_status', 'down');
			$node->set('mgn_uptime_down_since', gmdate('Y-m-d H:i:s'));
			return 'down';
		}
		return 'no_change';
	}

	/**
	 * Build and send the alert email. Returns true on send, false if no
	 * recipient could be resolved (logged to error log).
	 */
	private function send_alert($node, string $transition, array $result): bool {
		$to = $this->resolve_alert_recipient();
		if (!$to) {
			error_log('RunNodeUptimeChecks: no alert recipient resolved for node ' . $node->get('mgn_slug'));
			return false;
		}

		$name = $node->get('mgn_name');
		$url  = $node->get('mgn_site_url');
		$now  = gmdate('Y-m-d H:i:s') . ' UTC';

		if ($transition === 'down') {
			$subject = '[' . $name . '] is down';
			$body    = "Node: {$name}\n"
			         . "URL:  {$url}\n"
			         . "Time: {$now}\n"
			         . "Error: " . ($result['message'] ?? 'unknown') . "\n";
		} else { // recovered
			$down_since = $node->get('mgn_uptime_down_since');
			$duration   = $down_since ? $this->format_duration(time() - strtotime($down_since . ' UTC')) : 'unknown';
			$subject    = '[' . $name . '] recovered after ' . $duration;
			$body       = "Node: {$name}\n"
			            . "URL:  {$url}\n"
			            . "Time: {$now}\n"
			            . "Down duration: {$duration}\n";
		}

		try {
			EmailSender::quickSend($to, $subject, $body);
			return true;
		} catch (\Throwable $e) {
			error_log('RunNodeUptimeChecks: send failed for node ' . $node->get('mgn_slug') . ': ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Resolve the alert recipient via fallback chain:
	 *   1. server_manager_provisioning_admin_alert_email
	 *   2. webmaster_email
	 *   3. First permission-10 user's email
	 */
	private function resolve_alert_recipient(): string {
		$settings = Globalvars::get_instance();
		$email = trim((string)$settings->get_setting('server_manager_provisioning_admin_alert_email'));
		if ($email !== '') return $email;

		$email = trim((string)$settings->get_setting('webmaster_email'));
		if ($email !== '') return $email;

		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$admins = new MultiUser([
			'permission_range' => [10, 10],
			'deleted'          => false,
			'not_system_users' => true,
		], ['usr_user_id' => 'ASC'], 1);
		$admins->load();
		if (count($admins) > 0) {
			$email = trim((string)$admins->get(0)->get('usr_email'));
			if ($email !== '') return $email;
		}
		return '';
	}

	private function format_duration(int $seconds): string {
		if ($seconds < 60)   return $seconds . 's';
		if ($seconds < 3600) return intval(round($seconds / 60)) . 'm';
		$h = intval($seconds / 3600);
		$m = intval(($seconds % 3600) / 60);
		return $m > 0 ? "{$h}h{$m}m" : "{$h}h";
	}
}
