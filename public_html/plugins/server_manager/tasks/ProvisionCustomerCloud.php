<?php
/**
 * ProvisionCustomerCloud - Advances cloud-instance provisions to running sites.
 *
 * Handles both origins: order-origin provisions created by hosting purchases
 * and admin-origin provisions created by the Install New Node form's
 * cloud-instance target. Install parameters (docker mode, fresh/from-backup,
 * source node, port) ride on the provision row.
 *
 * Works the cvp_customer_cloud_provisions state machine each cron tick:
 *
 *   ready      -> create the instance on the customer's cloud account -> booting
 *   booting    -> wait for running + public IP -> create ManagedNode +
 *                 install_node job (Go agent executes it) -> installing
 *   installing -> drive JobResultProcessor on the finished job -> done | failed
 *
 * install_mode 'bare' (admin-origin only) births the instance and creates the
 * ManagedNode but installs no site: the verification job is a plain
 * check_status, and the node completes with mgn_skip_joinery_checks set and no
 * web root, site URL, or SSL state. Infrastructure roles (e.g. mail relay
 * shards) build on the bare node via their own provision jobs.
 *
 * The install job carries mjb_external_order_item_id, so the standard welcome
 * email and Provision Pending SSL flows apply unchanged from 'installing' on.
 *
 * Tokens are kept fresh via OAuth2Client::ensureFresh; a refresh failure or a
 * 401 from the provider flips the account to refresh_failed/revoked and parks
 * the provision back at pending_connect for the buyer to re-connect.
 *
 * Settings (Server Manager plugin settings):
 *   server_manager_customer_cloud_ssh_key_path  private key the Go agent uses
 *       for created nodes; its .pub sibling is installed via authorized_keys
 *   server_manager_customer_cloud_region        default region
 *   server_manager_customer_cloud_type          default instance type
 *   server_manager_customer_cloud_image         default OS image
 *
 * @version 1.4
 */
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class ProvisionCustomerCloud implements ScheduledTaskInterface {

	const BOOT_TIMEOUT_SECONDS = 1800; // 30 min from instance create to running

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

		if (count($actionable) === 0) {
			return ['status' => 'success', 'message' => 'No customer-cloud provisions to advance.'];
		}

		$settings = Globalvars::get_instance();
		$key_path = trim((string)$settings->get_setting('server_manager_customer_cloud_ssh_key_path'));
		if ($key_path === '' || !is_readable($key_path . '.pub')) {
			return [
				'status'  => 'error',
				'message' => 'server_manager_customer_cloud_ssh_key_path is unset or its .pub sibling is unreadable — customer-cloud provisioning is blocked.',
			];
		}

		$advanced = 0;
		foreach ($actionable as $provision) {
			try {
				switch ($provision->get('cvp_status')) {
					case 'ready':      $advanced += $this->handle_ready($provision, $key_path); break;
					case 'booting':    $advanced += $this->handle_booting($provision, $key_path); break;
					case 'installing': $advanced += $this->handle_installing($provision); break;
					case 'failed':     $advanced += $this->handle_failed_recheck($provision); break;
				}
			} catch (Exception $e) {
				$this->errors[] = "Provision #{$provision->key} ({$provision->get('cvp_domain')}): " . $e->getMessage();
			}
		}

		$msg = "Customer-cloud: {$advanced} provision(s) advanced of " . count($actionable) . " actionable.";
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
	private function handle_ready($provision, $key_path) {
		$driver = $this->get_driver($provision);
		if ($driver === null) return 0;

		$settings = Globalvars::get_instance();
		$region = $provision->get('cvp_region')        ?: ($settings->get_setting('server_manager_customer_cloud_region') ?: 'us-southeast');
		$type   = $provision->get('cvp_instance_type') ?: ($settings->get_setting('server_manager_customer_cloud_type')   ?: 'g6-nanode-1');
		$image  = $settings->get_setting('server_manager_customer_cloud_image') ?: 'linode/ubuntu24.04';

		$pubkey = trim((string)file_get_contents($key_path . '.pub'));

		try {
			$instance = $driver->createInstance(array(
				// cvp id suffix keeps labels unique on the customer's account
				// across re-provisions of the same domain.
				'label'           => $provision->get('cvp_slug') . '-' . $provision->key,
				'region'          => $region,
				'type'            => $type,
				'image'           => $image,
				// Never stored; all management is via the SSH key.
				'root_pass'       => 'Aa1!' . bin2hex(random_bytes(20)),
				'authorized_keys' => array($pubkey),
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
	 * booting -> installing: once the instance is running with a public IP,
	 * create the managed node and dispatch the standard install_node job.
	 */
	private function handle_booting($provision, $key_path) {
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

		$domain = $provision->get('cvp_domain');
		$slug   = $provision->get('cvp_slug');

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
		$docker_mode  = $provision->get('cvp_docker_mode')  ?: 'docker';
		$install_mode = $provision->get('cvp_install_mode') ?: 'fresh';
		$sitename     = $provision->get('cvp_sitename')     ?: $slug;
		$port         = (int)($provision->get('cvp_port')   ?: 8080);
		$is_bare      = ($install_mode === 'bare');

		$node->set('mgn_host',          $instance['ip']);
		$node->set('mgn_ssh_user',      'root');
		$node->set('mgn_ssh_key_path',  $key_path);
		$node->set('mgn_ssh_port',      22);
		$node->set('mgn_install_state', 'installing');
		if ($is_bare) {
			// No site on the box: nothing for Joinery status checks to probe,
			// no vhost for ProvisionPendingSsl to certbot. The domain is the
			// node's name/DNS identity (e.g. a relay MX hostname), not a site.
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

		// A bare instance is done when it answers over SSH: dispatch a plain
		// status check as the verification job and let handle_installing watch
		// it, reusing the same job-driven completion path as site installs.
		if ($is_bare) {
			try {
				$steps = JobCommandBuilder::build_check_status($node);
			} catch (Exception $e) {
				$node->set('mgn_install_state', 'install_failed');
				$node->save();
				$this->alert_and_fail($provision, 'Failed to build verification steps — ' . $e->getMessage());
				return 1;
			}
			ManagementJob::createJob($node->key, 'check_status', $steps, [], null);
			$provision->set('cvp_mgn_node_id', $node->key);
			$provision->set('cvp_instance_ip', $instance['ip']);
			$provision->set('cvp_status',      'installing');
			$provision->save();
			return 1;
		}

		// build_install_node contract: 'domain' is the primary domain for a
		// fresh install but the SOURCE domain for from_backup (the target
		// domain comes from the node's site URL via post-restore fixups).
		$job_domain = $domain;
		if ($install_mode === 'from_backup') {
			$source_node = new ManagedNode((int)$provision->get('cvp_source_node_id'), TRUE);
			$job_domain = parse_url($source_node->get('mgn_site_url') ?: '', PHP_URL_HOST) ?: $domain;
		}

		$job_params = [
			'mode'        => $install_mode,
			'sitename'    => $sitename,
			'domain'      => $job_domain,
			'docker_mode' => $docker_mode,
			'admin_email' => $provision->get('cvp_buyer_email'),
			'user_name'   => $provision->get('cvp_buyer_name'),
		];
		if ($install_mode === 'from_backup') {
			$job_params['source_node_id'] = (int)$provision->get('cvp_source_node_id');
			$job_params['backup_source']  = $provision->get('cvp_backup_source') ?: 'new';
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
	 * install job (the Go agent writes job status directly to the DB; result
	 * processing is what flips node state and sends the welcome email).
	 */
	private function handle_installing($provision) {
		$is_bare  = ($provision->get('cvp_install_mode') === 'bare');
		$job_type = $is_bare ? 'check_status' : 'install_node';

		$db = DbConnector::get_instance()->get_db_link();
		$q = $db->prepare(
			"SELECT mjb_id FROM mjb_management_jobs " .
			"WHERE mjb_mgn_node_id = ? AND mjb_job_type = ? AND mjb_delete_time IS NULL " .
			"ORDER BY mjb_id DESC LIMIT 1"
		);
		$q->execute([$provision->get('cvp_mgn_node_id'), $job_type]);
		$job_id = $q->fetchColumn();
		if (!$job_id) {
			$this->alert_and_fail($provision, ucfirst($is_bare ? 'verification' : 'install') . ' job disappeared — manual review required.');
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

		// Bare instances: nothing clears install_state for a check_status job —
		// a completed check IS the proof of life, so clear it here.
		if ($is_bare) {
			if ($status === 'completed') {
				$node->set('mgn_install_state', null);
				$node->save();
				$provision->set('cvp_status', 'done');
				$provision->set('cvp_error',  null);
				$provision->save();
			} else {
				$node->set('mgn_install_state', 'install_failed');
				$node->save();
				$this->alert_and_fail($provision,
					"Verification job #{$job->key} finished '{$status}' — the instance is up on the provider but did not answer over SSH.");
			}
			return 1;
		}

		if ($node->get('mgn_install_state') === null) {
			$provision->set('cvp_status', 'done');
			$provision->set('cvp_error',  null);
			$provision->save();
			$this->seed_fleet_enrollment($provision, $node);
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

		$provision->set('cvp_status', 'done');
		$provision->set('cvp_error',  null);
		$provision->save();
		$this->seed_fleet_enrollment($provision, $node);

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
	 * Order-time fleet enrollment (specs/mailbox_relay_shared_fleet.md
	 * § Follow-up): when the buyer's tier carries the fleet-slot feature, the
	 * finished site gets the fleet-service settings pre-seeded so its owner
	 * lands on a one-click Enroll. Best-effort — the site is up either way and
	 * the owner can always enter the credentials manually, so a seeding
	 * failure alerts ops but never fails the provision. Bare instances have
	 * no site to seed.
	 */
	private function seed_fleet_enrollment($provision, $node) {
		if (($provision->get('cvp_install_mode') ?: 'fresh') === 'bare') {
			return;
		}
		$seeder = PathHelper::getIncludePath('plugins/mailbox/includes/FleetProvisionSeeding.php');
		if (!is_file($seeder) || !PluginHelper::isPluginActive('mailbox')) {
			return;
		}
		try {
			require_once($seeder);
			$buyer_id = (int)$provision->get('cvp_usr_user_id');
			if (!FleetProvisionSeeding::applies($buyer_id)) {
				return;
			}
			$sitename = $provision->get('cvp_sitename') ?: $provision->get('cvp_slug');
			$result = FleetProvisionSeeding::seedNode($node, $buyer_id, (string)$sitename);
			if ($result['ok']) {
				error_log('ProvisionCustomerCloud: fleet enrollment seeded for provision #'
					. $provision->key . ' (' . $provision->get('cvp_domain') . ')');
				return;
			}
		} catch (\Throwable $e) {
			$result = array('ok' => false, 'message' => $e->getMessage());
		}

		$reason = 'Fleet enrollment seeding failed for ' . $provision->get('cvp_domain')
			. ': ' . $result['message'];
		error_log('ProvisionCustomerCloud: ' . $reason);
		$this->errors[] = "Provision #{$provision->key}: {$reason}";
		$to = $this->resolve_alert_recipient();
		if ($to) {
			try {
				EmailSender::quickSend($to, '[customer-cloud] Fleet seeding failed: ' . $provision->get('cvp_domain'),
					"The site installed fine, but pre-seeding its hosted-relay credentials failed.\n\n"
					. "Domain: " . $provision->get('cvp_domain') . "\n"
					. "Reason: " . $result['message'] . "\n\n"
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
	private function get_driver($provision) {
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
	 * Park a provision back at pending_connect (the buyer must re-grant) and
	 * note why. The Connect page doubles as the re-connect page; the consumer
	 * flips it to ready again on the next grant.
	 */
	private function park_for_reconnect($provision, $reason) {
		$provision->set('cvp_status', 'pending_connect');
		$provision->set('cvp_error',  mb_substr($reason, 0, 4000));
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
	private function alert_and_fail($provision, $reason) {
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
