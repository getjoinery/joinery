<?php
/**
 * NodeHealthProbe — ask a machine about itself from outside, over no shell.
 *
 * Some machines this plane manages will never carry a Joinery agent and host no
 * Joinery site: the ScrollDaddy DNS servers and the mail relay. Until now the
 * only way to learn anything about them was to SSH in and run df, free and
 * uptime, which stopped working when SSH was removed from the agent.
 *
 * The replacement is not another way to run commands on them. It is to read what
 * they already publish. The DNS boxes run a Go service of ours that answers
 * /health; that service reports the machine's own disk and memory alongside its
 * service facts (see internal/machine/facts.go in the scrolldaddy-dns repo), so
 * a plain HTTP GET answers everything the SSH steps used to. The relay answers
 * on tcp/25, which for a mail relay is the whole health question.
 *
 * Two callers, one implementation, deliberately: the uptime task decides up or
 * down from a probe, and check_status folds the same probe's figures onto the
 * node. They used to be separate code and could disagree about whether a
 * machine was reachable.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeMonitorHealth.php'));

class NodeHealthProbe {

	const TIMEOUT_SECONDS = 10;

	/** Transport name stamped onto every figure this class measures. */
	const TRANSPORT = 'probe';

	/**
	 * A health body is a small JSON document. The cap exists because the same
	 * probe can be pointed at a node's ordinary site URL, where the body is a
	 * web page and reading it would be pointless as well as large.
	 */
	const MAX_BODY_BYTES = 65536;

	/**
	 * Keys a service may publish about ITSELF.
	 */
	const SERVICE_KEYS = ['status', 'db_connected', 'uptime_seconds', 'last_reload'];

	/**
	 * Keys a service may publish about the MACHINE it runs on.
	 *
	 * This list is a contract with two Go implementations that produce it:
	 * primitives/observe_check_status.go in joinery-agent, and
	 * internal/machine/facts.go in scrolldaddy-dns. Both emit these names in
	 * these formats so the fold below does not care which one answered. Renaming
	 * one here does not show up as a wrong figure; it shows up as a fact that
	 * quietly stops being reported, which is why the Go side asserts the names
	 * in its own tests too.
	 */
	const MACHINE_KEYS = [
		'disk_usage_percent', 'disk_total', 'disk_used', 'disk_available',
		'memory_total_mb', 'memory_free_mb', 'memory_used_mb',
	];

	/**
	 * Can this node only be reached by probing?
	 *
	 * True when neither of the two transports that can hold a conversation with
	 * the node is available. It deliberately asks has_api_creds rather than
	 * has_api: this answers "is a probe the right instrument", and a node whose
	 * API is configured but momentarily failing its health check is still an API
	 * node with a problem, not a probe node.
	 */
	public static function is_probe_only($node) {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
		if (JobCommandBuilder::has_primitive($node, 'check_status')) { return false; }
		if (JobCommandBuilder::has_api_creds($node) && !$node->get('mgn_skip_joinery_checks')) { return false; }
		return self::has_target($node);
	}

	/** Is there anything to probe — a URL or a host and port? */
	public static function has_target($node) {
		$described = NodeMonitorHealth::describe_target($node);
		return ($described['problem'] ?? '') === '';
	}

	/**
	 * Run the node's configured probe.
	 *
	 * @return array{
	 *   ok:bool, unresolvable:bool, host:string, detail:string,
	 *   message:?string, measured:array, elapsed_ms:int
	 * }
	 *   `measured` holds only figures actually read this time. A fact the probe
	 *   could not take is absent, never zero: a node reporting 0% disk used
	 *   because the reading failed reads as the healthiest box in the fleet.
	 */
	public static function run($node, $timeout = self::TIMEOUT_SECONDS) {
		$type = NodeMonitorHealth::effective_check_type($node);
		if ($type === 'tcp_port') {
			return self::tcp($node, $timeout);
		}
		return self::http($node, $timeout);
	}

	/**
	 * TCP reachability. For a service with no web endpoint — an inbound mail
	 * relay is proven alive by accepting connections on 25, which is exactly
	 * what it exists to do.
	 *
	 * A refused or timed-out connection is down. The one inconclusive case is a
	 * host given as a name this machine cannot resolve: nothing was ever
	 * dialled, so there is no result to report about the node.
	 */
	public static function tcp($node, $timeout = self::TIMEOUT_SECONDS) {
		$host = trim((string)$node->get('mgn_host'));
		$port = (int)$node->get('mgn_uptime_tcp_port');

		$errno  = 0;
		$errstr = '';
		$started = microtime(true);
		$sock = @fsockopen($host, $port, $errno, $errstr, $timeout);
		$elapsed = (int)round((microtime(true) - $started) * 1000);

		if ($sock === false) {
			$detail = trim($errstr) !== '' ? $errstr : ('error ' . $errno);
			return self::result(false, $host, $detail,
				sprintf('TCP %s:%d unreachable (%s)', $host, $port, $detail),
				[], $elapsed,
				// fsockopen reports a DNS failure only in the message text, and
				// with errno 0 — so classify on the message alone.
				NodeMonitorHealth::is_name_resolution_failure(0, $detail));
		}
		fclose($sock);

		return self::result(true, $host, '', null,
			['port_reachable' => $port, 'probe_latency_ms' => $elapsed], $elapsed, false);
	}

	/**
	 * HTTP reachability, and whatever the endpoint says about itself.
	 *
	 * The body is read ONLY when the node has an explicit health check URL. A
	 * node without one is probed at its site root, where the body is a web page
	 * that has nothing to tell us and may be large. That is the whole rule; it
	 * keeps this method's cost identical to the HEAD it replaces everywhere
	 * except the handful of nodes that publish a health document.
	 */
	public static function http($node, $timeout = self::TIMEOUT_SECONDS) {
		$health_url = trim((string)$node->get('mgn_health_check_url'));
		$read_body  = ($health_url !== '');
		if (!$read_body) {
			$health_url = rtrim((string)$node->get('mgn_site_url'), '/') . '/';
		}

		$ch = curl_init($health_url);
		$opts = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_NOBODY         => !$read_body,
			CURLOPT_CONNECTTIMEOUT => $timeout,
			CURLOPT_TIMEOUT        => $timeout,
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
		$node_ip  = trim((string)$node->get('mgn_host'));
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
				// transient resolver failure: fall back to unpinned
			}
		}

		curl_setopt_array($ch, $opts);
		$started = microtime(true);
		$body    = curl_exec($ch);
		$elapsed = (int)round((microtime(true) - $started) * 1000);
		$errno   = curl_errno($ch);
		$errmsg  = curl_error($ch);
		$status  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$target = $url_host !== '' ? $url_host : $health_url;

		if ($errno) {
			$detail = $errmsg ?: ('curl errno ' . $errno);
			return self::result(false, $target, $detail, $detail, [], $elapsed,
				NodeMonitorHealth::is_name_resolution_failure($errno, $detail));
		}
		if ($status < 200 || $status >= 400) {
			return self::result(false, $target, 'HTTP ' . $status, 'HTTP ' . $status, [], $elapsed, false);
		}

		$measured = ['probe_latency_ms' => $elapsed, 'probe_http_status' => $status];
		if ($read_body && is_string($body)) {
			$measured += self::facts_from_body($body);
		}
		return self::result(true, $target, '', null, $measured, $elapsed, false);
	}

	/**
	 * Pull the recognised facts out of a health document.
	 *
	 * Anything not on the two lists is ignored rather than folded. A health
	 * endpoint is free to publish whatever it likes for its own operators; this
	 * plane stores the keys it knows how to display and date, and an unknown key
	 * arriving in the blob would age there for thirty days as furniture nobody
	 * can read.
	 */
	public static function facts_from_body($body) {
		if (strlen($body) > self::MAX_BODY_BYTES) {
			return [];
		}
		$decoded = json_decode($body, true);
		if (!is_array($decoded)) {
			return [];
		}

		$measured = [];
		foreach (array_merge(self::SERVICE_KEYS, self::MACHINE_KEYS) as $key) {
			if (array_key_exists($key, $decoded) && !is_array($decoded[$key])) {
				$measured[$key] = $decoded[$key];
			}
		}

		// The service's own uptime is not the machine's, and the status blob's
		// `uptime` is a machine figure everywhere else in the fleet. Keeping the
		// service reading under its own name stops a restarted daemon from
		// reading as a rebooted box.
		if (array_key_exists('uptime_seconds', $measured)) {
			$measured['service_uptime_seconds'] = $measured['uptime_seconds'];
			unset($measured['uptime_seconds']);
		}
		return $measured;
	}

	/**
	 * Probe the node, fold what it said onto the node record, and file the
	 * completed job that says it happened.
	 *
	 * There is no queue and no worker here. The probe is one HTTP GET or one TCP
	 * connect, so it finishes inside the request that asked for it and the row is
	 * written already complete — a record of work done, not a request for work.
	 * It is created in a terminal state, so no claim query can ever see it.
	 */
	public static function run_and_record($node, $created_by = null) {
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));

		$result = self::run($node);
		$now    = gmdate('Y-m-d H:i:s');

		if (!empty($result['measured'])) {
			$node->set('mgn_last_status_data', json_encode(JobResultProcessor::fold_status_data(
				$node->get('mgn_last_status_data'), $result['measured'], self::TRANSPORT, $now)));
			$node->set('mgn_last_status_check', $now);
			$node->save();
		}

		$job = new ManagementJob(NULL);
		$job->set('mjb_mgn_node_id', $node->key);
		$job->set('mjb_job_type', 'check_status');
		$job->set('mjb_status', $result['ok'] ? 'completed' : 'failed');
		$job->set('mjb_commands', json_encode(['probe' => 'check_status', 'steps' => []]));
		$job->set('mjb_total_steps', 1);
		$job->set('mjb_current_step', 1);
		$job->set('mjb_created_by', $created_by);
		$job->set('mjb_output', self::transcript($node, $result));
		$job->set('mjb_start_time', $now);
		$job->set('mjb_end_time', $now);
		if (!$result['ok']) {
			$job->set('mjb_error', $result['message'] ?: 'The node did not answer.');
		}
		$job->save();
		return $job;
	}

	/**
	 * What the operator reads on the job page. Same shape as a step transcript,
	 * because it lands on the same page as one.
	 */
	private static function transcript($node, array $result) {
		$type  = NodeMonitorHealth::effective_check_type($node);
		$lines = [];
		$lines[] = "=== [Step 1/1] Probe {$type} ===";
		$lines[] = 'Target: ' . $result['host'];

		if ($result['unresolvable']) {
			$lines[] = '[INCONCLUSIVE: this management node could not resolve the address, so the probe';
			$lines[] = ' never left here and proves nothing about the node.]';
			$lines[] = 'Detail: ' . $result['detail'];
			return implode("\n", $lines) . "\n";
		}
		if (!$result['ok']) {
			$lines[] = '[FAILED: ' . ($result['message'] ?: 'no answer') . ']';
			return implode("\n", $lines) . "\n";
		}

		$lines[] = 'Answered in ' . $result['elapsed_ms'] . 'ms';
		if (empty($result['measured'])) {
			$lines[] = 'Reachable. This node publishes no health document, so nothing else was read.';
			return implode("\n", $lines) . "\n";
		}
		foreach ($result['measured'] as $key => $value) {
			if (is_bool($value)) { $value = $value ? 'true' : 'false'; }
			$lines[] = '  ' . $key . ': ' . $value;
		}
		return implode("\n", $lines) . "\n";
	}

	private static function result($ok, $host, $detail, $message, array $measured, $elapsed, $unresolvable) {
		return [
			'ok'           => (bool)$ok,
			'unresolvable' => (bool)$unresolvable,
			'host'         => (string)$host,
			'detail'       => (string)$detail,
			'message'      => $message,
			'measured'     => $measured,
			'elapsed_ms'   => (int)$elapsed,
		];
	}
}
