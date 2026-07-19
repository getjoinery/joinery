<?php
/**
 * Relay admin machinery (specs/mailbox_relay_fix_pack.md § Fix 10,
 * specs/mailbox_relay_shared_fleet.md).
 *
 * There is no Relay page: tenant relay setup (status, health, provisioning,
 * hosted-slot enrollment) renders as the Setup tab's Relay section
 * (relay_section.php), relay configuration (service connection, outbound
 * mode) lives on the Settings tab, and the operator fleet console is its own
 * page (admin_mailbox_fleet) reached from the Server Manager dashboard. This
 * file is their shared machinery — the tenant/operator action handlers and
 * view-var assemblers, plus the lower-level helpers (job dispatch, health
 * battery, DNS rows, reconciles).
 *
 * @version 1.0
 */

/** The shared flash shape every relay surface uses. */
function admin_mailbox_relay_flash($session, string $msg, string $title = 'Done'): void {
	$session->save_message(new DisplayMessage(
		$msg, $title, '~/plugins/mailbox/admin/~',
		DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
	));
}

/**
 * Tenant-side relay actions (Setup tab's Relay section): relay lifecycle,
 * provisioning jobs, the origin-leak probe, and hosted-slot enrollment.
 * Returns a redirect when the input was one of these actions, null otherwise.
 */
function admin_mailbox_relay_tenant_actions(array $input, $session, string $self_url): ?LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));

	$action = $input['action'] ?? null;
	if ($action === null) {
		return null;
	}
	$relay_id = $input['mrl_mailbox_relay_id'] ?? null;
	$server_manager_active = PluginHelper::isPluginActive('server_manager');

	if (($action === 'enable' || $action === 'disable') && $relay_id) {
		$relay = new MailboxRelay(intval($relay_id), TRUE);
		$relay->set('mrl_is_enabled', $action === 'enable');
		$relay->save();
		admin_mailbox_relay_flash($session,
			$action === 'enable' ? 'Relay enabled — it now fronts every hosted domain.' : 'Relay disabled.');
		return LogicResult::redirect($self_url);
	}

	if ($action === 'delete' && $relay_id) {
		$relay = new MailboxRelay(intval($relay_id), TRUE);
		$relay->soft_delete();
		admin_mailbox_relay_flash($session, 'Relay removed.');
		return LogicResult::redirect($self_url);
	}

	// Out-and-back origin-leak probe (provider mode): send a marked message
	// from a hosted alias to itself; it returns via the relay MX and the
	// origin-leak check scans the delivered headers.
	if ($action === 'origin_probe') {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailHealth.php'));
		$res = InboundEmailHealth::sendOriginProbe();
		admin_mailbox_relay_flash($session, $res['message'], $res['ok'] ? 'Probe sent' : 'Probe not sent');
		return LogicResult::redirect($self_url);
	}

	if (($action === 'provision' || $action === 'rebuild') && $server_manager_active) {
		$result = admin_mailbox_relay_dispatch_job($action, $input, $session);
		admin_mailbox_relay_flash($session, $result['message'], $result['title']);
		return LogicResult::redirect($self_url);
	}

	// Hosted slot lifecycle (the service connection itself is saved on Settings).
	if (in_array($action, array('fleet_enroll', 'fleet_refresh', 'fleet_release'), true)) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetClient.php'));
		$client = new FleetClient();
		try {
			switch ($action) {
				case 'fleet_enroll':
					$data = $client->enroll();
					admin_mailbox_relay_flash($session,
						'Enrolled — slot ' . htmlspecialchars((string)($data['slug'] ?? ''))
						. ' (' . htmlspecialchars((string)($data['status'] ?? '')) . '). '
						. 'Point your domains\' MX at ' . htmlspecialchars((string)($data['mx_hostname'] ?? ''))
						. '. Each hosted domain\'s checks below show its ownership record to publish.');
					break;
				case 'fleet_refresh':
					$client->status();
					admin_mailbox_relay_flash($session, 'Hosted relay slot refreshed.');
					break;
				case 'fleet_release':
					$data = $client->release();
					admin_mailbox_relay_flash($session, (string)($data['message'] ?? 'Slot released.'));
					break;
			}
		} catch (\Throwable $e) {
			admin_mailbox_relay_flash($session, $e->getMessage(), 'Relay service error');
		}
		return LogicResult::redirect($self_url);
	}

	return null;
}

