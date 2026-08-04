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
 * battery, DNS rows, reconciles). The local-listener decommission machinery
 * lives in listener_admin.php; its actions and view vars are folded in here.
 *
 * @version 1.9 - scanner_probe action (specs/mailbox_relay_scanner_health.md)
 */

/** True when the Linode OAuth client is configured (the one-click branch). */
function admin_mailbox_relay_linode_oauth_configured(): bool {
	require_once(PathHelper::getIncludePath('includes/oauth/OAuth2ProviderRegistry.php'));
	$provider = OAuth2ProviderRegistry::get('linode');
	return $provider !== null && $provider::isConfigured();
}

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

	// Local mail listener decommission/restore (listener_admin.php).
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/listener_admin.php'));
	$listener_redirect = mailbox_listener_actions($input, $session, $self_url);
	if ($listener_redirect !== null) {
		return $listener_redirect;
	}

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

	// Ask the relay about its spam scanner right now. The cron pass already asks
	// once per reconcile, and the Setup tab reads that cached answer — but an
	// operator mid-incident needs a fresh one, not a cached answer of unknown age
	// (specs/mailbox_relay_scanner_health.md, D1).
	if ($action === 'scanner_probe') {
		$relay = MailboxRelay::active();
		if ($relay === null) {
			admin_mailbox_relay_flash($session, 'No relay is enabled to ask.', 'Nothing to check');
			return LogicResult::redirect($self_url);
		}
		$health = $relay->pollHealth();
		$ok = ($health['state'] === MailboxRelay::HEALTH_OK);
		admin_mailbox_relay_flash($session, (string)$health['detail'],
			$ok ? 'Scanner is working' : 'Checked');
		return LogicResult::redirect($self_url);
	}

	if (($action === 'provision' || $action === 'rebuild') && $server_manager_active) {
		$result = admin_mailbox_relay_dispatch_job($action, $input, $session);
		admin_mailbox_relay_flash($session, $result['message'], $result['title']);
		return LogicResult::redirect($self_url);
	}

	// Cloud path (specs/mailbox_relay_cloud_provisioning.md): create the run;
	// the section then shows the just-in-time credential step. Nothing to
	// configure beforehand.
	if ($action === 'relay_cloud_begin') {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provision_class.php'));

		$mail_hostname = strtolower(trim((string)($input['cloud_mail_hostname'] ?? '')));
		$region = trim((string)($input['cloud_region'] ?? ''));
		// Instance type is fixed to the 1 GB Nanode for now — a relay idles,
		// and the provider's own interface can resize it later.
		$type = 'g6-nanode-1';
		if ($mail_hostname === '' || strpos($mail_hostname, '.') === false) {
			admin_mailbox_relay_flash($session, 'A mail hostname (FQDN, e.g. mx.example.com) is required.', 'Cannot provision');
			return LogicResult::redirect($self_url);
		}
		if ($region === '') {
			admin_mailbox_relay_flash($session, 'Pick a region.', 'Cannot provision');
			return LogicResult::redirect($self_url);
		}
		if (RelayCloudProvision::live() !== null) {
			admin_mailbox_relay_flash($session, 'A relay cloud act is already in flight — one at a time.', 'Cannot provision');
			return LogicResult::redirect($self_url);
		}

		$run = new RelayCloudProvision(NULL);
		$run->set('rcp_kind', 'provision');
		$run->set('rcp_provider', 'linode');
		$run->set('rcp_mail_hostname', substr($mail_hostname, 0, 255));
		$run->set('rcp_region', substr($region, 0, 50));
		$run->set('rcp_instance_type', substr($type, 0, 50));
		$run->save();
		return LogicResult::redirect($self_url);
	}

	// The one-click credential branch: when a Linode OAuth client is
	// configured, the step is a single Approve at Linode — the consent lands
	// on the run via RelayCloudConsumer with the same grant-per-act custody.
	if ($action === 'relay_cloud_connect') {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provision_class.php'));
		require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Client.php'));
		$run = RelayCloudProvision::live();
		if ($run === null || (string)$run->get('rcp_status') !== 'awaiting_grant') {
			return LogicResult::redirect($self_url);
		}
		try {
			$consent_url = (new OAuth2Client())->beginConsent(
				'linode', array('linodes:read_write'), 'relay_cloud',
				array('run_id' => intval($run->key)), $self_url);
		} catch (\Throwable $e) {
			admin_mailbox_relay_flash($session, $e->getMessage(), 'Could not start the Linode approval');
			return LogicResult::redirect($self_url);
		}
		return LogicResult::redirect($consent_url);
	}

	// The just-in-time credential floor: a short-lived provider token, minted
	// by the customer for this one act, verified live, sealed onto the run,
	// and erased at the run's terminal state (grant-per-act custody).
	if ($action === 'relay_cloud_token') {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provision_class.php'));
		require_once(PathHelper::getIncludePath('includes/cloud_compute/LinodeComputeDriver.php'));

		$run = RelayCloudProvision::live();
		if ($run === null || (string)$run->get('rcp_status') !== 'awaiting_grant') {
			return LogicResult::redirect($self_url);
		}
		$token = trim((string)($input['cloud_token'] ?? ''));
		if ($token === '') {
			admin_mailbox_relay_flash($session, 'Paste the token to continue.', 'Token required');
			return LogicResult::redirect($self_url);
		}
		// Fail fast on a bad token (a cheap read call); transient provider
		// trouble is not the customer's fault, so only a rejection blocks.
		try {
			(new LinodeComputeDriver($token))->regions();
		} catch (CloudComputeException $e) {
			if ((int)$e->getCode() === 401) {
				admin_mailbox_relay_flash($session,
					'Linode rejected that token. Create a fresh one (scope: Linodes read/write) and paste it again.',
					'Token rejected');
				return LogicResult::redirect($self_url);
			}
		} catch (\Throwable $e) {
			// Network hiccup — proceed; the run's own error handling covers it.
		}
		$run->sealToken($token);
		$run->set('rcp_status', 'ready');
		$run->set('rcp_error', null);
		$run->save();
		admin_mailbox_relay_flash($session,
			'Provisioning started — the server is created in your account and built automatically. '
			. 'This page shows progress; the whole run takes several minutes.');
		return LogicResult::redirect($self_url);
	}

	// Dismiss a finished (or abandoned-at-consent) run from the section.
	if ($action === 'relay_cloud_dismiss') {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provision_class.php'));
		$run = RelayCloudProvision::latest();
		if ($run !== null && (string)$run->get('rcp_status') !== 'booting'
				&& (string)$run->get('rcp_status') !== 'provisioning') {
			$run->eraseCredentials();
			$run->soft_delete();
		}
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
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySsh.php'));
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

	// Live hosted-slot state when the service connection is configured — and
	// only while the hosted offering is launched (no network call for a
	// surface that is not rendered).
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetClient.php'));
	$fleet_client = new FleetClient();
	$fleet_configured = mailbox_hosted_relay_offered() && $fleet_client->configured();
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

	// Cloud path state: the latest act (live progress or last outcome). The
	// cheap transitions (create instance, poll boot) advance right here on
	// page load so a watching admin sees progress; the long SSH build stays
	// with the scheduled task.
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provision_class.php'));
	$live_run = RelayCloudProvision::live();
	if ($live_run !== null && in_array((string)$live_run->get('rcp_status'), array('ready', 'booting'), true)) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayCloudProvisioner.php'));
		$was_allowed = SystemBase::$allow_get_mutation;
		SystemBase::$allow_get_mutation = true;
		try {
			(new RelayCloudProvisioner())->advanceCheap($live_run);
		} catch (\Throwable $e) {
			error_log('relay cloud page-advance failed for run ' . intval($live_run->key) . ': ' . $e->getMessage());
		} finally {
			SystemBase::$allow_get_mutation = $was_allowed;
		}
	}

	// Local mail listener state + guardrail verdict (listener_admin.php) — the
	// box renders whenever a live relay row exists or a decommission is recorded.
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/listener_admin.php'));
	$listener = (count($relays) > 0 || mailbox_listener_setting() === 'decommissioned')
		? mailbox_listener_state() : null;

	return array(
		'listener'              => $listener,
		'relays'                => $relays,
		'nodes'                 => $nodes,
		'server_manager_active' => $server_manager_active,
		'main_wg_public_key'    => (string)$settings->get_setting('mailbox_relay_wg_public_key'),
		'pull_key_ready'        => is_file(RelaySsh::pullKeyPath()),
		'has_active_relay'      => ($active !== null),
		'outbound_mode'         => (strtolower(trim((string)$settings->get_setting('mailbox_relay_outbound_mode'))) === 'smarthost')
			? 'smarthost' : 'provider',
		'fleet_configured'      => $fleet_configured,
		'fleet_status'          => $fleet_status,
		'fleet_error'           => $fleet_error,
		'cloud_run'             => RelayCloudProvision::latest(),
		'cloud_oauth_configured'=> admin_mailbox_relay_linode_oauth_configured(),
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

	if ($action === 'fleet_create_product' && PluginHelper::isPluginActive('store')) {
		$result = admin_mailbox_relay_create_fleet_product();
		admin_mailbox_relay_flash($session, $result['message'], $result['title']);
		return LogicResult::redirect($self_url);
	}

	return null;
}

