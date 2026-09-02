<?php
/**
 * ProvisionPendingSsl - SSL automation scheduled task.
 *
 * For each managed node where mgn_ssl_state='pending' and install is complete,
 * gets it a certificate — by looking first, and asking second.
 *
 * LOOK FIRST. A machine this plane installs tries for its certificate during
 * the install and, when DNS is not pointed here yet, arms its own retry timer
 * (arm_ssl_retry.sh) which issues the moment it is. So most issuance needs no
 * dispatch at all: the node's — or, for a container, its HOST's — check_status
 * already reports every certificate under /etc/letsencrypt/live, and a
 * lineage covering the domain flips the node to active here.
 *
 * ASK SECOND. Where nothing has issued, the plane drives a chain of agent
 * primitives: place a probe token (Cloudflare domains only), fetch it from out
 * here, clear it, then ask the ISSUER to issue. The issuer is the node itself
 * on bare metal and the host's own paired agent for a container — Apache,
 * certbot and /etc/letsencrypt live on the host, and a certificate issued
 * from inside a container is written to a filesystem the next rebuild
 * discards. The steps are separate jobs, so this task is the driver: each
 * tick reads where the chain got to and takes the next step. No SSH anywhere
 * (specs/ssh_single_bootstrap.md WP3).
 *
 * A Cloudflare domain whose routing probe keeps missing is not a fault: it may
 * legitimately be waiting days for the customer's DNS cutover, so it never
 * flips to 'failed'. It retries hourly for ROUTING_FAST_ATTEMPTS tries, then
 * drops to one try every ROUTING_SLOW_GAP, and sends the operator one alert at
 * the changeover. Anything else gives up after ~16 hours of certificate
 * attempts and flips mgn_ssl_state='failed' for manual resolution.
 *
 * @version 1.6 - chain_jobs keys on for_node_id, so a node that issues for others never sees their jobs as its own
 * @version 1.5 - observe first: a certificate the host (or node) already reports flips the node
 *                active; the chain is the on-demand and slow-lane path, with a container's
 *                certificate job addressed to its host and stamped on the site it was for.
 *                The SSH provision_ssl path is gone.
 * @version 1.4 - paired bare-metal nodes provision through agent primitives; the
 *                probe is gated on node core version and the certificate result
 *                is read rather than assumed from the job's exit status
 * @version 1.3 - routing-probe misses back off to a slow lane after the fast attempts
 *                and alert the operator once, instead of retrying hourly in silence
 * @version 1.2 - CF domains awaiting routing (CF_ROUTING_UNVERIFIED) are exempt from the 16h give-up
 * @version 1.1 - P-6: dispatch Cloudflare-proxied domains (builder's CF branch) instead of skipping on the A-record gate
 */
require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));

class ProvisionPendingSsl {

	/** Hourly routing-probe retries before dropping to the slow lane. */
	const ROUTING_FAST_ATTEMPTS = 16;

	/**
	 * First core release whose front controller serves the routing probe.
	 *
	 * The probe writes sm-ssl-probe.txt into the webroot and the plane fetches
	 * it through the domain — but a Joinery site routes every request through
	 * serve.php, so a file sitting in the webroot is not reachable unless the
	 * node's own code has a route for it. serve.php 1.6.0 added that route and
	 * shipped in 0.8.304.
	 *
	 * This is not a nicety. Before that route existed the probe could not pass
	 * on ANY node, and because the failure surfaces as CF_ROUTING_UNVERIFIED it
	 * reads as a Cloudflare or DNS problem: 188 jobs failed that way over ten
	 * days against a node that was configured correctly the whole time. An old
	 * node is refused here, by name, instead of being told it has a DNS problem
	 * it does not have.
	 */
	const PROBE_MIN_CORE_VERSION = '0.8.304';

	/** The chain's job types, in the order the plane runs them. */
	const JOB_PROBE_PLACE  = 'ssl_probe_place';
	const JOB_PROBE_CLEAR  = 'ssl_probe_clear';
	const JOB_CERTIFICATE  = 'provision_certificate';

	/** Seconds between routing-probe retries in the fast lane. */
	const ROUTING_FAST_GAP = 3600;

	/** Seconds between routing-probe retries once the fast lane is exhausted. */
	const ROUTING_SLOW_GAP = 21600;

