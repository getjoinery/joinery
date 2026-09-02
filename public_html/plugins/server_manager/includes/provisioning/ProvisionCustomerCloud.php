<?php
/**
 * ProvisionCustomerCloud - Advances cloud-instance provisions to running sites.
 *
 * Handles both origins: order-origin provisions created by hosting purchases
 * and admin-origin provisions created by the Install New Node form's
 * cloud-instance target. Install parameters (docker mode, fresh/from-backup/
 * bare, source node, port) ride on the provision row.
 *
 * Works the cvp_customer_cloud_provisions state machine each cron tick:
 *
 *   ready      -> (from_backup: arm the source's clone export) -> create the
 *                 instance on the customer's cloud account -> booting
 *   booting    -> wait for running + public IP (and, for a clone, for the
 *                 source to report armed) -> create ManagedNode + install_node
 *                 job (InstallJobExecutor runs it, plane-side) -> installing
 *   installing -> drive JobResultProcessor on the finished job -> done | failed
 *   done       -> fleet seeding, once the node's agent has paired (below)
 *
 * The install job is ONE SSH session — the bootstrap (specs/ssh_single_bootstrap.md).
 * Every shape travels it: fresh, from_backup (a clone the new machine pulls
 * over HTTPS from the source site, which this task armed and disarms through
 * the source agent's clone_export_arm primitive) and bare (a Docker host with
 * no site, for infrastructure roles). Nothing after the bootstrap opens SSH.
 *
 * Fleet enrollment seeding (the mailbox plugin's FleetProvisionSeeding) is a
 * primitive on the new site's own agent, so it waits for that agent to pair:
 * cvp_fleet_seed_state goes pending at completion, dispatched once the node
 * reports the fleet_enroll primitive, then done or failed by the job's answer.
 *
 * The install job carries mjb_external_order_item_id, so the standard welcome
 * email and Provision Pending SSL flows apply unchanged from 'installing' on.
 *
 * Tokens are kept fresh via OAuth2Client::ensureFresh; a refresh failure or a
 * 401 from the provider flips the account to refresh_failed/revoked and parks
 * the provision back at pending_connect for the buyer to re-connect.
 *
 * Keyless: a machine we create never receives an SSH key. The instance is
 * born with a root password we seal onto the provision row
 * (cvp_root_pass_sealed), which the install executor uses to authenticate for
 * the length of the install and which the approval-time burn erases once the
 * node's agent has joined. See specs/keyless_provisioning.md.
 *
 * Settings (Server Manager plugin settings):
 *   server_manager_customer_cloud_region  default region
 *   server_manager_customer_cloud_type    default instance type
 *   server_manager_customer_cloud_image   default OS image
 *
 * @version 1.9 - review fixes: one clone per source at a time, arm once (not on every transient tick),
 *                disarm only when the source's latest arm is ours, blank the bootstrap's clone key at
 *                completion, and a failed provision releases its source after CLONE_ARM_TTL_DAYS
 * @version 1.8 - every shape provisions keyless: from_backup arms the source (clone_export_arm) and the
 *                clone travels over HTTPS in the bootstrap; bare is the bootstrap's docker half; fleet
 *                seeding is a primitive that waits for the node's agent to pair (specs/ssh_single_bootstrap.md)
 * @version 1.7 - fleet enrollment seeding reaches a keyless node over its sealed root password
 * @version 1.6 - handle_ready refuses what keyless cannot finish yet (bare, bare-metal, from_backup)
                 before an instance is created
 * @version 1.5 - keyless: seal a root password instead of installing a key
 */

class ProvisionCustomerCloud {

	const BOOT_TIMEOUT_SECONDS = 1800; // 30 min from instance create to running

	/**
	 * A failed provision keeps its source armed so Retry Install can re-run
	 * the same bootstrap. Not forever: after this many days the key is
	 * released whether or not anybody retried.
	 */
	const CLONE_ARM_TTL_DAYS = 7;

	/** @var array Collected human-readable errors for the run summary. */
	private $errors = [];