/**
 * Tenant-side view vars for the Setup tab's Relay section: the relay rows
 * (health attached to the active one, MX hostname reconciled), provisionable
 * nodes, and live hosted-slot state.
 */
function admin_mailbox_relay_tenant_vars(): array {
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
	$settings = Globalvars::get_instance();
	$server_manager_active = PluginHelper::isPluginActive('server_manager');

	$relays_multi = new MultiMailboxRelay(array('deleted' => false));
	$relays_multi->load();
	// The health battery (TCP probe + map build + DNS) resolves the ACTIVE relay
	// internally, so it is meaningful only for that row — run it once and attach
	// it there; other rows show their own row-level facts without health dots.
	$active = MailboxRelay::active();
	$active_health = ($active !== null) ? admin_mailbox_relay_health() : null;
	$relays = array();
	foreach ($relays_multi as $relay) {
		// Reconcile the relay's MX hostname from its provision job when the
		// row predates the hostname being persisted — the topology-aware
		// setup checks prescribe against it.
		admin_mailbox_relay_backfill_mx_hostname($relay);
		$is_active = ($active !== null && intval($relay->key) === intval($active->key));
		$relays[] = array(
			'model'  => $relay,
			'health' => $is_active ? $active_health : null,
		);
	}

	// Managed nodes available to provision onto (server_manager).
	$nodes = array();
	if ($server_manager_active) {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
		$node_multi = new MultiManagedNode(array('enabled' => true, 'deleted' => false));
		$node_multi->load();
		foreach ($node_multi as $node) {
			$nodes[] = $node;
		}
	}

	// Live hosted-slot state when the service connection is configured.
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetClient.php'));
	$fleet_client = new FleetClient();
	$fleet_configured = $fleet_client->configured();
	$fleet_status = null;
	$fleet_error = '';
	if ($fleet_configured) {
		// status() folds fresh coordinates into the relay row — intentional
		// server-side reconciliation on a GET view, like job result processing.
		SystemBase::$allow_get_mutation = true;
		try {
			$fleet_status = $fleet_client->status();
		} catch (\Throwable $e) {
			$fleet_error = $e->getMessage();
		} finally {
			SystemBase::$allow_get_mutation = false;
		}
	}

	return array(
		'relays'                => $relays,
		'nodes'                 => $nodes,
		'server_manager_active' => $server_manager_active,
		'main_wg_public_key'    => (string)$settings->get_setting('mailbox_relay_wg_public_key'),
		'has_active_relay'      => ($active !== null),
		'outbound_mode'         => (strtolower(trim((string)$settings->get_setting('mailbox_relay_outbound_mode'))) === 'smarthost')
			? 'smarthost' : 'provider',
		'fleet_configured'      => $fleet_configured,
		'fleet_status'          => $fleet_status,
		'fleet_error'           => $fleet_error,
	);
}

/**
 * Operator-side actions (fleet console): service on/off + MX zone, shard
 * provisioning. Returns a redirect when handled, null otherwise.
 */