	/**
	 * Seconds to wait after the last failed attempt before retrying a domain
	 * whose routing probe keeps missing. Hourly while the miss is plausibly
	 * propagation; every six hours once it has missed long enough that a
	 * person should be looking instead.
	 */
	public static function routing_retry_gap(int $failed_attempts): int {
		return ($failed_attempts < self::ROUTING_FAST_ATTEMPTS)
			? self::ROUTING_FAST_GAP
			: self::ROUTING_SLOW_GAP;
	}

	/**
	 * May this node be asked to serve a routing probe?
	 *
	 * An unknown version is refused rather than attempted. The cost of guessing
	 * wrong is not one failed job: it is sixteen hourly failures and then a slow
	 * lane, all of them blaming Cloudflare, before anybody learns the node
	 * simply could not serve the file. Refusing says so on the first tick, and
	 * the fix it names is one an operator can act on.
	 *
	 * @return array{ok:bool,reason:string}
	 */
	public static function probe_gate($node_version): array {
		$version = trim((string)$node_version);

		if ($version === '') {
			return ['ok' => false, 'reason' =>
				'this plane does not know what core version the node runs, so it cannot tell whether the node '
				. 'can serve a routing probe — refresh the node\'s status, then provisioning resumes on its own'];
		}
		if (version_compare($version, self::PROBE_MIN_CORE_VERSION, '<')) {
			return ['ok' => false, 'reason' =>
				"node core is too old for the routing probe (runs {$version}, needs "
				. self::PROBE_MIN_CORE_VERSION . '). Its front controller has no route for '
				. 'sm-ssl-probe.txt, so the token cannot be fetched however well DNS is pointed — upgrade the node'];
		}
		return ['ok' => true, 'reason' => ''];
	}

