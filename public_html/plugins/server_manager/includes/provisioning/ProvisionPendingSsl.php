<?php
/**
 * ProvisionPendingSsl - SSL automation scheduled task.
 *
 * For each managed node where mgn_ssl_state='pending' and install is complete,
 * resolves the domain via DNS. If it points to the node's host IP, creates a
 * provision_ssl job to run certbot. Retries hourly on failure; after ~16 hours
 * of failed attempts it flips mgn_ssl_state='failed' for manual resolution.
 *
 * @version 1.2 - CF domains awaiting routing (CF_ROUTING_UNVERIFIED) are exempt from the 16h give-up
 * @version 1.1 - P-6: dispatch Cloudflare-proxied domains (builder's CF branch) instead of skipping on the A-record gate
 */
require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));

class ProvisionPendingSsl {

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

			// Inspect previous provision_ssl jobs for this node
			$q = $db->prepare(
				"SELECT mjb_id, mjb_status, mjb_create_time, mjb_completed_time
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

					// Hourly backoff
					if ((time() - $last_at) < 3600) {
						$skipped++;
						continue;
					}

					// Give up after ~16 hours of attempts — EXCEPT when the failure
					// is the Cloudflare routing probe reporting the domain does not
					// reach this node yet. That is "waiting on the customer's DNS
					// cutover", the same state the A-record gate models for non-CF
					// domains, and a cutover legitimately takes days: keep quietly
					// retrying instead of flipping to failed.
					$oq = $db->prepare("SELECT mjb_output FROM mjb_management_jobs WHERE mjb_id = ?");
					$oq->execute([$last['mjb_id']]);
					$awaiting_routing = (strpos((string)$oq->fetchColumn(), 'CF_ROUTING_UNVERIFIED') !== false);

					if (!$awaiting_routing && (time() - strtotime($first['mjb_create_time'])) > 57600) {
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
			if (!JobCommandBuilder::is_cloudflare_domain($domain)) {
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
}
?>
