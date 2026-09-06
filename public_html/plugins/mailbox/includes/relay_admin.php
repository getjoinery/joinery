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
 * @version 2.1 - the health battery carries a pending grade
 *                (ProvisioningCheckPending): a converging alias map renders as
 *                an amber wait on the relay card, not a red failure
 * @version 2.2 - the ssh era is over: no relay jobs, no node route, no tunnel identity; a fleet
 *                shard is born from a skeleton-only cloud run (specs/relay_without_a_shell.md)
 * @version 2.1 - a relay without a shell: Update wording, checkRelayReachable on the receiving
 *                card, the no-relay notice (specs/relay_without_a_shell.md)
 * @version 2.0 - relay_upgrade action + per-relay upgrade standing; Rebuild is
 *                gated on a managed node that actually resolves
 *                (specs/mailbox_relay_upgrade_without_server_manager.md)
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


	// Upgrade a cloud relay: open an upgrade run against its existing instance.
	// The relay cannot be logged in to — no root credential exists for it — so the
	// upgrade drains it and replaces the machine's contents in place
	// (specs/mailbox_relay_upgrade_without_server_manager.md).
	if ($action === 'relay_upgrade') {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provision_class.php'));
		$relay_id = intval($input['mrl_mailbox_relay_id'] ?? 0);
		$relay = null;
		if ($relay_id > 0) {
			try {
				$relay = new MailboxRelay($relay_id, true);
			} catch (\Throwable $e) {
				$relay = null;
			}
		}
		if ($relay === null || !$relay->key) {
			admin_mailbox_relay_flash($session, 'That relay no longer exists.', 'Cannot upgrade');
			return LogicResult::redirect($self_url);
		}
		$vars = admin_mailbox_relay_upgrade_vars($relay);
		if ($vars['route'] !== 'cloud') {
			// The button is not rendered for these, so reaching here means a stale
			// page or a hand-posted form. Refuse rather than guess a route.
			admin_mailbox_relay_flash($session,
				'This relay is not one this site can update for you.', 'Cannot update');
			return LogicResult::redirect($self_url);
		}
		if (RelayCloudProvision::live() !== null) {
			admin_mailbox_relay_flash($session,
				'A relay cloud act is already in flight — one at a time.', 'Cannot upgrade');
			return LogicResult::redirect($self_url);
		}

		// The wipe guard, first pass. The provisioner re-asks the relay live before
		// draining; this catches it at the button so the customer is told before a
		// run exists rather than by a failed run afterwards.
		$sole = $vars['sole'];
		if ($sole === false) {
			admin_mailbox_relay_flash($session,
				'This relay serves other deployments as well as this one. Re-imaging it would destroy '
				. 'their mail and their configuration.', 'Cannot update a shared relay');
			return LogicResult::redirect($self_url);
		}
		if ($sole === null && empty($input['shared_ack'])) {
			// A relay too old to answer. The platform cannot prove it is safe, so
			// it does not decide — but it does not proceed silently either.
			admin_mailbox_relay_flash($session,
				'This relay is too old to say whether other deployments share it. Confirm you know it '
				. 'serves only this site before updating it.', 'Confirmation needed');
			return LogicResult::redirect($self_url);
		}

		$run = new RelayCloudProvision(NULL);
		$run->set('rcp_kind', 'upgrade');
		$run->set('rcp_mrl_mailbox_relay_id', intval($relay->key));
		$run->set('rcp_provider', (string)$relay->get('mrl_cloud_provider'));
		$run->set('rcp_instance_id', (string)$relay->get('mrl_cloud_instance_id'));
		$run->set('rcp_instance_ip', (string)$relay->get('mrl_public_ip'));
		// The re-image builds the relay again under the same hostname it already
		// answers to: it is the milters' AuthservID and the HELO name, so a
		// different value here would silently change what the relay is.
		$run->set('rcp_mail_hostname', (string)$relay->get('mrl_mx_hostname')
			?: (string)$relay->get('mrl_name'));
		$run->save();
		admin_mailbox_relay_flash($session,
			'Approve access to your cloud account to continue. The relay is drained first, then the same '
			. 'server is re-imaged and born again from the current release — it stops accepting mail for '
			. 'several minutes, and senders retry.');
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
			'Provisioning started — the server is created in your account and builds itself from its first boot, '
			. 'then reports in here. This page shows progress; the whole run takes several minutes.');
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
/**
 * Where one relay stands on code age, and which update route (if any) applies
 * to it.
 *
 * The routes are decided by what the platform can actually reach, not by
 * preference: a cloud instance means a grant-per-act drain and re-image;
 * anything else means the customer built the box and is the only one who can
 * act on it.
 *
 * @return array{standing:string,running:string,shipped:string,offers:bool,
 *               route:string,queue:?int,describe:string}
 */
