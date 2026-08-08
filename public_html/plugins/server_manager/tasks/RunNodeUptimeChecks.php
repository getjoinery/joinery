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
 * A probe only reports up or down when it actually reached the node. If it
 * failed in this machine's own name resolution it never left here, so it is
 * recorded as inconclusive and alerts nothing — otherwise a resolver fault on
 * the monitoring host mails out the entire fleet as down while every node is
 * serving traffic normally.
 *
 * Each enabled node also gets an independent TLS certificate-expiry check
 * (over the wire, pinned to the node's own IP) that warns before a
 * self-renewed cert lapses. See check_cert_expiry().
 *
 * @version 1.6 - a probe that dies in this machine's own resolver is inconclusive, not down:
 *                a broken resolver on the monitoring host no longer alerts the whole fleet down
 * @version 1.5 - P-19: recovered alert reports real down duration (capture down_since before apply_state clears it)
 */
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class RunNodeUptimeChecks implements ScheduledTaskInterface {

	const TIMEOUT_SECONDS         = 10;
	const FAILURE_THRESHOLD       = 2;
	const CERT_RECHECK_ALERT_DAYS = 3;

	public function run(array $config): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeMonitorHealth.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
		require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));

		$nodes = new MultiManagedNode([
			'deleted' => false,
		]);
		$nodes->load();

		$checked     = 0;
		$alerts      = 0;
		$cert_alerts = 0;
		$skipped     = 0;
		$not_due     = 0;
		$errors      = [];

		$now_utc = gmdate('Y-m-d H:i:s');

		foreach ($nodes as $node) {
			if (!$node->get('mgn_enabled') || !$node->get('mgn_uptime_enabled')) {
				continue;
			}
			// Whether this node can be probed at all depends on its check type,
			// so ask the shared evaluator rather than assuming a site URL.
			$target = NodeMonitorHealth::describe_target($node);
			if ($target['problem'] !== '') {
				$skipped++;
				$node->set('mgn_uptime_last_error', substr($target['problem'], 0, 255));
				$node->save();
				$errors[] = "Node '" . $node->get('mgn_slug') . "': " . $target['problem'];
				continue;
			}

			// Task frequency is only a floor. The node's own interval is the real
			// cadence, so probe volume stays fixed no matter how often cron ticks.
			if (!$this->is_node_due($node, $now_utc)) {
				$not_due++;
				continue;
			}
			$node->set('mgn_uptime_last_check', $now_utc);

			$result = $this->run_check($node);

			// 'skip' means we can't conclude up/down (e.g. api missing keys). The
			// cert check below still runs — it is independent of the up/down probe.
			if ($result['status'] === 'skip') {
				$skipped++;
				// Record WHY it could not conclude. Without this the node is
				// indistinguishable from a healthy one on every surface, which is
				// how broken monitoring stays invisible.
				$node->set('mgn_uptime_last_error',
					substr($result['message'] ?? 'check could not conclude up or down', 0, 255));
				// Persist the attempt stamp anyway: a node that cannot conclude
				// up/down must still respect its interval, or it would be retried
				// on every single tick.
				$node->save();
				if (!empty($result['message'])) {
					$errors[] = "Node '" . $node->get('mgn_slug') . "': " . $result['message'];
				}
			} else {
				$checked++;
				// Conclusive: clear any recorded fault and stamp the success, so
				// staleness is measured from real results only.
				$node->set('mgn_uptime_last_error', null);
				$node->set('mgn_uptime_last_conclusive', $now_utc);
				// Capture down_since before apply_state clears it on the up->up
				// recovery, so the recovered alert reports the real down duration
				// instead of "unknown".
				$down_since_for_alert = $node->get('mgn_uptime_down_since');
				$transition = $this->apply_state($node, $result['ok']);
				$node->save();

				if ($transition === 'down' || $transition === 'recovered') {
					if ($this->send_alert($node, $transition, $result, $down_since_for_alert)) {
						$alerts++;
					}
				}
			}

			// Independent TLS certificate-expiry check. Self-limits to self-renewed,
			// directly-exposed nodes; a no-op (and no save) for everything else.
			$cert = $this->check_cert_expiry($node);
			if ($cert['modified'] || $cert['alerted']) {
				$node->save();
			}
			if ($cert['alerted']) {
				$cert_alerts++;
			}
		}

		$message = sprintf('Checked %d node(s); %d up/down alert(s); %d cert alert(s); %d skipped; %d not due.', $checked, $alerts, $cert_alerts, $skipped, $not_due);
		if (!empty($errors)) {
			$message .= ' Notes: ' . implode(' | ', array_slice($errors, 0, 5));
		}
		return ['status' => 'success', 'message' => $message];
	}

	/**
	 * Is this node due for a probe?
	 *
	 * The task fires every cron pass, but each node carries its own interval
	 * (mgn_uptime_interval_seconds, default 300). Probe volume therefore
	 * depends on the node's interval, not on how often cron ticks — tightening
	 * the tick to improve mail latency does not multiply monitoring traffic.
	 *
	 * A node never checked before is always due.
	 */
	private function is_node_due($node, $now_utc): bool {
		$last = trim((string)$node->get('mgn_uptime_last_check'));
		if ($last === '') {
			return true;
		}
		$interval = (int)$node->get('mgn_uptime_interval_seconds');
		if ($interval <= 0) {
			return true; // 0 or unset means every pass
		}
		$elapsed = strtotime($now_utc) - strtotime($last);
		// A clock skew or bad stored value must not wedge a node permanently.
		if ($elapsed < 0) {
			return true;
		}
		return $elapsed >= $interval;
	}

	/**
	 * Dispatch to the configured check type and return:
	 *   ['ok' => bool, 'message' => ?string, 'status' => 'done'|'skip']
	 */
	private function run_check($node): array {
		$type = NodeMonitorHealth::effective_check_type($node);

		if ($type === 'tcp_port') {
			return $this->check_tcp_port($node);
		}
		if ($type === 'http_status') {
			return $this->check_http_status($node);
		}
		return $this->check_api($node);
	}

	/**
	 * tcp_port check: open a TCP connection to the node's host on the
	 * configured port. For services with no web endpoint — an inbound mail
	 * relay is proven alive by accepting connections on 25, which is exactly
	 * what it exists to do. A refused or timed-out connection is down. The one
	 * inconclusive case is a host given as a name that this machine cannot
	 * resolve — nothing was ever dialled, so there is no result to report.
	 */
	private function check_tcp_port($node): array {
		$host = trim((string)$node->get('mgn_host'));
		$port = (int)$node->get('mgn_uptime_tcp_port');

		$errno = 0;
		$errstr = '';
		$sock = @fsockopen($host, $port, $errno, $errstr, self::TIMEOUT_SECONDS);
		if ($sock === false) {
			$detail = trim($errstr) !== '' ? $errstr : ('error ' . $errno);
			// fsockopen reports a DNS failure only in the message text, and with
			// errno 0 — so classify on the message alone.
			if (NodeMonitorHealth::is_name_resolution_failure(0, $detail)) {
				return $this->unresolvable($host, $detail);
			}
			return [
				'ok'      => false,
				'status'  => 'done',
				'message' => sprintf('TCP %s:%d unreachable (%s)', $host, $port, $detail),
			];
		}
		fclose($sock);
		return ['ok' => true, 'message' => null, 'status' => 'done'];
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
			$detail = $result['message'] ?? 'transport failure';
			// fetch_status_via_api folds every curl failure into one reason, so the
			// resolver case is separated back out here by its message.
			if (NodeMonitorHealth::is_name_resolution_failure($result['errno'] ?? 0, $detail)) {
				return $this->unresolvable(parse_url((string)$node->get('mgn_site_url'), PHP_URL_HOST), $detail);
			}
			return ['ok' => false, 'message' => $detail, 'status' => 'done'];
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
		$opts = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_NOBODY         => true,
			CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
			CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 5,
			CURLOPT_SSL_VERIFYPEER => $node->get('mgn_tls_insecure') ? false : true,
			CURLOPT_SSL_VERIFYHOST => $node->get('mgn_tls_insecure') ? 0 : 2,
		];
		// Pin the request to THIS node's own IP while preserving SNI/Host, so a node
		// behind a shared or round-robin hostname (e.g. dual-A-record DNS servers) is
		// checked as itself rather than whichever A record DNS happens to return.
		// Only when directly exposed: pinning a Cloudflare-fronted node to its origin
		// IP bypasses the edge and hits the Apache default-vhost fallback cert, which
		// fails SNI/cert validation for the site's hostname (false "down").
		$node_ip = trim((string)$node->get('mgn_host'));
		$parts    = parse_url($health_url);
		$url_host = $parts['host'] ?? '';
		$url_port = $parts['port'] ?? ((($parts['scheme'] ?? 'https') === 'https') ? 443 : 80);
		if ($url_host !== '' && $node_ip !== '' && $url_host !== $node_ip
				&& filter_var($node_ip, FILTER_VALIDATE_IP) && !filter_var($url_host, FILTER_VALIDATE_IP)) {
			try {
				$public_ips = DnsResolver::getA($url_host);
				if (in_array($node_ip, $public_ips, true)) {
					$opts[CURLOPT_RESOLVE] = ["{$url_host}:{$url_port}:{$node_ip}"];
				}
			} catch (DnsLookupException $e) {
				// transient resolver failure: fall back to unpinned (pre-existing behavior)
			}
		}
		curl_setopt_array($ch, $opts);
		curl_exec($ch);
		$errno  = curl_errno($ch);
		$errmsg = curl_error($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

		$node->set('mgn_last_status_check', gmdate('Y-m-d H:i:s'));

		if ($errno) {
			$detail = $errmsg ?: ('curl errno ' . $errno);
			if (NodeMonitorHealth::is_name_resolution_failure($errno, $detail)) {
				return $this->unresolvable($url_host !== '' ? $url_host : $health_url, $detail);
			}
			return ['ok' => false, 'message' => $detail, 'status' => 'done'];
		}
		if ($status >= 200 && $status < 400) {
			return ['ok' => true, 'message' => null, 'status' => 'done'];
		}
		return ['ok' => false, 'message' => 'HTTP ' . $status, 'status' => 'done'];
	}

	/**
	 * The inconclusive result for a probe that never left this machine because
	 * the hostname would not resolve.
	 *
	 * Worded from the monitoring host's point of view on purpose: the operator
	 * reading it on the dashboard needs to know the fault is here, not on the
	 * node, since a broken resolver marks every node at once and the node
	 * itself may be serving traffic perfectly.
	 */
	private function unresolvable(?string $hostname, string $detail): array {
		$name = trim((string)$hostname);
		return [
			'ok'      => false,
			'status'  => 'skip',
			'message' => 'monitoring host could not resolve '
			           . ($name !== '' ? $name : 'the node hostname') . ' (' . $detail . ')',
		];
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
	private function send_alert($node, string $transition, array $result, ?string $down_since = null): bool {
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
			// $down_since is captured by the caller before apply_state clears it.
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

	/**
	 * Certificate-expiry check for a node. Independent of the up/down probe.
	 *
	 * Self-limits to certs WE renew on directly-exposed nodes: the node's own
	 * mgn_host must appear in the public A records for the site hostname.
	 * Cloudflare-fronted hostnames resolve to Cloudflare, not the origin, so
	 * they are skipped (Cloudflare renews that edge cert). The served cert is
	 * read over the wire pinned to mgn_host with correct SNI, and must actually
	 * cover the hostname — a shared default-vhost fallback cert is ignored.
	 *
	 * On success, stores mgn_cert_expiry_ts and, when days-remaining is under
	 * the warn threshold, emails a warning (once, then re-alerting every
	 * CERT_RECHECK_ALERT_DAYS while still under). Clears the alert stamp when a
	 * fresh cert pushes the date back past the threshold.
	 *
	 * @return array{modified:bool,alerted:bool}
	 */
	private function check_cert_expiry($node): array {
		$out = ['modified' => false, 'alerted' => false];

		$site = trim((string)$node->get('mgn_site_url'));
		$host = trim((string)$node->get('mgn_host'));
		if ($site === '' || $host === '' || !filter_var($host, FILTER_VALIDATE_IP)) {
			return $out;
		}
		$hostname = parse_url($site, PHP_URL_HOST);
		if (!$hostname || filter_var($hostname, FILTER_VALIDATE_IP)) {
			return $out; // need an FQDN that can carry a cert
		}

		// Directly-exposed check: mgn_host must be one of the hostname's public A records.
		try {
			$public_ips = DnsResolver::getA($hostname);
		} catch (DnsLookupException $e) {
			return $out; // transient resolver failure: leave state untouched
		}
		if (!in_array($host, $public_ips, true)) {
			return $out; // fronted / not directly exposed — not our cert to monitor
		}

		$cert = $this->fetch_peer_cert($host, $hostname);
		if ($cert === null || empty($cert['validTo_time_t'])) {
			return $out; // handshake failure is the uptime check's job, not this one
		}
		if (!$this->cert_covers_host($cert, $hostname)) {
			return $out; // fallback/default-vhost cert — nothing dedicated to monitor here
		}

		$not_after = (int)$cert['validTo_time_t'];
		$node->set('mgn_cert_expiry_ts', gmdate('Y-m-d H:i:s', $not_after));
		$out['modified'] = true;

		$warn_days = (int)Globalvars::get_instance()->get_setting('server_manager_cert_expiry_warn_days');
		if ($warn_days <= 0) { $warn_days = 21; }
		$days_left  = (int)floor(($not_after - time()) / 86400);
		$alerted_ts = $node->get('mgn_cert_alerted_ts');

		if ($days_left < $warn_days) {
			$due = ($alerted_ts === null || $alerted_ts === '')
				|| (time() - strtotime($alerted_ts . ' UTC') >= self::CERT_RECHECK_ALERT_DAYS * 86400);
			if ($due && $this->send_cert_alert($node, $days_left, $not_after)) {
				$node->set('mgn_cert_alerted_ts', gmdate('Y-m-d H:i:s'));
				$out['alerted'] = true;
			}
		} elseif ($alerted_ts !== null && $alerted_ts !== '') {
			$node->set('mgn_cert_alerted_ts', NULL); // renewed — reset so a future dip re-alerts
		}

		return $out;
	}

	/**
	 * Read the peer certificate served at $ip:443 for SNI $sni, or null on a
	 * connection/handshake failure. Validity is deliberately NOT verified — we
	 * must be able to read the notAfter of an expired or near-expiry cert.
	 *
	 * @return array|null Parsed cert (openssl_x509_parse shape) or null.
	 */
	private function fetch_peer_cert(string $ip, string $sni) {
		$ctx = stream_context_create(['ssl' => [
			'capture_peer_cert' => true,
			'verify_peer'       => false,
			'verify_peer_name'  => false,
			'SNI_enabled'       => true,
			'peer_name'         => $sni,
		]]);
		$errno = 0; $errstr = '';
		$client = @stream_socket_client(
			'ssl://' . $ip . ':443',
			$errno, $errstr, self::TIMEOUT_SECONDS,
			STREAM_CLIENT_CONNECT, $ctx
		);
		if ($client === false) {
			return null;
		}
		$params = stream_context_get_params($client);
		fclose($client);
		if (empty($params['options']['ssl']['peer_certificate'])) {
			return null;
		}
		$parsed = @openssl_x509_parse($params['options']['ssl']['peer_certificate']);
		return is_array($parsed) ? $parsed : null;
	}

	/**
	 * Whether a parsed cert's CN or SANs cover $hostname (exact or single-label
	 * wildcard). Guards against reading a shared default-vhost fallback cert.
	 */
	private function cert_covers_host(array $cert, string $hostname): bool {
		$hostname = strtolower($hostname);
		$names = [];
		if (!empty($cert['subject']['CN'])) {
			$names[] = strtolower($cert['subject']['CN']);
		}
		if (!empty($cert['extensions']['subjectAltName'])) {
			foreach (explode(',', $cert['extensions']['subjectAltName']) as $entry) {
				$entry = trim($entry);
				if (stripos($entry, 'DNS:') === 0) {
					$names[] = strtolower(substr($entry, 4));
				}
			}
		}
		foreach ($names as $n) {
			if ($n === $hostname) {
				return true;
			}
			if (strpos($n, '*.') === 0) {
				$suffix = substr($n, 1); // ".example.com"
				$dot    = strpos($hostname, '.');
				if ($dot !== false && substr($hostname, $dot) === $suffix) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Send the cert-expiry warning email. Returns true on send, false if no
	 * recipient resolved.
	 */
	private function send_cert_alert($node, int $days_left, int $not_after): bool {
		$to = $this->resolve_alert_recipient();
		if (!$to) {
			error_log('RunNodeUptimeChecks: no alert recipient for cert warning on node ' . $node->get('mgn_slug'));
			return false;
		}
		$name   = $node->get('mgn_name');
		$host   = parse_url((string)$node->get('mgn_site_url'), PHP_URL_HOST);
		$expiry = gmdate('Y-m-d H:i:s', $not_after) . ' UTC';

		if ($days_left < 0) {
			$subject  = '[' . $name . '] TLS certificate EXPIRED';
			$headline = 'The TLS certificate has EXPIRED (' . abs($days_left) . ' day(s) ago).';
		} else {
			$subject  = '[' . $name . '] TLS certificate expires in ' . $days_left . ' day(s)';
			$headline = 'The TLS certificate expires in ' . $days_left . ' day(s).';
		}
		$body = "Node: {$name}\n"
		      . "Host: {$host}\n"
		      . "{$headline}\n"
		      . "Expires: {$expiry}\n\n"
		      . "Automatic renewal appears to be failing. Check the certificate manager on this node.\n";

		try {
			EmailSender::quickSend($to, $subject, $body);
			return true;
		} catch (\Throwable $e) {
			error_log('RunNodeUptimeChecks: cert alert send failed for node ' . $node->get('mgn_slug') . ': ' . $e->getMessage());
			return false;
		}
	}

	private function format_duration(int $seconds): string {
		if ($seconds < 60)   return $seconds . 's';
		if ($seconds < 3600) return intval(round($seconds / 60)) . 'm';
		$h = intval($seconds / 3600);
		$m = intval(($seconds % 3600) / 60);
		return $m > 0 ? "{$h}h{$m}m" : "{$h}h";
	}
}
