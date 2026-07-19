<?php
/**
 * Mailbox - the Setup tab's Relay section (tenant side).
 *
 * Renders the deployment's relay state and every action that moves it toward
 * fronting mail: the relay rows with health, the hosted-slot lifecycle
 * (enroll / refresh / release, ownership-proof state), and the
 * provision-your-own path. Configuration (the relay service connection and
 * outbound mode) lives on the Settings tab; this section points there when
 * the connection is missing. Vars come from admin_mailbox_relay_tenant_vars()
 * and actions post back to the Setup tab
 * (admin_mailbox_relay_tenant_actions()).
 *
 * @version 1.0
 */

/** A single-button action form (hidden inputs + submit only). */
function mailbox_relay_action_button(int $relay_id, string $action, string $label, string $cls = 'btn-secondary', string $confirm = ''): string {
	$onsubmit = $confirm !== '' ? ' onsubmit="return confirm(' . htmlspecialchars(json_encode($confirm), ENT_QUOTES) . ')"' : '';
	return '<form method="post" style="display:inline"' . $onsubmit . '>'
		. '<input type="hidden" name="mrl_mailbox_relay_id" value="' . $relay_id . '">'
		. '<input type="hidden" name="action" value="' . htmlspecialchars($action, ENT_QUOTES) . '">'
		. '<button type="submit" class="btn btn-sm ' . htmlspecialchars($cls, ENT_QUOTES) . '">' . htmlspecialchars($label) . '</button>'
		. '</form> ';
}

/** Echo the Relay section (one box, anchored #relay-section). */
function mailbox_relay_section_render($page, array $v): void {
	echo '<div id="relay-section">';
	$page->begin_box(array('title' => 'Relay'));

	// --- relay rows -----------------------------------------------------------
	if (empty($v['relays'])) {
		echo '<p>No relay yet. Get one below — a hosted relay slot, or one you run yourself.</p>';
	} else {
		echo '<table class="table"><thead><tr>'
			. '<th>Status</th><th>Tunnel host</th><th>Public IP</th><th>WireGuard key</th>'
			. '<th>Map</th><th>Last push</th><th>Last pull</th><th>Health</th><th>Actions</th>'
			. '</tr></thead><tbody>';
		foreach ($v['relays'] as $row) {
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
			echo mailbox_relay_action_button($rid, $enabled ? 'disable' : 'enable', $enabled ? 'Disable' : 'Enable');
			if (!empty($v['server_manager_active']) && !(bool)$relay->get('mrl_is_hosted')) {
				echo mailbox_relay_action_button($rid, 'rebuild', 'Rebuild', 'btn-warning');
			}
			echo mailbox_relay_action_button($rid, 'delete', 'Delete', 'btn-danger', 'Remove this relay?');
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	// Provider mode: an out-and-back probe proves sent mail carries no origin leak.
	if (!empty($v['has_active_relay']) && $v['outbound_mode'] !== 'smarthost') {
		echo '<form method="post" style="display:inline">';
		echo '<input type="hidden" name="action" value="origin_probe">';
		echo '<button type="submit" class="btn btn-sm btn-outline-secondary">Run origin-leak probe</button>';
		echo '</form>';
		echo ' <span class="text-muted small">Sends a marked message out through your provider and back via the '
			. 'relay MX; the origin-leak check then scans the delivered headers.</span>';
	}

	// --- hosted relay slot ----------------------------------------------------
	echo '<h5 class="mt-3">Hosted relay</h5>';
	if (empty($v['fleet_configured'])) {
		echo '<p>Rent a spot on a relay service instead of running your own. Add the service connection on the '
			. '<a href="/plugins/mailbox/admin/admin_mailbox_settings">Settings tab</a>, then enroll here.</p>';
	} elseif ($v['fleet_error'] !== '') {
		echo '<p class="text-danger">' . htmlspecialchars($v['fleet_error']) . '</p>';
	} elseif (is_array($v['fleet_status']) && empty($v['fleet_status']['enrolled'])) {
		if ($v['main_wg_public_key'] === '') {
			echo '<p>Before enrolling, give this box its tunnel identity. Run once as root:</p>'
				. '<pre><code>sudo bash plugins/mailbox/provisioning/provision_relay_main.sh</code></pre>';
		} else {
			echo mailbox_relay_action_button(0, 'fleet_enroll', 'Enroll for a hosted relay slot', 'btn-primary');
		}
	} elseif (is_array($v['fleet_status'])) {
		$coords = $v['fleet_status']['coordinates'] ?? array();
		echo '<p><strong>Slot:</strong> ' . htmlspecialchars((string)($coords['slug'] ?? ''))
			. ' — <strong>' . htmlspecialchars((string)($coords['status'] ?? '')) . '</strong></p>';
		echo '<p><strong>Point every hosted domain\'s MX at:</strong> '
			. PublicPageBase::copy_field((string)($coords['mx_hostname'] ?? '')) . '</p>';

		// Ownership proofs — read-only state. Challenges are filed and
		// re-verified automatically; each domain's checks above carry the
		// publishable record as a normal DNS row.
		$claims = is_array($v['fleet_status']['claims'] ?? null) ? $v['fleet_status']['claims'] : array();
		if (!empty($claims)) {
			echo '<h6>Ownership proofs</h6>';
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

		echo mailbox_relay_action_button(0, 'fleet_refresh', 'Refresh', 'btn-secondary');
		echo mailbox_relay_action_button(0, 'fleet_release', 'Release slot', 'btn-danger',
			'Release this hosted relay slot? Point your MX elsewhere first.');
	}

	// --- run your own ---------------------------------------------------------
	echo '<h5 class="mt-3">Run your own relay</h5>';
	if (empty($v['server_manager_active'])) {
		echo '<p>Provisioning through the dashboard needs the Server Manager plugin. '
			. 'Without it, run <code>provisioning/provision_relay.sh &lt;mail-hostname&gt;</code> on a fresh VPS by hand '
			. '(see the plugin docs), then add the relay row.</p>';
	} elseif ($v['main_wg_public_key'] === '') {
		echo '<p>The main box has no WireGuard identity yet — the relay tunnel cannot come up without it. Run once as root:</p>'
			. '<pre><code>sudo bash plugins/mailbox/provisioning/provision_relay_main.sh</code></pre>'
			. '<p>Then reload this page.</p>';
	} elseif (empty($v['nodes'])) {
		echo '<p>No managed nodes are available. Add a node in Server Manager first.</p>';
	} else {
		$formwriter = $page->getFormWriter('provision_relay');
		echo $formwriter->begin_form();
		$node_options = array();
		foreach ($v['nodes'] as $node) {
			$node_options[(string)$node->key] = $node->get('mgn_name') . ' (' . $node->get('mgn_host') . ')';
		}
		$formwriter->dropinput('mgn_managed_node_id', 'Managed node', array('options' => $node_options));
		$formwriter->textinput('mail_hostname', 'Mail hostname', array('placeholder' => 'mx.example.com'));
		$formwriter->hiddeninput('action', '', array('value' => 'provision'));
		$formwriter->submitbutton('btn_provision', 'Provision relay');
		echo $formwriter->end_form();
	}

	$page->end_box();
	echo '</div>';
}
?>
