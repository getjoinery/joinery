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
 *     guarded delete_node).
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
 * @version 1.23 - approve_join checks a join from a provisioned machine with the provider before binding:
 *                 the instance must be running at the join's address, and the node must be that
 *                 machine's site or host (specs/keyless_provisioning.md WP5)
 * @version 1.22 - review fixes: retry_install refuses a keyless bare-metal retry (one-shot) and a clone whose key
 *                 was released; the SSL button observes a certificate the machine already holds before asking
 * @version 1.21 - SSH is one bootstrap (specs/ssh_single_bootstrap.md): provision_ssl always starts the
 *                 agent chain (a container's certificate is issued on its host); enable_agent is gone
 * @version 1.20 - comment truth: apply_update_all_on_host queues primitives only; an unpaired
 *                 sibling throws and is logged, since the SSH fallback no longer exists
 * @version 1.19 - the backup_database and backup_project actions are gone with their builders;
 *                 backup_run is the one backup a node detail page dispatches
 * @version 1.18 - the three restore actions dispatch through createFromBuild. Their builders now
 *                 have a primitive branch (kept shut by the destructive gate), and createJob
 *                 refuses an envelope — so the caller must be able to store either shape before
 *                 the gate can ever open, not on the morning it does
 * @version 1.17 - provision_ssl starts the agent SSL chain on a paired bare-metal node (same gates as
 *                 the scheduled pass) and dispatches through createFromBuild on the SSH path too
 * @version 1.16 - both apply_update actions dispatch through createFromBuild, so a paired node's
 *                 upgrade crosses the agent channel instead of being buried under a steps key
 * @version 1.15 - A1/A3 retirement: run_command (the console) and both copy_database actions are
 *                 gone. No plane composes an instruction for a node to run; copying a database is
 *                 backup-on-source then restore-on-target, human-present
 * @version 1.14 - enable_agent: switch a node's agent on remotely and, on request, have it ask to
 *                 join this plane. Approval is untouched — the fingerprint check is the security
 * @version 1.13 - unpair_agent converges on AgentChannelEndpoint::forgetAgent(), the same forgetting
 *                 the node-initiated leave endpoint performs
 * @version 1.12 - set_agent_channel removed: a connected agent is routed to unconditionally
 *                 (hard cutover, owner-set); approving the join is the routing decision
 * @version 1.11 - enrollment is a node-initiated join (Phase 1.5, A6): approve_join/reject_join
 *                 replace pair_agent; approval after a human fingerprint comparison IS the binding
 * @version 1.10 - agent channel actions: pair_agent (one-time token, shown once), unpair_agent,
 *                 and set_agent_channel (the per-node cutover flag)
 * @version 1.9 - a backup is refused for a node with no verified recovery key of its own, named as
 *                such on the tab; the management node's own key is no longer consulted, because no
 *                key is supplied to a node
 * @version 1.8 - save_backup_policy action (fleet default / custom schedule / off), and backup_run
 *                takes mode and full-interval from the node's policy so a manual run extends the
 *                same family of restore points the schedule builds
 * @version 1.7 - run_command action (node console): superadmin + per-node flag + step-up, and a
 *                refusal re-renders in place so the typed command survives
 * @version 1.6 - backup key escrow runs as a job step on the management node (the web user cannot read
 *                node SSH keys); the web request only checks that recovery is set up before letting
 *                an encrypting backup be created
 * @version 1.5 - purge_node refuses while offsite backups exist for the slug (or a target can't be
 *                listed) — deleting the record would orphan them; clear them from the target first
 * @version 1.4 - purge_node action: hard-delete a removed node's record (guarded — only after
 *                soft-delete; escrow + job history preserved via cascade rules)
 * @version 1.3 - decommission_node action: type-to-confirm guarded permanent site deletion
 *                (host teardown job); delete_node message clarifies it is record-only
 * @version 1.2 - reverse-DNS messages are plain text (display loop escapes); ensureNodeKey failures fail loud
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobResultProcessor.php'));
require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/SmAdminCsrf.php'));

class NodeDetailActions {

