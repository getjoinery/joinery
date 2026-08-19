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
 * @version 1.3 - routing-probe misses back off to a slow lane after the fast attempts
 *                and alert the operator once, instead of retrying hourly in silence
 * @version 1.2 - CF domains awaiting routing (CF_ROUTING_UNVERIFIED) are exempt from the 16h give-up
 * @version 1.1 - P-6: dispatch Cloudflare-proxied domains (builder's CF branch) instead of skipping on the A-record gate
 */
require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));

class ProvisionPendingSsl {

	/** Hourly routing-probe retries before dropping to the slow lane. */
	const ROUTING_FAST_ATTEMPTS = 16;

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
			if (!$is_cloudflare) {
				try {
					$resolved_ips = DnsResolver::getA($domain);
				} catch (DnsLookupException $e) {
					$resolved_ips = []; // resolver failure — skip, retry next run
				}
				if (!in_array($host_ip, $resolved_ips, true)) {
					$skipped++;
					continue;
				}
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
		foreach ($ssl_jobs as $job) {
			$params = json_decode((string)($job['mjb_parameters'] ?? ''), true);
			if (is_array($params) && !empty($params['routing_alert_sent'])) {
				return true;
			}
		}
		return false;
	}

	private function mark_routing_alert_sent(int $job_id): void {
		$job    = new ManagementJob($job_id, TRUE);
		$params = $job->get('mjb_parameters');
		if (is_string($params)) {
			$params = json_decode($params, true);
		}
		if (!is_array($params)) {
			$params = [];
		}
		$params['routing_alert_sent'] = true;
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