function admin_mailbox_relay_operator_actions(array $input, $session, string $self_url): ?LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	$action = $input['action'] ?? null;
	if ($action === null) {
		return null;
	}

	if ($action === 'fleet_service_config') {
		$enabled = !empty($input['mailbox_fleet_service_enabled']) ? '1' : '0';
		$zone = strtolower(trim((string)($input['mailbox_fleet_mx_zone'] ?? '')));
		if ($enabled === '1' && (strpos($zone, '.') === false)) {
			admin_mailbox_relay_flash($session,
				'Set the fleet MX zone first — a DNS zone this deployment\'s operator controls, e.g. mx.example.com.',
				'Cannot enable fleet service');
			return LogicResult::redirect($self_url);
		}
		admin_mailbox_relay_write_setting('mailbox_fleet_service_enabled', $enabled);
		admin_mailbox_relay_write_setting('mailbox_fleet_mx_zone', $zone);
		admin_mailbox_relay_flash($session, $enabled === '1'
			? 'Fleet service is on. Each tenant\'s MX hostname is <slug>.' . $zone
				. ' — publish it as an A record pointing at the tenant\'s shard.'
			: 'Fleet service is off. Tenant slots stop reconciling until it is re-enabled.');
		return LogicResult::redirect($self_url);
	}

	if ($action === 'provision_shard' && PluginHelper::isPluginActive('server_manager')) {
		$result = admin_mailbox_relay_provision_shard($input, $session);
		admin_mailbox_relay_flash($session, $result['message'], $result['title']);
		return LogicResult::redirect($self_url);
	}

	return null;
}

/**
 * Operator-side view vars for the fleet console: service state, shard rows
 * (connection facts reconciled) with slot counts and DNS-to-publish rows,
 * and the nodes a shard can be provisioned onto.
 */
function admin_mailbox_relay_operator_vars(): array {
	$settings = Globalvars::get_instance();
	$server_manager_active = PluginHelper::isPluginActive('server_manager');

	$fleet_service_on = ((string)$settings->get_setting('mailbox_fleet_service_enabled') === '1');
	$fleet_shards = array();
	if ($fleet_service_on) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetService.php'));
		$shard_multi = new MultiMailboxFleetShard(array('deleted' => false));
		$shard_multi->load();
		// Shard-row sync from node facts is reconciliation on a GET view.
		SystemBase::$allow_get_mutation = true;
		try {
			foreach ($shard_multi as $shard) {
				admin_mailbox_relay_sync_shard_from_node($shard);
				$fleet_shards[] = array(
					'model' => $shard,
					'slots' => $shard->slotCount(),
					'dns'   => admin_mailbox_relay_shard_dns_rows($shard),
				);
			}
		} finally {
			SystemBase::$allow_get_mutation = false;
		}
	}

	$nodes = array();
	if ($server_manager_active) {
		require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
		$node_multi = new MultiManagedNode(array('enabled' => true, 'deleted' => false));
		$node_multi->load();
		foreach ($node_multi as $node) {
			$nodes[] = $node;
		}
	}

	return array(
		'server_manager_active' => $server_manager_active,
		'fleet_service_on'      => $fleet_service_on,
		'fleet_mx_zone'         => trim((string)$settings->get_setting('mailbox_fleet_mx_zone')),
		'fleet_shards'          => $fleet_shards,
		'nodes'                 => $nodes,
	);
}

/**
 * Register a fleet shard: create/refresh the MailboxFleetShard row and dispatch
 * the skeleton-only provisioning job against its managed node (the operator's
 * deployment is not a tenant of its own shards).
 *
 * @return array{message:string,title:string}
 */