	/** Which tab a failed action redirects back to (its error message shows there). */
	private static $error_tab = [
		'check_status'             => 'overview',
		'restore_database'         => 'database',
		'restore_project'          => 'backups',
		'restore_chain'            => 'backups',
		'backup_run'               => 'backups',
		'save_backup_policy'       => 'backups',
		'apply_update'             => 'updates',
		'apply_update_all_on_host' => 'updates',
		'retry_install'            => 'overview',
		'provision_ssl'            => 'overview',
		'run_plugin_installers'    => 'overview',
		'restart_agent'            => 'api_keys',
		'set_reverse_dns'          => 'overview',
		'save_api_credential'      => 'api_keys',
		'approve_join'             => 'api_keys',
		'reject_join'              => 'api_keys',
		'unpair_agent'             => 'api_keys',
		'clear_api_credential'     => 'api_keys',
		'save_node'                => 'overview',
		'delete_node'              => 'overview',
		'decommission_node'        => 'overview',
		'purge_node'               => 'overview',
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
	 *
	 * A handler may instead return null to re-render the current page with its
	 * message — for a refusal that would otherwise discard what the operator
	 * typed (the console's command box).
	 */
	private static function run($action, $node, $session, $base_url, $page_regex): ?string {
		$uid = $session->get_user_id();

		switch ($action) {

			case 'check_status': {
				$built = JobCommandBuilder::build_check_status($node);
				$job = ManagementJob::createFromBuild($node->key, 'check_status', $built, null, $uid);
				return self::jobUrl($job);
			}

			case 'backup_run': {
				// This management node's own backup of the node, run now instead of
				// waiting for its schedule. Same engine, same chain, same shelf
				// and same retention as the scheduled one — an on-demand backup
				// that landed somewhere else would be a restore point nobody's
				// retention was counting. Mode and full-interval come from the
				// node's policy for the same reason: a full-mode node handed a
				// chain run (or vice versa) would file under the other family
				// and skew what retention keeps.
				if (!$node->get('mgn_web_root')) {
					self::fail($session, $page_regex,
						'This node does not host a Joinery site, so there is nothing to back up.');
					return $base_url . '&tab=backups';
				}
				require_once(PathHelper::getIncludePath('plugins/server_manager/includes/FleetBackupPolicy.php'));
				$policy = FleetBackupPolicy::for_node($node);
				$params = [
					'type'               => (($_POST['backup_scope'] ?? 'project') === 'database') ? 'database' : 'project',
					'mode'               => $policy['mode'],
					'full_interval_days' => $policy['full_interval_days'],
				];
				try {
					$built = JobCommandBuilder::build_backup_run($node, $params);
				} catch (Exception $e) {
					self::fail($session, $page_regex, $e->getMessage());
					return $base_url . '&tab=backups';
				}
				// A paired node gets a primitive envelope here, not steps.
				$job = ManagementJob::createFromBuild($node->key, 'backup_run', $built, $params, $uid);
				return self::jobUrl($job);
			}

			case 'save_backup_policy': {
				// Three positions, stored three ways. "Fleet default" stores
				// nothing, so the node follows future changes to the fleet
				// settings; "off" stores exactly that decision; "custom" stores
				// the full field set, frozen against the fleet default — a value
				// the operator saw and saved is a value they chose.
				require_once(PathHelper::getIncludePath('plugins/server_manager/includes/FleetBackupPolicy.php'));
				$source = (string)($_POST['backup_policy_source'] ?? 'default');
				if ($source === 'off') {
					$node->set('mgn_backup_policy', ['enabled' => false]);
				} elseif ($source === 'custom') {
					$node->set('mgn_backup_policy', FleetBackupPolicy::from_form($_POST));
				} else {
					$node->set('mgn_backup_policy', null);
				}
				$node->save();
				$session->save_message(new DisplayMessage(
					'Backup schedule saved.', 'Success', $page_regex,
					DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
				return $base_url . '&tab=backups';
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
					// createFromBuild, not createJob: build_restore_database() has a
					// primitive branch, and createJob refuses an envelope outright.
					// The branch cannot fire in this build — restore is destructive
					// and node_can_dispatch_destructive() is false — but the call
					// site is where the last such change broke the nightly fleet
					// backup at 04:00, so it stops being a thing anyone has to
					// remember on the day the gate opens.
					$built = JobCommandBuilder::build_restore_database($node, $params);
					$job = ManagementJob::createFromBuild($node->key, 'restore_database', $built, $params, $uid);
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
					'domain'        => trim($_POST['restore_domain'] ?? ''),
					'skip_database' => empty($_POST['restore_database']),
					'skip_files'    => empty($_POST['restore_files']),
				];
				// A missing or malformed domain throws out of the builder; the
				// central catch in dispatch() turns that into a message on the
				// Backups tab rather than a 500.
				$built = JobCommandBuilder::build_restore_project($node, $params);
				$job = ManagementJob::createFromBuild($node->key, 'restore_project', $built, $params, $uid);
				return self::jobUrl($job);
			}

			case 'restore_chain': {
				$params = [
					'chain_id'      => trim($_POST['chain_id'] ?? ''),
					'profile'       => trim($_POST['chain_profile'] ?? ''),
					'seq'           => trim($_POST['chain_seq'] ?? ''),
					'domain'        => trim($_POST['restore_domain'] ?? ''),
					'skip_database' => empty($_POST['restore_database']),
				];
				$built = JobCommandBuilder::build_restore_chain($node, $params);
				$job = ManagementJob::createFromBuild($node->key, 'restore_chain', $built, $params, $uid);
				return self::jobUrl($job);
			}

			case 'apply_update': {
				// createFromBuild, not createJob: build_apply_update now returns
				// a primitive envelope on a paired node, and createJob would
				// bury it under a "steps" key where isPrimitiveJob() cannot see
				// it. The job would then be handed to the step executor and the
				// agent would fail to parse it. That is not hypothetical — it is
				// exactly what happened to the nightly fleet backup the morning
				// build_backup_run grew its primitive branch.
				$built = JobCommandBuilder::build_apply_update($node);
				$job = ManagementJob::createFromBuild($node->key, 'apply_update', $built, [], $uid);
				return self::jobUrl($job);
			}

			case 'apply_update_all_on_host': {
				// Siblings share a placement record (mgn_mgh_host_id), never a host
				// string. A node with no placement record has no known siblings, so
				// the action covers just it. Either way, a LIVE node at the same
				// address that the placement grouping would miss is a refusal, not
				// a silent skip — "all sites on this host" must never quietly mean
				// "some".
				$host_id = (int)$node->get('mgn_mgh_host_id');
				$db_ungrouped = DbConnector::get_instance()->get_db_link();
				$uq = $db_ungrouped->prepare(
					"SELECT string_agg(mgn_slug, ', ') FROM mgn_managed_nodes
					 WHERE mgn_host = ? AND mgn_delete_time IS NULL AND mgn_enabled = true
					   AND mgn_mgh_host_id IS DISTINCT FROM ? AND mgn_id <> ?");
				$uq->execute([(string)$node->get('mgn_host'), $host_id ?: null, (int)$node->key]);
				$ungrouped = (string)$uq->fetchColumn();
				if ($ungrouped !== '') {
					self::fail($session, $page_regex,
						"Some sites at this address are not grouped under this host record ({$ungrouped}). "
						. 'Assign them on their node pages first, so this action covers every site.');
					return $base_url . '&tab=updates';
				}
				if ($host_id) {
					$siblings = new MultiManagedNode(
						['host_id' => $host_id, 'enabled' => true, 'deleted' => false],
						['mgn_slug' => 'ASC']
					);
					$siblings->load();
				} else {
					$siblings = new MultiManagedNode(['deleted' => false]);
					$siblings->add($node);
				}
				if ($siblings->count() === 0) {
					self::fail($session, $page_regex, 'No eligible sites found on this host.');
					return $base_url . '&tab=updates';
				}
				$queued = 0;
				foreach ($siblings as $sibling) {
					try {
						// Same entry point as the single-node case above; an
						// unpaired sibling throws and is logged rather than
						// silently skipped.
						$built = JobCommandBuilder::build_apply_update($sibling);
						ManagementJob::createFromBuild($sibling->key, 'apply_update', $built, [], $uid);
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
				// A keyless bare-metal bootstrap is one-shot: install.sh server
				// disabled root password login, and the root password was the
				// only credential. A retry would wait five minutes for SSH the
				// box no longer answers. Say so instead.
				if (($params['docker_mode'] ?? '') === 'bare-metal' && !$node->get('mgn_ssh_key_path')) {
					self::fail($session, $page_regex,
						'A bare-metal install cannot be retried: its server setup already disabled root password login, '
						. 'and the root password was this plane\'s only way in. Finish the site by hand from the provider console, '
						. 'or delete the instance and provision again.');
					return $base_url;
				}
				// A clone's key was blanked once the provision finished; a
				// retry after that has no source to pull from.
				if (($params['mode'] ?? '') === 'from_backup' && (string)($params['clone_key'] ?? '') === '') {
					self::fail($session, $page_regex,
						'This clone\'s export key was released when its provision finished; provision a new clone instead.');
					return $base_url;
				}
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
				// Look first: the machine may already hold the certificate (the
				// host's own timer issued it), in which case the button is the
				// same observation the scheduled pass makes, and no job.
				if (ProvisionPendingSsl::certificate_observed($node, $domain)) {
					$node->set('mgn_ssl_state', 'active');
					$node->save();
					$session->save_message(new DisplayMessage(
						'A certificate for ' . $domain . ' is already on the machine; the node is marked active.',
						'Success', $page_regex, DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE));
					return $base_url . '&tab=overview';
				}
				// A certificate is issued by the node's own agent (bare metal) or
				// its host's (a container), by a chain of jobs rather than one.
				// This dispatches the first step through the same entry the
				// scheduled pass uses — so the button and the timer are the same
				// operation, with the same Cloudflare, core-version and A-record
				// gates — and the pass carries it the rest of the way.
				$outcome = ProvisionPendingSsl::begin_chain($node, $domain, $uid);
				if ($outcome['error'] !== '') {
					self::fail($session, $page_regex, 'Cannot provision SSL: ' . $outcome['error']);
					return $base_url . '&tab=overview';
				}
				// Marked pending either way: the node IS awaiting a certificate,
				// and that is what makes the scheduled pass pick it up once the
				// wait the note describes is over.
				$node->set('mgn_ssl_state', 'pending');
				$node->save();
				if ($outcome['started'] === 0) {
					self::fail($session, $page_regex, $outcome['note'] !== ''
						? $outcome['note']
						: 'SSL provisioning is queued; nothing could be dispatched yet.');
					return $base_url . '&tab=overview';
				}
				return $base_url . '&tab=jobs';
			}

			case 'run_plugin_installers': {
				$built = JobCommandBuilder::build_run_plugin_installers($node);
				$job = ManagementJob::createFromBuild($node->key, 'run_plugin_installers', $built, [], $uid);
				return self::jobUrl($job);
			}

			case 'restart_agent': {
				// Primitive-only, so this can throw for an unpaired node — the
				// message says why, and there is deliberately no SSH fallback
				// to quietly succeed with (that fallback would be pkill).
				try {
					$built = JobCommandBuilder::build_restart_agent($node);
				} catch (Exception $e) {
					self::fail($session, $page_regex, $e->getMessage());
					return $base_url . '&tab=api_keys';
				}
				$job = ManagementJob::createFromBuild($node->key, 'restart_agent', $built, null, $uid);
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

			// ── The agent channel (specs/agent_on_node_architecture.md §3.1) ──

			case 'approve_join': {
				// Approval IS enrollment (Phase 1.5, A6): no secret was ever
				// shared, so the human comparing the fingerprint against the
				// node's own panel is the entire trust decision. Superadmin-only,
				// and the binding is stamped visibly below so an approval nobody
				// expected is seen rather than silent.
				if ($session->get_permission() < 10) {
					self::fail($session, $page_regex, 'Approving an agent join request is superadmin-only.');
					return $base_url . '&tab=api_keys';
				}
				$request = self::load_join_request((int)($_POST['ajr_id'] ?? 0), $session, $page_regex);
				if (!$request) {
					return $base_url . '&tab=api_keys';
				}
				if ($request->is_expired()) {
					self::fail($session, $page_regex,
						'That join request has expired. Send it again from the node\'s Management Node page.');
					return $base_url . '&tab=api_keys';
				}
				if ($node->get('mgn_agent_public_key')) {
					// A second identity from a machine this plane provisioned, after
					// its agent was admitted, is something on that box minting a new
					// agent. First join wins; this one is refused and logged as an alarm.
					if (class_exists('CustomerCloudProvision')
							&& CustomerCloudProvision::for_machine_address((string)$request->get('ajr_source_ip'))) {
						error_log('ALARM agent join: a second join request from provisioned address '
							. $request->get('ajr_source_ip') . ' for node #' . $node->key . ' (' . $node->get('mgn_name')
							. '), which already has a connected agent. Request #' . $request->key . ' refused.');
					}
					self::fail($session, $page_regex,
						'This node already has a connected agent. Disconnect it first if you mean to replace it.');
					return $base_url . '&tab=api_keys';
				}
				// A join from a machine this plane created is checked with the
				// provider before a fingerprint comparison stands alone: the
				// instance must be running at the join's address, and this node
				// must be that machine's site or its host record.
				if (class_exists('ProvisionCustomerCloud')) {
					$approval = (new ProvisionCustomerCloud())->join_approval_check((string)$request->get('ajr_source_ip'), $node);
					if (!$approval['ok']) {
						self::fail($session, $page_regex, 'Join not approved. ' . $approval['reason']);
						return $base_url . '&tab=api_keys';
					}
				}
				require_once(PathHelper::getIncludePath('plugins/server_manager/includes/AgentChannelEndpoint.php'));
				$linked_host = AgentChannelEndpoint::approveJoin($request, $node);
				$host_note = $linked_host
					? ' It is this host\'s own agent, so host-scope work (site removal, certificates) now routes to it.'
					: '';
				$session->save_message(new DisplayMessage(
					'Agent connected. ' . $request->get('ajr_claimed_name') . ' (key '
					. AgentJoinRequest::display_fingerprint($request->get('ajr_fingerprint'))
					. ') is now this node\'s agent; it will pick the approval up on its next check.'
					. $host_note,
					'Success', $page_regex,
					DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
				return $base_url . '&tab=api_keys';
			}

			case 'reject_join': {
				if ($session->get_permission() < 10) {
					self::fail($session, $page_regex, 'Rejecting an agent join request is superadmin-only.');
					return $base_url . '&tab=api_keys';
				}
				$request = self::load_join_request((int)($_POST['ajr_id'] ?? 0), $session, $page_regex);
				if (!$request) {
					return $base_url . '&tab=api_keys';
				}
				$request->set('ajr_status', AgentJoinRequest::STATUS_REJECTED);
				$request->save();
				$session->save_message(new DisplayMessage(
					'Join request rejected. The node will be told on its next check and can send a fresh request.',
					'Success', $page_regex,
					DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
				return $base_url . '&tab=api_keys';
			}

			case 'unpair_agent': {
				// Forgetting the node's public key ends the channel from this
				// side. The node keeps its private key and will simply be told
				// it has not joined; reconnecting starts over from the node's
				// Management Node page.
				AgentChannelEndpoint::forgetAgent($node);
				$session->save_message(new DisplayMessage(
					'Agent disconnected. This node\'s work routes over the API and SSH again.',
					'Success', $page_regex,
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
					'Removed from dashboard. The site itself keeps running on its host.', 'Success', $page_regex,
					DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
				return '/admin/server_manager';
			}

			case 'decommission_node': {
				if (!$node->key) {
					return $base_url;
				}
				// Server-side guard behind the type-to-confirm modal: the pasted site
				// name must match the one derived from the node's own fields, or the
				// destructive job is not built.
				$expected = JobCommandBuilder::decommission_site_name($node);
				$typed = trim($_POST['confirm_site_name'] ?? '');
				if ($typed !== $expected) {
					$session->save_message(new DisplayMessage(
						'Type the exact site name to permanently delete it. Nothing was deleted.', 'Error',
						$page_regex, DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
					));
					return $base_url . '&tab=overview';
				}
				// The job's SUBJECT is the HOST's node — the machine that runs the
				// teardown — and the victim travels in the params so the result
				// processor finalizes the right record. Built first, so every
				// refusal (unpaired host, core too old, open jobs) lands before
				// anything is filed.
				$built = JobCommandBuilder::build_decommission_node($node, []);
				$host_node = JobCommandBuilder::decommission_host_node_for($node);
				$job = ManagementJob::createFromBuild($host_node->key, 'decommission_node', $built,
					['victim_node_id' => $node->key, 'site' => $expected], $uid);
				// The victim goes quiet: its agent dies with the container, and
				// that death is a deliberate silence, not a node that broke.
				$node->set('mgn_agent_quiet_time', gmdate('Y-m-d H:i:s'));
				$node->save();
				$session->save_message(new DisplayMessage(
					'Permanent deletion started. The site must approve its own removal on its Backups page; '
					. 'the record is removed once the host verifies the site gone.', 'Success',
					$page_regex, DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
				));
				return self::jobUrl($job);
			}

			case 'purge_node': {
				if (!$node->key) {
					return $base_url;
				}
				// Guard: never hard-delete the record of a node that is still tracked.
				// Purging a live node's record is exactly how a running site becomes an
				// untracked orphan — remove it from the dashboard (or permanently delete
				// the site) first, which soft-deletes the record.
				if (!$node->get('mgn_delete_time')) {
					$session->save_message(new DisplayMessage(
						'Remove this node from the dashboard first, then permanently delete its entry.', 'Error',
						$page_regex, DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
					));
					return $base_url . '&tab=overview';
				}
				$typed = trim($_POST['confirm_slug'] ?? '');
				if ($typed !== (string)$node->get('mgn_slug')) {
					$session->save_message(new DisplayMessage(
						'Type the exact site slug to permanently delete the entry. Nothing was deleted.', 'Error',
						$page_regex, DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
					));
					return $base_url . '&tab=overview';
				}
				// Refuse while offsite backups still exist for this slug: hard-deleting the
				// record would orphan them from the node they belong to. Delete them from the
				// target's Stored Backups panel first. Fail safe — if a target can't be
				// listed we cannot confirm zero, so we also refuse.
				require_once(PathHelper::getIncludePath('includes/TargetBackups.php'));
				$bk = TargetBackups::slug_backup_count($node->get('mgn_slug'));
				if ($bk['count'] > 0) {
					$session->save_message(new DisplayMessage(
						'This site still has ' . $bk['count'] . ' offsite backup' . ($bk['count'] === 1 ? '' : 's')
						. '. Delete them from the backup target\'s Stored Backups panel before deleting the record.',
						'Error', $page_regex, DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
					));
					return $base_url . '&tab=overview';
				}
				if (!empty($bk['unchecked'])) {
					$session->save_message(new DisplayMessage(
						'Could not verify backups on: ' . implode(', ', $bk['unchecked'])
						. '. Resolve those targets before deleting the record.',
						'Error', $page_regex, DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
					));
					return $base_url . '&tab=overview';
				}
				// Hard delete. Escrow rows (SET NULL) and job history (nulled) survive.
				$node->permanent_delete();
				$session->save_message(new DisplayMessage(
					'Server Manager entry permanently deleted.', 'Success',
					$page_regex, DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
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
		$bool_fields = ['mgn_enabled', 'mgn_delete_local_after_upload', 'mgn_skip_joinery_checks',
			'mgn_uptime_enabled'];
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
	 * Does this operator still owe a second-factor confirmation?
	 *
	 * True only when the account holds a factor and has not confirmed with it
	 * recently in this session — the platform's standing step-up rule (see
	 * docs/account_security.md). An account with no factor passes: the gate
	 * binds a factor the account has, it does not invent an enrollment
	 * requirement. The window is the session's, not the command's; asking for
	 * a fresh touch per command adds friction without adding protection.
	 */
	public static function step_up_required($session): bool {
		require_once(PathHelper::getIncludePath('data/users_class.php'));
		$account = new User($session->get_user_id(), TRUE);
		if (!$account->key) {
			return true;
		}
		return $session->user_has_second_factor($account) && !$session->has_recent_second_factor();
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

	/** Load a live, still-pending join request, or fail with a message and return null. */
	private static function load_join_request(int $ajr_id, $session, $page_regex) {
		if ($ajr_id <= 0) {
			self::fail($session, $page_regex, 'No join request was named.');
			return null;
		}
		try {
			$request = new AgentJoinRequest($ajr_id, TRUE);
		} catch (Exception $e) {
			self::fail($session, $page_regex, 'That join request no longer exists.');
			return null;
		}
		if ($request->get('ajr_delete_time')
			|| $request->get('ajr_status') !== AgentJoinRequest::STATUS_PENDING) {
			self::fail($session, $page_regex, 'That join request has already been decided.');
			return null;
		}
		return $request;
	}
}
