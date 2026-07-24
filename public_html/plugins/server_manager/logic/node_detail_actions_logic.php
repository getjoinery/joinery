<?php
/**
 * NodeDetailActions — the POST action dispatch for the node detail page.
 *
 * Every state-changing button on /admin/server_manager/node_detail posts here
 * (through the thin shell view). One entry point gives three things the inline
 * handlers could not:
 *
 *   - CSRF once, for every action. The token is validated a single time before
 *     any handler runs, so all 18 actions are covered (the inline version only
 *     guarded delete_node and escrow_backup_key).
 *   - Uniform error handling (R-3). A builder that throws produces a user-facing
 *     message and a redirect back to the right tab — never an unhandled 500.
 *     Only check_status lacked a try/catch before; now none can.
 *   - The handlers out of the render path, leaving the shell to load the node,
 *     pick the tab, and include the tab partial.
 *
 * dispatch() returns the redirect URL for a handled action, or null when there
 * is no known action (the shell then renders the page). The shell owns the
 * actual header()/redirect — logic files never exit().
 *
 * @version 1.2 - reverse-DNS messages are plain text (display loop escapes); ensureNodeKey failures fail loud
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/BackupKeyCustody.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmAdminCsrf.php'));

class NodeDetailActions {

	/** Which tab a failed action redirects back to (its error message shows there). */
	private static $error_tab = [
		'check_status'             => 'overview',
		'backup_database'          => 'backups',
		'backup_project'           => 'backups',
		'escrow_backup_key'        => 'backups',
		'copy_database'            => 'database',
		'copy_database_local'      => 'database',
		'restore_database'         => 'database',
		'restore_project'          => 'backups',
		'apply_update'             => 'updates',
		'apply_update_all_on_host' => 'updates',
		'retry_install'            => 'overview',
		'provision_ssl'            => 'overview',
		'run_plugin_installers'    => 'overview',
		'set_reverse_dns'          => 'overview',
		'save_api_credential'      => 'api_keys',
		'clear_api_credential'     => 'api_keys',
		'save_node'                => 'overview',
		'delete_node'              => 'overview',
	];

	/**
	 * Handle the posted action, if any. Returns the redirect URL for a handled
	 * action; returns null when there is no action or it is unknown (the shell
	 * then renders the page).
	 */
	public static function dispatch($node, $session, string $base_url, string $page_regex): ?string {
		if (!($_POST && isset($_POST['action']))) {
			return null;
		}
		$action = (string) $_POST['action'];
		if (!isset(self::$error_tab[$action])) {
			return null; // unknown action — let the shell render the page normally
		}

		// CSRF once, before any handler runs (covers all 18 actions).
		if (!SmAdminCsrf::valid()) {
			self::fail($session, $page_regex, 'Invalid request token. Please try again.');
			return $base_url;
		}

		try {
			return self::run($action, $node, $session, $base_url, $page_regex);
		} catch (Throwable $e) {
			// R-3: any builder/save throw becomes a message + redirect, not a 500.
			self::fail($session, $page_regex, $e->getMessage());
			return $base_url . '&tab=' . self::$error_tab[$action];
		}
	}

	/**
	 * The per-action work. Each case returns the redirect URL. A validation
	 * bounce sets its own message and returns the tab URL; a builder/save throw
	 * is turned into a message + tab redirect by the central catch in dispatch().
	 */
	private static function run($action, $node, $session, $base_url, $page_regex): string {
		$uid = $session->get_user_id();

		switch ($action) {

			case 'check_status': {
				$steps = JobCommandBuilder::build_check_status($node);
				$job = ManagementJob::createJob($node->key, 'check_status', $steps, null, $uid);
				return self::jobUrl($job);
			}

			case 'backup_database': {
				$params = ['encryption' => !empty($_POST['encryption'])];
				self::ensure_backup_key_if_encrypting($node, $params);
				$steps = JobCommandBuilder::build_backup_database($node, $params);
				$job = ManagementJob::createJob($node->key, 'backup_database', $steps, $params, $uid);
				return self::jobUrl($job);
			}

			case 'backup_project': {
				$params = ['encryption' => !empty($_POST['encryption'])];
				self::ensure_backup_key_if_encrypting($node, $params);
				$steps = JobCommandBuilder::build_backup_project($node, $params);
				$job = ManagementJob::createJob($node->key, 'backup_project', $steps, $params, $uid);
				return self::jobUrl($job);
			}

			// Escrow this node's existing backup key (migration action). Reads the
			// key over the direct SSH channel, seals it, appends an escrow row —
			// never via a job (job rows persist forever; the key must not).
			case 'escrow_backup_key': {
				BackupKeyCustody::ensureNodeKey($node);
				$session->save_message(new DisplayMessage(
					'Backup key escrowed for this node.',
					'Escrowed', NULL, DisplayMessage::MESSAGE_ANNOUNCEMENT));
				return $base_url . '&tab=backups';
			}

			case 'copy_database': {
				$source_id = intval($_POST['source_node_id'] ?? 0);
				if ($source_id) {
					if ($source_id === $node->key) {
						self::fail($session, $page_regex, 'Source and target sites must be different.');
						return $base_url . '&tab=database';
					}
					try {
						$source_node = new ManagedNode($source_id, TRUE);
					} catch (Exception $e) {
						self::fail($session, $page_regex, 'Source site not found.');
						return $base_url . '&tab=database';
					}
					$params = ['source_node_id' => $source_id, 'target_node_id' => $node->key];
					$steps = JobCommandBuilder::build_copy_database($source_node, $node, $params);
					$job = ManagementJob::createJob($node->key, 'copy_database', $steps, $params, $uid);
					return self::jobUrl($job);
				}
				return $base_url . '&tab=database';
			}

			case 'copy_database_local': {
				$source_db_name = trim($_POST['source_db_name'] ?? '');
				if ($source_db_name) {
					$params = ['source_db_name' => $source_db_name];
					$steps = JobCommandBuilder::build_copy_database_by_name($node, $params);
					$job = ManagementJob::createJob($node->key, 'copy_database_local', $steps, $params, $uid);
					return self::jobUrl($job);
				}
				return $base_url . '&tab=database';
			}

			case 'restore_database': {
				$filename   = trim($_POST['backup_filename'] ?? '');
				$local_path = trim($_POST['backup_local_path'] ?? '');
				$cloud_path = trim($_POST['backup_cloud_path'] ?? '');
				if ($filename && ($local_path || $cloud_path)) {
					$params = [
						'filename'   => $filename,
						'local_path' => $local_path ?: null,
						'cloud_path' => $cloud_path ?: null,
					];
					$steps = JobCommandBuilder::build_restore_database($node, $params);
					$job = ManagementJob::createJob($node->key, 'restore_database', $steps, $params, $uid);
					return self::jobUrl($job);
				}
				return $base_url . '&tab=database';
			}

			case 'restore_project': {
				$filename   = trim($_POST['backup_filename'] ?? '');
				$local_path = trim($_POST['backup_local_path'] ?? '');
				$cloud_path = trim($_POST['backup_cloud_path'] ?? '');
				if (!$filename || (!$local_path && !$cloud_path)) {
					return $base_url . '&tab=backups';
				}
				$params = [
					'filename'      => $filename,
					'local_path'    => $local_path ?: null,
					'cloud_path'    => $cloud_path ?: null,
					'skip_database' => empty($_POST['restore_database']),
					'skip_files'    => empty($_POST['restore_files']),
					'skip_apache'   => empty($_POST['restore_apache']),
				];
				$steps = JobCommandBuilder::build_restore_project($node, $params);
				$job = ManagementJob::createJob($node->key, 'restore_project', $steps, $params, $uid);
				return self::jobUrl($job);
			}

			case 'apply_update': {
				$steps = JobCommandBuilder::build_apply_update($node);
				$job = ManagementJob::createJob($node->key, 'apply_update', $steps, [], $uid);
				return self::jobUrl($job);
			}

			case 'apply_update_all_on_host': {
				$siblings = new MultiManagedNode(
					['host' => $node->get('mgn_host'), 'enabled' => true, 'deleted' => false],
					['mgn_slug' => 'ASC']
				);
				$siblings->load();
				if ($siblings->count() === 0) {
					self::fail($session, $page_regex, 'No eligible sites found on this host.');
					return $base_url . '&tab=updates';
				}
				$queued = 0;
				foreach ($siblings as $sibling) {
					try {
						$steps = JobCommandBuilder::build_apply_update($sibling);
						ManagementJob::createJob($sibling->key, 'apply_update', $steps, [], $uid);
						$queued++;
					} catch (Exception $e) {
						error_log("apply_update_all_on_host: failed to queue node {$sibling->key}: " . $e->getMessage());
					}
				}
				if ($queued === 0) {
					self::fail($session, $page_regex, 'No jobs were queued.');
					return $base_url . '&tab=updates';
				}
				$session->save_message(new DisplayMessage(
					"Queued {$queued} upgrade " . ($queued === 1 ? 'job' : 'jobs') . " for sites on this host.",
					'Success', $page_regex,
					DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
				return '/admin/server_manager?tab=jobs';
			}

			case 'retry_install': {
				// Reuse params from the most recent install_node job for this node.
				$prev = ManagementJob::latestForNode($node->key, 'install_node');
				if (!$prev) {
					self::fail($session, $page_regex, 'No prior install job found for this node.');
					return $base_url;
				}
				$params = $prev->get('mjb_parameters');
				if (is_string($params)) { $params = json_decode($params, true); }
				$steps = JobCommandBuilder::build_install_node($node, $params ?: []);
				$node->set('mgn_install_state', 'installing');
				$node->save();
				$job = ManagementJob::createJob($node->key, 'install_node', $steps, $params, $uid);
				// Carry the auto-provisioned order linkage forward: the retry is the
				// same fulfillment, and success must still send the buyer's welcome
				// email (JobResultProcessor keys it off this field).
				if ($prev->get('mjb_external_order_item_id')) {
					$job->set('mjb_external_order_item_id', $prev->get('mjb_external_order_item_id'));
					$job->save();
				}
				return self::jobUrl($job);
			}

			case 'provision_ssl': {
				$domain = parse_url($node->get('mgn_site_url'), PHP_URL_HOST);
				if (!$domain) {
					self::fail($session, $page_regex, 'Cannot provision SSL: node has no site URL with a domain.');
					return $base_url . '&tab=overview';
				}
				if (!JobCommandBuilder::has_ssh($node)) {
					self::fail($session, $page_regex, 'Cannot provision SSL: SSH is not configured for this node.');
					return $base_url . '&tab=overview';
				}
				$settings = Globalvars::get_instance();
				$alert_email = $settings->get_setting('server_manager_provisioning_admin_alert_email') ?: '';
				$job_params = ['domain' => $domain, 'admin_email' => $alert_email];
				$steps = JobCommandBuilder::build_provision_ssl($node, $job_params);
				$job = ManagementJob::createJob($node->key, 'provision_ssl', $steps, $job_params, $uid);
				$node->set('mgn_ssl_state', 'pending');
				$node->save();
				return self::jobUrl($job);
			}

			case 'run_plugin_installers': {
				$steps = JobCommandBuilder::build_run_plugin_installers($node);
				$job = ManagementJob::createJob($node->key, 'run_plugin_installers', $steps, [], $uid);
				return self::jobUrl($job);
			}

			case 'set_reverse_dns': {
				require_once(PathHelper::getIncludePath('plugins/server_manager/includes/NodeReverseDns.php'));
				$rdns_hostname = trim($_POST['rdns_hostname'] ?? '');
				try {
					$result = NodeReverseDns::set($node, $rdns_hostname);
					// Messages are plain text: the display loop escapes them, so
					// pre-escaping double-encodes and embedded HTML renders literally.
					$session->save_message(new DisplayMessage(
						'Reverse DNS set: ' . $result['ip'] . ' now answers ' . $result['rdns'] . '. Resolver caches may take a few minutes to catch up.',
						'Saved', $page_regex, DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
					));
				} catch (NodeReverseDnsException $e) {
					$msg = $e->getMessage();
					if ($e->reconnect) {
						$msg .= ' Re-connect the cloud account at /profile/server_manager/connect_cloud, then retry.';
					}
					$session->save_message(new DisplayMessage(
						$msg, 'Error', $page_regex, DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
					));
				}
				return $base_url . '&tab=overview';
			}

			case 'save_api_credential': {
				$pub = trim($_POST['mgn_api_public_key'] ?? '');
				$sec = trim($_POST['mgn_api_secret_key'] ?? '');
				$tls_insecure = !empty($_POST['mgn_tls_insecure']);
				$node->set('mgn_api_public_key', $pub !== '' ? $pub : null);
				// Empty secret on an existing-credentials form means "keep current".
				if ($sec !== '') {
					$node->set('mgn_api_secret_key', $sec);
				} elseif ($pub === '') {
					$node->set('mgn_api_secret_key', null); // both cleared → wipe secret
				}
				$node->set('mgn_tls_insecure', $tls_insecure);
				$node->save();
				$node->load();
				$session->save_message(new DisplayMessage(
					'API credential saved.', 'Success', $page_regex,
					DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
				return $base_url . '&tab=api_keys';
			}

			case 'clear_api_credential': {
				$node->set('mgn_api_public_key', null);
				$node->set('mgn_api_secret_key', null);
				$node->save();
				$session->save_message(new DisplayMessage(
					'API credential cleared. Jobs will now route via SSH.', 'Success', $page_regex,
					DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
				return $base_url . '&tab=api_keys';
			}

			case 'save_node': {
				self::save_node($node);
				$session->save_message(new DisplayMessage(
					'Site saved successfully.', 'Success', $page_regex,
					DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
				return $base_url . '&tab=overview';
			}

			case 'delete_node': {
				if (!$node->key) {
					return $base_url;
				}
				$node->soft_delete();
				$session->save_message(new DisplayMessage(
					'Site deleted.', 'Success', $page_regex,
					DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
				return '/admin/server_manager';
			}
		}

		// Unreachable: dispatch() only calls run() for keys in $error_tab.
		return $base_url;
	}

	/** Apply the editable connection-settings fields from the overview form. */
	private static function save_node($node): void {
		$editable_fields = [
			'mgn_name', 'mgn_slug', 'mgn_host', 'mgn_ssh_user', 'mgn_ssh_key_path',
			'mgn_ssh_port', 'mgn_container_name', 'mgn_container_user', 'mgn_web_root',
			'mgn_site_url', 'mgn_bkt_backup_target_id', 'mgn_notes', 'mgn_enabled',
			'mgn_delete_local_after_upload', 'mgn_skip_joinery_checks',
			'mgn_uptime_enabled', 'mgn_uptime_check_type',
			'mgn_uptime_tcp_port', 'mgn_uptime_interval_seconds',
		];
		$bool_fields = ['mgn_enabled', 'mgn_delete_local_after_upload', 'mgn_skip_joinery_checks', 'mgn_uptime_enabled'];
		foreach ($editable_fields as $field) {
			if (in_array($field, $bool_fields, true)) {
				$node->set($field, !empty($_POST[$field]));
				continue;
			}
			if (isset($_POST[$field])) {
				$value = trim($_POST[$field]);
				if ($field === 'mgn_ssh_port' && $value === '') {
					$value = 22;
				}
				if ($field === 'mgn_bkt_backup_target_id' && $value === '') {
					$value = null;
				}
				if (($field === 'mgn_uptime_tcp_port' || $field === 'mgn_uptime_interval_seconds') && $value === '') {
					$value = $field === 'mgn_uptime_interval_seconds' ? 300 : 0;
				}
				$node->set($field, $value);
			}
		}
		// Clear the recorded monitoring fault so a corrected configuration stops
		// reporting the old problem immediately. The next check re-records it if
		// the fix did not actually work.
		$node->set('mgn_uptime_last_error', null);
		$node->prepare();
		$node->save();
		$node->load();
	}

	/**
	 * Mint (and escrow) the node's backup key before an encrypting backup runs.
	 * Encryption is forced when the node has a cloud target, so treat that as
	 * encrypting too. Throws on failure — the central catch reports it.
	 */
	private static function ensure_backup_key_if_encrypting($node, $params): void {
		$will_encrypt = !empty($params['encryption']) || JobCommandBuilder::get_target($node);
		if ($will_encrypt) {
			BackupKeyCustody::ensureNodeKey($node);
		}
	}

	private static function fail($session, $page_regex, string $message): void {
		$session->save_message(new DisplayMessage(
			$message, 'Error', $page_regex,
			DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	}

	private static function jobUrl($job): string {
		return '/admin/server_manager/job_detail?job_id=' . $job->key;
	}
}
