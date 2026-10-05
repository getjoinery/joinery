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
 * @version 2.10 - in a site container (no mail server of its own) disabling the relay says mail
 *   stops until it is enabled again, the Local mail listener box never shows, and the relay card
 *   says a container with no enabled relay receives nothing (it no longer mentions a tunnel)
 * @version 2.9 - the operator fleet console's functions live in admin_mailbox_fleet_logic.php;
 *   admin_mailbox_relay_health() runs once per request (B3); the unposted
 *   relay_cloud_connect action is gone (the grant modal's grant=oauth begins the consent)
 * @version 2.8 - Test Relay Health records when it ran (mrl_last_test_time)
 * @version 2.7 - delete refuses a relay that is still enabled
 * @version 2.6 - a run still waiting for its permission gives way to a new update or create;
 *                relay messages show inside the Relay section (mailbox_relay_notice_html)
 * @version 2.5 - the Linode permission comes with the update or the create (grant=token|oauth,
 *                admin_mailbox_relay_take_grant); enable/disable set the receive mode; every
 *                relay action returns to #relay-section
 * @version 2.4 - one relay_health_check action (the fresh health answer plus the origin-leak
 *                probe) replaces scanner_probe and origin_probe
 * @version 2.3 - a new fleet product is named Relay Hosting (tier Relay, link relay-hosting)
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
/**
 * Leave a message for the Relay section, where every relay action lands
 * (#relay-section): shown inside the section by mailbox_relay_notice_html(),
 * then forgotten. The page's own message area sits at the top, above where the
 * person has been taken, which read as "nothing happened".
 */
function admin_mailbox_relay_flash($session, string $msg, string $title = 'Done'): void {
	$_SESSION['mailbox_relay_notice'] = array('title' => $title, 'message' => $msg);
}

/**
 * Clear the way for a new relay act: a run still waiting for its Linode
 * permission has done nothing, so a new update or create replaces it. True
 * when nothing is under way now; false when a run has actually started.
 */
function admin_mailbox_relay_nothing_under_way(): bool {
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provisions_class.php'));
	$live = RelayCloudProvision::live();
	if ($live === null) {
		return true;
	}
	if ((string)$live->get('rcl_status') === 'awaiting_grant') {
		$live->eraseCredentials();
		$live->soft_delete();
		return true;
	}
	return false;
}

/**
 * Tenant-side relay actions (Setup tab's Relay section): relay lifecycle,
 * provisioning jobs, the origin-leak probe, and hosted-slot enrollment.
 * Returns a redirect when the input was one of these actions, null otherwise.
 */
function admin_mailbox_relay_tenant_actions(array $input, $session, string $self_url): ?LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relays_class.php'));

	$action = $input['action'] ?? null;
	if ($action === null) {
		return null;
	}
	$relay_id = $input['mrl_mailbox_relay_id'] ?? null;
	// Every relay action returns to the Relay section, not the top of the page.
	$back = $self_url . '#relay-section';
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
		// Enabling or disabling the relay is also how this server's receive mode
		// is chosen: the domain DNS checks prescribe from it (receive_mode.php).
		// A site container whose mail arrives by SMTP cannot receive directly,
		// so its mode stays relay either way.
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
		$needs_relay = mailbox_needs_relay();
		Setting::put('mailbox_receive_mode', ($action === 'enable' || $needs_relay) ? 'relay' : 'direct');
		admin_mailbox_relay_flash($session, $action === 'enable'
			? 'Relay enabled — it now fronts every hosted domain.'
			: (!$needs_relay
				? 'Relay disabled — this server receives mail directly. Point your domains\' MX records here; the checks show what to change.'
				: 'Relay disabled — this site has no mail server of its own, so no new mail reaches it until the relay is enabled again.'));
		return LogicResult::redirect($back);
	}

	if ($action === 'delete' && $relay_id) {
		$relay = new MailboxRelay(intval($relay_id), TRUE);
		// Only a disabled relay is removed: one still carrying mail is disabled
		// first, which says what happens to the mail on the way.
		if ((bool)$relay->get('mrl_is_enabled')) {
			admin_mailbox_relay_flash($session, 'Disable the relay first, then delete it.', 'Relay still in use');
			return LogicResult::redirect($back);
		}
		$relay->soft_delete();
		admin_mailbox_relay_flash($session, 'Relay removed.');
		return LogicResult::redirect($back);
	}

	// Test Relay Health: ask the relay for a fresh health answer (reachable, and
	// whether its spam scanner runs and its verdicts reach this server — the
	// cron pass asks once per reconcile, but an operator mid-incident needs a
	// fresh one, specs/mailbox_relay_scanner_health.md D1), then send the
	// out-and-back origin-leak probe, whose delivered headers the "No leaks in
	// sent mail" check scans when it comes back.
	if ($action === 'relay_health_check') {
		$relay = MailboxRelay::active();
		if ($relay === null) {
			admin_mailbox_relay_flash($session, 'No relay is enabled to check.', 'Nothing to check');
			return LogicResult::redirect($back);
		}
		$health = $relay->pollHealth();
		$ok = ($health['state'] === MailboxRelay::HEALTH_OK);

		$lines = array();
		$lines[] = Globalvars::get_instance()->get_setting('mailbox_spam_filtering_enabled')
			? 'Relay: ' . (string)$health['detail']
			: ($ok ? 'Relay: it answered.' : 'Relay: ' . (string)$health['detail']);
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailHealth.php'));
		$probe = InboundEmailHealth::sendOriginProbe();
		if ($probe['ok']) {
			// The Relay section's "Last tested" line reads the leak test's result
			// against this time (mailbox_relay_last_test_html). Recorded only when
			// the test message went out, so "has not come back" means just that.
			MailboxRelay::updateColumns(intval($relay->key), array('mrl_last_test_time' => gmdate('Y-m-d H:i:s')));
		}
		$lines[] = 'Leak check: ' . ($probe['ok']
			? 'a test message is on its way out and back; its result shows under the relay in a minute or two.'
			: $probe['message']);
		admin_mailbox_relay_flash($session, implode(' ', $lines), $ok ? 'Relay checked' : 'Relay needs attention');
		return LogicResult::redirect($back);
	}

	// Upgrade a cloud relay: open an upgrade run against its existing instance.
	// The relay cannot be logged in to — no root credential exists for it — so the
	// upgrade drains it and replaces the machine's contents in place
	// (specs/mailbox_relay_upgrade_without_server_manager.md).
	if ($action === 'relay_upgrade') {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provisions_class.php'));
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
			return LogicResult::redirect($back);
		}
		$vars = admin_mailbox_relay_upgrade_vars($relay);
		if ($vars['route'] !== 'cloud') {
			// The button is not rendered for these, so reaching here means a stale
			// page or a hand-posted form. Refuse rather than guess a route.
			admin_mailbox_relay_flash($session,
				'This relay is not one this site can update for you.', 'Cannot update');
			return LogicResult::redirect($back);
		}
		if (!admin_mailbox_relay_nothing_under_way()) {
			admin_mailbox_relay_flash($session,
				'A relay update or creation is already under way — one at a time.', 'Cannot upgrade');
			return LogicResult::redirect($back);
		}

		// The wipe guard, first pass. The provisioner re-asks the relay live before
		// draining; this catches it at the button so the customer is told before a
		// run exists rather than by a failed run afterwards.
		$sole = $vars['sole'];
		if ($sole === false) {
			admin_mailbox_relay_flash($session,
				'This relay serves other deployments as well as this one. Re-imaging it would destroy '
				. 'their mail and their configuration.', 'Cannot update a shared relay');
			return LogicResult::redirect($back);
		}
		if ($sole === null && empty($input['shared_ack'])) {
			// A relay too old to answer. The platform cannot prove it is safe, so
			// it does not decide — but it does not proceed silently either.
			admin_mailbox_relay_flash($session,
				'This relay is too old to say whether other deployments share it. Confirm you know it '
				. 'serves only this site before updating it.', 'Confirmation needed');
			return LogicResult::redirect($back);
		}

		$run = new RelayCloudProvision(NULL);
		$run->set('rcl_kind', 'upgrade');
		$run->set('rcl_mrl_mailbox_relay_id', intval($relay->key));
		$run->set('rcl_provider', (string)$relay->get('mrl_cloud_provider'));
		$run->set('rcl_instance_id', (string)$relay->get('mrl_cloud_instance_id'));
		$run->set('rcl_instance_ip', (string)$relay->get('mrl_public_ip'));
		// The re-image builds the relay again under the same hostname it already
		// answers to: it is the milters' AuthservID and the HELO name, so a
		// different value here would silently change what the relay is.
		$run->set('rcl_mail_hostname', (string)$relay->get('mrl_mx_hostname')
			?: (string)$relay->get('mrl_name'));
		$run->save();
		$granted = admin_mailbox_relay_take_grant($run, $input, $session, $back);
		if ($granted !== null) {
			return $granted;
		}
		admin_mailbox_relay_flash($session,
			'Approve access to your cloud account to continue. The relay is drained first, then the same '
			. 'server is re-imaged and born again from the current release — it stops accepting mail for '
			. 'several minutes, and senders retry.');
		return LogicResult::redirect($back);
	}

	// Cloud path (specs/mailbox_relay_cloud_provisioning.md): create the run;
	// the section then shows the just-in-time credential step. Nothing to
	// configure beforehand.
	if ($action === 'relay_cloud_begin') {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provisions_class.php'));

		$mail_hostname = strtolower(trim((string)($input['cloud_mail_hostname'] ?? '')));
		$region = trim((string)($input['cloud_region'] ?? ''));
		// Instance type is fixed to the 1 GB Nanode for now — a relay idles,
		// and the provider's own interface can resize it later.
		$type = 'g6-nanode-1';
		if ($mail_hostname === '' || strpos($mail_hostname, '.') === false) {
			admin_mailbox_relay_flash($session, 'A mail hostname (FQDN, e.g. mx.example.com) is required.', 'Cannot provision');
			return LogicResult::redirect($back);
		}
		if ($region === '') {
			admin_mailbox_relay_flash($session, 'Pick a region.', 'Cannot provision');
			return LogicResult::redirect($back);
		}
		if (!admin_mailbox_relay_nothing_under_way()) {
			admin_mailbox_relay_flash($session, 'A relay update or creation is already under way — one at a time.', 'Cannot provision');
			return LogicResult::redirect($back);
		}

		$run = new RelayCloudProvision(NULL);
		$run->set('rcl_kind', 'provision');
		$run->set('rcl_provider', 'linode');
		$run->set('rcl_mail_hostname', substr($mail_hostname, 0, 255));
		$run->set('rcl_region', substr($region, 0, 50));
		$run->set('rcl_instance_type', substr($type, 0, 50));
		$run->save();
		$granted = admin_mailbox_relay_take_grant($run, $input, $session, $back);
		return $granted ?? LogicResult::redirect($back);
	}

	// The just-in-time credential floor: a short-lived provider token, minted
	// by the customer for this one act, verified live, sealed onto the run,
	// and erased at the run's terminal state (grant-per-act custody).
	if ($action === 'relay_cloud_token') {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provisions_class.php'));
		require_once(PathHelper::getIncludePath('includes/cloud_compute/LinodeComputeDriver.php'));

		$run = RelayCloudProvision::live();
		if ($run === null || (string)$run->get('rcl_status') !== 'awaiting_grant') {
			return LogicResult::redirect($back);
		}
		// A run already waiting (the section's Continue): Approve at Linode or a
		// token, and a missing or rejected token leaves it waiting.
		return admin_mailbox_relay_take_grant($run, array(
			'grant'       => (($input['grant'] ?? '') === 'oauth') ? 'oauth' : 'token',
			'cloud_token' => $input['cloud_token'] ?? '',
			'keep_run'    => 1,
		), $session, $back) ?? LogicResult::redirect($back);
	}

	// Dismiss a finished (or abandoned-at-consent) run from the section.
	if ($action === 'relay_cloud_dismiss') {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provisions_class.php'));
		$run = RelayCloudProvision::latest();
		if ($run !== null && (string)$run->get('rcl_status') !== 'booting'
				&& (string)$run->get('rcl_status') !== 'provisioning') {
			$run->eraseCredentials();
			$run->soft_delete();
		}
		return LogicResult::redirect($back);
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
		return LogicResult::redirect($back);
	}

	return null;
}