/**
 * Products whose tier carries the fleet-slot feature — what makes an order a
 * Fortress order. Derived by query, no marker setting to drift. Returns rows
 * of ['id','name','is_active','fulfillment'].
 */
function admin_mailbox_relay_fleet_products(): array {
	if (!PluginHelper::isPluginActive('store')) {
		return array();
	}
	$db = DbConnector::get_instance()->get_db_link();
	$q = $db->prepare(
		"SELECT p.pro_product_id, p.pro_name, p.pro_is_active, p.pro_fulfillment_provider
		 FROM pro_products p
		 JOIN sbt_subscription_tiers t
		   ON t.sbt_subscription_tier_id = p.pro_sbt_subscription_tier_id
		  AND t.sbt_delete_time IS NULL
		 WHERE p.pro_delete_time IS NULL
		   AND (t.sbt_features->>'mailbox_fleet_slot') = 'true'
		 ORDER BY p.pro_name");
	$q->execute();
	$rows = array();
	foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
		$rows[] = array(
			'id'          => (int)$row['pro_product_id'],
			'name'        => (string)$row['pro_name'],
			'is_active'   => (bool)$row['pro_is_active'],
			'fulfillment' => (string)($row['pro_fulfillment_provider'] ?? ''),
		);
	}
	return $rows;
}