	public function run(array $config): array {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_account_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provision_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
		require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
		require_once(PathHelper::getIncludePath('includes/cloud_compute/LinodeComputeDriver.php'));
		require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Client.php'));
		require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

		$actionable = new MultiCustomerCloudProvision(array(
			'statuses' => array('ready', 'booting', 'installing', 'failed'),
			'deleted'  => false,
		));
		$actionable->load();

		// Done provisions whose fleet seeding is still travelling: waiting for
		// the node's agent to pair, or for the seeding job to answer.
		$seeding = new MultiCustomerCloudProvision(array(
			'statuses'          => array('done'),
			'fleet_seed_states' => array('pending', 'dispatched'),
			'deleted'           => false,
		));
		$seeding->load();

		if (count($actionable) === 0 && count($seeding) === 0) {
			return ['status' => 'success', 'message' => 'No customer-cloud provisions to advance.'];
		}

		// A keyless provision places no SSH key on the machine, so the
		// customer-cloud key setting is no longer a precondition to run: the
		// instance is created with a root password we seal onto the row and use
		// for the install only. The pipeline is unblocked whether or not a key
		// path is configured.
		$advanced = 0;
		foreach ($actionable as $provision) {
			try {
				switch ($provision->get('cvp_status')) {
					case 'ready':      $advanced += $this->handle_ready($provision); break;
					case 'booting':    $advanced += $this->handle_booting($provision); break;
					case 'installing': $advanced += $this->handle_installing($provision); break;
					case 'failed':     $advanced += $this->handle_failed_recheck($provision); break;
				}
			} catch (Exception $e) {
				$this->errors[] = "Provision #{$provision->key} ({$provision->get('cvp_domain')}): " . $e->getMessage();
			}
		}
		foreach ($seeding as $provision) {
			try {
				$advanced += $this->handle_seeding($provision);
			} catch (Exception $e) {
				$this->errors[] = "Provision #{$provision->key} ({$provision->get('cvp_domain')}): seeding: " . $e->getMessage();
			}
		}

		$msg = "Customer-cloud: {$advanced} provision(s) advanced of " . (count($actionable) + count($seeding)) . " actionable.";
		if ($this->errors) {
			$msg .= ' ' . count($this->errors) . ' error(s): ' . implode('; ', array_slice($this->errors, 0, 3));
			if (count($this->errors) > 3) $msg .= ' …';
			return ['status' => 'error', 'message' => $msg];
		}
		return ['status' => 'success', 'message' => $msg];
	}

	/**
	 * ready -> booting: create the instance on the customer's account.
	 * Returns 1 if the provision advanced, 0 otherwise.
	 */
	protected function handle_ready($provision) {
		$install_mode = $provision->get('cvp_install_mode') ?: 'fresh';

		// A clone's source is armed BEFORE the instance exists: the new machine
		// pulls the source over HTTPS inside its install, and the key it
		// presents has to be on the source by then. Refusing here, when the
		// source cannot be armed, leaves no box on the customer's account.
		if ($install_mode === 'from_backup' && !$this->arm_clone_source($provision)) {
			return 1;
		}

		$driver = $this->get_driver($provision);
		if ($driver === null) return 0;

		$settings = Globalvars::get_instance();
		$region = $provision->get('cvp_region')        ?: ($settings->get_setting('server_manager_customer_cloud_region') ?: 'us-southeast');
		$type   = $provision->get('cvp_instance_type') ?: ($settings->get_setting('server_manager_customer_cloud_type')   ?: 'g6-nanode-1');
		$image  = $settings->get_setting('server_manager_customer_cloud_image') ?: 'linode/ubuntu26.04';

		// Keyless: create with a root password and no SSH key of ours. The
		// password is the sole credential, and only for the length of the
		// install — sealed onto the row here, used by the install executor, and
		// erased the moment the agent's join is approved. Seal it BEFORE the
		// instance exists, so a crash between create and save never leaves a
		// running box whose only credential we have forgotten.
		$root_pass = 'Aa1!' . bin2hex(random_bytes(20));
		$box = new SecretBox();
		$provision->set('cvp_root_pass_sealed',
			$box->seal('cvp_customer_cloud_provisions.cvp_root_pass_sealed', $root_pass));
		$provision->save();

		try {
			$instance = $driver->createInstance(array(
				// cvp id suffix keeps labels unique on the customer's account
				// across re-provisions of the same domain.
				'label'           => $provision->get('cvp_slug') . '-' . $provision->key,
				'region'          => $region,
				'type'            => $type,
				'image'           => $image,
				'root_pass'       => $root_pass,
				// No key of ours is installed on a machine we create.
			));
		} catch (CloudComputeException $e) {
			return $this->handle_compute_failure($provision, $e, 'ready');
		}

		$provision->set('cvp_instance_id',   $instance['id']);
		$provision->set('cvp_instance_ip',   $instance['ip']);
		$provision->set('cvp_region',        $region);
		$provision->set('cvp_instance_type', $type);
		$provision->set('cvp_status',        'booting');
		$provision->set('cvp_error',         null);
		$provision->save();
		return 1;
	}

