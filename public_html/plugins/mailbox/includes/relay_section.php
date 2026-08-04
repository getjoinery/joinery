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
 * @version 2.0 - relay scanner health: last answer + Check spam scanning now
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
	// One plain line per relay — name (ip), status, health. The technical
	// facts and the lifecycle actions live behind its details disclosure.
	if (empty($v['relays'])) {
		echo mailbox_hosted_relay_offered()
			? '<p>No relay yet. Get one below — a hosted relay slot, or one you run yourself.</p>'
			: '<p>No relay yet. Set one up below on a server you control.</p>';
	} else {
		foreach ($v['relays'] as $row) {
			$relay = $row['model'];
			$enabled = (bool)$relay->get('mrl_is_enabled');
			$rid = (int)$relay->key;
			$name = (string)$relay->get('mrl_name') ?: (string)$relay->get('mrl_mx_hostname');

			// Health dots only for the active relay — the battery resolves the
			// active relay internally, so it would be misleading on any other row.
			$health_html = '';
			if (is_array($row['health'])) {
				foreach ($row['health'] as $h) {
					$dot = $h['ok'] ? '🟢' : '🔴';
					$health_html .= '<span style="margin-right:.75rem;white-space:nowrap;" title="'
						. htmlspecialchars($h['message'] !== '' ? $h['message'] : $h['label'], ENT_QUOTES) . '">'
						. $dot . ' ' . htmlspecialchars($h['label']) . '</span>';
				}
			}

			echo '<div style="margin-bottom:.75rem;">';
			echo '<div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">';
			echo '<strong>' . htmlspecialchars($name) . '</strong>'
				. '<span class="text-muted">(' . htmlspecialchars((string)$relay->get('mrl_public_ip')) . ')</span>'
				. ($enabled ? '<span class="badge badge-success">Enabled</span>'
					: '<span class="badge badge-secondary">Disabled</span>');
			echo '</div>';
			if ($health_html !== '') {
				echo '<div style="margin-top:.4rem;">' . $health_html . '</div>';
			}

			echo '<details style="margin-top:.4rem;"><summary>Details &amp; actions</summary>';
			echo '<div style="margin-top:.5rem;">';
			echo '<table class="table" style="max-width:560px;"><tbody>';
			echo '<tr><th style="width:45%;">Tunnel address (private)</th><td>' . htmlspecialchars((string)$relay->get('mrl_host')) . '</td></tr>';
			echo '<tr><th>WireGuard key</th><td><code>' . htmlspecialchars(substr((string)$relay->get('mrl_wg_public_key'), 0, 16)) . '…</code></td></tr>';
			echo '<tr><th>Address-list version</th><td>v' . (int)$relay->get('mrl_map_version') . '</td></tr>';
			echo '<tr><th>Last address-list push</th><td>' . (htmlspecialchars((string)$relay->get('mrl_last_push_time')) ?: '—') . '</td></tr>';
			echo '<tr><th>Last mail pull</th><td>' . (htmlspecialchars((string)$relay->get('mrl_last_pull_time')) ?: '—') . '</td></tr>';
			// Spam scanning is the one relay capability stored mail cannot confirm,
			// so the relay's own last answer is shown here as a fact about the relay.
			$scanner = $relay->lastHealth();
			echo '<tr><th>Spam scanning</th><td>'
				. ($scanner === null ? '<span class="text-muted">Not asked yet</span>'
					: htmlspecialchars((string)$scanner['detail'])
						. ' <span class="text-muted">(' . htmlspecialchars((string)$scanner['checked_time']) . ' UTC)</span>')
				. '</td></tr>';
			echo '</tbody></table>';
			echo mailbox_relay_action_button($rid, $enabled ? 'disable' : 'enable', $enabled ? 'Disable' : 'Enable');
			if (!empty($v['server_manager_active']) && !(bool)$relay->get('mrl_is_hosted')) {
				echo mailbox_relay_action_button($rid, 'rebuild', 'Rebuild', 'btn-warning');
			}
			$delete_confirm = ((string)$relay->get('mrl_cloud_instance_id') !== '')
				? 'Remove this relay from your mail setup? The server itself keeps running (and billing) at your cloud provider until you delete it there.'
				: 'Remove this relay?';
			echo mailbox_relay_action_button($rid, 'delete', 'Delete', 'btn-danger', $delete_confirm);
			echo '</div></details>';
			echo '</div>';
		}
	}

	// A relay that scans and finds nothing looks exactly like a relay whose scanner
	// is dead, so the only way to tell is to ask it.
	if (!empty($v['has_active_relay'])) {
		echo '<form method="post" style="display:inline">';
		echo '<input type="hidden" name="action" value="scanner_probe">';
		echo '<button type="submit" class="btn btn-sm btn-outline-secondary">Check spam scanning now</button>';
		echo '</form>';
		echo ' <span class="text-muted small">Asks the relay whether its spam scanner is running and whether '
			. 'its verdicts still reach this server.</span><br>';
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

	// --- hosted relay slot (gated off until the fleet launches) ---------------
	if (mailbox_hosted_relay_offered()) {
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
	} // hosted relay gate

	// --- run your own ---------------------------------------------------------
	// One relay per deployment: once a relay row exists, the get-one paths
	// disappear. The block still renders while a cloud act (provision retry,
	// destroy) is in flight — its credential/progress step lives here.
	$cloud_run_live = !empty($v['cloud_run']) && $v['cloud_run']->isLive();
	if (empty($v['relays']) || $cloud_run_live) {
	echo '<h5 class="mt-3">' . ($cloud_run_live && !empty($v['relays']) ? 'Relay cloud act' : 'Run your own relay') . '</h5>';
	if ($v['main_wg_public_key'] === '' || empty($v['pull_key_ready'])) {
		echo '<p>The main box has no relay identity yet — the tunnel and the mail pull cannot work without it. Run once as root:</p>'
			. '<pre><code>sudo bash plugins/mailbox/provisioning/provision_relay_main.sh</code></pre>'
			. '<p>Then reload this page.</p>';
	} else {
		// The cloud path: this deployment creates and builds the relay in the
		// customer's own cloud account (specs/mailbox_relay_cloud_provisioning.md).
		$run = $v['cloud_run'] ?? null;
		$run_status = $run ? (string)$run->get('rcp_status') : '';
		$status_lines = array(
			'ready'          => 'Creating the server in your account…',
			'booting'        => 'Server created in your account — waiting for it to boot…',
			'provisioning'   => 'Building the relay on your new server (this takes several minutes)…',
		);

		if ($run !== null && $run->isLive()) {
			if ($run_status === 'awaiting_grant') {
				// The just-in-time credential step — the only moment Linode
				// comes up, and the credential dies with this one act.
				$act_label = 'One approval needed to create the server';
				$referral = '<p>No Linode account yet? '
					. '<a href="https://www.linode.com/lp/refer/?r=f89d0c9308eeef26368cc67356eb8fa81365d488" '
					. 'target="_blank" rel="noopener">Sign up with this link</a> to receive two months of hosting free.</p>';
				echo '<div style="max-width:700px;">';
				if (!empty($v['cloud_oauth_configured'])) {
					// One-click branch: a registered Linode OAuth client exists.
					echo '<p><strong>' . htmlspecialchars($act_label) . ':</strong> approve the connection at Linode. '
						. 'The approval is used for this one job and never kept.</p>';
					echo $referral;
					echo mailbox_relay_action_button(0, 'relay_cloud_connect', 'Approve at Linode', 'btn-primary');
					echo mailbox_relay_action_button(0, 'relay_cloud_dismiss', 'Cancel', 'btn-secondary');
					echo '<details style="margin-top:.75rem;"><summary>Use another method (paste an API token)</summary><div style="margin-top:.75rem;">';
				} else {
					echo '<p><strong>' . htmlspecialchars($act_label) . ':</strong> a one-time key from Linode.</p>';
					echo $referral;
					echo '<p style="margin-bottom:.5rem;">How to get the key:</p>';
				}
				echo '<ol style="margin:0 0 1rem 1.5rem;padding:0;list-style:decimal;">'
					. '<li style="margin-bottom:.5rem;">Open <a href="https://cloud.linode.com/profile/tokens" target="_blank" rel="noopener">'
					. 'cloud.linode.com/profile/tokens</a> (sign in to your Linode account if asked).</li>'
					. '<li style="margin-bottom:.5rem;">Click <strong>Create a Personal Access Token</strong>.</li>'
					. '<li style="margin-bottom:.5rem;"><strong>Label:</strong> anything — for example, joinery relay.</li>'
					. '<li style="margin-bottom:.5rem;"><strong>Expiry:</strong> the shortest option in the list.</li>'
					. '<li style="margin-bottom:.5rem;"><strong>Access:</strong> set every row to <strong>No Access</strong>, except '
					. '<strong>Linodes</strong> — set that one to <strong>Read/Write</strong>.</li>'
					. '<li style="margin-bottom:.5rem;">Click <strong>Create Token</strong>, then copy the token it shows '
					. '(Linode shows it only once).</li>'
					. '<li style="margin-bottom:0;">Paste it below and press Start.</li>'
					. '</ol>';
				$tform = $page->getFormWriter('relay_cloud_token');
				echo $tform->begin_form();
				$tform->passwordinput('cloud_token', 'Linode API token', array());
				// Two named submits in one form so Start and Cancel sit side by
				// side (a dismiss needs no separate form).
				echo '<div style="display:flex;gap:.5rem;align-items:center;margin-top:.5rem;">'
					. '<button type="submit" name="action" value="relay_cloud_token" class="btn btn-primary">Start</button>'
					. '<button type="submit" name="action" value="relay_cloud_dismiss" formnovalidate class="btn btn-secondary">Cancel</button>'
					. '</div>';
				echo $tform->end_form();
				echo '<p class="text-muted" style="margin-top:.75rem;">The key is used for this one job and never kept. '
					. 'You can also delete it at Linode afterward.</p>';
				if (!empty($v['cloud_oauth_configured'])) {
					echo '</div></details>';
				}
				echo '</div>';
			} else {
				echo '<p>⏳ ' . htmlspecialchars($status_lines[$run_status] ?? $run_status)
					. ' <a href="">Refresh</a></p>';
				if ((string)$run->get('rcp_error') !== '') {
					echo '<p class="text-muted small">' . htmlspecialchars((string)$run->get('rcp_error')) . '</p>';
				}
			}
		} else {
			if ($run !== null && $run_status === 'failed') {
				echo '<p class="text-danger">' . htmlspecialchars((string)$run->get('rcp_error')) . '</p>';
				echo mailbox_relay_action_button(0, 'relay_cloud_dismiss', 'Dismiss', 'btn-secondary');
			}

			$cform = $page->getFormWriter('relay_cloud');
			echo $cform->begin_form();
			$cform->hiddeninput('action', '', array('value' => 'relay_cloud_begin'));
			$cform->textinput('cloud_mail_hostname', 'Mail hostname', array(
				'placeholder' => 'mx.example.com',
				'helptext'    => 'The DNS name your domains\' mail will be addressed to. Pick a name in a zone you control.',
			));
			$cform->dropinput('cloud_region', 'Region', array(
				'value'   => 'us-southeast',
				'options' => array(
					'us-southeast' => 'Atlanta, GA (US)',
					'us-east'      => 'Newark, NJ (US)',
					'us-central'   => 'Dallas, TX (US)',
					'us-west'      => 'Fremont, CA (US)',
					'us-sea'       => 'Seattle, WA (US)',
					'us-mia'       => 'Miami, FL (US)',
					'ca-central'   => 'Toronto (Canada)',
					'eu-west'      => 'London (UK)',
					'eu-central'   => 'Frankfurt (Germany)',
					'nl-ams'       => 'Amsterdam (Netherlands)',
					'fr-par'       => 'Paris (France)',
					'ap-south'     => 'Singapore',
					'ap-northeast' => 'Tokyo (Japan)',
					'ap-southeast' => 'Sydney (Australia)',
					'br-gru'       => 'São Paulo (Brazil)',
				),
			));
			// Instance type is fixed to the 1 GB Nanode for now — a relay idles,
			// and Linode's own interface can resize it later if ever needed.
			$cform->submitbutton('btn_relay_cloud', 'Provision into my Linode account');
			echo $cform->end_form();
			echo '<p class="text-muted small">Creates one small instance (1 GB Nanode) in your Linode account, '
				. 'billed to you, and builds the relay on it automatically. It can be resized later at Linode if ever needed.</p>';
		}

		// Operator convenience: provision onto an existing managed node.
		if (empty($v['relays']) && !$cloud_run_live
				&& !empty($v['server_manager_active']) && !empty($v['nodes'])) {
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

		// The standalone floor: any VPS, by hand.
		if (empty($v['relays']) && !$cloud_run_live) {
			echo '<p class="text-muted small">Or by hand on any fresh VPS: run '
				. '<code>provisioning/provision_relay.sh &lt;mail-hostname&gt;</code> as root (see the plugin docs).</p>';
		}
	}
	} // get-a-relay gate (one relay per deployment)

	$page->end_box();

	// The "Local mail listener" box (specs/mailbox_listener_decommission.md):
	// present whenever a live relay row exists — or a decommission is recorded,
	// so the Restore path never strands.
	if (!empty($v['listener'])) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/listener_admin.php'));
		mailbox_listener_box_render($page, $v['listener']);
	}

	echo '</div>';
}
?>