/**
 * One-click Fortress hosting product: reuse (or create) a tier whose features
 * grant the fleet slot, then create an INACTIVE customer-cloud hosting product
 * on it — the operator prices and activates it deliberately on the product
 * edit page. Idempotent: an existing fleet product means nothing to do.
 *
 * @return array{message:string,title:string}
 */
function admin_mailbox_relay_create_fleet_product(): array {
	$existing = admin_mailbox_relay_fleet_products();
	if (!empty($existing)) {
		return array('title' => 'Already set up',
			'message' => 'A product granting the fleet slot already exists: ' . $existing[0]['name'] . '.');
	}

	require_once(PathHelper::getIncludePath('data/subscription_tiers_class.php'));
	require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));

	// Reuse a slot-granting tier if one exists; otherwise create one above the
	// current top level.
	$tier = null;
	$top_level = 0;
	$tiers = new MultiSubscriptionTier(array('sbt_delete_time' => 'IS NULL'));
	$tiers->load();
	foreach ($tiers as $row) {
		$top_level = max($top_level, (int)$row->get('sbt_tier_level'));
		$features = json_decode((string)$row->get('sbt_features'), true) ?: array();
		if (!empty($features['mailbox_fleet_slot']) && $tier === null) {
			$tier = $row;
		}
	}
	$tier_created = false;
	if ($tier === null) {
		$tier = new SubscriptionTier(NULL);
		$tier->set('sbt_name', 'fortress');
		$tier->set('sbt_display_name', 'Fortress');
		$tier->set('sbt_tier_level', $top_level + 10);
		$tier->set('sbt_description', 'Fortress hosting: a dedicated server with a hosted relay slot on the shared fleet.');
		$tier->setFeatures(array('mailbox_fleet_slot' => true, 'mailbox_fleet_max_domains' => 5));
		$tier->save();
		$tier->load();
		$tier_created = true;
	}

	$link = 'fortress-hosting';
	$link_taken = new MultiProduct(array('link' => $link));
	if ($link_taken->count_all() > 0) {
		$link .= '-' . substr(md5(uniqid('', true)), 0, 6);
	}
	$product = new Product(NULL);
	$product->set('pro_name', 'Fortress Hosting');
	$product->set('pro_link', $link);
	$product->set('pro_description',
		'A dedicated server in your own cloud account, built automatically, with a hosted relay slot on the shared fleet.');
	$product->set('pro_sbt_subscription_tier_id', $tier->key);
	if (PluginHelper::isPluginActive('server_manager')) {
		$product->set('pro_fulfillment_provider', 'customer_cloud');
	}
	// Born inactive: price and publish are the operator's explicit acts.
	$product->set('pro_is_active', FALSE);
	$product->save();
	$product->load();

	return array('title' => 'Product created',
		'message' => ($tier_created
			? 'Tier "Fortress" created (level ' . $tier->get('sbt_tier_level') . ') with the fleet-slot feature. '
			: 'Reused tier "' . $tier->get('sbt_display_name') . '" (it already grants the fleet slot). ')
			. 'Product "Fortress Hosting" created inactive — set its price and activate it on the product edit page. '
			. 'Orders then provision the buyer\'s server and pre-seed its relay enrollment automatically.');
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
		'store_active'          => PluginHelper::isPluginActive('store'),
		'fleet_products'        => $fleet_service_on ? admin_mailbox_relay_fleet_products() : array(),
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
	// Labels are plain outcomes; the technical detail rides the tooltip on
	// failure.
	$run = array(
		'checkRelaySpoolDraining' => 'Mail pickup',
		'checkRelaySpoolHeld'     => 'No mail held on relay',
		'checkRelayMapFresh'      => 'Address list current',
		'checkOriginHidden'       => 'Server address hidden',
	);
	if ($mode === 'smarthost') {
		$run['checkRelayTunnel'] = 'Sending tunnel';
	} else {
		$run['checkOutboundTransportClass'] = 'Sending route hides your address';
		$run['checkOutboundOriginLeak']     = 'No leaks in sent mail';
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
 * The relay's Setup-tab cards: one in Receiving, and one in Sending only when
 * sent mail actually rides the relay.
 *
 * A relay is optional, so with none set up the Receiving card is a grey
 * "optional" line pointing at the setup that lives under Advanced — present
 * enough to be discoverable, quiet enough not to read as a to-do. Once a relay
 * exists the card carries its health: green when every check for that side
 * passes, red when any does not, naming which.
 *
 * **The Sending card appears only under a smarthost**, because that is the only
 * arrangement where sent mail goes through the relay at all. With an API
 * provider carrying outbound, a green relay card in the Sending group says
 * "healthy" about a component that is not in the path — it is green because it
 * is unused, which is the wrong thing to tell someone reading a checklist. The
 * outbound origin-leak checks still run; they surface as health dots in the
 * Relay section under Advanced, where they read as facts about the relay rather
 * than as a verdict on sending.
 *
 * Cost note: the battery (TCP probe, map build, DNS) runs only when a relay
 * exists. A deployment without one pays nothing for these cards.
 *
 * @return array{receiving:?array,sending:?array} Rows in InboundEmailSetupCheck's shape.
 */
function admin_mailbox_relay_check_rows(string $advanced_url = ''): array {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));

	$setup_link = array(
		'text' => 'Relay setup lives under Advanced server setup, at the bottom of this page.',
	);
	if ($advanced_url !== '') {
		$setup_link['link'] = array('url' => $advanced_url, 'label' => 'Go to relay setup');
	}

	$row = function ($id, $label, $status, $summary, $detail = '', $fix = null) {
		return array(
			'id' => $id, 'scope' => '', 'layer' => 'relay', 'label' => $label,
			'severity' => InboundEmailSetupCheck::RECOMMENDED, 'status' => $status,
			'summary' => $summary, 'detail' => $detail, 'fix' => $fix, 'recheckable' => true,
		);
	};

	$active = null;
	try {
		$active = MailboxRelay::active();
	} catch (\Throwable $e) {
		// Relay table absent (before update_database) — the same as no relay.
	}

	if ($active === null) {
		// No Sending card: with no relay, nothing about sending goes through one.
		return array(
			'receiving' => $row('relay.receiving', 'Relay', InboundEmailSetupCheck::OPTIONAL,
				'No relay — mail is delivered straight to this server.',
				'A relay receives your mail on a separate server and hands it here over a private tunnel, '
				. 'so this server\'s address never appears in public DNS.', $setup_link),
			'sending'   => null,
		);
	}

	$smarthost = (strtolower(trim((string)Globalvars::get_instance()
		->get_setting('mailbox_relay_outbound_mode'))) === 'smarthost');

	// Partition the battery by which side of the mail path each check speaks to.
	// The sending side is the relay's tunnel and nothing else: the outbound
	// origin-leak checks describe the provider path, not the relay, and putting
	// them on a card headed "Relay" would claim the relay is doing a job it is
	// not doing.
	$receiving_checks = array('checkRelaySpoolDraining', 'checkRelaySpoolHeld', 'checkRelayMapFresh', 'checkOriginHidden');
	$sending_checks   = $smarthost ? array('checkRelayTunnel') : array();

	$health = admin_mailbox_relay_health();
	$name = trim((string)$active->get('mrl_name')) ?: trim((string)$active->get('mrl_mx_hostname'));
	$enabled = (bool)$active->get('mrl_is_enabled');

	$side = function (array $keys, $id, $ok_summary) use ($health, $row, $name, $enabled, $setup_link) {
		$dots = array();
		foreach ($keys as $key) {
			if (isset($health[$key])) {
				$dots[] = $health[$key];
			}
		}
		if (empty($dots)) {
			return null;   // nothing on this side applies to the chosen mode
		}
		$failing = array();
		$labels  = array();
		foreach ($dots as $dot) {
			$labels[] = ($dot['ok'] ? '✓ ' : '✗ ') . $dot['label'];
			if (!$dot['ok']) {
				$failing[] = $dot['label'] . ($dot['message'] !== '' ? ' — ' . $dot['message'] : '');
			}
		}
		$detail = implode(' · ', $labels);
		if (!empty($failing)) {
			return $row($id, 'Relay', InboundEmailSetupCheck::FAIL,
				count($failing) === 1
					? $name . ': ' . $failing[0]
					: $name . ': ' . count($failing) . ' checks are failing.',
				$detail . ($failing ? '  ' . implode('  ', $failing) : ''), $setup_link);
		}
		// A disabled relay passes its checks but is not doing its job — the
		// emergency stop left on is worth saying out loud, not colouring green.
		if (!$enabled) {
			return $row($id, 'Relay', InboundEmailSetupCheck::WARN,
				$name . ' is set up but disabled.', $detail, $setup_link);
		}
		return $row($id, 'Relay', InboundEmailSetupCheck::PASS, $ok_summary($name), $detail);
	};

	return array(
		'receiving' => $side($receiving_checks, 'relay.receiving',
			function ($n) { return $n . ' is receiving your mail and handing it to this server.'; }),
		'sending'   => $side($sending_checks, 'relay.sending',
			function ($n) { return 'Sent mail leaves through ' . $n . '\'s tunnel.'; }),
	);
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