	public function run(array $config): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_host_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));

		// Nodes that finished install but are still awaiting SSL
		$pending = new MultiManagedNode([
			'ssl_state'     => 'pending',
			'install_state' => null,
			'deleted'       => false,
		]);
		$pending->load();

		if (!count($pending)) {
			return ['status' => 'success', 'message' => 'No nodes pending SSL.'];
		}

		$db       = DbConnector::get_instance()->get_db_link();
		$started  = 0;
		$skipped  = 0;
		$observed = 0;
		$errors   = [];

		foreach ($pending as $node) {
			$slug     = $node->get('mgn_slug');
			$site_url = $node->get('mgn_site_url') ?: '';
			$domain   = parse_url($site_url, PHP_URL_HOST) ?: $node->get('mgn_name');
			$host_ip  = $node->get('mgn_host');

			if (!$domain || !$host_ip) {
				$errors[] = "Node '{$slug}': missing domain or host IP — skipping.";
				continue;
			}

			// Look first. The machine may already hold the certificate — issued
			// during the install, or by the host's own retry timer since.
			if (self::certificate_observed($node, $domain)) {
				$node->set('mgn_ssl_state', 'active');
				$node->save();
				$observed++;
				continue;
			}

			// Resolved once per node per tick: the retry pacing and the DNS gate
			// both need to know whether the domain currently parks at Cloudflare.
			$is_cloudflare = JobCommandBuilder::is_cloudflare_domain($domain);

			$outcome = $this->advance_primitive_ssl($db, $node, $domain, $host_ip, $is_cloudflare);
			$started += $outcome['started'];
			$skipped += $outcome['skipped'];
			if ($outcome['error'] !== '') {
				$errors[] = "Node '{$slug}': " . $outcome['error'];
			}
		}

		$msg = "SSL poll: {$observed} certificate(s) observed, {$started} job(s) started, {$skipped} waiting.";
		if ($errors) {
			$msg .= ' ' . count($errors) . ' error(s): ' . implode('; ', array_slice($errors, 0, 3));
			if (count($errors) > 3) $msg .= ' ...';
			return ['status' => 'error', 'message' => $msg];
		}
		return ['status' => 'success', 'message' => $msg];
	}

	// ------------------------------------------------------------------
	// Observation
	// ------------------------------------------------------------------

	/**
	 * Does a machine this plane already hears from hold a CA-issued
	 * certificate covering the domain?
	 *
	 * Read from the last status report of the node itself and, for a
	 * container, of its host — the agent's check_status enumerates every
	 * lineage in /etc/letsencrypt/live. A self-signed placeholder does not
	 * count; a report that could not read the directory says nothing.
	 */
	public static function certificate_observed($node, string $domain): bool {
		$machines = [$node];
		$host_node = self::host_node_of($node);
		if ($host_node) {
			$machines[] = $host_node;
		}
		foreach ($machines as $machine) {
			$status = $machine->get('mgn_last_status_data');
			if (is_string($status)) {
				$status = json_decode($status, true);
			}
			if (self::status_reports_certificate($status, $domain)) {
				return true;
			}
		}
		return false;
	}

	/** Pure half of the observation, so it can be tested without a node. */
	public static function status_reports_certificate($status, string $domain): bool {
		if (!is_array($status) || !array_key_exists('ssl_certificate_count', $status)) {
			return false;
		}
		if (!empty($status['ssl_certificates_unreadable'])) {
			return false;
		}
		$certs = isset($status['ssl_certificates']) && is_array($status['ssl_certificates'])
			? $status['ssl_certificates'] : [];
		foreach ($certs as $cert) {
			if (!empty($cert['self_signed'])) {
				continue;
			}
			// Expired is not covered: a lineage the renewer let lapse is what
			// this task exists to notice.
			if (!empty($cert['not_after'])) {
				$ts = strtotime((string)$cert['not_after']);
				if ($ts && $ts < time()) {
					continue;
				}
			}
			$names = isset($cert['domains']) && is_array($cert['domains']) ? $cert['domains'] : [];
			foreach ($names as $name) {
				if (JobResultProcessor::cert_name_covers((string)$name, $domain)) {
					return true;
				}
			}
		}
		return false;
	}

	/** A container's host node (its host's own paired agent), or null. */
	public static function host_node_of($node) {
		if (trim((string)$node->get('mgn_container_name')) === '') {
			return null;
		}
		$host_id = (int)$node->get('mgn_mgh_host_id');
		if (!$host_id) {
			return null;
		}
		$host = new ManagedHost($host_id, TRUE);
		if (!$host->key || $host->get('mgh_delete_time')) {
			return null;
		}
		return $host->host_node();
	}

	// ------------------------------------------------------------------
	// The primitive chain
	//
	// One operation, up to four jobs, and a plane action in the middle:
	//
	//   ssl_probe_place       (site node)  write a one-time token into the webroot
	//   [fetch]               (plane)      read it back through the domain
	//   ssl_probe_clear       (site node)  remove the token, whatever the answer
	//   provision_certificate (ISSUER)     issue the certificate
	//
	// The fetch is on the plane deliberately and must stay there: a node
	// asked whether a domain reaches itself is a node answering its own
	// question. The issuer is the site node on bare metal and its HOST's
	// paired agent for a container, so a chain's jobs can sit on two node
	// ids; every job names the site it is FOR (for_node_id), and the chain is
	// reconstructed from that each tick.
	//
	// The probe half runs only for a Cloudflare-proxied domain. For anything
	// else the A-record gate has already proved the domain reaches this host.
	// ------------------------------------------------------------------

	/**
	 * Take the chain's next step for one node.
	 *
	 * @return array{started:int,skipped:int,error:string}
	 */
	private function advance_primitive_ssl($db, $node, string $domain, string $host_ip, bool $is_cloudflare): array {
		$jobs = $this->chain_jobs($db, $node->key);
		$last = $jobs ? end($jobs) : null;

		// Something is already running for this node.
		if ($last && in_array($last['mjb_status'], ['pending', 'running'], true)) {
			return self::chain_result(0, 1);
		}

		// The node has placed the token; the plane's own half is due now.
		if ($last && $last['mjb_job_type'] === self::JOB_PROBE_PLACE && $last['mjb_status'] === 'completed') {
			return $this->verify_routing($node, $domain, $last);
		}

		// End of the chain. What the certificate step did is in its output, not
		// its exit status: setup_ssl.sh returns 0 whether it issued a
		// certificate, fell back, or found no challenge path at all. Flipping
		// the node to active on 'completed' alone would report SSL as live on a
		// node holding nothing — worse than a stuck state, which is at least
		// visible.
		$cert_outcome = null;
		if ($last && $last['mjb_job_type'] === self::JOB_CERTIFICATE && $last['mjb_status'] === 'completed') {
			$cert_outcome = SslProvisionOutcome::classify($last['mjb_output'] ?? '');

			if (SslProvisionOutcome::is_issued($cert_outcome['state'])) {
				$node->set('mgn_ssl_state', 'active');
				$node->save();
				return self::chain_result(0, 1);
			}

			// Nothing this plane can do will change the answer, so it says
			// once, out loud, what would — and then keeps a slow retry going so
			// that making the change is all an operator has to do.
			if (SslProvisionOutcome::needs_operator($cert_outcome['state'])
				&& !$this->alert_sent($jobs, 'certificate_alert_sent')) {
				$this->send_certificate_alert($node, $domain, $cert_outcome);
				$this->mark_alert_sent((int)$last['mjb_id'], 'certificate_alert_sent');
			}
		}

		// Anything still here is a finished attempt that did not get there.
		// Wait out the gap this node has earned, then start again.
		if ($last) {
			$stuck_on_routing = $is_cloudflare && $last['mjb_job_type'] !== self::JOB_CERTIFICATE;
			$misses = self::routing_miss_count($jobs);
			$gap    = self::retry_gap($stuck_on_routing, $misses, $cert_outcome);
			$since  = strtotime($last['mjb_completed_time'] ?: $last['mjb_create_time']);

			if ((time() - $since) < $gap) {
				// Only a state a person has to act on is worth repeating every
				// tick; a transient failure mid-retry is not news.
				$waiting_on_someone = $cert_outcome && SslProvisionOutcome::needs_operator($cert_outcome['state']);
				return self::chain_result(0, 1, $waiting_on_someone ? $cert_outcome['detail'] : '');
			}

			if ($stuck_on_routing) {
				// A cutover the customer has not made is not a fault and never
				// flips the node to 'failed', but it does not get to be
				// invisible either.
				if ($misses >= self::ROUTING_FAST_ATTEMPTS && !$this->alert_sent($jobs, 'routing_alert_sent')) {
					$first = reset($jobs);
					$this->send_routing_alert($node, $domain, $misses, $first['mjb_create_time']);
					$this->mark_alert_sent((int)$last['mjb_id'], 'routing_alert_sent');
				}
			} else if (self::certificate_give_up_due($jobs, time())) {
				$node->set('mgn_ssl_state', 'failed');
				$node->save();
				return self::chain_result(0, 0,
					'SSL provisioning failed after 16+ hours of certificate attempts — manual intervention required.');
			}
		}

		return $this->start_chain($node, $domain, $host_ip, $is_cloudflare);
	}

	/**
	 * How long before the next attempt.
	 *
	 * A routing wait paces on the existing lanes. A certificate that needs
	 * somebody to put a file on the node or repoint a domain drops to the slow
	 * lane too — retrying that hourly produces an identical answer every hour
	 * and buries the one line saying what to fix. Everything else is hourly,
	 * because it might genuinely be different next time.
	 */
	public static function retry_gap(bool $stuck_on_routing, int $misses, ?array $cert_outcome): int {
		if ($stuck_on_routing) {
			return self::routing_retry_gap($misses);
		}
		if ($cert_outcome && SslProvisionOutcome::needs_operator($cert_outcome['state'])) {
			return self::ROUTING_SLOW_GAP;
		}
		return self::ROUTING_FAST_GAP;
	}

	/**
	 * Begin an attempt: place a probe for a Cloudflare domain, or go straight
	 * to the certificate for one that already resolves here.
	 */
	private function start_chain($node, string $domain, string $host_ip, bool $is_cloudflare, $created_by = null): array {
		if (!$is_cloudflare) {
			// A domain still pointing somewhere else is mid-cutover, not
			// broken. Waiting quietly is the right answer, and it is also what
			// keeps this off Let's Encrypt's five-failed-validations-per-hour
			// budget for a name that cannot validate yet.
			if (!self::domain_resolves_to_host($domain, $host_ip)) {
				return self::chain_result(0, 1, '',
					"{$domain} does not resolve to this node ({$host_ip}) yet, so no certificate was "
					. 'requested. Provisioning starts on its own within the hour of the domain pointing here.');
			}
			return $this->dispatch_certificate($node, $domain, $created_by);
		}

		$gate = self::probe_gate($node->get('mgn_joinery_version'));
		if (!$gate['ok']) {
			// Deliberately not a job. Dispatching one would fail as
			// CF_ROUTING_UNVERIFIED and blame Cloudflare for something
			// Cloudflare is not doing.
			return self::chain_result(0, 1, 'cannot verify routing — ' . $gate['reason'] . '.');
		}

		$token = JobCommandBuilder::mint_ssl_probe_token();
		try {
			$built = JobCommandBuilder::build_ssl_probe_place($node, ['token' => $token]);
		} catch (Exception $e) {
			return self::chain_result(0, 0, $e->getMessage());
		}
		// The token is kept on the job because the plane, not the node, is the
		// party that has to recognise it coming back.
		ManagementJob::createFromBuild($node->key, self::JOB_PROBE_PLACE, $built,
			['token' => $token, 'domain' => $domain, 'for_node_id' => (int)$node->key], $created_by);
		return self::chain_result(1, 0);
	}

	/**
	 * Start the chain for one node on demand, from the node's action button.
	 *
	 * The same entry the scheduled pass uses, so the button gets the Cloudflare
	 * detection, the core-version gate and the A-record gate for free rather
	 * than a second copy of them that drifts. It dispatches only the FIRST step;
	 * the scheduled pass carries the chain the rest of the way, which is what
	 * makes pressing the button and waiting for the timer the same operation.
	 *
	 * @return array{started:int,skipped:int,error:string,note:string}
	 */
	public static function begin_chain($node, string $domain, $created_by = null): array {
		$task = new self();
		return $task->start_chain(
			$node,
			$domain,
			(string)$node->get('mgn_host'),
			JobCommandBuilder::is_cloudflare_domain($domain),
			$created_by
		);
	}

	/**
	 * The plane's half of the proof: fetch the token back through the domain.
	 *
	 * The token is cleared either way — a failed probe must not leave one
	 * sitting in a public webroot — and the verdict is recorded on the place
	 * job, so the next tick knows what this one found without refetching.
	 */
	private function verify_routing($node, string $domain, array $place_job): array {
		$params = self::job_params($place_job);
		$token   = (string)($params['token'] ?? '');
		$fetched = self::fetch_probe_token($domain);
		$verified = ($token !== '' && $fetched !== null && hash_equals($token, $fetched));

		$params['routing_verified'] = $verified;
		$this->store_job_params((int)$place_job['mjb_id'], $params);

		$started = 0;
		try {
			ManagementJob::createFromBuild($node->key, self::JOB_PROBE_CLEAR,
				JobCommandBuilder::build_ssl_probe_clear($node),
				['domain' => $domain, 'for_node_id' => (int)$node->key], null);
			$started++;
		} catch (Exception $e) {
			// Not fatal: an uncleared token is untidy, not dangerous (it proves
			// nothing to whoever reads it), and refusing to continue over it
			// would strand a node whose routing just checked out.
			error_log('ProvisionPendingSsl: could not queue probe cleanup: ' . $e->getMessage());
		}

		if (!$verified) {
			return self::chain_result($started, 1);
		}

		// Routing is proved. Queue the certificate behind the cleanup; jobs run
		// one at a time per node, and the order of these two does not matter.
		$cert = $this->dispatch_certificate($node, $domain);
		return self::chain_result($started + $cert['started'], 0, $cert['error']);
	}

	/**
	 * Ask the issuer for the certificate. The job is filed against the ISSUER
	 * (the node itself, or its host) and names the site it is for, which is
	 * the node JobResultProcessor::process_provision_certificate stamps.
	 */
	private function dispatch_certificate($node, string $domain, $created_by = null): array {
		try {
			$issuer = JobCommandBuilder::certificate_issuer_for($node);
			$built  = JobCommandBuilder::build_provision_certificate($node, ['domain' => $domain]);
		} catch (Exception $e) {
			return self::chain_result(0, 0, $e->getMessage());
		}
		ManagementJob::createFromBuild($issuer->key, self::JOB_CERTIFICATE, $built,
			['domain' => $domain, 'for_node_id' => (int)$node->key], $created_by);
		return self::chain_result(1, 0);
	}

	/**
	 * The chain's jobs for one site, oldest first — wherever they were filed.
	 * A container's certificate job sits on its host's node id and names the
	 * site in for_node_id; the probe jobs sit on the site itself.
	 */
	private function chain_jobs($db, $node_id): array {
		$q = $db->prepare(
			"SELECT mjb_id, mjb_job_type, mjb_status, mjb_create_time, mjb_completed_time,
			        mjb_parameters, mjb_output
			 FROM mjb_management_jobs
			 WHERE mjb_delete_time IS NULL
			   AND mjb_job_type IN (?, ?, ?)
			   AND (mjb_parameters->>'for_node_id' = ?
			        OR (mjb_mgn_node_id = ? AND mjb_parameters->>'for_node_id' IS NULL))
			 ORDER BY mjb_create_time ASC, mjb_id ASC"
		);
		// for_node_id names the site every chain job is FOR. The second half
		// is for rows that predate it, and it excludes jobs a node ran for
		// OTHER sites — a host's chain must not pick up its containers'.
		$q->execute([self::JOB_PROBE_PLACE, self::JOB_PROBE_CLEAR, self::JOB_CERTIFICATE,
			(string)(int)$node_id, (int)$node_id]);
		return $q->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * The most recent chain job for a site, for the node page to link to, or
	 * null when none exists.
	 */
	public static function latest_chain_job($node) {
		$task = new self();
		$jobs = $task->chain_jobs(DbConnector::get_instance()->get_db_link(), $node->key);
		if (!$jobs) {
			return null;
		}
		$last = end($jobs);
		return new ManagementJob((int)$last['mjb_id'], TRUE);
	}

	/**
	 * How many times the routing proof has come back wrong.
	 *
	 * A placement that failed outright counts too: the token never got there,
	 * which is the same "this attempt did not prove routing" for pacing.
	 */
	public static function routing_miss_count(array $jobs): int {
		$misses = 0;
		foreach ($jobs as $job) {
			if ($job['mjb_job_type'] !== self::JOB_PROBE_PLACE) {
				continue;
			}
			if ($job['mjb_status'] === 'failed') {
				$misses++;
				continue;
			}
			$params = self::job_params($job);
			if (array_key_exists('routing_verified', $params) && !$params['routing_verified']) {
				$misses++;
			}
		}
		return $misses;
	}

	/**
	 * True once certificate attempts alone have run 16+ hours without issuing.
	 *
	 * Counted from the first certificate attempt that did not produce one, so
	 * time a domain spent waiting at Cloudflare never burns this window.
	 */
	public static function certificate_give_up_due(array $jobs, int $now): bool {
		foreach ($jobs as $job) {
			if ($job['mjb_job_type'] !== self::JOB_CERTIFICATE) {
				continue;
			}
			if ($job['mjb_status'] === 'completed'
				&& SslProvisionOutcome::is_issued(SslProvisionOutcome::classify($job['mjb_output'] ?? '')['state'])) {
				// It worked at least once; this is not a node stuck failing.
				return false;
			}
			return ($now - strtotime($job['mjb_create_time'])) > 57600;
		}
		return false;
	}

	/**
	 * Fetch the probe token through the domain, from out here.
	 *
	 * Plain HTTP on purpose: the point is to reach the origin before it has a
	 * certificate. Redirects are followed because a proxied domain may bounce
	 * to HTTPS, which is a routing success, not a failure.
	 */
	private static function fetch_probe_token(string $domain): ?string {
		$ctx = stream_context_create(['http' => [
			'timeout'        => 15,
			'follow_location' => 1,
			'max_redirects'  => 3,
			'ignore_errors'  => false,
		]]);
		$body = @file_get_contents("http://{$domain}/sm-ssl-probe.txt", false, $ctx);
		return ($body === false) ? null : trim($body);
	}

	/**
	 * Does this domain's A record point at this node's host?
	 *
	 * A resolver failure answers no. That is the conservative direction: it
	 * defers an attempt by one tick, where the other way round would run a
	 * challenge for a name nothing can currently resolve.
	 */
	private static function domain_resolves_to_host(string $domain, string $host_ip): bool {
		try {
			$resolved_ips = DnsResolver::getA($domain);
		} catch (DnsLookupException $e) {
			return false;
		}
		return in_array($host_ip, $resolved_ips, true);
	}

	private static function job_params(array $job): array {
		$params = json_decode((string)($job['mjb_parameters'] ?? ''), true);
		return is_array($params) ? $params : [];
	}

	private function store_job_params(int $job_id, array $params): void {
		$job = new ManagementJob($job_id, TRUE);
		$job->set('mjb_parameters', $params);
		$job->save();
	}

	/**
	 * @param string $error A fault worth reporting from the scheduled run.
	 * @param string $note  A normal wait, explained. Not an error: a domain
	 *                      mid-cutover is the expected state for days, and
	 *                      reporting it hourly would bury the real faults. An
	 *                      operator who pressed a button, though, is owed the
	 *                      sentence rather than a silent no-op.
	 */
	private static function chain_result(int $started, int $skipped, string $error = '', string $note = ''): array {
		return ['started' => $started, 'skipped' => $skipped, 'error' => $error, 'note' => $note];
	}

	private function send_certificate_alert($node, string $domain, array $outcome): void {
		$to = $this->resolve_alert_recipient();
		if ($to === '') {
			return;
		}
		$slug = $node->get('mgn_slug');
		$body = "SSL provisioning for node '{$slug}' ran, and issued no certificate.\n\n"
		      . ($domain !== '' ? "Domain: {$domain}\n\n" : '')
		      . $outcome['detail'] . "\n\n"
		      . "This is not something retrying will fix, so provisioning has stopped asking hourly. "
		      . "Once the change above is made, the next scheduled pass picks it up on its own.";
		try {
			EmailSender::quickSend($to, "[server-manager] SSL not issued: " . ($domain !== '' ? $domain : $slug), $body);
		} catch (Exception $e) {
			error_log('ProvisionPendingSsl: certificate alert send failed: ' . $e->getMessage());
		}
	}

	/**
	 * Has an alert of this kind already gone out for this node's episode?
	 *
	 * Keyed, because a node can be stuck on two different things and only one
	 * of them is the thing to tell somebody about. A domain that waited days at
	 * Cloudflare and then could not get a certificate for want of a credentials
	 * file has two separate stories, and the second is the actionable one — a
	 * single shared marker would swallow it. The marker lives in a job row's
	 * parameters rather than on the node so that clearing the episode (jobs
	 * deleted, node re-provisioned) naturally re-arms it.
	 */
	private function alert_sent(array $jobs, string $key): bool {
		foreach ($jobs as $job) {
			$params = json_decode((string)($job['mjb_parameters'] ?? ''), true);
			if (is_array($params) && !empty($params[$key])) {
				return true;
			}
		}
		return false;
	}

	private function mark_alert_sent(int $job_id, string $key): void {
		$job    = new ManagementJob($job_id, TRUE);
		$params = $job->get('mjb_parameters');
		if (is_string($params)) {
			$params = json_decode($params, true);
		}
		if (!is_array($params)) {
			$params = [];
		}
		$params[$key] = true;
		$job->set('mjb_parameters', $params);
		$job->save();
	}

	private function send_routing_alert($node, string $domain, int $attempts, string $first_attempt_time): void {
		$to = $this->resolve_alert_recipient();
		if ($to === '') {
			return;
		}
		$slug = $node->get('mgn_slug');
		$body = "SSL provisioning for node '{$slug}' cannot verify that {$domain} routes to it.\n\n"
		      . "The domain resolves to Cloudflare, so provisioning is gated on a routing probe: "
		      . "a token placed on the node must come back when fetched through the domain. "
		      . "It has not, in {$attempts} attempts since {$first_attempt_time} UTC.\n\n"
		      . "Two known causes:\n"
		      . "- The Cloudflare zone does not proxy this domain to the node yet (DNS cutover incomplete).\n"
		      . "- The node's code predates the /sm-ssl-probe.txt route and cannot serve the probe — upgrade the node.\n\n"
		      . "Retries continue every " . (self::ROUTING_SLOW_GAP / 3600) . " hours. "
		      . "See the node's detail page in Server Manager to investigate or provision manually.";
		try {
			require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
			EmailSender::quickSend($to, "[server-manager] SSL routing still unverified: {$domain}", $body);
		} catch (Exception $e) {
			error_log('ProvisionPendingSsl: routing alert send failed: ' . $e->getMessage());
		}
	}

	/**
	 * Alert recipient fallback chain (same as ProvisionCustomerCloud):
	 * provisioning_admin_alert_email -> webmaster_email -> first superadmin.
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
}
?>