function admin_mailbox_relay_provision_shard(array $input, $session): array {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetService.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));

	$node_id = intval($input['shard_node_id'] ?? 0);
	$hostname = trim((string)($input['shard_hostname'] ?? ''));
	$capacity = max(1, intval($input['shard_capacity'] ?? 25));
	if ($node_id <= 0 || $hostname === '' || strpos($hostname, '.') === false) {
		return array('title' => 'Cannot provision shard', 'message' => 'Select a node and give the shard a mail hostname (FQDN).');
	}
	try {
		$node = new ManagedNode($node_id, TRUE);
	} catch (\Throwable $e) {
		return array('title' => 'Cannot provision shard', 'message' => 'That managed node no longer exists.');
	}

	// One shard row per node.
	$existing = new MultiMailboxFleetShard(array('node_id' => $node_id, 'deleted' => false));
	$existing->load();
	$shard = null;
	foreach ($existing as $row) { $shard = $row; break; }
	if ($shard === null) {
		$shard = new MailboxFleetShard(NULL);
		$shard->set('mfs_mgn_managed_node_id', $node_id);
	}
	$shard->set('mfs_name', (string)$node->get('mgn_name'));
	$shard->set('mfs_hostname', substr($hostname, 0, 255));
	$shard->set('mfs_capacity', $capacity);
	$shard->save();

	$params = array('mail_hostname' => $hostname, 'skeleton_only' => true);
	$steps = JobCommandBuilder::build_provision_relay($node, $params);
	ManagementJob::createJob($node->key, 'provision_relay', $steps, $params, $session->get_user_id());

	return array('title' => 'Shard job queued',
		'message' => 'Skeleton provisioning queued on ' . $node->get('mgn_name')
			. '. Tenants land on it through fleet enrollment once it reports ready.');
}

/**
 * Keep the shard row's connection facts (public IP, WireGuard endpoint + key)
 * in step with what the provisioning job recorded on the managed node.
 */
function admin_mailbox_relay_sync_shard_from_node($shard): void {
	$node_id = intval($shard->get('mfs_mgn_managed_node_id'));
	if ($node_id <= 0) {
		return;
	}
	try {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT mgn_wg_public_key, mgn_wg_endpoint FROM mgn_managed_nodes
			  WHERE mgn_id = ? LIMIT 1");
		$stmt->execute(array($node_id));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		if (!$row) {
			return;
		}
		$endpoint = trim((string)$row['mgn_wg_endpoint']);
		$pubkey = trim((string)$row['mgn_wg_public_key']);
		$public_ip = ($endpoint !== '' && strpos($endpoint, ':') !== false)
			? substr($endpoint, 0, strrpos($endpoint, ':')) : '';
		$dirty = false;
		if ($pubkey !== '' && $pubkey !== (string)$shard->get('mfs_wg_public_key')) {
			$shard->set('mfs_wg_public_key', $pubkey); $dirty = true;
		}
		if ($endpoint !== '' && $endpoint !== (string)$shard->get('mfs_wg_endpoint')) {
			$shard->set('mfs_wg_endpoint', $endpoint); $dirty = true;
		}
		if ($public_ip !== '' && $public_ip !== (string)$shard->get('mfs_public_ip')) {
			$shard->set('mfs_public_ip', $public_ip); $dirty = true;
		}
		if ($dirty) {
			$shard->save();
		}
	} catch (\Throwable $e) {
		// Best-effort sync; the shard list still renders — but never silently.
		error_log('admin_mailbox_relay_sync_shard_from_node failed for shard '
			. intval($shard->key) . ': ' . $e->getMessage());
	}
}

/**
 * Persist a self-hosted relay's MX hostname from its provision/rebuild job
 * parameters when the row does not carry one. One field serves both relay
 * topologies: the hosted path stores it from slot coordinates; this reconcile
 * covers self-hosted rows whose provisioning predates the field.
 */
