<?php
/**
 * Mailbox - Hardened ingest relay admin (specs/mailbox_relay_fix_pack.md § Fix 10).
 *
 * Lists the deployment's relay(s) with status + the four provisioning checks, and
 * drives provisioning (a server_manager job), rebuild, enable/disable, and delete.
 * Guided controls only — no explainer prose; details live in the plugin docs.
 *
 * @version 1.1
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
