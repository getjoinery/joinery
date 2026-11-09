<?php
/**
 * ProvisionStarterSites - a paid Starter site, from ready to a running site
 * in a container on a shared starter host (specs/starter_tier.md, Launch
 * scope step 3). A phase of the provisioning umbrella, after Customer cloud.
 *
 * Its twin for a site on an instance of its own is ProvisionCustomerCloud,
 * which creates instances and never takes a container row. Here nothing is
 * created at a provider: the site is placed on the starter host with the most
 * room, and that host's own agent installs it (site_create).
 *
 *   ready       place it: the host (pick_for_provisioning('starter')), a node
 *               row for the site (its container name, port and host link),
 *               the admin password minted and sealed, then the site_create
 *               job on the host's agent -> installing
 *   installing  the job answered: JobResultProcessor::process_site_create
 *               has flipped the node (installed or install_failed), recorded
 *               the site agent's key and sent the welcome email -> done, or
 *               failed and alerted
 *
 * No starter host with room: the row waits at ready and the operator is
 * alerted once per row (cvp_error says so on the board). Checkout already
 * refuses a Starter line while the hosts are full, so this is a race.
 *
 * The site's limits are the starter settings (server_manager_starter_memory,
 * _cpus, _disk), sized so 8 sites share a Linode 4 GB.
 *
 * @version 1.1 - reviewer2 F3-1/F3-3/F3-4/F3-7/F3-8: the container name is the plane's (st<provision id>), never the
 *               buyer's; the job carries a password slot, not the password; a cancelled or lost job fails the
 *               row; retry(); an agent too old for site_create, a host gone or no release waits with one alert;
 *               the host reserved at payment is used, unless it stopped taking sites before the site was made
 *               (removed, no longer a starter host, Provisioning off): then the place is given up and another found
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_nodes_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_hosts_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_jobs_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ReleaseManifestSource.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionCustomerCloud.php'));

class ProvisionStarterSites {

	/** What cvp_error says while a paid row waits for a starter host with room. */
	const NO_ROOM = 'Waiting for a starter host with room: add one, or raise a host\'s Max Sites.';

	public function run(array $config): array {
		$rows = new MultiCustomerCloudProvision(array(
			'statuses' => array('ready', 'installing'),
			'landing'  => 'container',
			'deleted'  => false,
		));
		$advanced = 0;
		$errors = array();
		foreach ($rows as $provision) {
			try {
				$status = (string)$provision->get('cvp_status');
				$advanced += $status === 'ready' ? $this->place($provision) : $this->finish($provision);
			} catch (Throwable $e) {
				$errors[] = $provision->get('cvp_domain') . ': ' . $e->getMessage();
			}
		}
		if (!$advanced && !$errors) {
			return array('status' => 'success', 'message' => 'No starter sites to advance.');
		}
		$msg = $advanced . ' starter site(s) advanced.';
		if ($errors) {
			return array('status' => 'error', 'message' => $msg . ' ' . implode('; ', array_slice($errors, 0, 3)));
		}
		return array('status' => 'success', 'message' => $msg);
	}

	/** The limits every starter site is installed with. */
	public static function limits(): array {
		$settings = Globalvars::get_instance();
		// One spelling each, whatever case the operator typed: the host takes
		// memory as 384m and disk as 5G.
		return array(
			'memory' => strtolower(trim((string)$settings->get_setting('server_manager_starter_memory'))),
			'cpus'   => trim((string)$settings->get_setting('server_manager_starter_cpus')),
			'disk'   => strtoupper(trim((string)$settings->get_setting('server_manager_starter_disk'))),
		);
	}

	/** The release a new site is installed at: this plane's own, else the newest it serves. */
	public static function release(): string {
		$own = (string)LibraryFunctions::get_joinery_version();
		if ($own !== '' && ReleaseManifestSource::core_archive($own) !== null) {
			return $own;
		}
		$versions = ReleaseManifestSource::published_core_versions();
		usort($versions, 'version_compare');
		return (string)(end($versions) ?: '');
	}

	/**
	 * The container and site name of a starter site: chosen here from the
	 * provision's own id, never from the buyer. Two buyers' domains can share
	 * a first label (www.alice.com, www.bob.com), and on a shared host a name
	 * already there would be another customer's site.
	 */
	public static function container_name(CustomerCloudProvision $provision): string {
		return 'st' . (int)$provision->key;
	}

	/**
	 * Record that a paid row waits for something a person must do, saying it
	 * on the board and alerting once, not every tick. Never fails the row: the
	 * buyer has paid and the site is still owed.
	 */
	protected function wait(CustomerCloudProvision $provision, string $why): int {
		if ((string)$provision->get('cvp_error') !== $why) {
			$provision->set('cvp_error', $why);
			$provision->save();
			$this->alert($provision, 'A paid Starter site is waiting: ' . $why);
		}
		return 0;
	}

	/** ready -> installing: place the site on a host and queue its install there. */
	protected function place(CustomerCloudProvision $provision): int {
		$domain = strtolower(trim((string)$provision->get('cvp_domain')));
		$admin_email = trim((string)$provision->get('cvp_buyer_email'));
		$name = self::container_name($provision);
		if ($domain === '' || $admin_email === '') {
			$this->fail($provision, 'A starter site needs its domain and admin email; this row is missing one.');
			return 1;
		}

		// A row that already has its node keeps it (a retry); else the host it
		// was reserved on at payment; else a host with room now.
		$node = null;
		if ((int)$provision->get('cvp_mgn_managed_node_id')) {
			$node = new ManagedNode((int)$provision->get('cvp_mgn_managed_node_id'), TRUE);
			if (!$node->key || $node->get('mgn_delete_time')) {
				$node = null;
			}
		}
		$host = null;
		if ($node !== null) {
			$host = new ManagedHost((int)$node->get('mgn_mgh_managed_host_id'), TRUE);
		} elseif ((int)$provision->get('cvp_mgh_managed_host_id')) {
			$host = new ManagedHost((int)$provision->get('cvp_mgh_managed_host_id'), TRUE);
			// Reserved at payment, and nothing is made there yet. A host that
			// has since stopped taking sites (removed, no longer a starter
			// host, or its Provisioning switched off to drain it) gives the
			// place up, and the site goes where there is room now.
			if (!$host->key || $host->get('mgh_delete_time') || (string)$host->get('mgh_tier') !== 'starter'
					|| !$host->get('mgh_provisioning_enabled')) {
				$provision->set('cvp_mgh_managed_host_id', null);
				$provision->save();
				$host = null;
			}
		}
		if ($host === null) {
			$host = ManagedHost::pick_for_provisioning('starter');
			if ($host === null) {
				return $this->wait($provision, self::NO_ROOM);
			}
			$provision->set('cvp_mgh_managed_host_id', (int)$host->key);
			$provision->save();
		}
		if (!$host->key || $host->get('mgh_delete_time') || (string)$host->get('mgh_tier') !== 'starter') {
			return $this->wait($provision, 'Its starter host #' . (int)$host->key . ' is gone or no longer a starter host.');
		}
		$host_node = $host->host_node();
		if ($host_node === null) {
			return $this->wait($provision, 'Starter host ' . $host->get('mgh_name') . ' has no agent of its own paired here.');
		}
		// Checked before anything is taken on the host: an agent too old for
		// the word is an upgrade away, not a failed purchase.
		if (!JobCommandBuilder::has_primitive($host_node, 'site_create')) {
			return $this->wait($provision, 'Starter host ' . $host->get('mgh_name') . '\'s agent cannot install a new site yet. '
				. AgentVocabulary::needs_newer_agent_text($host_node, ['site_create']));
		}
		if ($provision->admin_password_state() === 'revealed') {
			$this->fail($provision, 'The admin password was already shown, so a new install would create an account the buyer cannot open. Reset it on the site instead.');
			return 1;
		}
		$release = self::release();
		if ($release === '') {
			return $this->wait($provision, 'This management node serves no release archive to install a site from.');
		}

		if ($node === null) {
			$clash = new MultiManagedNode(array('mgn_mgh_managed_host_id' => (int)$host->key,
				'mgn_container_name' => $name, 'deleted' => false));
			if (count($clash) > 0) {
				$this->fail($provision, 'Host ' . $host->get('mgh_name') . ' already has a site named ' . $name . '.');
				return 1;
			}
			$node = new ManagedNode(NULL);
			$node->set('mgn_name', $provision->external_name_prefix() . $domain);
			$node->set('mgn_slug', AgentChannelEndpoint::freeSlug((string)$provision->get('cvp_slug')));
			$node->set('mgn_host', (string)$host->get('mgh_host'));
			$node->set('mgn_site_url', 'https://' . $domain);
			$node->set('mgn_web_root', '/var/www/html/' . $name . '/public_html');
			$node->set('mgn_container_name', $name);
			$node->set('mgn_mgh_managed_host_id', (int)$host->key);
			$node->set('mgn_enabled', true);
			$node->set('mgn_install_state', 'installing');
			$node->set('mgn_ssl_state', 'pending');
			$node->set('mgn_cloud_account', (string)($host->get('mgh_cloud_account') ?: ''));
			$node->prepare();
			$node->save();
			$node->load();
			$node->set('mgn_port', JobCommandBuilder::next_container_port((int)$host->key, (int)$node->key));
			$node->save();
			$node->open_default_backup_space();
			$provision->set('cvp_mgn_managed_node_id', (int)$node->key);
			// The site's own agent joins under its site name (site_agent_name()).
			$provision->set('cvp_sitename', $name);
			$provision->save();
		} else {
			$node->set('mgn_install_state', 'installing');
			$node->save();
		}

		// The buyer's first password, sealed before the job exists, shown to
		// them once on their sites page. The job never carries it: it holds a
		// slot the hand-out opens (AgentChannelEndpoint).
		if ($provision->admin_password_state() === 'none') {
			$provision->set('cvp_admin_pass_sealed', (new SecretBox())->seal(
				'cvp_customer_cloud_provisions.cvp_admin_pass_sealed', ProvisionCustomerCloud::mint_admin_password()));
			$provision->save();
		}

		$limits = self::limits();
		// The job and the row's link to it land together: the hand-out opens
		// the password only for the job the row names.
		try {
			DbConnector::transaction(function () use ($provision, $node, $host_node, $name, $domain, $release, $admin_email, $limits) {
				$built = JobCommandBuilder::build_site_create($host_node, array(
					'name'         => $name,
					'domain'       => $domain,
					'port'         => (int)$node->get('mgn_port'),
					'release'      => $release,
					'admin_email'  => $admin_email,
					'provision_id' => (int)$provision->key,
					'memory'       => $limits['memory'],
					'cpus'         => $limits['cpus'],
					'disk'         => $limits['disk'],
				));
				$job = ManagementJob::createFromBuild($host_node->key, 'site_create', $built, array(
					'provision_id' => (int)$provision->key,
					'node_id'      => (int)$node->key,
					'domain'       => $domain,
					'user_name'    => (string)$provision->get('cvp_buyer_name'),
				), null);
				$job->set('mjb_external_order_item_id', $provision->get('cvp_external_order_item_id'));
				$job->save();
				$provision->set('cvp_install_job_id', (int)$job->key);
				$provision->set('cvp_status', 'installing');
				$provision->set('cvp_error', null);
				$provision->save();
			});
		} catch (Throwable $e) {
			// Rolled back already: the row is read again as the database has it.
			$provision->load();
			$node->set('mgn_install_state', 'install_failed');
			$node->save();
			$this->fail($provision, 'The site\'s install could not be queued on its host: ' . $e->getMessage());
			return 1;
		}
		return 1;
	}

	/** installing -> done or failed, once the host has answered. */
	protected function finish(CustomerCloudProvision $provision): int {
		$job_id = (int)$provision->get('cvp_install_job_id');
		$job = $job_id ? new ManagementJob($job_id, TRUE) : null;
		if ($job === null || !$job->key) {
			$this->fail($provision, 'Its install job is gone; look at the site on the board.');
			return 1;
		}
		$status = (string)$job->get('mjb_status');
		if (in_array($status, array('pending', 'queued', 'running'), true)) {
			return 0;
		}
		if ($status === 'completed' && !$job->get('mjb_result')) {
			JobResultProcessor::process($job);
			$job->load();
		}
		$node = new ManagedNode((int)$provision->get('cvp_mgn_managed_node_id'), TRUE);
		if ($status === 'completed' && $node->key && $node->get('mgn_install_state') === null) {
			$provision->set('cvp_status', 'done');
			$provision->set('cvp_error', null);
			$provision->save();
		} else {
			// Failed, cancelled, lost: anything that is not an install.
			if ($node->key && $node->get('mgn_install_state') === 'installing') {
				$node->set('mgn_install_state', 'install_failed');
				$node->save();
			}
			$this->fail($provision, "Install job #{$job->key} on its host ended '{$status}'; see the job's page, then Retry install on the site's node.");
		}
		return 1;
	}

	/**
	 * A failed starter site, back to ready: the next pass installs it again
	 * on the same node and host, with the same sealed password (the buyer
	 * cannot have seen it: it is shown only once the site is done). The
	 * node's Retry install calls this for a container site.
	 */
	public static function retry(CustomerCloudProvision $provision): void {
		if (!$provision->is_container() || (string)$provision->get('cvp_status') !== 'failed') {
			throw new Exception('Only a failed Starter site is retried here.');
		}
		$provision->set('cvp_status', 'ready');
		$provision->set('cvp_install_job_id', null);
		$provision->set('cvp_error', null);
		$provision->save();
	}

	protected function fail(CustomerCloudProvision $provision, string $reason): void {
		$provision->fail($reason);
		$this->alert($provision, $reason);
	}

	/** A person's task, never silent. Protected: the edge a test double intercepts. */
	protected function alert(CustomerCloudProvision $provision, string $reason): void {
		error_log('ProvisionStarterSites: ' . $provision->get('cvp_domain') . ': ' . $reason);
		try {
			require_once(PathHelper::getIncludePath('plugins/server_manager/includes/provisioning/ProvisionManagedDomains.php'));
			$to = ProvisionManagedDomains::resolve_alert_recipient();
			if ($to === '') {
				return;
			}
			EmailSender::quickSend($to, '[starter-hosting] ' . $provision->get('cvp_domain'),
				'Starter site ' . $provision->get('cvp_domain') . ' (provision #' . $provision->key . "):\n\n" . $reason
				. "\n\nLook at it on /admin/server_manager.\n");
		} catch (Throwable $e) {
			error_log('ProvisionStarterSites: the alert also failed: ' . $e->getMessage());
		}
	}
}
