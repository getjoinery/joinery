<?php
/**
 * Mailbox - Hardened ingest relay admin (specs/mailbox_relay_fix_pack.md § Fix 10).
 *
 * Lists the deployment's relay(s) with status + the four provisioning checks, and
 * drives provisioning (a server_manager job), rebuild, enable/disable, and delete.
 * Also the hosted-fleet surfaces (specs/mailbox_relay_shared_fleet.md): the
 * tenant-side slot (enroll, ownership-proof state, release) and — on the
 * operator's deployment — fleet shard registration plus the DNS-to-publish
 * table. Ownership proofs are read-only state here: challenges are filed and
 * verified automatically, and the Setup tab carries the publishable record.
 * Guided controls only — no explainer prose; details live in the plugin docs.
 *
 * @version 1.4
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_relay_logic.php'));

$page_vars = process_logic(admin_mailbox_relay_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'incoming',
	'breadcrumbs' => array(
		'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox',
		'Relay' => '',
	),
	'session' => $session,
));

echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Relay');

// --- existing relays ---------------------------------------------------------
$page->begin_box(array('title' => 'Relays'));

if (empty($relays)) {
	echo '<p>No relay is configured. This deployment is colocated (the MTA runs on the main box).</p>';
} else {
	echo '<table class="table"><thead><tr>'
		. '<th>Status</th><th>Tunnel host</th><th>Public IP</th><th>WireGuard key</th>'
		. '<th>Map</th><th>Last push</th><th>Last pull</th><th>Health</th><th>Actions</th>'
		. '</tr></thead><tbody>';
	foreach ($relays as $row) {
		$relay = $row['model'];
		$enabled = (bool)$relay->get('mrl_is_enabled');
		$rid = (int)$relay->key;

		// Health dots only for the active relay — the battery resolves the active
		// relay internally, so it would be misleading on any other row.
		$health_html = '—';
		if (is_array($row['health'])) {
			$health_html = '';
			foreach ($row['health'] as $h) {
				$dot = $h['ok'] ? '🟢' : '🔴';
				$health_html .= '<div title="' . htmlspecialchars($h['message'], ENT_QUOTES) . '">'
					. $dot . ' ' . htmlspecialchars($h['label']) . '</div>';
			}
		}

		echo '<tr>';
		echo '<td>' . ($enabled ? '<span class="badge badge-success">Enabled</span>' : '<span class="badge badge-secondary">Disabled</span>') . '</td>';
		echo '<td>' . htmlspecialchars((string)$relay->get('mrl_host')) . '</td>';
		echo '<td>' . htmlspecialchars((string)$relay->get('mrl_public_ip')) . '</td>';
		echo '<td><code>' . htmlspecialchars(substr((string)$relay->get('mrl_wg_public_key'), 0, 16)) . '…</code></td>';
		echo '<td>v' . (int)$relay->get('mrl_map_version') . '</td>';
		echo '<td>' . htmlspecialchars((string)$relay->get('mrl_last_push_time')) . '</td>';
		echo '<td>' . htmlspecialchars((string)$relay->get('mrl_last_pull_time')) . '</td>';
		echo '<td>' . $health_html . '</td>';

		echo '<td>';
		// Single-button action forms (hidden inputs + submit only) — allowed without FormWriter.
		echo relay_action_button($rid, $enabled ? 'disable' : 'enable', $enabled ? 'Disable' : 'Enable');
		if ($server_manager_active) {
			echo relay_action_button($rid, 'rebuild', 'Rebuild', 'btn-warning');
		}
		echo relay_action_button($rid, 'delete', 'Delete', 'btn-danger', 'Remove this relay?');
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
}

$page->end_box();

// --- outbound: where sent mail leaves from -----------------------------------
// Only meaningful once a relay fronts the deployment; colocated deployments never
// had a hidden origin to protect (specs/mailbox_relay_inbound_only.md).
if (!empty($has_active_relay)) {
	$page->begin_box(array('title' => 'Outbound sending'));

	require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));
	$oform = $page->getFormWriter('outbound_mode');
	echo $oform->begin_form();
	$oform->hiddeninput('action', '', array('value' => 'set_outbound_mode'));

	$is_smarthost = ($outbound_mode === 'smarthost');
	$oform->dropinput('mailbox_relay_outbound_mode', 'Sent mail leaves through:', array(
		'value'   => $outbound_mode,
		'options' => array(
			'provider'  => 'Your email provider (recommended)',
			'smarthost' => 'The relay (advanced)',
		),
		'visibility_rules' => array(
			'provider'  => array('show' => array('provider_note'),  'hide' => array('smarthost_note')),
			'smarthost' => array('show' => array('smarthost_note'), 'hide' => array('provider_note')),
		),
	));

	// One consequence line per option, shown one-at-a-time by the select above.
	// Server-set initial display avoids a flash before the toggle script runs.
	echo '<p class="text-muted small" id="provider_note" style="display:' . ($is_smarthost ? 'none' : '') . '">'
		. 'Deliverability is your provider\'s job, and it carries the message in transit. '
		. 'The sent message\'s Received chain begins inside the provider, so this server\'s address stays hidden.</p>';
	echo '<p class="text-muted small" id="smarthost_note" style="display:' . ($is_smarthost ? '' : 'none') . '">'
		. 'No third party carries sent mail — it leaves through the relay over the tunnel. In exchange this '
		. 'deployment owns the relay IP\'s sending reputation: warmup, blocklist monitoring, and PTR hygiene.</p>';

	$oform->submitbutton('btn_outbound', 'Save');
	echo $oform->end_form();

	// Provider mode: an out-and-back probe proves sent mail carries no origin leak.
	if (!$is_smarthost) {
		echo '<hr>';
		echo '<form method="post" style="display:inline">';
		echo '<input type="hidden" name="action" value="origin_probe">';
		echo '<button type="submit" class="btn btn-sm btn-outline-secondary">Run origin-leak probe</button>';
		echo '</form>';
		echo ' <span class="text-muted small">Sends a marked message out through your provider and back via the '
			. 'relay MX; the origin-leak check then scans the delivered headers.</span>';
	}

	$page->end_box();
}

// --- hosted relay (fleet slot) -------------------------------------------------
// The zero-infrastructure alternative to provisioning your own relay
// (specs/mailbox_relay_shared_fleet.md): enroll with the operator's fleet,
// point MX at the returned hostname, and the same pull/push consumers run.
$page->begin_box(array('title' => 'Hosted relay (fleet)'));

require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));
$fform = $page->getFormWriter('fleet_config');
echo $fform->begin_form();
$fform->hiddeninput('action', '', array('value' => 'fleet_config'));
$fform->textinput('mailbox_fleet_service_url', 'Fleet service URL', array(
	'value' => $fleet_service_url, 'placeholder' => 'https://getjoinery.com'));
$fform->textinput('mailbox_fleet_api_public_key', 'API public key', array(
	'value' => $fleet_api_public_key));
$fform->passwordinput('mailbox_fleet_api_secret_key', 'API secret key', array(
	'placeholder' => $fleet_secret_set ? '(stored — leave blank to keep)' : ''));
$fform->submitbutton('btn_fleet_config', 'Save connection');
echo $fform->end_form();

if ($fleet_configured) {
	echo '<hr>';
	if ($fleet_error !== '') {
		echo '<p class="text-danger">' . htmlspecialchars($fleet_error) . '</p>';
	} elseif (is_array($fleet_status) && empty($fleet_status['enrolled'])) {
		if ($main_wg_public_key === '') {
			echo '<p>Before enrolling, give this box its tunnel identity. Run once as root:</p>'
				. '<pre><code>sudo bash plugins/mailbox/provisioning/provision_relay_main.sh</code></pre>';
		} else {
			echo relay_action_button(0, 'fleet_enroll', 'Enroll for a hosted relay slot', 'btn-primary');
		}
	} elseif (is_array($fleet_status)) {
		$coords = $fleet_status['coordinates'] ?? array();
		echo '<p><strong>Slot:</strong> ' . htmlspecialchars((string)($coords['slug'] ?? ''))
			. ' — <strong>' . htmlspecialchars((string)($coords['status'] ?? '')) . '</strong></p>';
		echo '<p><strong>Point every hosted domain\'s MX at:</strong> '
			. PublicPageBase::copy_field((string)($coords['mx_hostname'] ?? '')) . '</p>';

		// Ownership proofs — read-only state. Challenges are filed and
		// re-verified automatically; each domain's Setup tab carries the
		// publishable record as a normal DNS row.
		$claims = is_array($fleet_status['claims'] ?? null) ? $fleet_status['claims'] : array();
		if (!empty($claims)) {
			echo '<h5>Ownership proofs</h5>';
			echo '<table class="table"><thead><tr>'
				. '<th>Domain</th><th>Status</th><th>TXT record</th>'
				. '</tr></thead><tbody>';
			foreach ($claims as $claim) {
				$proven = ((string)$claim['status'] === 'verified');
				echo '<tr>';
				echo '<td>' . htmlspecialchars((string)$claim['domain']) . '</td>';
				echo '<td>' . ($proven
					? '<span class="badge badge-success">Proven</span>'
					: '<span class="badge badge-secondary">Awaiting DNS record</span>') . '</td>';
				echo '<td>' . ($proven ? '—'
					: '<code>' . htmlspecialchars((string)$claim['txt_host']) . '</code> = '
						. PublicPageBase::copy_field((string)$claim['txt_value'])) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo relay_action_button(0, 'fleet_refresh', 'Refresh', 'btn-secondary');
		echo relay_action_button(0, 'fleet_release', 'Release slot', 'btn-danger',
			'Release this hosted relay slot? Point your MX elsewhere first.');
	}
}

$page->end_box();

// --- hosted fleet (operator side) ----------------------------------------------
// The operator needs server_manager to run shards, so the box only appears where
// operating a fleet is possible; on tenant-only deployments it stays hidden.
if ($server_manager_active) {
	$page->begin_box(array('title' => 'Hosted fleet (operator)'));

	$oform = $page->getFormWriter('fleet_service_config');
	echo $oform->begin_form();
	$oform->hiddeninput('action', '', array('value' => 'fleet_service_config'));
	$oform->checkboxinput('mailbox_fleet_service_enabled', 'Run a hosted relay fleet other deployments can enroll in', array(
		'checked' => !empty($fleet_service_on),
	));
	$oform->textinput('mailbox_fleet_mx_zone', 'Fleet MX zone', array(
		'value'       => (string)($fleet_mx_zone ?? ''),
		'placeholder' => 'mx.example.com',
		'helptext'    => 'A DNS zone you control. Each tenant\'s MX hostname is <slug>.<zone> (slug format t<id>), published by you as an A record pointing at its shard.',
	));
	$oform->submitbutton('btn_fleet_service', 'Save');
	echo $oform->end_form();

	if (!empty($fleet_service_on) && !empty($fleet_shards)) {
		echo '<table class="table"><thead><tr>'
			. '<th>Shard</th><th>Hostname</th><th>Public IP</th><th>Tenants</th><th>Active</th>'
			. '</tr></thead><tbody>';
		foreach ($fleet_shards as $row) {
			$shard = $row['model'];
			echo '<tr>';
			echo '<td>' . htmlspecialchars((string)$shard->get('mfs_name')) . '</td>';
			echo '<td>' . htmlspecialchars((string)$shard->get('mfs_hostname')) . '</td>';
			echo '<td>' . htmlspecialchars((string)$shard->get('mfs_public_ip')) . '</td>';
			echo '<td>' . intval($row['slots']) . ' / ' . intval($shard->get('mfs_capacity')) . '</td>';
			echo '<td>' . ((bool)$shard->get('mfs_is_active') ? 'Yes' : 'No') . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		// DNS to publish: the operator's half of the tenants' MX guidance —
		// every record the fleet zone needs, with a live resolution verdict.
		echo '<h5>DNS to publish</h5>';
		echo '<table class="table"><thead><tr>'
			. '<th></th><th>Type</th><th>Name</th><th>Value</th><th>Currently</th>'
			. '</tr></thead><tbody>';
		$dns_dots = array('ok' => '🟢', 'wrong' => '🔴', 'missing' => '🔴', 'unknown' => '⚪');
		foreach ($fleet_shards as $row) {
			foreach ($row['dns'] as $dns) {
				echo '<tr>';
				echo '<td>' . ($dns_dots[$dns['state']] ?? '⚪') . '</td>';
				echo '<td>' . htmlspecialchars($dns['kind']) . '</td>';
				echo '<td>' . PublicPageBase::copy_field($dns['name']) . '</td>';
				echo '<td>' . PublicPageBase::copy_field($dns['value']) . '</td>';
				echo '<td>' . ($dns['state'] === 'ok' ? 'published'
					: ($dns['state'] === 'missing' ? 'no record'
					: ($dns['state'] === 'unknown' ? 'lookup failed'
					: htmlspecialchars($dns['found'])))) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';
		echo '<p class="text-muted small">PTR records are set where the shard\'s IP is hosted, not in the DNS zone.</p>';
	}

	if (!empty($fleet_service_on) && !empty($nodes)) {
		$sform = $page->getFormWriter('provision_shard');
		echo $sform->begin_form();
		$sform->hiddeninput('action', '', array('value' => 'provision_shard'));
		$shard_node_options = array();
		foreach ($nodes as $node) {
			$shard_node_options[(string)$node->key] = $node->get('mgn_name') . ' (' . $node->get('mgn_host') . ')';
		}
		$sform->dropinput('shard_node_id', 'Managed node', array('options' => $shard_node_options));
		$sform->textinput('shard_hostname', 'Shard mail hostname', array('placeholder' => 'shard1.mx.example.com'));
		$sform->textinput('shard_capacity', 'Capacity (tenants)', array('value' => '25'));
		$sform->submitbutton('btn_provision_shard', 'Provision shard');
		echo $sform->end_form();
	}

	$page->end_box();
}

// --- provision a new relay ---------------------------------------------------
$page->begin_box(array('title' => 'Provision a relay'));

if (!$server_manager_active) {
	echo '<p>Provisioning through the dashboard needs the Server Manager plugin. '
		. 'Without it, run <code>provisioning/provision_relay.sh &lt;mail-hostname&gt;</code> on a fresh VPS by hand '
		. '(see the plugin docs), then add the relay row.</p>';
} elseif ($main_wg_public_key === '') {
	echo '<p>The main box has no WireGuard identity yet — the relay tunnel cannot come up without it. Run once as root:</p>'
		. '<pre><code>sudo bash plugins/mailbox/provisioning/provision_relay_main.sh</code></pre>'
		. '<p>Then reload this page.</p>';
} elseif (empty($nodes)) {
	echo '<p>No managed nodes are available. Add a node in Server Manager first.</p>';
} else {
	require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));
	$formwriter = $page->getFormWriter('provision_relay');
	echo $formwriter->begin_form();

	$node_options = array();
	foreach ($nodes as $node) {
		$node_options[(string)$node->key] = $node->get('mgn_name') . ' (' . $node->get('mgn_host') . ')';
	}
	$formwriter->dropinput('mgn_managed_node_id', 'Managed node', array('options' => $node_options));
	$formwriter->textinput('mail_hostname', 'Mail hostname', array('placeholder' => 'mx.example.com'));
	$formwriter->hiddeninput('action', '', array('value' => 'provision'));
	$formwriter->submitbutton('btn_provision', 'Provision relay');

	echo $formwriter->end_form();
}

$page->end_box();

$page->admin_footer();

/** A single-button action form (hidden inputs + submit only). */
function relay_action_button(int $relay_id, string $action, string $label, string $cls = 'btn-secondary', string $confirm = ''): string {
	$onsubmit = $confirm !== '' ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm), ENT_QUOTES) . ')"' : '';
	return '<form method="post" style="display:inline"' . $onsubmit . '>'
		. '<input type="hidden" name="mrl_mailbox_relay_id" value="' . $relay_id . '">'
		. '<input type="hidden" name="action" value="' . htmlspecialchars($action, ENT_QUOTES) . '">'
		. '<button type="submit" class="btn btn-sm ' . htmlspecialchars($cls, ENT_QUOTES) . '">' . htmlspecialchars($label) . '</button>'
		. '</form> ';
}
?>