function admin_mailbox_relay_upgrade_vars(MailboxRelay $relay): array {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayVersion.php'));
	$running  = $relay->provisionedVersion();
	$shipped  = RelayVersion::shipped();
	$standing = RelayVersion::compare($running, $shipped);

	if ((bool)$relay->get('mrl_is_hosted')) {
		// A tenant cannot wipe a shard they share with strangers.
		$route = 'hosted';
	} elseif ((string)$relay->get('mrl_cloud_instance_id') !== ''
			&& (string)$relay->get('mrl_cloud_provider') !== '') {
		$route = 'cloud';
	} else {
		$route = 'manual';
	}

	return array(
		'standing' => $standing,
		'running'  => $running,
		'shipped'  => $shipped,
		'offers'   => RelayVersion::offersUpgrade($standing),
		'route'    => $route,
		'queue'    => $relay->queuedCount(),
		// TRUE / FALSE / NULL for "the relay is too old to say" — never collapse
		// NULL into either, it decides whether a wipe is safe.
		'sole'     => $relay->isSoleTenant(),
		'describe' => RelayVersion::describe($standing, $running, $shipped),
	);
}

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
		$is_active = ($active !== null && intval($relay->key) === intval($active->key));
		$relays[] = array(
			'model'   => $relay,
			'health'  => $is_active ? $active_health : null,
			'upgrade' => admin_mailbox_relay_upgrade_vars($relay),
		);
	}

	// Managed nodes available to provision onto (server_manager).

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
		try {
			$fleet_status = SystemBase::server_initiated_write(function () use ($fleet_client) {
				return $fleet_client->status();
			});
		} catch (\Throwable $e) {
			$fleet_error = $e->getMessage();
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
		try {
			SystemBase::server_initiated_write(function () use ($live_run) {
				(new RelayCloudProvisioner())->advanceCheap($live_run);
			});
		} catch (\Throwable $e) {
			error_log('relay cloud page-advance failed for run ' . intval($live_run->key) . ': ' . $e->getMessage());
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
		'server_manager_active' => $server_manager_active,
		'has_active_relay'      => ($active !== null),
		'fleet_configured'      => $fleet_configured,
		'fleet_status'          => $fleet_status,
		'fleet_error'           => $fleet_error,
		'cloud_run'             => RelayCloudProvision::latest(),
		'cloud_oauth_configured'=> admin_mailbox_relay_linode_oauth_configured(),
		// No enabled relay, but the cutover was recorded complete: the world
		// still sends this deployment's mail to a relay it no longer has.
		'mx_points_at_gone_relay' => ($active === null
			&& (string)$settings->get_setting('mailbox_relay_cutover_complete') === '1'),
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
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provision_class.php'));
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
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provision_class.php'));

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
		return array('title' => 'Cannot provision shard', 'message' => 'A relay cloud act is already in flight — one at a time.');
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
	$run->set('rcp_kind', 'provision');
	$run->set('rcp_provider', 'linode');
	$run->set('rcp_mail_hostname', substr($hostname, 0, 255));
	$run->set('rcp_region', substr($region, 0, 50));
	$run->set('rcp_instance_type', 'g6-nanode-1');
	$run->set('rcp_mfs_shard_id', intval($shard->key));
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
	// Labels are plain outcomes; the technical detail rides the tooltip on
	// failure. Whether the relay answers its API is the first fact.
	$run = array(
		'checkRelayReachable'     => 'Relay reachable',
		'checkRelaySpoolDraining' => 'Mail pickup',
		'checkRelaySpoolHeld'     => 'No mail held on relay',
		'checkRelayMapFresh'      => 'Address list current',
		'checkOriginHidden'       => 'Server address hidden',
		'checkOutboundTransportClass' => 'Sending route hides your address',
		'checkOutboundOriginLeak'     => 'No leaks in sent mail',
	);
	$out = array();
	foreach ($run as $method => $label) {
		$ok = true; $pending = false; $message = '';
		try {
			InboundEmailHealth::$method();
		} catch (ProvisioningCheckPending $e) {
			// Unmet but converging — the machinery that fixes it is alive and
			// one tick away. Rendered as a wait, never as a failure.
			$pending = true;
			$message = $e->getMessage();
		} catch (\Throwable $e) {
			$ok = false;
			$message = $e->getMessage();
		}
		$out[$method] = array('label' => $label, 'ok' => $ok, 'pending' => $pending, 'message' => $message);
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
 * **There is no Sending card for the relay**: the relay is inbound only, so sent
 * mail never goes through it. A green relay card in the Sending group would say
 * "healthy" about a component that is not in the path — green because it
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

	// The relay speaks only to the receiving side of the mail path: the outbound
	// origin-leak checks describe the provider path, not the relay, and putting
	// them on a card headed "Relay" would claim the relay is doing a job it is
	// not doing. Whether it answers its API is the first fact about receiving.
	$receiving_checks = array('checkRelayReachable', 'checkRelaySpoolDraining', 'checkRelaySpoolHeld', 'checkRelayMapFresh', 'checkOriginHidden');
	$sending_checks   = array();

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
		$waiting = array();
		$labels  = array();
		foreach ($dots as $dot) {
			$is_pending = !empty($dot['pending']);
			$labels[] = ($is_pending ? '… ' : ($dot['ok'] ? '✓ ' : '✗ ')) . $dot['label'];
			if ($is_pending) {
				$waiting[] = $dot['label'] . ($dot['message'] !== '' ? ' — ' . $dot['message'] : '');
			} elseif (!$dot['ok']) {
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
		// Converging, not broken: a recent change is queued and the machinery
		// that applies it is alive. A wait, so amber — red here cries wolf at
		// every domain/alias/sender change for one reconcile tick.
		if (!empty($waiting)) {
			return $row($id, 'Relay', InboundEmailSetupCheck::WARN,
				$name . ': ' . $waiting[0], $detail, $setup_link);
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

?>
