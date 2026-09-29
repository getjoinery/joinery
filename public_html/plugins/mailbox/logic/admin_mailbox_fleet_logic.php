<?php
/**
 * Logic for the relay fleet console (operator side).
 *
 * The control panel for running a shared relay service other deployments
 * enroll in: service on/off + MX zone, shard registration/provisioning, and
 * the DNS the fleet zone needs. Operator infrastructure, so it lives off the
 * Server Manager dashboard — tenant relay surfaces (Setup/Settings tabs)
 * never show it. The console's own functions live here; what it shares with
 * the tenant surfaces (flash, settings writes) stays in includes/relay_admin.php.
 *
 * @version 1.1 - the console's functions moved here from relay_admin.php
 * @version 1.0
 */
function admin_mailbox_fleet_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/relay_admin.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	// Running a fleet needs server_manager (shards are managed nodes and
	// provisioning runs as its jobs); without it there is no console.
	if (!PluginHelper::isPluginActive('server_manager')) {
		return LogicResult::redirect('/admin/server_manager');
	}

	$self_url = '/plugins/mailbox/admin/admin_mailbox_fleet';
	$redirect = admin_mailbox_relay_operator_actions($input, $session, $self_url);
	if ($redirect !== null) {
		return $redirect;
	}

	$vars = admin_mailbox_relay_operator_vars();
	$vars['session'] = $session;
	return LogicResult::render($vars);
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

	if ($action === 'provision_shard') {
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
 * relay-hosting order. Derived by query, no marker setting to drift. Returns rows
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
 * One-click Relay Hosting product: reuse (or create) a tier whose features
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
		$tier->set('sbt_name', 'relay');
		$tier->set('sbt_display_name', 'Relay');
		$tier->set('sbt_tier_level', $top_level + 10);
		$tier->set('sbt_description', 'Relay hosting: a dedicated server with a hosted relay slot on the shared fleet.');
		$tier->setFeatures(array('mailbox_fleet_slot' => true, 'mailbox_fleet_max_domains' => 5));
		$tier->save();
		$tier->load();
		$tier_created = true;
	}

	$link = 'relay-hosting';
	$link_taken = new MultiProduct(array('link' => $link));
	if ($link_taken->count_all() > 0) {
		$link .= '-' . substr(md5(uniqid('', true)), 0, 6);
	}
	$product = new Product(NULL);
	$product->set('pro_name', 'Relay Hosting');
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
			? 'Tier "Relay" created (level ' . $tier->get('sbt_tier_level') . ') with the fleet-slot feature. '
			: 'Reused tier "' . $tier->get('sbt_display_name') . '" (it already grants the fleet slot). ')
			. 'Product "Relay Hosting" created inactive — set its price and activate it on the product edit page. '
			. 'Orders then provision the buyer\'s server and pre-seed its relay enrollment automatically.');
}

/**
 * Operator-side view vars for the fleet console: service state, shard rows
 * (connection facts reconciled) with slot counts and DNS-to-publish rows,
 * and the nodes a shard can be provisioned onto.
 */
function admin_mailbox_relay_operator_vars(): array {
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provisions_class.php'));
	$settings = Globalvars::get_instance();
	$server_manager_active = PluginHelper::isPluginActive('server_manager');

	$fleet_service_on = ((string)$settings->get_setting('mailbox_fleet_service_enabled') === '1');
	$fleet_shards = array();
	if ($fleet_service_on) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetService.php'));
		$shard_multi = new MultiMailboxFleetShard(array('deleted' => false));
		$shard_multi->load();
		foreach ($shard_multi as $shard) {
			$fleet_shards[] = array(
				'model' => $shard,
				'slots' => $shard->slotCount(),
				'dns'   => admin_mailbox_relay_shard_dns_rows($shard),
			);
		}
	}


	return array(
		'server_manager_active' => $server_manager_active,
		'fleet_service_on'      => $fleet_service_on,
		'fleet_mx_zone'         => trim((string)$settings->get_setting('mailbox_fleet_mx_zone')),
		'fleet_shards'          => $fleet_shards,
		// A shard is born like any relay: the live run, if one is in flight.
		'shard_run'             => RelayCloudProvision::live(),
		'cloud_oauth_configured'=> admin_mailbox_relay_linode_oauth_configured(),
		'store_active'          => PluginHelper::isPluginActive('store'),
		'fleet_products'        => $fleet_service_on ? admin_mailbox_relay_fleet_products() : array(),
	);
}

/**
 * Register a fleet shard and start its birth: create the MailboxFleetShard row
 * and open a skeleton-only provisioning run for it in the operator's own cloud
 * account (specs/relay_without_a_shell.md). The run's user-data carries the
 * operator identity's public key and no tenant; tenants land on the shard
 * through fleet enrollment once it has reported in.
 *
 * @return array{message:string,title:string}
 */
function admin_mailbox_relay_provision_shard(array $input, $session): array {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/FleetService.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provisions_class.php'));

	$hostname = strtolower(trim((string)($input['shard_hostname'] ?? '')));
	$region = trim((string)($input['shard_region'] ?? ''));
	$capacity = max(1, intval($input['shard_capacity'] ?? 25));
	if ($hostname === '' || strpos($hostname, '.') === false) {
		return array('title' => 'Cannot provision shard', 'message' => 'Give the shard a mail hostname (FQDN).');
	}
	if ($region === '') {
		return array('title' => 'Cannot provision shard', 'message' => 'Pick a region.');
	}
	if (RelayCloudProvision::live() !== null) {
		return array('title' => 'Cannot provision shard', 'message' => 'A relay update or creation is already under way — one at a time.');
	}

	$shard = new MailboxFleetShard(NULL);
	$shard->set('mfs_name', $hostname);
	$shard->set('mfs_hostname', substr($hostname, 0, 255));
	$shard->set('mfs_capacity', $capacity);
	$shard->set('mfs_region', substr($region, 0, 50));
	$shard->set('mfs_cloud_provider', 'linode');
	$shard->set('mfs_is_active', false); // active once born
	$shard->save();

	$run = new RelayCloudProvision(NULL);
	$run->set('rcl_kind', 'provision');
	$run->set('rcl_provider', 'linode');
	$run->set('rcl_mail_hostname', substr($hostname, 0, 255));
	$run->set('rcl_region', substr($region, 0, 50));
	$run->set('rcl_instance_type', 'g6-nanode-1');
	$run->set('rcl_mfs_mailbox_fleet_shard_id', intval($shard->key));
	$run->save();

	return array('title' => 'Shard birth started',
		'message' => 'Approve access to your cloud account in the Relay section to create the shard\'s server. '
			. 'It builds itself and reports in; tenants land on it through fleet enrollment once it is active.');
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
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_fleet_slots_class.php'));

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
?>
