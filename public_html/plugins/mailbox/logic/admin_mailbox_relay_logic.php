<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

/**
 * Relay admin logic (specs/mailbox_relay_fix_pack.md § Fix 10).
 *
 * Lists the deployment's hardened ingest relay(s) with status/health and drives
 * the lifecycle: provision a relay on a managed node (a server_manager
 * provision_relay job), rebuild it, enable/disable it, delete it. The relay row
 * (MailboxRelay) is created/updated by the job result processor on success; the
 * admin enables it here once it exists.
 */
function admin_mailbox_relay_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	$self_url = '/plugins/mailbox/admin/admin_mailbox_relay';

	$flash = function ($msg, $title = 'Done') use ($session) {
		$session->save_message(new DisplayMessage(
			$msg, $title, '~/plugins/mailbox/admin/~',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	};

	$server_manager_active = PluginHelper::isPluginActive('server_manager');

	// --- actions -------------------------------------------------------------
	$action = $input['action'] ?? null;
	if ($action !== null) {
		$relay_id = $input['mrl_mailbox_relay_id'] ?? null;

		if (($action === 'enable' || $action === 'disable') && $relay_id) {
			$relay = new MailboxRelay(intval($relay_id), TRUE);
			$relay->set('mrl_is_enabled', $action === 'enable');
			$relay->save();
			$flash($action === 'enable' ? 'Relay enabled — it now fronts every hosted domain.' : 'Relay disabled.');
			return LogicResult::redirect($self_url);
		}

		if ($action === 'delete' && $relay_id) {
			$relay = new MailboxRelay(intval($relay_id), TRUE);
			$relay->soft_delete();
			$flash('Relay removed.');
			return LogicResult::redirect($self_url);
		}

		// Outbound mode: where compose sends leave from
		// (specs/mailbox_relay_inbound_only.md). Provider (default) rides the
		// configured email provider's API and hides the origin; smarthost routes
		// compose through the relay over the tunnel.
		if ($action === 'set_outbound_mode') {
			$prior = (strtolower(trim((string)$settings->get_setting('mailbox_relay_outbound_mode'))) === 'smarthost')
				? 'smarthost' : 'provider';
			$mode = (($input['mailbox_relay_outbound_mode'] ?? 'provider') === 'smarthost') ? 'smarthost' : 'provider';
			admin_mailbox_relay_write_setting('mailbox_relay_outbound_mode', $mode);
			// The relay's Postfix submission listener is baked at provision time
			// (provision_relay.sh's smarthost argument), so a mode switch takes
			// effect on the relay itself only at the next Rebuild. The tunnel
			// check does a real submission handshake, so it fails honestly until then.
			if ($mode === 'smarthost') {
				$flash('Sent mail now leaves through the relay smarthost. This deployment owns the relay IP\'s '
					. 'sending reputation. Run Rebuild on the relay to open its tunnel submission listener — '
					. 'until then compose sends are refused and the tunnel check fails.');
			} else {
				$flash('Sent mail now leaves through your email provider.'
					. ($prior === 'smarthost'
						? ' The relay\'s submission listener stays open until its next Rebuild.' : ''));
			}
			return LogicResult::redirect($self_url);
		}

		// Out-and-back origin-leak probe (provider mode): send a marked message
		// from a hosted alias to itself; it returns via the relay MX and the
		// origin-leak check scans the delivered headers.
		if ($action === 'origin_probe') {
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailHealth.php'));
			$res = InboundEmailHealth::sendOriginProbe();
			$flash($res['message'], $res['ok'] ? 'Probe sent' : 'Probe not sent');
			return LogicResult::redirect($self_url);
		}

		if (($action === 'provision' || $action === 'rebuild') && $server_manager_active) {
			$result = admin_mailbox_relay_dispatch_job($action, $input, $session);
			$flash($result['message'], $result['title']);
			return LogicResult::redirect($self_url);
		}
	}

	// --- list view -----------------------------------------------------------
	$relays_multi = new MultiMailboxRelay(array('deleted' => false));
	$relays_multi->load();
	// The health battery (TCP probe + map build + DNS) resolves the ACTIVE relay
	// internally, so it is meaningful only for that row — run it once and attach
	// it there; other rows show their own row-level facts without health dots.
	$active = MailboxRelay::active();
	$active_health = ($active !== null) ? admin_mailbox_relay_health() : null;
	$relays = array();
	foreach ($relays_multi as $relay) {
		$is_active = ($active !== null && intval($relay->key) === intval($active->key));
		$relays[] = array(
			'model'   => $relay,
			'health'  => $is_active ? $active_health : null,
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

	$outbound_mode = (strtolower(trim((string)$settings->get_setting('mailbox_relay_outbound_mode'))) === 'smarthost')
		? 'smarthost' : 'provider';

	return LogicResult::render(array(
		'relays'                => $relays,
		'nodes'                 => $nodes,
		'server_manager_active' => $server_manager_active,
		'main_wg_public_key'    => (string)$settings->get_setting('mailbox_relay_wg_public_key'),
		'has_active_relay'      => ($active !== null),
		'outbound_mode'         => $outbound_mode,
		'session'               => $session,
		'settings'              => $settings,
	));
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