	/**
	 * Arm the clone's source: mint one export key, seal it on the provision,
	 * and hand it to the source through its agent (clone_export_arm). The
	 * source is reached by its web address from then on. Returns false after
	 * failing the provision when the source cannot be armed.
	 */
	private function arm_clone_source($provision): bool {
		$source = $this->clone_source($provision);
		if (!$source) {
			$this->alert_and_fail($provision, 'The clone source node no longer exists. No instance was created.');
			return false;
		}
		$clone_from = rtrim((string)$source->get('mgn_site_url'), '/');
		if (!preg_match('#^https://#', $clone_from)) {
			$this->alert_and_fail($provision,
				"The clone source '{$source->get('mgn_slug')}' has no https site URL to pull from. No instance was created.");
			return false;
		}

		// Armed already, by this provision: a transient provider failure on a
		// previous tick left the row at ready. Arm once.
		if (trim((string)$provision->get('cvp_clone_key_sealed')) !== '' && $this->source_armed_by($source, $provision)) {
			return true;
		}

		// clone_export_key is ONE value on the source, so one clone at a
		// time: a second arm would overwrite the first key mid-pull, and the
		// first disarm would blank the second. Wait, saying so on the row.
		$busy = $this->source_busy_with($source, $provision);
		if ($busy !== null) {
			$provision->set('cvp_error', "Waiting: clone source '{$source->get('mgn_slug')}' is armed for provision #{$busy} until it finishes.");
			$provision->save();
			$this->errors[] = "Provision #{$provision->key}: waiting for the clone source, armed for provision #{$busy}.";
			return false;
		}

		$key = JobCommandBuilder::mint_clone_export_key();
		try {
			$built = JobCommandBuilder::build_clone_export_arm($source, ['export_key' => $key]);
		} catch (Exception $e) {
			$this->alert_and_fail($provision, 'Cannot arm the clone source — ' . $e->getMessage() . ' No instance was created.');
			return false;
		}
		$box = new SecretBox();
		$provision->set('cvp_clone_key_sealed',
			$box->seal('cvp_customer_cloud_provisions.cvp_clone_key_sealed', $key));
		$provision->save();
		ManagementJob::createFromBuild($source->key, 'clone_export_arm', $built,
			['provision_id' => (int)$provision->key], null);
		return true;
	}