function admin_mailbox_relay_backfill_mx_hostname($relay): void {
	if ((bool)$relay->get('mrl_is_hosted') || trim((string)$relay->get('mrl_mx_hostname')) !== '') {
		return;
	}
	$node_id = intval($relay->get('mrl_mgn_managed_node_id'));
	if ($node_id <= 0 || !PluginHelper::isPluginActive('server_manager')) {
		return;
	}
	try {
		$db = DbConnector::get_instance()->get_db_link();
		$stmt = $db->prepare(
			"SELECT mjb_parameters FROM mjb_management_jobs
			  WHERE mjb_mgn_node_id = ? AND mjb_job_type IN ('provision_relay', 'rebuild_relay')
			    AND mjb_delete_time IS NULL
			  ORDER BY mjb_id DESC LIMIT 1");
		$stmt->execute(array($node_id));
		$params = json_decode((string)$stmt->fetchColumn(), true) ?: array();
		$hostname = strtolower(trim((string)($params['mail_hostname'] ?? '')));
		if ($hostname === '' || strpos($hostname, '.') === false) {
			return;
		}
		$was_allowed = SystemBase::$allow_get_mutation;
		SystemBase::$allow_get_mutation = true;
		try {
			$relay->set('mrl_mx_hostname', substr($hostname, 0, 255));
			$relay->save();
		} finally {
			SystemBase::$allow_get_mutation = $was_allowed;
		}
	} catch (\Throwable $e) {
		// Best-effort reconcile; the list still renders — but never silently.
		error_log('admin_mailbox_relay_backfill_mx_hostname failed for relay '
			. intval($relay->key) . ': ' . $e->getMessage());
	}
}

/**
 * The operator's DNS-to-publish rows for a shard: the shard hostname's A
 * record, the shard IP's PTR expectation, and one A row per live slot MX
 * hostname — each with a live resolution verdict for the green/red dot.
 *
 * @return array<int,array{kind:string,name:string,value:string,state:string,found:string}>
 *         state: 'ok' | 'wrong' | 'missing' | 'unknown'.
 */
function admin_mailbox_relay_shard_dns_rows($shard): array {
	require_once(PathHelper::getIncludePath('includes/DnsResolver.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_fleet_slot_class.php'));

	$ip = trim((string)$shard->get('mfs_public_ip'));
	$host = strtolower(trim((string)$shard->get('mfs_hostname')));
	$rows = array();

	$a_row = function (string $name, string $expect) {
		try {
			$found = DnsResolver::getA($name);
		} catch (\Throwable $e) {
			return array('kind' => 'A', 'name' => $name, 'value' => $expect, 'state' => 'unknown', 'found' => '');
		}
		if (empty($found)) {
			return array('kind' => 'A', 'name' => $name, 'value' => $expect, 'state' => 'missing', 'found' => '');
		}
		$state = ($expect !== '' && in_array($expect, $found, true)) ? 'ok' : 'wrong';
		return array('kind' => 'A', 'name' => $name, 'value' => $expect, 'state' => $state, 'found' => implode(', ', $found));
	};

	if ($host !== '') {
		$rows[] = $a_row($host, $ip);
	}
	if ($ip !== '' && $host !== '') {
		try {
			$ptr = DnsResolver::getPtr($ip);
			$ptr_name = !empty($ptr) ? strtolower(rtrim((string)$ptr[0], '.')) : '';
			$state = ($ptr_name === $host) ? 'ok' : ($ptr_name === '' ? 'missing' : 'wrong');
		} catch (\Throwable $e) {
			$ptr_name = '';
			$state = 'unknown';
		}
		$rows[] = array('kind' => 'PTR', 'name' => $ip, 'value' => $host, 'state' => $state, 'found' => $ptr_name);
	}

	$slots = new MultiMailboxFleetSlot(array(
		'shard_id' => intval($shard->key), 'live' => true, 'deleted' => false,
	));
	$slots->load();
	foreach ($slots as $slot) {
		$mx_host = strtolower(trim((string)$slot->get('mft_mx_hostname')));
		if ($mx_host !== '' && $mx_host !== $host) {
			$rows[] = $a_row($mx_host, $ip);
		}
	}
	return $rows;
}

/**
 * Upsert a single stg_settings row by name (there is no set_setting()) — the same
 * model path the Setup/Settings tabs use. A missing row is created.
 */
function admin_mailbox_relay_write_setting(string $name, string $value): void {
	require_once(PathHelper::getIncludePath('data/settings_class.php'));
	$existing = new MultiSetting(array('setting_name' => $name));
	$existing->load();
	if (count($existing)) {
		$setting = $existing->get(0);
	} else {
		$setting = new Setting(NULL);
		$setting->set('stg_name', $name);
	}
	$setting->set('stg_value', $value);
	$setting->save();
}

/**
 * Run the four relay provisioning checks once and return their pass/fail state
 * for the status column. Only meaningful when a relay is active; cheap otherwise.
 *
 * @return array<string,array{label:string,ok:bool,message:string}>
 */
function admin_mailbox_relay_health(): array {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailHealth.php'));
	$mode = (strtolower(trim((string)Globalvars::get_instance()->get_setting('mailbox_relay_outbound_mode'))) === 'smarthost')
		? 'smarthost' : 'provider';
	// The check list matches the chosen outbound path — never an N/A row.
	$run = array(
		'checkRelaySpoolDraining' => 'Spool draining',
		'checkRelayMapFresh'      => 'Alias map fresh',
		'checkOriginHidden'       => 'Origin hidden in DNS',
	);
	if ($mode === 'smarthost') {
		$run['checkRelayTunnel'] = 'Tunnel accepts compose submission';
	} else {
		$run['checkOutboundTransportClass'] = 'Sent mail leaves via provider API';
		$run['checkOutboundOriginLeak']     = 'No origin leak in sent mail';
	}
	$out = array();
	foreach ($run as $method => $label) {
		$ok = true; $message = '';
		try {
			InboundEmailHealth::$method();
		} catch (\Throwable $e) {
			$ok = false;
			$message = $e->getMessage();
		}
		$out[$method] = array('label' => $label, 'ok' => $ok, 'message' => $message);
	}
	return $out;
}

/**
 * Create a provision_relay / rebuild_relay job on the selected managed node via
 * server_manager's queue. The job result processor registers/updates the
 * MailboxRelay row on success.
 *
 * @return array{message:string,title:string}
 */
function admin_mailbox_relay_dispatch_job(string $action, array $input, $session): array {
	require_once(PathHelper::getIncludePath('plugins/server_manager/includes/JobCommandBuilder.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/management_job_class.php'));
	require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));

	$mail_hostname = trim((string)($input['mail_hostname'] ?? ''));
	$node_id = intval($input['mgn_managed_node_id'] ?? 0);

	// Rebuild targets the relay's existing node + hostname.
	if ($action === 'rebuild') {
		$relay = ($input['mrl_mailbox_relay_id'] ?? null)
			? new MailboxRelay(intval($input['mrl_mailbox_relay_id']), TRUE) : null;
		if ($relay !== null) {
			$node_id = intval($relay->get('mrl_mgn_managed_node_id')) ?: $node_id;
			if ($mail_hostname === '') {
				$settings = Globalvars::get_instance();
				$mail_hostname = trim((string)$settings->get_setting('mailbox_mail_hostname'));
			}
		}
	}

	if ($node_id <= 0) {
		return array('title' => 'Cannot provision', 'message' => 'Select a managed node to provision onto.');
	}
	if ($mail_hostname === '' || strpos($mail_hostname, '.') === false) {
		return array('title' => 'Cannot provision', 'message' => 'A mail hostname (FQDN) is required.');
	}

	try {
		$node = new ManagedNode($node_id, TRUE);
	} catch (\Throwable $e) {
		return array('title' => 'Cannot provision', 'message' => 'That managed node no longer exists.');
	}

	$settings = Globalvars::get_instance();
	$params = array(
		'mail_hostname'       => $mail_hostname,
		'main_wg_public_key'  => (string)$settings->get_setting('mailbox_relay_wg_public_key'),
	);
	$job_type = ($action === 'rebuild') ? 'rebuild_relay' : 'provision_relay';
	$builder = 'build_' . $job_type;
	$steps = JobCommandBuilder::$builder($node, $params);
	ManagementJob::createJob($node->key, $job_type, $steps, $params, $session->get_user_id());

	return array(
		'title'   => 'Job queued',
		'message' => ucfirst($action) . ' job queued on ' . $node->get('mgn_name')
			. '. Watch it in Server Manager; the relay registers here on success.',
	);
}
?>
