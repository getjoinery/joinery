<?php
/**
 * ProvisionPendingSsl - SSL automation scheduled task.
 *
 * For each managed node where mgn_ssl_state='pending' and install is complete,
 * resolves the domain via DNS. If it points to the node's host IP, creates a
 * provision_ssl job to run certbot. Retries hourly on failure; after ~16 hours
 * of failed attempts it flips mgn_ssl_state='failed' for manual resolution.
 *
 * A Cloudflare domain whose routing probe keeps missing (CF_ROUTING_UNVERIFIED)
 * is a different case: it may legitimately be waiting days for the customer's
 * DNS cutover, so it never flips to 'failed'. Instead it retries hourly for
 * ROUTING_FAST_ATTEMPTS tries, then drops to one try every ROUTING_SLOW_GAP,
 * and sends the operator one alert at the changeover — so a domain that is
 * stuck (rather than waiting) is seen by a person instead of failing silently
 * forever. Both the slow lane and the alert apply only while the domain still
 * resolves to Cloudflare: once it repoints, the next attempt is due within
 * the hour and the 16h give-up window opens fresh at the first non-routing
 * failure.
 *
 * A paired bare-metal node takes a different route entirely: instead of one
 * SSH job running certbot over a shell, the plane drives a chain of agent
 * primitives — place the probe token, fetch it from out here, clear it, then
 * ask the node to issue its own certificate. The steps are separate jobs, so
 * this task is the driver: each tick reads where the chain got to and takes
 * the next step. Container nodes keep the SSH path, because certbot and
 * /etc/letsencrypt live on their Docker host rather than inside the container.
 *
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
	 * Does this node provision through its own agent rather than over SSH?
	 *
	 * Two conditions, and the second is a contract only this plane can keep.
	 * The agent runs INSIDE a container node while Apache, certbot and
	 * /etc/letsencrypt live on its Docker host, so a certificate issued from
	 * in there is written to a filesystem the next rebuild discards — after
	 * spending one of the five certificates per domain per week that Let's
	 * Encrypt allows. The node cannot refuse that on its own ("am I in a
	 * container" has only heuristic answers); mgn_container_name is the
	 * non-heuristic answer and it lives here.
	 */
	public static function uses_primitive_route($node): bool {
		return self::is_bare_metal($node)
			&& JobCommandBuilder::has_primitive($node, 'provision_certificate');
	}

	/** Pure half of the container gate, so it can be tested without a node. */
	public static function is_bare_metal($node): bool {
		return trim((string)$node->get('mgn_container_name')) === '';
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

		$settings    = Globalvars::get_instance();
		$alert_email = $settings->get_setting('server_manager_provisioning_admin_alert_email') ?: '';

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

		$db      = DbConnector::get_instance()->get_db_link();
		$started = 0;
		$skipped = 0;
		$errors  = [];

		foreach ($pending as $node) {
			$node_id  = $node->key;
			$slug     = $node->get('mgn_slug');
			$site_url = $node->get('mgn_site_url') ?: '';
			$domain   = parse_url($site_url, PHP_URL_HOST) ?: $node->get('mgn_name');
			$host_ip  = $node->get('mgn_host');

			if (!$domain || !$host_ip) {
				$errors[] = "Node '{$slug}': missing domain or host IP — skipping.";
				continue;
			}

			// Resolved once per node per tick: the retry pacing and the DNS gate
			// both need to know whether the domain currently parks at Cloudflare.
			$is_cloudflare = JobCommandBuilder::is_cloudflare_domain($domain);

			// A paired bare-metal node drives the primitive chain instead. The
			// whole SSH path below is left exactly as it was, because it is
			// still what container nodes use.
			if (self::uses_primitive_route($node)) {
				$outcome = $this->advance_primitive_ssl($db, $node, $domain, $host_ip, $is_cloudflare);
				$started += $outcome['started'];
				$skipped += $outcome['skipped'];
				if ($outcome['error'] !== '') {
					$errors[] = "Node '{$slug}': " . $outcome['error'];
				}
				continue;
			}

			// Inspect previous provision_ssl jobs for this node
			$q = $db->prepare(
				"SELECT mjb_id, mjb_status, mjb_create_time, mjb_completed_time, mjb_parameters
				 FROM mjb_management_jobs
				 WHERE mjb_mgn_node_id = ? AND mjb_job_type = 'provision_ssl' AND mjb_delete_time IS NULL
				 ORDER BY mjb_create_time ASC"
			);
			$q->execute([$node_id]);
			$ssl_jobs = $q->fetchAll(PDO::FETCH_ASSOC);

			if (!empty($ssl_jobs)) {
				$last  = end($ssl_jobs);
				$first = reset($ssl_jobs);

				// Skip if a job is still in flight
				if (in_array($last['mjb_status'], ['pending', 'running'])) {
					$skipped++;
					continue;
				}

				// A completed job means the cert is in — process its result
				// here (flips mgn_ssl_state to active). Admin page views also
				// process results, but an unattended pipeline must not depend
				// on someone looking at a dashboard; without this the node
				// stays pending and gets a fresh certbot job every tick.
				if ($last['mjb_status'] === 'completed') {
					require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
					$done_job = new ManagementJob($last['mjb_id'], TRUE);
					if (!$done_job->get('mjb_result')) {
						JobResultProcessor::process($done_job);
					}
					$skipped++;
					continue;
				}

				if ($last['mjb_status'] === 'failed') {
					$last_at = strtotime($last['mjb_completed_time'] ?: $last['mjb_create_time']);

					// A Cloudflare routing-probe miss (the fetched token did not
					// come back through the domain) is "the domain does not reach
					// this node yet" — the same state the A-record gate models for
					// non-CF domains, and a DNS cutover legitimately takes days.
					$oq = $db->prepare("SELECT mjb_output FROM mjb_management_jobs WHERE mjb_id = ?");
					$oq->execute([$last['mjb_id']]);
					$awaiting_routing = (strpos((string)$oq->fetchColumn(), 'CF_ROUTING_UNVERIFIED') !== false);

					$failed_attempts = count(array_filter($ssl_jobs, function ($j) {
						return $j['mjb_status'] === 'failed';
					}));

					// Backoff between attempts: hourly, except a domain stuck
					// awaiting routing drops to the slow lane after the fast tries.
					// The slow lane only applies while the domain still resolves to
					// Cloudflare — the wait it paces is "the zone does not proxy
					// here yet". The moment DNS stops parking at Cloudflare (proxy
					// turned off, records repointed) that wait is over, and the
					// next attempt takes the certbot path within the hour instead
					// of sitting out a six-hour gap earned by a state that no
					// longer exists.
					$gap = ($awaiting_routing && $is_cloudflare) ? self::routing_retry_gap($failed_attempts) : 3600;
					if ((time() - $last_at) < $gap) {
						$skipped++;
						continue;
					}

					if ($awaiting_routing) {
						// Never flips to 'failed' — a cutover the customer has not
						// made yet is not a fault. But it must not be invisible
						// either: entering the slow lane sends the operator one
						// alert, stamped on a job row so it is sent exactly once.
						if ($is_cloudflare && $failed_attempts >= self::ROUTING_FAST_ATTEMPTS && !$this->routing_alert_sent($ssl_jobs)) {
							$this->send_routing_alert($node, $domain, $failed_attempts, $first['mjb_create_time']);
							$this->mark_routing_alert_sent((int)$last['mjb_id']);
						}
					} else if ($this->give_up_due($db, $node_id)) {
						// Any other failure gives up after ~16 hours of attempts.
						$node->set('mgn_ssl_state', 'failed');
						$node->save();
						$errors[] = "Node '{$slug}': SSL provisioning failed after 16+ hours — manual intervention required.";
						continue;
					}
				}
			}

			// DNS gate. A Cloudflare-proxied domain resolves to Cloudflare edge IPs,
			// never the host, so the A-record gate would skip it forever (P-6).
			// Detect Cloudflare the same way the builder does and dispatch anyway —
			// build_provision_ssl's Cloudflare branch skips certbot, proves routing
			// with a webroot probe fetched through the domain (the job fails until
			// Cloudflare actually proxies to this node), then patches the proxy
			// proto. The A-record gate still applies to non-CF domains, so certbot
			// never runs before DNS actually points at this host.
			if (!$is_cloudflare && !self::domain_resolves_to_host($domain, $host_ip)) {
				$skipped++;
				continue;
			}

			$job_params = [
				'domain'      => $domain,
				'admin_email' => $alert_email,
			];

			try {
				$steps = JobCommandBuilder::build_provision_ssl($node, $job_params);
			} catch (Exception $e) {
				$errors[] = "Node '{$slug}': " . $e->getMessage();
				continue;
			}

			ManagementJob::createJob($node_id, 'provision_ssl', $steps, $job_params, null);
			$started++;
		}

		$msg = "SSL poll: {$started} job(s) started, {$skipped} waiting for DNS.";
		if ($errors) {
			$msg .= ' ' . count($errors) . ' error(s): ' . implode('; ', array_slice($errors, 0, 3));
			if (count($errors) > 3) $msg .= ' ...';
			return ['status' => 'error', 'message' => $msg];
		}
		return ['status' => 'success', 'message' => $msg];
	}

	// ------------------------------------------------------------------
	// The primitive chain
	//
	// One operation, four jobs, and a plane action in the middle of them:
	//
	//   ssl_probe_place  (node)   write a one-time token into the webroot
	//   [fetch]          (plane)  read it back through the domain
	//   ssl_probe_clear  (node)   remove the token, whatever the answer was
	//   provision_certificate (node)  issue the certificate
	//
	// The fetch is on the plane deliberately and must stay there: a node
	// asked whether a domain reaches itself is a node answering its own
	// question. Because each node step is its own job, "the chain" only
	// exists as a thing this task reconstructs each tick from the job rows —
	// it reads where the chain got to and takes one more step.
	//
	// The probe half runs only for a Cloudflare-proxied domain. For anything
	// else the A-record gate above has already proved the domain reaches this
	// host, and a probe would add a way to fail while proving nothing that is
	// not already known.
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
				// Same rule the SSH path follows: a cutover the customer has
				// not made is not a fault and never flips the node to 'failed',
				// but it does not get to be invisible either.
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
			// The same A-record gate the SSH path applies, and for the same
			// reason: a domain still pointing somewhere else is mid-cutover,
			// not broken. Waiting quietly is the right answer, and it is also
			// what keeps this off Let's Encrypt's five-failed-validations-per-
			// hour budget for a name that cannot validate yet.
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
			['token' => $token, 'domain' => $domain], $created_by);
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
				JobCommandBuilder::build_ssl_probe_clear($node), ['domain' => $domain], null);
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

	private function dispatch_certificate($node, string $domain, $created_by = null): array {
		try {
			$built = JobCommandBuilder::build_provision_certificate($node, ['domain' => $domain]);
		} catch (Exception $e) {
			return self::chain_result(0, 0, $e->getMessage());
		}
		ManagementJob::createFromBuild($node->key, self::JOB_CERTIFICATE, $built, ['domain' => $domain], $created_by);
		return self::chain_result(1, 0);
	}

	/** The chain's jobs for one node, oldest first. */
	private function chain_jobs($db, $node_id): array {
		$q = $db->prepare(
			"SELECT mjb_id, mjb_job_type, mjb_status, mjb_create_time, mjb_completed_time,
			        mjb_parameters, mjb_output
			 FROM mjb_management_jobs
			 WHERE mjb_mgn_node_id = ? AND mjb_delete_time IS NULL
			   AND mjb_job_type IN (?, ?, ?)
			 ORDER BY mjb_create_time ASC, mjb_id ASC"
		);
		$q->execute([$node_id, self::JOB_PROBE_PLACE, self::JOB_PROBE_CLEAR, self::JOB_CERTIFICATE]);
		return $q->fetchAll(PDO::FETCH_ASSOC);
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
	 * time a domain spent waiting at Cloudflare never burns this window — the
	 * same rule give_up_due() applies on the SSH path.
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
	 * True once non-routing failures have run for 16+ hours. The window opens
	 * at the first failed run that was NOT a routing-probe miss: a domain that
	 * spent days parked at Cloudflare gets a full fresh window once it repoints
	 * and certbot starts failing for real reasons, instead of being flipped to
	 * 'failed' on its first certbot attempt by a clock the routing wait ran out.
	 */
	private function give_up_due($db, int $node_id): bool {
		$q = $db->prepare(
			"SELECT MIN(mjb_create_time)
			 FROM mjb_management_jobs
			 WHERE mjb_mgn_node_id = ? AND mjb_job_type = 'provision_ssl'
			   AND mjb_delete_time IS NULL AND mjb_status = 'failed'
			   AND (mjb_output IS NULL OR mjb_output NOT LIKE '%CF_ROUTING_UNVERIFIED%')"
		);
		$q->execute([$node_id]);
		$first_real = $q->fetchColumn();
		return $first_real && (time() - strtotime($first_real)) > 57600;
	}

	/**
	 * Has the awaiting-routing alert already gone out for this node's stuck
	 * episode? The marker lives in a job row's parameters rather than on the
	 * node so that clearing the episode (jobs deleted, node re-provisioned)
	 * naturally re-arms it.
	 */
	private function routing_alert_sent(array $ssl_jobs): bool {
		return $this->alert_sent($ssl_jobs, 'routing_alert_sent');
	}

	private function mark_routing_alert_sent(int $job_id): void {
		$this->mark_alert_sent($job_id, 'routing_alert_sent');
	}

	/**
	 * Has an alert of this kind already gone out for this node's episode?
	 *
	 * Keyed, because a node can be stuck on two different things and only one
	 * of them is the thing to tell somebody about. A domain that waited days at
	 * Cloudflare and then could not get a certificate for want of a credentials
	 * file has two separate stories, and the second is the actionable one — a
	 * single shared marker would swallow it.
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