	/**
	 * Another live provision holding this source's key, or null. Live means
	 * not yet released: any status, with a sealed clone key still on the row.
	 */
	private function source_busy_with($source, $provision) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT cvp_id FROM cvp_customer_cloud_provisions
			 WHERE cvp_source_node_id = ? AND cvp_id <> ? AND cvp_delete_time IS NULL
			   AND COALESCE(cvp_clone_key_sealed, '') <> ''
			 ORDER BY cvp_id ASC LIMIT 1");
		$q->execute([(int)$source->key, (int)$provision->key]);
		$id = $q->fetchColumn();
		return $id ? (int)$id : null;
	}

	/** Is the source's latest arm job this provision's? */
	private function source_armed_by($source, $provision): bool {
		$job = ManagementJob::latestForNode($source->key, 'clone_export_arm');
		return $job && (int)($this->job_params($job)['provision_id'] ?? 0) === (int)$provision->key;
	}

	/** The source node of a from_backup provision, or null. */
	private function clone_source($provision) {
		$id = (int)$provision->get('cvp_source_node_id');
		if (!$id) return null;
		$source = new ManagedNode($id, TRUE);
		return ($source->key && !$source->get('mgn_delete_time')) ? $source : null;
	}

	/**
	 * Has the source answered its arm job? 'ready', 'wait', or 'failed' (with
	 * the reason). Read from the job the plane filed, processed here if the
	 * channel has not yet.
	 */
	private function clone_source_state($provision, $source): array {
		$job = ManagementJob::latestForNode($source->key, 'clone_export_arm');
		if (!$job) {
			return ['state' => 'failed', 'reason' => 'the arm job for the clone source is missing'];
		}
		if ((int)($this->job_params($job)['provision_id'] ?? 0) !== (int)$provision->key) {
			return ['state' => 'failed', 'reason' => "the clone source was re-armed by another provision (job #{$job->key}) after this one armed it"];
		}
		$status = (string)$job->get('mjb_status');
		if ($status !== 'completed' && $status !== 'failed') {
			return ['state' => 'wait', 'reason' => ''];
		}
		if (!$job->get('mjb_result')) {
			JobResultProcessor::process($job);
			$job->load();
		}
		$result = json_decode((string)$job->get('mjb_result'), true);
		if ($status === 'completed' && is_array($result) && !empty($result['armed'])) {
			return ['state' => 'ready', 'reason' => ''];
		}
		return ['state' => 'failed', 'reason' => "the clone source did not arm its export (job #{$job->key} {$status}: "
			. trim((string)($job->get('mjb_error_message') ?: 'see the job output')) . ')'];
	}

	private function job_params($job): array {
		$params = $job->get('mjb_parameters');
		if (is_string($params)) { $params = json_decode($params, true); }
		return is_array($params) ? $params : [];
	}

	/**
	 * booting -> installing: once the instance is running with a public IP,
	 * create the managed node and dispatch the bootstrap.
	 */
	protected function handle_booting($provision) {
		$driver = $this->get_driver($provision);
		if ($driver === null) return 0;

		try {
			$instance = $driver->getInstance((string)$provision->get('cvp_instance_id'));
		} catch (CloudComputeException $e) {
			return $this->handle_compute_failure($provision, $e, 'booting');
		}

		if ($instance['status'] !== 'running' || $instance['ip'] === '') {
			// Not up yet — give up after the boot timeout (measured from the
			// ready->booting transition, the last time this row was saved).
			$since = $provision->get('cvp_update_time') ?: $provision->get('cvp_create_time');
			if ($since && (time() - strtotime($since . ' UTC')) > self::BOOT_TIMEOUT_SECONDS) {
				$this->alert_and_fail($provision,
					"Instance {$provision->get('cvp_instance_id')} did not reach running+IP within " .
					(self::BOOT_TIMEOUT_SECONDS / 60) . " minutes (status: {$instance['status']}). " .
					"Instance left in place on the customer's account for manual review.");
				return 1;
			}
			return 0;
		}

		$domain       = $provision->get('cvp_domain');
		$slug         = $provision->get('cvp_slug');
		$docker_mode  = $provision->get('cvp_docker_mode')  ?: 'docker';
		$install_mode = $provision->get('cvp_install_mode') ?: 'fresh';
		$sitename     = $provision->get('cvp_sitename')     ?: $slug;
		$port         = (int)($provision->get('cvp_port')   ?: 8080);
		$is_bare      = ($install_mode === 'bare');

		// A clone waits for its source to report armed: the bootstrap pulls
		// from the source the moment it runs.
		$clone = null;
		if ($install_mode === 'from_backup') {
			$source = $this->clone_source($provision);
			if (!$source) {
				$this->alert_and_fail($provision, 'The clone source node no longer exists.');
				return 1;
			}
			$state = $this->clone_source_state($provision, $source);
			if ($state['state'] === 'wait') {
				return 0;
			}
			if ($state['state'] === 'failed') {
				$this->alert_and_fail($provision, 'Cannot clone: ' . $state['reason'] . '.');
				return 1;
			}
			$opened = (new SecretBox())->open((string)$provision->get('cvp_clone_key_sealed'));
			if ($opened['state'] !== 'ok') {
				$this->alert_and_fail($provision, 'The sealed clone key on this provision cannot be read back.');
				return 1;
			}
			$clone = ['from' => rtrim((string)$source->get('mgn_site_url'), '/'), 'key' => $opened['value']];
		}

		// Same duplicate-slug rule as shared-host fulfillment: an existing
		// non-failed node with this slug needs manual resolution.
		$existing_multi = new MultiManagedNode(['slug' => $slug, 'deleted' => false]);
		$existing_multi->load();
		$node = null;
		foreach ($existing_multi as $ex) {
			if ($ex->get('mgn_install_state') !== 'install_failed') {
				$this->alert_and_fail($provision, "Domain '{$domain}' is already provisioned (slug: {$slug}) — manual resolution required.");
				return 1;
			}
			$node = $ex; // reuse the failed node record
		}

		if ($node === null) {
			$node = new ManagedNode(NULL);
			$node->set('mgn_name', $domain);
			$node->set('mgn_slug', $slug);
		}

		$node->set('mgn_host',          $instance['ip']);
		$node->set('mgn_ssh_user',      'root');
		// Keyless: no key path. The install executor authenticates with the
		// sealed root password on the provision row; after the burn nothing but
		// the machine's owner and its agent can reach it.
		$node->set('mgn_ssh_key_path',  null);
		$node->set('mgn_ssh_port',      22);
		$node->set('mgn_install_state', 'installing');
		if ($is_bare) {
			// No site on the box: nothing for Joinery status checks to probe,
			// no domain to certify. The domain is the node's name/DNS identity
			// (e.g. a relay MX hostname), not a site.
			$node->set('mgn_skip_joinery_checks', true);
		} else {
			$node->set('mgn_web_root',  "/var/www/html/{$sitename}/public_html");
			$node->set('mgn_site_url',  'https://' . $domain);
			$node->set('mgn_ssl_state', 'pending');
			if ($docker_mode === 'docker') {
				$node->set('mgn_port', $port);
			}
		}
		$node->set('mgn_enabled',       true);
		if (!$node->key) {
			$node->prepare();
		}
		$node->save();
		$node->load();

		// A container on a shared host needs its placement record now. It is the
		// only sibling identity (mgn_mgh_host_id), the port pool unions siblings
		// through it, and — once the host's own agent joins — it is the record
		// link_host_node fills so host-scope work (decommission_site, certs,
		// rebuild) can be routed.
		if ($docker_mode === 'docker' && !$is_bare) {
			ManagedHost::ensure_for_node($node);
		}

		$job_params = [
			'mode'        => $install_mode,
			'sitename'    => $sitename,
			'domain'      => $domain,
			'docker_mode' => $docker_mode,
			'admin_email' => $provision->get('cvp_buyer_email'),
			'user_name'   => $provision->get('cvp_buyer_name'),
		];
		if ($clone !== null) {
			$job_params['source_node_id'] = (int)$provision->get('cvp_source_node_id');
			$job_params['clone_from']     = $clone['from'];
			$job_params['clone_key']      = $clone['key'];
		}

		try {
			$steps = JobCommandBuilder::build_install_node($node, $job_params);
		} catch (Exception $e) {
			$node->set('mgn_install_state', 'install_failed');
			$node->save();
			$this->alert_and_fail($provision, 'Failed to build install steps — ' . $e->getMessage());
			return 1;
		}

		$job = ManagementJob::createJob($node->key, 'install_node', $steps, $job_params, null);
		$job->set('mjb_external_order_item_id', $provision->get('cvp_external_order_item_id'));
		$job->save();

		$provision->set('cvp_mgn_node_id', $node->key);
		$provision->set('cvp_instance_ip', $instance['ip']);
		$provision->set('cvp_status',      'installing');
		$provision->save();
		return 1;
	}

	/**
	 * installing -> done|failed: drive result processing on the finished
	 * install job (the executor writes job status directly; result processing
	 * is what flips node state and sends the welcome email).
	 */
	private function handle_installing($provision) {
		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT mjb_id FROM mjb_management_jobs " .
			"WHERE mjb_mgn_node_id = ? AND mjb_job_type = 'install_node' AND mjb_delete_time IS NULL " .
			"ORDER BY mjb_id DESC LIMIT 1"
		);
		$q->execute([$provision->get('cvp_mgn_node_id')]);
		$job_id = $q->fetchColumn();
		if (!$job_id) {
			$this->alert_and_fail($provision, 'Install job disappeared — manual review required.');
			return 1;
		}

		$job = new ManagementJob($job_id, TRUE);
		$status = $job->get('mjb_status');
		if ($status !== 'completed' && $status !== 'failed') {
			return 0; // still running
		}

		if (!$job->get('mjb_result')) {
			JobResultProcessor::process($job);
			$job->load();
		}

		$node = new ManagedNode($provision->get('cvp_mgn_node_id'), TRUE);

		if ($node->get('mgn_install_state') === null) {
			$this->complete($provision, $node);
		} else {
			$this->alert_and_fail($provision,
				"Install job #{$job->key} finished '{$status}' with install_state '{$node->get('mgn_install_state')}' — see the job detail page.");
		}
		return 1;
	}

	/**
	 * A failed provision is not a dead end: the admin can Retry Install from
	 * the node detail page. When that retry succeeds the NODE recovers
	 * (install_state clears) but nothing else touches the provision — so each
	 * tick re-checks failed provisions against their node and completes them
	 * once the node is healthy. Silent while the node is still broken: the
	 * failure was already alerted once, and re-alerting every tick would spam
	 * the admin.
	 */
	private function handle_failed_recheck($provision) {
		// A failed clone keeps its source armed for Retry Install, but not
		// past the TTL: a key scoped to one provision needs an end.
		if (trim((string)$provision->get('cvp_clone_key_sealed')) !== '') {
			$since = $provision->get('cvp_update_time') ?: $provision->get('cvp_create_time');
			if ($since && (time() - strtotime($since . ' UTC')) > self::CLONE_ARM_TTL_DAYS * 86400) {
				$this->release_clone_source($provision);
				$provision->save();
				return 1;
			}
		}
		$node_id = (int)$provision->get('cvp_mgn_node_id');
		if (!$node_id) {
			return 0; // failed before a node existed — nothing to recover from
		}
		$node = new ManagedNode($node_id, TRUE);
		if (!$node->key || $node->get('mgn_install_state') !== null) {
			return 0; // node gone or still broken — stay failed, no re-alert
		}

		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT mjb_id FROM mjb_management_jobs " .
			"WHERE mjb_mgn_node_id = ? AND mjb_job_type = 'install_node' AND mjb_delete_time IS NULL " .
			"ORDER BY mjb_id DESC LIMIT 1"
		);
		$q->execute([$node_id]);
		$job_id = $q->fetchColumn();
		if (!$job_id) {
			return 0;
		}
		$job = new ManagementJob($job_id, TRUE);
		if ($job->get('mjb_status') !== 'completed') {
			return 0;
		}

		$this->complete($provision, $node);

		// The buyer's welcome email is normally sent by JobResultProcessor when
		// the completed job carries the order-item linkage. A retry job that
		// predates linkage-copying has none — send it here instead, never both.
		if (!$job->get('mjb_external_order_item_id')
				&& $provision->get('cvp_external_order_item_id')) {
			$job->set('mjb_external_order_item_id', $provision->get('cvp_external_order_item_id'));
			$job->save();
			JobResultProcessor::send_provisioning_welcome_email($job, $node);
		}
		return 1;
	}

	/**
	 * The install is done: mark it, let the clone's source go, and queue the
	 * fleet seeding that waits for the node's agent.
	 */
	private function complete($provision, $node): void {
		$provision->set('cvp_status', 'done');
		$provision->set('cvp_error',  null);
		$provision->set('cvp_fleet_seed_state', $this->seeding_applies($provision) ? 'pending' : null);
		$this->release_clone_source($provision);
		$provision->save();
		// The bootstrap job carried the clone key for Retry Install. The
		// source is disarmed, so the key opens nothing and does not stay.
		$install = ManagementJob::latestForNode($node->key, 'install_node');
		if ($install) {
			JobResultProcessor::blank_install_clone_key($install);
		}
	}

	/**
	 * Disarm the clone's source and forget the key. Called when the provision
	 * ends, and when it fails before an instance exists; a failure WITH a live
	 * instance keeps the source armed, because Retry Install re-runs the same
	 * bootstrap with the same key (the mirror of the sealed-root-password rule).
	 */
	private function release_clone_source($provision): void {
		if (trim((string)$provision->get('cvp_clone_key_sealed')) === '') {
			return;
		}
		$provision->set('cvp_clone_key_sealed', null);
		$source = $this->clone_source($provision);
		if (!$source) {
			return;
		}
		// Disarm only a key that is ours. If another provision has since armed
		// the source (it should not have — see source_busy_with — but a row
		// edited by hand can get there), blanking it would cut that clone off
		// mid-pull.
		if (!$this->source_armed_by($source, $provision)) {
			return;
		}
		try {
			ManagementJob::createFromBuild($source->key, 'clone_export_arm',
				JobCommandBuilder::build_clone_export_arm($source, ['export_key' => '']),
				['provision_id' => (int)$provision->key], null);
		} catch (Exception $e) {
			// The source cannot be disarmed over the channel right now. Say
			// so: an armed export is a door left open.
			$reason = "Could not disarm the clone source '{$source->get('mgn_slug')}': " . $e->getMessage()
				. ' Clear clone_export_key on that site.';
			error_log('ProvisionCustomerCloud: ' . $reason);
			$this->errors[] = "Provision #{$provision->key}: {$reason}";
		}
	}

	// ── Fleet enrollment seeding (specs/mailbox_relay_shared_fleet.md § Follow-up) ──
	//
	// When the buyer's tier carries the fleet-slot feature, the finished site
	// gets the fleet-service settings seeded so its owner lands on a one-click
	// Enroll. It travels as the fleet_enroll primitive on the new site's own
	// agent, so it waits for that agent to pair. Best-effort — the site is up
	// either way and the owner can always enter the credentials manually — so
	// a seeding failure alerts ops but never fails the provision. Bare
	// instances have no site to seed.

	private function seeding_applies($provision): bool {
		if (($provision->get('cvp_install_mode') ?: 'fresh') === 'bare') {
			return false;
		}
		$seeder = PathHelper::getIncludePath('plugins/mailbox/includes/FleetProvisionSeeding.php');
		if (!is_file($seeder) || !PluginHelper::isPluginActive('mailbox')) {
			return false;
		}
		try {
			require_once($seeder);
			return FleetProvisionSeeding::applies((int)$provision->get('cvp_usr_user_id'));
		} catch (\Throwable $e) {
			error_log('ProvisionCustomerCloud: fleet seeding gate failed: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * pending -> dispatched once the node's agent has paired and reports
	 * fleet_enroll; dispatched -> done | failed by the job's answer.
	 */
	private function handle_seeding($provision) {
		$node_id = (int)$provision->get('cvp_mgn_node_id');
		$node = $node_id ? new ManagedNode($node_id, TRUE) : null;
		if (!$node || !$node->key || $node->get('mgn_delete_time')) {
			$provision->set('cvp_fleet_seed_state', 'failed');
			$provision->save();
			return 1;
		}
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetProvisionSeeding.php'));

		if ($provision->get('cvp_fleet_seed_state') === 'pending') {
			if (!FleetProvisionSeeding::nodeReady($node)) {
				return 0; // the agent has not paired yet, or is older than fleet_enroll
			}
			$result = FleetProvisionSeeding::seedNode($node, (int)$provision->get('cvp_usr_user_id'));
			if (!$result['ok']) {
				$this->fail_seeding($provision, $result['message']);
				return 1;
			}
			$provision->set('cvp_fleet_seed_state', 'dispatched');
			$provision->save();
			return 1;
		}

		$outcome = FleetProvisionSeeding::outcome($node);
		if ($outcome['state'] === 'pending') {
			return 0;
		}
		if ($outcome['state'] === 'seeded') {
			$provision->set('cvp_fleet_seed_state', 'done');
			$provision->save();
			error_log('ProvisionCustomerCloud: fleet enrollment seeded for provision #'
				. $provision->key . ' (' . $provision->get('cvp_domain') . ')');
			return 1;
		}
		$this->fail_seeding($provision, $outcome['message']);
		return 1;
	}

	private function fail_seeding($provision, string $message): void {
		$provision->set('cvp_fleet_seed_state', 'failed');
		$provision->save();

		$reason = 'Fleet enrollment seeding failed for ' . $provision->get('cvp_domain') . ': ' . $message;
		error_log('ProvisionCustomerCloud: ' . $reason);
		$this->errors[] = "Provision #{$provision->key}: {$reason}";
		$to = $this->resolve_alert_recipient();
		if ($to) {
			try {
				EmailSender::quickSend($to, '[customer-cloud] Fleet seeding failed: ' . $provision->get('cvp_domain'),
					"The site installed fine, but seeding its hosted-relay credentials failed.\n\n"
					. "Domain: " . $provision->get('cvp_domain') . "\n"
					. "Reason: " . $message . "\n\n"
					. "The owner can still connect manually: mint an API key for their account and "
					. "enter it with this deployment's URL on their mailbox Settings tab.\n");
			} catch (\Throwable $e) {
				error_log('ProvisionCustomerCloud: fleet seeding alert send failed: ' . $e->getMessage());
			}
		}
	}

	/**
	 * Build a driver carrying a fresh access token for the provision's linked
	 * account. Returns null (after parking the provision appropriately) when
	 * no usable grant exists.
	 */
	protected function get_driver($provision) {
		$account_id = (int)$provision->get('cvp_cca_account_id');
		$account = $account_id ? new CustomerCloudAccount($account_id, TRUE) : null;
		if (!$account || !$account->key || $account->get('cca_status') !== 'active') {
			$this->park_for_reconnect($provision, 'Account link missing or not active.');
			return null;
		}

		$token = $account->getToken();
		if ($token === null) {
			$this->park_for_reconnect($provision, 'No stored token on the account link.');
			return null;
		}

		$client = new OAuth2Client();
		$provider_class = OAuth2ProviderRegistry::get($account->get('cca_provider'));
		if ($provider_class === null) {
			$this->errors[] = "Provision #{$provision->key}: unknown provider '{$account->get('cca_provider')}'.";
			return null;
		}

		try {
			$fresh = $client->ensureFresh($provider_class, $token);
		} catch (OAuth2Exception $e) {
			$account->set('cca_status', 'refresh_failed');
			$account->save();
			$this->park_for_reconnect($provision, 'Token refresh failed: ' . $e->getMessage());
			return null;
		}

		if ($fresh->getAccessToken() !== $token->getAccessToken()) {
			$account->storeToken($fresh);
			$account->save();
		}

		return new LinodeComputeDriver($fresh->getAccessToken());
	}

	/**
	 * Compute-API failure policy: 401 revokes the grant and parks the
	 * provision for re-connect; other 4xx (bad request/validation) are
	 * terminal; 5xx/network stay put and retry next tick.
	 * Returns 1 when the provision changed state, 0 for retry.
	 */
	private function handle_compute_failure($provision, CloudComputeException $e, $phase) {
		$code = (int)$e->getCode();
		if ($code === 401) {
			$account_id = (int)$provision->get('cvp_cca_account_id');
			if ($account_id) {
				$account = new CustomerCloudAccount($account_id, TRUE);
				if ($account->key) {
					$account->set('cca_status', 'revoked');
					$account->save();
				}
			}
			$this->park_for_reconnect($provision, 'Provider rejected the grant (401).');
			return 1;
		}
		if ($code >= 400 && $code < 500 && $code !== 429) {
			$this->alert_and_fail($provision, "Provider API error during {$phase}: " . $e->getMessage());
			return 1;
		}
		// Transient — record and retry next tick.
		$provision->set('cvp_error', mb_substr('Transient (' . $phase . '): ' . $e->getMessage(), 0, 4000));
		$provision->save();
		$this->errors[] = "Provision #{$provision->key}: transient provider error ({$phase}), will retry.";
		return 0;
	}

	/**
	 * The bridge credentials — the sealed root password, and a clone's export
	 * key — exist for a running instance, and only for the length of its
	 * install. When a provision ends (or parks) with no instance created,
	 * there is no machine they open — so they are released now rather than
	 * held indefinitely, which would be the shared-key defect in miniature. A
	 * provision that failed WITH a live instance keeps both: that is the WP3
	 * recovery decision, made where the instance actually exists.
	 */
	private function release_credentials_if_no_instance($provision) {
		if (trim((string)$provision->get('cvp_instance_id')) !== '') {
			return;
		}
		if (trim((string)$provision->get('cvp_root_pass_sealed')) !== '') {
			$provision->set('cvp_root_pass_sealed', null);
		}
		$this->release_clone_source($provision);
	}

	/**
	 * Park a provision back at pending_connect (the buyer must re-grant) and
	 * note why. The Connect page doubles as the re-connect page; the consumer
	 * flips it to ready again on the next grant.
	 */
	private function park_for_reconnect($provision, $reason) {
		$provision->set('cvp_status', 'pending_connect');
		$provision->set('cvp_error',  mb_substr($reason, 0, 4000));
		$this->release_credentials_if_no_instance($provision);
		$provision->save();
		$this->errors[] = "Provision #{$provision->key}: parked for re-connect — {$reason}";

		// The buyer must act (re-grant) or the provision waits forever — tell them.
		$to = trim((string)$provision->get('cvp_buyer_email'));
		if ($to === '') return;
		$connect_url = LibraryFunctions::get_absolute_url('/profile/server_manager/connect_cloud');
		$body = "Hi " . ($provision->get('cvp_buyer_name') ?: 'there') . ",\n\n"
		      . "Setting up " . $provision->get('cvp_domain') . " needs a fresh connection to your Linode account.\n"
		      . "Please reconnect here and setup will resume automatically:\n\n"
		      . $connect_url . "\n\n"
		      . "— The Get Joinery Team\n";
		try {
			EmailSender::quickSend($to, 'Action needed: reconnect your account to finish ' . $provision->get('cvp_domain'), $body);
		} catch (\Throwable $e) {
			error_log('ProvisionCustomerCloud: reconnect email send failed: ' . $e->getMessage());
		}
	}

	/**
	 * Terminal failure: record it and alert the ops address.
	 */
	protected function alert_and_fail($provision, $reason) {
		// Release before fail()'s save so a no-instance failure carries no
		// dangling credential; a failure with a live instance keeps them (WP3 recovery).
		$this->release_credentials_if_no_instance($provision);
		$provision->fail($reason);
		$this->errors[] = "Provision #{$provision->key}: FAILED — {$reason}";

		$to = $this->resolve_alert_recipient();
		if (!$to) {
			error_log('ProvisionCustomerCloud: no alert recipient resolved for provision ' . $provision->key);
			return;
		}
		$body = "Customer-cloud provision failed.\n\n"
		      . "Domain: " . $provision->get('cvp_domain') . "\n"
		      . "Order item: " . $provision->get('cvp_external_order_item_id') . "\n"
		      . "Buyer: " . $provision->get('cvp_buyer_email') . "\n"
		      . "Instance: " . ($provision->get('cvp_instance_id') ?: 'not created') . "\n"
		      . "Reason: {$reason}\n";
		try {
			EmailSender::quickSend($to, '[customer-cloud] Provision failed: ' . $provision->get('cvp_domain'), $body);
		} catch (\Throwable $e) {
			error_log('ProvisionCustomerCloud: alert send failed: ' . $e->getMessage());
		}
	}

	/**
	 * Alert recipient fallback chain (same as RunNodeUptimeChecks):
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
