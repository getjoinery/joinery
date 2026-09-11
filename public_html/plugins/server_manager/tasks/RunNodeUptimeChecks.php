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
 * Each enabled node also gets an independent TLS certificate check (over the
 * wire, pinned to the node's own IP, so it sees the origin certificate whether
 * or not an edge fronts the name) that warns when renewal is overdue, when
 * expiry is near, and when www reaches the origin uncovered. See
 * check_cert_expiry().
 *
 * @version 2.3 - a third alert reason, uncovered: the origin answers TLS with a certificate for
 *                other names (a shared fallback vhost, the install-time placeholder). Strict
 *                would take the site down, so it is said, on the same cadence; nothing is stored
 *                for a foreign certificate
 * @version 2.2 - watches the origin certificate of every node, fronted or not: the A-record
 *                gate that skipped anything behind Cloudflare is gone and cert_covers_host()
 *                alone decides whose certificate it is. Alerts on renewal overdue by more than
 *                a day, not only on expiry approaching, and on www reaching the origin without
 *                a certificate that covers it (a Strict flip would take www dark)
 * @version 2.1 - the cert-expiry alert reports whether renewal is actually overdue, and by how
 *                much, instead of guessing that "renewal appears to be failing": issue date,
 *                lifetime, renewal-due date, issuer, serial, and whether the cert changed
 * @version 2.0 - queues a check_status job for every enabled agent node whose status facts are
 *                older than STATUS_REFRESH_SECONDS: nothing else ever measured a node again after
 *                its last button press or deploy, so versions and certificate facts went stale
 * @version 1.9 - probes moved to NodeHealthProbe so the uptime pass and check_status cannot
 *                disagree about reachability; a health document read on the way past is folded,
 *                and only a pass that measured something dates the node figures
 * @version 1.8 - the http_status probe no longer stamps mgn_last_status_check. It reads no status
 *                data, so stamping it made months-stale figures read as seconds old on every tick
 * @version 1.7 - sweeps stale agent-channel claims: a job claimed by a node agent that never
 *                reported back is returned to the queue instead of wedging that node's lock
 * @version 1.6 - a probe that dies in this machine's own resolver is inconclusive, not down:
 *                a broken resolver on the monitoring host no longer alerts the whole fleet down
 * @version 1.5 - P-19: recovered alert reports real down duration (capture down_since before apply_state clears it)
 */
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class RunNodeUptimeChecks implements ScheduledTaskInterface {

	const TIMEOUT_SECONDS         = 10;
	const FAILURE_THRESHOLD       = 2;
	const CERT_RECHECK_ALERT_DAYS = 3;
	/** How old an agent node's status facts may be before a check_status is queued. */
	const STATUS_REFRESH_SECONDS  = 6 * 3600;

	public function run(array $config): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeMonitorHealth.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeHealthProbe.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
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

			// Independent TLS certificate check on the node's own address; a no-op
			// (and no save) when the node presents no certificate for its name.
			$cert = $this->check_cert_expiry($node);
			if ($cert['modified'] || $cert['alerted']) {
				$node->save();
			}
			if ($cert['alerted']) {
				$cert_alerts++;
			}
		}

		// A node agent claims a primitive job and then reports back. When it
		// never reports — it crashed, the network went, the box rebooted — the
		// job sits in 'running' holding that node's concurrency lock, and
		// nothing else for the node can move. The claim endpoint sweeps on
		// every poll, which heals an agent that comes back; this sweep is for
		// the agent that does not, where no poll is ever going to arrive.
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
		$requeued = ManagementJob::requeueStaleClaims();
		$refreshed = $this->refresh_status_facts($nodes, $now_utc);

		$message = sprintf('Checked %d node(s); %d up/down alert(s); %d cert alert(s); %d skipped; %d not due.', $checked, $alerts, $cert_alerts, $skipped, $not_due);
		if ($requeued > 0) {
			$message .= sprintf(' %d stale agent claim(s) returned to the queue.', $requeued);
		}
		if ($refreshed > 0) {
			$message .= sprintf(' %d status refresh(es) queued.', $refreshed);
		}
		if (!empty($errors)) {
			$message .= ' Notes: ' . implode(' | ', array_slice($errors, 0, 5));
		}
		return ['status' => 'success', 'message' => $message];
	}

	/**
	 * Keep an agent node's status facts current.
	 *
	 * Version, certificate, disk and memory facts are measured only by a
	 * check_status job, and until now nothing queued one on its own: a node
	 * was measured when a person pressed the button or a deploy ran, then
	 * never again, and the fleet page showed the version a node had answered
	 * weeks earlier. Every enabled node whose agent offers the primitive and
	 * whose facts are older than STATUS_REFRESH_SECONDS gets one queued here,
	 * whatever its uptime setting — the up/down probe and the facts measure
	 * different things. A queued or running job, or one completed inside the
	 * window, is cover, so one stale node yields one job per window.
	 *
	 * @param iterable $nodes   live ManagedNode rows
	 * @param string   $now_utc 'Y-m-d H:i:s'
	 * @return int jobs queued
	 */
	public function refresh_status_facts($nodes, string $now_utc): int {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
		$queued = 0;
		$floor = strtotime($now_utc . ' UTC') - self::STATUS_REFRESH_SECONDS;
		foreach ($nodes as $node) {
			if (!$node->get('mgn_enabled') || $node->get('mgn_delete_time')) {
				continue;
			}
			if (!JobCommandBuilder::has_primitive($node, 'check_status')) {
				continue;
			}
			$last = trim((string)$node->get('mgn_last_status_check'));
			if ($last !== '' && strtotime($last . ' UTC') >= $floor) {
				continue;
			}
			if (ManagementJob::activeOrRecentForNode($node->key, 'check_status', self::STATUS_REFRESH_SECONDS)) {
				continue;
			}
			ManagementJob::createFromBuild($node->key, 'check_status',
				JobCommandBuilder::build_check_status_primitive($node), null, null);
			$queued++;
		}
		return $queued;
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
		return $this->from_probe($node, NodeHealthProbe::tcp($node, self::TIMEOUT_SECONDS));
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
	 * http_status check: ask the node's web endpoint whether it answers.
	 *
	 * Where the node publishes a health document, that document is also read and
	 * its figures folded onto the node — see check_status_without_ssh.md. Two
	 * things follow from that, and both matter:
	 *
	 * mgn_last_status_check is stamped ONLY on a pass that actually measured
	 * something. A probe against a node with no health document establishes that
	 * a web server answered and nothing more, and dating the node's figures to
	 * that would put "Last checked: a minute ago" over disk and load numbers
	 * taken months earlier. Consistency with a number nobody measured is not
	 * consistency.
	 *
	 * The up/down conclusion is drawn from reachability alone, never from the
	 * contents of the document. A node whose service reports itself degraded is
	 * a node that answered.
	 */
	private function check_http_status($node): array {
		return $this->from_probe($node, NodeHealthProbe::http($node, self::TIMEOUT_SECONDS));
	}

	/**
	 * Adapt a probe result to this task's up/down vocabulary, folding any figures
	 * the probe collected on the way past.
	 */
	private function from_probe($node, array $probe): array {
		if ($probe['unresolvable']) {
			return $this->unresolvable($probe['host'], $probe['detail']);
		}
		if (!empty($probe['measured'])) {
			$now = gmdate('Y-m-d H:i:s');
			$node->set('mgn_last_status_data', json_encode(JobResultProcessor::fold_status_data(
				$node->get('mgn_last_status_data'), $probe['measured'],
				NodeHealthProbe::TRANSPORT, $now)));
			$node->set('mgn_last_status_check', $now);
		}
		return ['ok' => $probe['ok'], 'message' => $probe['message'], 'status' => 'done'];
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
	 * Certificate check for a node. Independent of the up/down probe.
	 *
	 * The served cert is read over the wire pinned to mgn_host with correct
	 * SNI, and must actually cover the hostname — a shared default-vhost
	 * fallback cert is ignored. A name fronted by Cloudflare is watched the
	 * same as one served direct: the edge renews its own certificate, but the
	 * origin behind it holds another that expires on its own schedule, and on
	 * Full (not Strict) that expiry is invisible from outside right up until
	 * Strict is enabled and it becomes an outage.
	 *
	 * On success, stores mgn_cert_expiry_ts and, when cert_alert_verdict() or
	 * www_gap() finds something to say, emails a warning (once, then
	 * re-alerting every CERT_RECHECK_ALERT_DAYS while it persists). An origin
	 * presenting a certificate for other names is its own reason, on the same
	 * cadence, with nothing stored. Clears the alert stamp once nothing is
	 * wrong any more.
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

		// The node's own address is probed with the site's name as SNI. Whether the
		// name publicly resolves here or to an edge in front is irrelevant: the
		// node holds and renews its own certificate either way, and that is the
		// one at risk. cert_covers_host() is the sole "is this ours" test — a
		// shared fallback certificate for some other name is declined below.
		$cert = $this->fetch_peer_cert($host, $hostname);
		if ($cert === null || empty($cert['validTo_time_t'])) {
			return $out; // handshake failure is the uptime check's job, not this one
		}
		$alerted_ts = $node->get('mgn_cert_alerted_ts');
		$now        = time();
		if (!$this->cert_covers_host($cert, $hostname)) {
			// The origin answers TLS but with somebody else's certificate — a
			// shared fallback vhost, or the placeholder minted at install. Under
			// Full the site serves; under Full (Strict) it goes dark. Nothing is
			// stored for a foreign certificate (its expiry is not this node's),
			// but the gap is its own alert reason.
			$due = ($alerted_ts === null || $alerted_ts === '')
				|| ($now - strtotime($alerted_ts . ' UTC') >= self::CERT_RECHECK_ALERT_DAYS * 86400);
			if ($due && $this->send_uncovered_alert($node, $hostname, $this->cert_names($cert))) {
				$node->set('mgn_cert_alerted_ts', gmdate('Y-m-d H:i:s'));
				$out['modified'] = true;
				$out['alerted']  = true;
			}
			return $out;
		}

		$not_after  = (int)$cert['validTo_time_t'];
		$not_before = isset($cert['validFrom_time_t']) ? (int)$cert['validFrom_time_t'] : 0;
		// Read before the overwrite: an expiry date unchanged since the previous
		// pass is proof nothing renewed, which the alert says out loud.
		$prev_expiry = trim((string)$node->get('mgn_cert_expiry_ts'));
		$node->set('mgn_cert_expiry_ts', gmdate('Y-m-d H:i:s', $not_after));
		$out['modified'] = true;

		$warn_days = (int)Globalvars::get_instance()->get_setting('server_manager_cert_expiry_warn_days');
		if ($warn_days <= 0) { $warn_days = 21; }
		$days_left  = (int)floor(($not_after - $now) / 86400);

		$reason  = $this->cert_alert_verdict($now, $not_before, $not_after, $warn_days);
		$www_gap = $this->www_gap($host, $hostname);

		if ($reason !== null || $www_gap !== '') {
			$due = ($alerted_ts === null || $alerted_ts === '')
				|| ($now - strtotime($alerted_ts . ' UTC') >= self::CERT_RECHECK_ALERT_DAYS * 86400);
			if ($due && $this->send_cert_alert($node, $days_left, $cert, $prev_expiry, $alerted_ts, $reason, $www_gap)) {
				$node->set('mgn_cert_alerted_ts', gmdate('Y-m-d H:i:s'));
				$out['alerted'] = true;
			}
		} elseif ($alerted_ts !== null && $alerted_ts !== '') {
			$node->set('mgn_cert_alerted_ts', NULL); // renewed — reset so a future dip re-alerts
		}

		return $out;
	}

	/**
	 * Why the served certificate warrants an alert, or null when it does not.
	 *
	 * Two independent triggers, whichever comes first:
	 *
	 *   'overdue'  — renewal was due more than a day ago and the certificate on
	 *                the wire is still the one that was due. No stored fingerprint
	 *                is needed for that second half: the due date is computed from
	 *                the served certificate's own dates, so a replacement carries a
	 *                fresh notBefore and its own due date lies in the future.
	 *   'expiring' — fewer than $warn_days remain, the safety net for a
	 *                certificate whose issue date cannot be read.
	 *
	 * A 90-day certificate is due at day 60 and the warn threshold defaults to 21
	 * days, so without the first trigger renewal can be provably failing for nine
	 * days before anything is said.
	 */
	private function cert_alert_verdict(int $now, int $not_before, int $not_after, int $warn_days): ?string {
		$due_ts = $this->renewal_due_ts($not_before, $not_after);
		if ($due_ts !== null && $now - $due_ts > 86400) {
			return 'overdue';
		}
		if ((int)floor(($not_after - $now) / 86400) < $warn_days) {
			return 'expiring';
		}
		return null;
	}

	/**
	 * Whether www.<hostname> reaches this origin without a certificate that
	 * covers it — the one gap a Cloudflare Full (Strict) flip turns into an
	 * outage while the apex looks perfectly healthy. Returns the alert reason,
	 * or '' when there is nothing to say: www does not resolve (nothing reaches
	 * the origin under that name), the resolver failed (say nothing rather than
	 * something wrong), or the handshake failed (the uptime probe's job).
	 */
	private function www_gap(string $ip, string $hostname): string {
		$www = 'www.' . $hostname;
		try {
			$www_ips = DnsResolver::getA($www);
		} catch (DnsLookupException $e) {
			return '';
		}
		if (empty($www_ips)) {
			return '';
		}
		return $this->www_coverage_gap($this->fetch_peer_cert($ip, $www), $www);
	}

	/**
	 * The network-free half of www_gap(): given the certificate the origin
	 * presented for SNI $www, the alert reason or ''.
	 */
	private function www_coverage_gap(?array $www_cert, string $www): string {
		if ($www_cert === null) {
			return '';
		}
		if ($this->cert_covers_host($www_cert, $www)) {
			return '';
		}
		return $www . ' is not covered by the origin certificate; Strict would reject it';
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

	/** The names a parsed certificate carries: its CN and every DNS SAN, lowercased, deduplicated. */
	private function cert_names(array $cert): array {
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
		return array_values(array_unique($names));
	}

	/**
	 * Whether a parsed cert's CN or SANs cover $hostname (exact or single-label
	 * wildcard). Guards against reading a shared default-vhost fallback cert.
	 */
	private function cert_covers_host(array $cert, string $hostname): bool {
		$hostname = strtolower($hostname);
		$names = $this->cert_names($cert);
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
	 * The reason and wording for an origin that presents a certificate for
	 * other names. Pure, so it is pinned from an injected name list.
	 *
	 * @return array{subject:string,body:string}
	 */
	private function uncovered_alert_text(string $node_name, string $hostname, array $presented): array {
		$names = count($presented) > 0 ? implode(', ', $presented) : 'no name at all';
		return [
			'subject' => '[' . $node_name . '] origin certificate does not cover ' . $hostname,
			'body'    => "The origin presents a certificate for {$names}, not for {$hostname}; Strict would take the site down.\n\n"
			           . "Under Cloudflare Full the site serves anyway, which is why nothing else looks wrong. Under Full (Strict)\n"
			           . "the edge rejects the handshake and the site goes dark. The certificate on the wire is somebody\n"
			           . "else's — a shared fallback vhost on this host, or the placeholder minted at install — so its\n"
			           . "expiry is not this node's and is not recorded.\n\n"
			           . "Issue the certificate for the apex and www on the box that terminates TLS for it:\n"
			           . "  sudo /var/www/html/*/maintenance_scripts/sysadmin_tools/issue_origin_cert.sh {$hostname}\n",
		];
	}

	/** Send the uncovered-origin alert. Returns true on send, false if no recipient resolved. */
	private function send_uncovered_alert($node, string $hostname, array $presented): bool {
		$to = $this->resolve_alert_recipient();
		if (!$to) {
			error_log('RunNodeUptimeChecks: no alert recipient for uncovered-origin warning on node ' . $node->get('mgn_slug'));
			return false;
		}
		$text = $this->uncovered_alert_text((string)$node->get('mgn_name'), $hostname, $presented);
		$ip   = trim((string)$node->get('mgn_host'));
		$body = "Node: " . $node->get('mgn_name') . "\n"
		      . "Host: {$hostname}" . ($ip !== '' ? " ({$ip})" : '') . "\n\n"
		      . $text['body'];
		$detail = $this->node_detail_url($node);
		if ($detail !== '') {
			$body .= "\nNode detail: {$detail}\n";
		}
		try {
			EmailSender::quickSend($to, $text['subject'], $body);
			return true;
		} catch (\Throwable $e) {
			error_log('RunNodeUptimeChecks: uncovered-origin alert send failed for node ' . $node->get('mgn_slug') . ': ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * Send the cert-expiry warning email. Returns true on send, false if no
	 * recipient resolved.
	 *
	 * "Expires in N days" on its own does not say whether anything is wrong —
	 * a healthy 90-day certificate spends every day of its life expiring. What
	 * says something is wrong is that the renewal date has already passed and
	 * the certificate on the wire is still the old one. Both facts are read
	 * straight off the served certificate, so the mail carries the diagnosis
	 * instead of the guess it used to make.
	 *
	 * @param array       $cert        Parsed served cert (openssl_x509_parse shape).
	 * @param string      $prev_expiry mgn_cert_expiry_ts as it stood before this pass.
	 * @param string|null $alerted_ts  mgn_cert_alerted_ts, i.e. when we last warned.
	 * @param string|null $reason      cert_alert_verdict(): 'overdue', 'expiring' or null.
	 * @param string      $www_gap     www_gap(): the www reason, or '' when www is fine.
	 */
	private function send_cert_alert($node, int $days_left, array $cert, string $prev_expiry = '', ?string $alerted_ts = null, ?string $reason = 'expiring', string $www_gap = ''): bool {
		$to = $this->resolve_alert_recipient();
		if (!$to) {
			error_log('RunNodeUptimeChecks: no alert recipient for cert warning on node ' . $node->get('mgn_slug'));
			return false;
		}
		$name      = $node->get('mgn_name');
		$host      = parse_url((string)$node->get('mgn_site_url'), PHP_URL_HOST);
		$ip        = trim((string)$node->get('mgn_host'));
		$not_after = (int)$cert['validTo_time_t'];
		$expiry    = gmdate('Y-m-d H:i:s', $not_after) . ' UTC';

		if ($days_left < 0) {
			$subject  = '[' . $name . '] TLS certificate EXPIRED';
			$headline = 'The TLS certificate has EXPIRED (' . abs($days_left) . ' day(s) ago).';
		} elseif ($reason === 'overdue') {
			$subject  = '[' . $name . '] TLS certificate renewal is overdue';
			$headline = 'Renewal of the TLS certificate is overdue; it expires in ' . $days_left . ' day(s).';
		} elseif ($reason === 'expiring') {
			$subject  = '[' . $name . '] TLS certificate expires in ' . $days_left . ' day(s)';
			$headline = 'The TLS certificate expires in ' . $days_left . ' day(s).';
		} else {
			$subject  = '[' . $name . '] www is not covered by the origin certificate';
			$headline = 'The origin certificate is healthy (' . $days_left . ' day(s) left) but does not cover www.';
		}

		$facts = "Expires:     {$expiry}\n";
		$issuer = $this->describe_issuer($cert);
		if ($issuer !== '') {
			$facts .= "Issuer:      {$issuer}\n";
		}
		if (!empty($cert['serialNumberHex'])) {
			$facts .= "Serial:      " . $cert['serialNumberHex'] . "\n";
		}

		// The renewal verdict. Without a usable notBefore we cannot say when
		// renewal was due, so we state what we measured and claim nothing more.
		$not_before = isset($cert['validFrom_time_t']) ? (int)$cert['validFrom_time_t'] : 0;
		$due_ts     = $this->renewal_due_ts($not_before, $not_after);
		if ($due_ts === null) {
			$verdict = "This certificate carries no usable issue date, so how overdue its renewal is\n"
			         . "cannot be measured from here. Check the certificate manager on the node.\n";
		} else {
			$lifetime_days = (int)round(($not_after - $not_before) / 86400);
			$facts .= "Issued:      " . gmdate('Y-m-d H:i:s', $not_before) . " UTC\n"
			        . "Lifetime:    {$lifetime_days} days\n"
			        . "Renewal due: " . gmdate('Y-m-d H:i:s', $due_ts) . " UTC";

			$overdue_days = (int)floor((time() - $due_ts) / 86400);
			if ($overdue_days >= 0) {
				$facts  .= " — " . $overdue_days . " day(s) ago\n";
				$verdict = "Renewal is {$overdue_days} day(s) overdue, so automatic renewal on this node is\n"
				         . "failing. It is not simply a certificate nearing the end of a normal life.\n";
			} else {
				$facts  .= " — in " . abs($overdue_days) . " day(s)\n";
				$verdict = "Renewal is not overdue yet; it is due in " . abs($overdue_days) . " day(s). If the certificate\n"
				         . "has not been replaced by then, automatic renewal on this node is failing.\n";
			}
		}

		// An unchanged expiry date across two alerts means no new certificate was
		// issued in between — the strongest evidence we can gather from outside.
		if ($alerted_ts !== null && $alerted_ts !== '' && $prev_expiry !== ''
			&& strtotime($prev_expiry . ' UTC') === $not_after) {
			$verdict .= "\nThis is the same certificate reported in the previous alert on "
			          . gmdate('Y-m-d', strtotime($alerted_ts . ' UTC')) . ";\nnothing has renewed since.\n";
		}

		if ($www_gap !== '') {
			$verdict .= "\n" . ucfirst($www_gap) . ". A visitor who types www reaches this origin under that\n"
			          . "name, so under Cloudflare Full (Strict) the www address goes dark. Re-issue the\n"
			          . "certificate for the apex and www together, on the node:\n"
			          . "  sudo /var/www/html/*/maintenance_scripts/sysadmin_tools/issue_origin_cert.sh {$host}\n";
		}

		$where = "\nThis certificate is issued and served by the node itself. This system only reads\n"
		       . "it over the wire, so the reason renewal is failing is in the node's own\n"
		       . "certificate manager log, not here.\n";

		$body = "Node: {$name}\n"
		      . "Host: {$host}" . ($ip !== '' ? " ({$ip})" : '') . "\n\n"
		      . "{$headline}\n\n"
		      . $facts . "\n"
		      . $verdict
		      . $where;

		$detail = $this->node_detail_url($node);
		if ($detail !== '') {
			$body .= "\nNode detail: {$detail}\n";
		}

		try {
			EmailSender::quickSend($to, $subject, $body);
			return true;
		} catch (\Throwable $e) {
			error_log('RunNodeUptimeChecks: cert alert send failed for node ' . $node->get('mgn_slug') . ': ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * When a standard ACME client would have replaced this certificate: at two
	 * thirds of its life, i.e. one third of the lifetime before expiry. That is
	 * what Let's Encrypt's renewal window works out to for a 90-day cert (day
	 * 60), and the proportion holds for shorter and longer lifetimes too.
	 *
	 * Returns null when notBefore is missing or not before notAfter, so a
	 * malformed date produces no claim rather than a wrong one.
	 */
	private function renewal_due_ts(int $not_before, int $not_after): ?int {
		if ($not_before <= 0 || $not_before >= $not_after) {
			return null;
		}
		return $not_after - intdiv($not_after - $not_before, 3);
	}

	/**
	 * Human-readable issuer, "O CN" where both are present. Worth stating: an
	 * issuer that is not the one you expect (a staging or test CA) is its own
	 * answer to why clients are unhappy.
	 */
	private function describe_issuer(array $cert): string {
		$issuer = $cert['issuer'] ?? [];
		$parts  = [];
		foreach (['O', 'CN'] as $field) {
			if (!empty($issuer[$field]) && is_string($issuer[$field])) {
				$parts[] = trim($issuer[$field]);
			}
		}
		return implode(' ', array_unique($parts));
	}

	/**
	 * Absolute URL of this node's detail page, or '' when the site's own
	 * address is not configured.
	 */
	private function node_detail_url($node): string {
		$web = trim((string)Globalvars::get_instance()->get_setting('webDir'), " /");
		$id  = (int)$node->get('mgn_id');
		if ($web === '' || $id <= 0) {
			return '';
		}
		return 'https://' . $web . '/admin/server_manager/node_detail?mgn_id=' . $id;
	}

	private function format_duration(int $seconds): string {
		if ($seconds < 60)   return $seconds . 's';
		if ($seconds < 3600) return intval(round($seconds / 60)) . 'm';
		$h = intval($seconds / 3600);
		$m = intval(($seconds % 3600) / 60);
		return $m > 0 ? "{$h}h{$m}m" : "{$h}h";
	}
}