/**
 * The Linode permission, taken in the same post that opened the run (the Relay
 * section's modal asks for it with the update or the create): grant=token with
 * cloud_token, verified live, sealed onto the run, the run started; or
 * grant=oauth, the consent begun at Linode. Null when the post carried no
 * grant, and the run waits in awaiting_grant for the section's own step.
 *
 * A token Linode rejects removes the run it came with, so a refused modal
 * leaves nothing half-started behind.
 */
function admin_mailbox_relay_take_grant(RelayCloudProvision $run, array $input, $session, string $back): ?LogicResult {
	$grant = (string)($input['grant'] ?? '');
	if ($grant === 'oauth') {
		require_once(PathHelper::getIncludePath('includes/oauth/OAuth2Client.php'));
		try {
			$consent_url = (new OAuth2Client())->beginConsent(
				'linode', array('linodes:read_write'), 'relay_cloud',
				array('run_id' => intval($run->key)), $back);
		} catch (\Throwable $e) {
			admin_mailbox_relay_flash($session, $e->getMessage(), 'Could not start the Linode approval');
			return LogicResult::redirect($back);
		}
		return LogicResult::redirect($consent_url);
	}
	if ($grant !== 'token') {
		return null;
	}
	require_once(PathHelper::getIncludePath('includes/cloud_compute/LinodeComputeDriver.php'));
	$token = trim((string)($input['cloud_token'] ?? ''));
	$drop = function () use ($run, $input) {
		if (empty($input['keep_run'])) {
			$run->eraseCredentials();
			$run->soft_delete();
		}
	};
	if ($token === '') {
		$drop();
		admin_mailbox_relay_flash($session, 'Paste the token to continue.', 'Token required');
		return LogicResult::redirect($back);
	}
	// Fail fast on a bad token (a cheap read call); transient provider
	// trouble is not the customer's fault, so only a rejection blocks.
	try {
		(new LinodeComputeDriver($token))->regions();
	} catch (CloudComputeException $e) {
		if ((int)$e->getCode() === 401) {
			$drop();
			admin_mailbox_relay_flash($session,
				'Linode rejected that token, so nothing started. Create a fresh one (scope: Linodes read/write) and try again.',
				'Token rejected');
			return LogicResult::redirect($back);
		}
	} catch (\Throwable $e) {
		// Network hiccup — proceed; the run's own error handling covers it.
	}
	$run->sealToken($token);
	$run->set('rcl_status', 'ready');
	$run->set('rcl_error', null);
	$run->save();
	admin_mailbox_relay_flash($session, ((string)$run->get('rcl_kind') === 'upgrade')
		? 'Update started — the relay is drained first, then the same server is re-imaged from the current release '
			. 'and reports in here. It stops accepting mail for several minutes; senders retry.'
		: 'Provisioning started — the server is created in your account and builds itself from its first boot, '
			. 'then reports in here. This page shows progress; the whole run takes several minutes.');
	return LogicResult::redirect($back);
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
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relays_class.php'));
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
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/relay_cloud_provisions_class.php'));
	$live_run = RelayCloudProvision::live();
	if ($live_run !== null && in_array((string)$live_run->get('rcl_status'), array('ready', 'booting'), true)) {
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
	// A site container has no listener to show.
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
	$listener = (mailbox_site_has_mail_server()
			&& (count($relays) > 0 || mailbox_listener_setting() === 'decommissioned'))
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
 * Run the relay health checks once per request and return their pass/fail
 * state for the status column ($fresh runs them again). Only meaningful when a
 * relay is active; cheap otherwise.
 *
 * @return array<string,array{label:string,ok:bool,message:string}>
 */
function admin_mailbox_relay_health(bool $fresh = false): array {
	// One run per request. The Setup page asks twice on every view with an
	// enabled relay (the relay card and the tenant section), and each run
	// builds the whole relay map; the answer cannot change between the two.
	static $memo = null;
	if ($memo !== null && !$fresh) {
		return $memo;
	}
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
	return $memo = $out;
}

/**
 * The relay's Setup-tab card, in Receiving.
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
 * @return array{receiving:?array} A row in InboundEmailSetupCheck's shape.
 */
function admin_mailbox_relay_check_rows(string $advanced_url = ''): array {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relays_class.php'));

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
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
		if (mailbox_needs_relay()) {
			return array(
				'receiving' => $row('relay.receiving', 'Relay', InboundEmailSetupCheck::FAIL,
					'No relay is enabled, so no mail reaches this site.',
					'This site runs in a container and has no mail server of its own. A relay takes its mail '
					. 'in on a separate server and checks it, and this site collects it from there.', $setup_link),
			);
		}
		return array(
			'receiving' => $row('relay.receiving', 'Relay', InboundEmailSetupCheck::OPTIONAL,
				'No relay — mail is delivered straight to this server.',
				'A relay receives your mail on a separate server, and this server collects it from there, '
				. 'so this server\'s address never appears in public DNS.', $setup_link),
		);
	}

	// The relay speaks only to the receiving side of the mail path: the outbound
	// origin-leak checks describe the provider path, not the relay, and putting
	// them on a card headed "Relay" would claim the relay is doing a job it is
	// not doing. Whether it answers its API is the first fact about receiving.
	$receiving_checks = array('checkRelayReachable', 'checkRelaySpoolDraining', 'checkRelaySpoolHeld', 'checkRelayMapFresh', 'checkOriginHidden');

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
	);
}

?>
