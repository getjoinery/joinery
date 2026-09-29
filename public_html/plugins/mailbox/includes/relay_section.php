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
 * @version 2.5 - the Linode step (Approve at Linode, or a pasted token) is in the system modal
 *                with the update or the create, so neither starts without it
 * @version 2.4 - no relay-or-direct table: Disable relay / Enable relay, in plain view, decide
 *                it; every confirm here (update, disable, enable, delete) is the system modal
 * @version 2.3 - the section says what the relay is in one sentence, its health in one line
 *                (or the list of what is not healthy), shows its update in plain sight, has one
 *                Check Relay Health button, and carries the relay-or-direct choice that the
 *                "How mail reaches this server" box used to
 * @version 2.2 - a pending health dot renders amber: unmet but converging is
 *                a wait, not a fault
 * @version 2.1 - relay version line and the upgrade affordance, which differs by
 *                what the platform can reach (job / cloud wipe / the customer's
 *                own box / operator-managed shard)
 * @version 2.2 - the ssh era is over: no job form, no Rebuild, no outbound mode
 * @version 2.1 - a relay without a shell (specs/relay_without_a_shell.md): identity pin and
 *                last-ping class in place of tunnel rows, the whole ping behind a disclosure,
 *                Update wording, a Delete confirm that names the machine and the MX, the
 *                no-relay notice with both ways out, and no tunnel-identity gates
 * @version 2.0 - relay scanner health: last answer + Check spam scanning now
 */

/**
 * A single-button action form (hidden inputs + submit only). A confirm is
 * asked in the system modal (JoineryModal, through PublicPageBase::action_button),
 * with $confirm_label on its confirm button.
 */
function mailbox_relay_action_button(int $relay_id, string $action, string $label, string $cls = 'btn-secondary',
		string $confirm = '', string $confirm_label = '', array $extra_hidden = array()): string {
	$options = array(
		'hidden' => array_merge(array('mrl_mailbox_relay_id' => $relay_id, 'action' => $action), $extra_hidden),
		'class'  => 'btn btn-sm ' . $cls,
	);
	if ($confirm !== '') {
		$options['confirm'] = $confirm;
		$options['confirm_label'] = $confirm_label !== '' ? $confirm_label : $label;
		$options['confirm_style'] = ($cls === 'btn-danger') ? 'danger' : 'primary';
	}
	return PublicPageBase::action_button($label, '', $options) . ' ';
}

/** How to make a one-time Linode token: the numbered steps, the same everywhere they are asked for. */
function mailbox_relay_token_steps(): string {
	return '<ol style="margin:0 0 1rem 1.5rem;padding:0;list-style:decimal;">'
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
}

/**
 * The Linode step in the system modal, for a relay update or a relay create:
 * what is about to happen ($intro_html), then the one-time permission — Approve
 * at Linode when a Linode OAuth client is configured, a pasted token otherwise
 * (or as the other method) — posted with the act itself (grant=oauth|token,
 * admin_mailbox_relay_take_grant), so nothing starts until the permission comes
 * with it. Rendered hidden; a [data-relay-grant] button moves it into
 * JoineryModal (mailbox_relay_grant_script). $copy names fields the modal copies
 * from another form on the page when it opens (the create path's hostname and
 * region).
 */
function mailbox_relay_grant_modal($page, string $id, string $intro_html, array $hidden, bool $oauth,
		string $start_label, array $copy = array()): string {
	$form = $page->getFormWriter($id . '_form');
	ob_start();
	echo $form->begin_form();
	foreach ($hidden as $name => $value) {
		$form->hiddeninput($name, '', array('value' => (string)$value));
	}
	foreach ($copy as $name) {
		echo '<input type="hidden" name="' . htmlspecialchars($name, ENT_QUOTES) . '" data-copy="'
			. htmlspecialchars($name, ENT_QUOTES) . '">';
	}
	if ($oauth) {
		echo '<p>' . 'Approve the connection at Linode. The approval is used for this one job and never kept.' . '</p>';
		echo '<button type="submit" name="grant" value="oauth" class="btn btn-primary">Approve at Linode</button>';
		echo '<details style="margin-top:.75rem;"><summary>Use another method (paste an API token)</summary><div style="margin-top:.75rem;">';
	} else {
		echo '<p><strong>One approval needed:</strong> a one-time key from Linode.</p>';
	}
	echo mailbox_relay_token_steps();
	$form->passwordinput('cloud_token', 'Linode API token', array());
	echo '<button type="submit" name="grant" value="token" class="btn btn-primary" style="margin-top:.5rem;">'
		. htmlspecialchars($start_label) . '</button>';
	echo '<p class="text-muted small" style="margin-top:.75rem;">The key is used for this one job and never kept. '
		. 'You can also delete it at Linode afterward.</p>';
	if ($oauth) {
		echo '</div></details>';
	}
	echo $form->end_form();
	$form_html = ob_get_clean();
	return '<div hidden id="' . htmlspecialchars($id, ENT_QUOTES) . '"><div style="max-width:640px;">'
		. $intro_html . $form_html . '</div></div>' . mailbox_relay_grant_script();
}

/** The one script behind every [data-relay-grant] button: open its hidden step in the system modal. */
function mailbox_relay_grant_script(): string {
	static $done = false;
	if ($done) {
		return '';
	}
	$done = true;
	return <<<'JS'
<script>
document.addEventListener('click', function (e) {
	var b = e.target.closest ? e.target.closest('[data-relay-grant]') : null;
	if (!b || !window.JoineryModal) return;
	e.preventDefault();
	var holder = document.getElementById(b.getAttribute('data-relay-grant'));
	if (!holder || !holder.firstElementChild) return;
	var node = holder.firstElementChild;
	var from = b.getAttribute('data-copy-from');
	var src = from ? document.getElementById(from) : null;
	if (src) {
		node.querySelectorAll('input[data-copy]').forEach(function (i) {
			var f = src.querySelector('[name="' + i.getAttribute('data-copy') + '"]');
			if (f) i.value = f.value;
		});
	}
	var m = JoineryModal.open(node, { buttons: [{ label: 'Cancel', style: 'secondary' }] });
	// Back into its holder when the modal closes, so it opens again.
	m.dialog.addEventListener('close', function () { holder.appendChild(node); }, { once: true });
});
</script>
JS;
}

/**
 * The upgrade affordance for one relay, which differs by what the platform can
 * reach — a button where it can act, a sentence where only the customer can.
 *
 * Every route states the downtime before the customer commits. A relay stops
 * accepting mail for the whole rebuild, and while SMTP senders retry for days
 * (so nothing is lost), "your mail server will be offline for several minutes"
 * is a fact somebody may want to act on at 2pm on a Tuesday.
 */
function mailbox_relay_upgrade_control($page, int $relay_id, array $up, bool $oauth = false): string {
	$route = (string)($up['route'] ?? '');

	if ($route === 'hosted') {
		// A tenant cannot wipe a shard they share with strangers, and should not
		// be offered a control implying otherwise. No request button either: a
		// request the operator has no console to see is a message into nothing.
		return '<p class="text-muted small" style="margin-top:.5rem;">This relay is run and upgraded by '
			. 'the relay service operator.</p>';
	}

	if ($route === 'manual') {
		// This customer built the box, so they are the one who can act on it —
		// and unlike a cloud relay, they demonstrably have a way in.
		return '<p class="small" style="margin-top:.5rem;">This relay was set up by hand, so this site '
			. 'cannot update it for you: rebuild the machine from a fresh image and run '
			. '<code>provision_relay.sh</code> on it again.</p>';
	}

	// A relay that says outright it serves other deployments is never offered a
	// wipe: the rebuild would destroy their mail and their configuration, and the
	// drain only empties this tenant's own spool.
	$sole = $up['sole'] ?? null;
	if ($sole === false) {
		return '<p class="text-danger small" style="margin-top:.5rem;">This relay serves other '
			. 'deployments as well as this one, so it cannot be rebuilt from here — doing so would '
			. 'destroy their mail and their configuration.</p>';
	}

	// Cloud: the platform can do this, with the customer's one-time approval.
	$queue = $up['queue'] ?? null;
	$warn = '';
	if ($queue !== null && intval($queue) > 0) {
		// Mail Postfix accepted but has not handed to the sealer yet. The drain
		// cannot reach it — the tenant credential cannot read the Postfix queue —
		// so a rebuild destroys it. Stated, not blocked: it is the customer's call.
		$warn = ' <span class="text-danger">' . intval($queue) . ' message(s) are still queued on the '
			. 'relay and would be lost.</span>';
	}

	$confirm = 'Update this relay now? It is drained of stored mail first, then the same server is re-imaged '
		. 'from this site\'s current release and is born again. It stops accepting mail for several minutes — '
		. 'senders retry, so nothing bounces. Its address does not change.';
	$ack = false;
	if ($sole === null) {
		// Too old to answer. The platform will not decide this on the customer's
		// behalf, and will not proceed silently either.
		$confirm = 'This relay is too old to report whether other deployments share it. If it does, '
			. 'rebuilding destroys their mail and their configuration. Continue only if this relay '
			. 'serves this site alone. ' . $confirm;
		$ack = true;
		$warn .= ' <span class="text-danger">This relay cannot report whether others share it — '
			. 'confirm it serves only this site.</span>';
	}

	$hidden = array('mrl_mailbox_relay_id' => $relay_id, 'action' => 'relay_upgrade');
	if ($ack) {
		$hidden['shared_ack'] = '1';
	}
	$modal_id = 'relay-upgrade-' . $relay_id;
	return '<div style="margin-top:.5rem;">'
		. '<button type="button" class="btn btn-sm btn-warning" data-relay-grant="' . $modal_id . '">Update relay</button> '
		. mailbox_relay_grant_modal($page, $modal_id, '<h5>Update this relay</h5><p>' . htmlspecialchars($confirm) . '</p>'
			. ($warn !== '' ? '<p>' . $warn . '</p>' : ''), $hidden, $oauth, 'Start the update')
		. '<span class="text-muted small">Drains the relay, then re-images the same server from this site\'s '
		. 'current release. It stops accepting mail for several minutes; senders retry and its address does not '
		. 'change.' . $warn . '</span></div>';
}

/**
 * What a relay row means, in one sentence: mail arrives there and this server
 * collects it, so this server's address stays out of public DNS.
 */
function mailbox_relay_summary(bool $enabled): string {
	return $enabled
		? 'Mail for your domains arrives at this relay first, and this server collects it from there, so this '
			. 'server\'s address never appears in public DNS.'
		: 'This relay is switched off: it does not receive mail for your domains until you enable it.';
}

/**
 * The relay's health in one line: healthy, or the list of what is not. The
 * battery's checks, plus the relay's own last answer about its spam scanner
 * when spam filtering is on. Amber is a wait (converging on the next tick),
 * red a fault.
 */
function mailbox_relay_health_html(array $battery, $relay): string {
	$dot = function (string $color): string {
		return '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . $color
			. ';margin-right:6px;vertical-align:middle;"></span>';
	};
	$issues = array();
	foreach ($battery as $h) {
		if ($h['ok'] && empty($h['pending'])) {
			continue;
		}
		$issues[] = array(!empty($h['pending']) ? $dot('#ffc107') : $dot('#dc3545'), (string)$h['label'], (string)$h['message']);
	}
	if (Globalvars::get_instance()->get_setting('mailbox_spam_filtering_enabled')) {
		$scanner = $relay->lastHealth();
		if ($scanner !== null && ($scanner['state'] ?? '') !== MailboxRelay::HEALTH_OK) {
			$issues[] = array($dot('#dc3545'), 'Spam scanning', (string)($scanner['detail'] ?? ''));
		}
	}
	if (!$issues) {
		return '<p class="mb-2">' . $dot('#28a745') . 'The relay is healthy.</p>';
	}
	$h = '<p class="mb-1">' . (count($issues) === 1 ? 'One thing needs attention:' : count($issues) . ' things need attention:') . '</p>'
		. '<ul class="mb-2" style="list-style:none;padding-left:.25rem;">';
	foreach ($issues as $i) {
		$h .= '<li>' . $i[0] . '<strong>' . htmlspecialchars($i[1]) . '</strong>'
			. ($i[2] !== '' ? ' — ' . htmlspecialchars($i[2]) : '') . '</li>';
	}
	return $h . '</ul>';
}

/** What disabling the relay does, said before it happens. */
function mailbox_relay_disable_message($relay): string {
	$name = (string)$relay->get('mrl_name') ?: (string)$relay->get('mrl_mx_hostname');
	return 'Stop using the relay ' . $name . '? This server stops collecting mail from it and receives mail '
		. 'directly instead. Your domains\' MX records still point at the relay, so until you point them at this '
		. 'server (the DNS checks will show what to change) and turn this server\'s mail listener back on, new mail '
		. 'waits on the relay. The relay keeps running, and billing, at your cloud provider; you can enable it '
		. 'again at any time.';
}

/** Echo the Relay section (one box, anchored #relay-section). */
function mailbox_relay_section_render($page, array $v): void {
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
	echo '<div id="relay-section">';
	$page->begin_box(array('title' => 'Relay'));

	// --- relay rows -----------------------------------------------------------
	// One relay per deployment: its name, address and state; one sentence on
	// what that means; its health in a line; its update when it is behind. The
	// technical facts and the rarer actions live behind a disclosure.
	if (empty($v['relays'])) {
		if (!empty($v['mx_points_at_gone_relay'])) {
			// The world still sends mail to a relay this deployment no longer
			// has. Neither way out is taken automatically - both change where
			// the world sends mail - so both are offered, in one place.
			echo '<p class="text-danger"><strong>Mail is addressed to a relay this deployment no longer has.</strong> '
				. 'The cutover is recorded complete, so your domains\' MX records point at a relay, and no relay is '
				. 'enabled here. Two ways out: create a relay below (its address becomes the new MX target), or '
				. 'repoint every hosted domain\'s MX at this server and turn its mail listener back on in the '
				. 'Local mail listener box.</p>';
		}
		echo '<p>' . htmlspecialchars(mailbox_receive_mode() === 'relay'
			? 'This server is set up to receive through a relay, but none is set up yet. Until one is, mail cannot reach it.'
			: 'No relay: mail comes straight to this server. A relay would take mail in first and keep this server\'s '
				. 'address out of public DNS.') . '</p>';
	} else {
		foreach ($v['relays'] as $row) {
			$relay = $row['model'];
			$enabled = (bool)$relay->get('mrl_is_enabled');
			$rid = (int)$relay->key;
			$name = (string)$relay->get('mrl_name') ?: (string)$relay->get('mrl_mx_hostname');
			$up = is_array($row['upgrade'] ?? null) ? $row['upgrade'] : array();

			echo '<div style="margin-bottom:1rem;">';
			echo '<div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">';
			echo '<strong>' . htmlspecialchars($name) . '</strong>'
				. '<span class="text-muted">(' . htmlspecialchars((string)$relay->get('mrl_public_ip')) . ')</span>'
				. ($enabled ? '<span class="badge badge-success">Enabled</span>'
					: '<span class="badge badge-secondary">Disabled</span>');
			echo '</div>';
			echo '<p class="mt-2 mb-2">' . htmlspecialchars(mailbox_relay_summary($enabled)) . '</p>';

			// Health only for the active relay: the battery resolves the active
			// relay internally, so it would say nothing true about another row.
			if (is_array($row['health'])) {
				echo mailbox_relay_health_html($row['health'], $relay);
			}

			// The update, where it can be seen: a relay behind this site's
			// release says so and offers the way to move it.
			if (!empty($up['offers']) || ($up['route'] ?? '') === 'hosted') {
				if (!empty($up['offers'])) {
					echo '<p class="mb-1">' . htmlspecialchars((string)$up['describe']) . '</p>';
				}
				echo mailbox_relay_upgrade_control($page, $rid, $up, !empty($v['cloud_oauth_configured']));
			}

			echo '<details style="margin-top:.75rem;"><summary class="small">Details &amp; actions</summary>';
			echo '<div style="margin-top:.5rem;">';
			echo '<table class="table" style="max-width:560px;"><tbody>';
			if ($relay->usesRelayApi()) {
				echo '<tr><th style="width:45%;">Identity pin</th><td><code>' . htmlspecialchars((string)$relay->get('mrl_identity_fingerprint')) . '</code></td></tr>';
				$failure = trim((string)$relay->get('mrl_last_health_failure'));
				echo '<tr><th>Last ping</th><td>' . ($failure === ''
					? '<span class="text-success">answered</span>'
					: '<span class="text-danger">' . htmlspecialchars($failure) . '</span> — '
						. htmlspecialchars(RelayClient::describeFailure($failure))) . '</td></tr>';
			} else {
				echo '<tr><th style="width:45%;">Identity pin</th><td><span class="text-danger">none — this row predates the relay API '
					. 'and cannot be reached; create a new relay and delete it</span></td></tr>';
			}
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
			if ($up !== array()) {
				echo '<tr><th>Relay version</th><td>' . htmlspecialchars((string)$up['describe']) . '</td></tr>';
			}
			echo '</tbody></table>';
			// Health is the only window into a relay without a shell: every group
			// the relay reported, as it reported it, behind a disclosure.
			if ($relay->usesRelayApi() && $scanner !== null && is_array($scanner['ping'] ?? null)) {
				echo '<details style="margin-bottom:.5rem;"><summary>Everything the relay reported at its last ping</summary>'
					. '<pre style="max-height:24rem;overflow:auto;font-size:.8rem;">'
					. htmlspecialchars(json_encode($scanner['ping'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
					. '</pre></details>';
			}
			$machine = (string)$relay->get('mrl_public_ip') ?: $name;
			$delete_confirm = ((string)$relay->get('mrl_cloud_instance_id') !== '')
				? 'Remove the relay at ' . $machine . ' from your mail setup? The server itself keeps running, and billing, '
					. 'at your cloud provider until you delete it there. Your domains\' MX records still point at it: '
					. 'create a new relay, or repoint the MX at this server and turn its mail listener on.'
				: 'Remove the relay at ' . $machine . '? Your domains\' MX records still point at it: create a new relay, '
					. 'or repoint the MX at this server and turn its mail listener on.';
			echo mailbox_relay_action_button($rid, 'delete', 'Delete', 'btn-danger', $delete_confirm);
			echo '</div></details>';
			echo '</div>';
		}
	}

	// One check, on demand: a fresh answer from the relay (its spam scanner
	// included) and the out-and-back leak probe. A relay that scans and finds
	// nothing looks exactly like one whose scanner is dead, so the only way to
	// tell is to ask it. Beside it, the one switch: stop using the relay, or
	// use it again, which is also how this server's receive mode is chosen
	// (relay_admin.php).
	if (!empty($v['relays'])) {
		echo '<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.5rem;">';
		if (!empty($v['has_active_relay'])) {
			echo PublicPageBase::action_button('Check Relay Health', '', array(
				'hidden' => array('action' => 'relay_health_check'), 'class' => 'btn btn-sm btn-outline-secondary'));
		}
		foreach ($v['relays'] as $row) {
			$relay = $row['model'];
			if ((bool)$relay->get('mrl_is_enabled')) {
				echo mailbox_relay_action_button((int)$relay->key, 'disable', 'Disable relay', 'btn-outline-danger',
					mailbox_relay_disable_message($relay), 'Disable relay');
			} else {
				echo mailbox_relay_action_button((int)$relay->key, 'enable', 'Enable relay', 'btn-primary',
					'Use this relay again? This server starts collecting your domains\' mail from it and sends it the '
					. 'address list, and your domains\' DNS checks expect their MX records to point at the relay.', 'Enable relay');
			}
		}
		echo '</div>';
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
		echo mailbox_relay_action_button(0, 'fleet_enroll', 'Enroll for a hosted relay slot', 'btn-primary');
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
	{
		// The cloud path: this deployment creates the relay in the customer's
		// own cloud account and the relay builds itself from first-boot
		// user-data, then reports in over HTTPS (specs/relay_without_a_shell.md).
		// Nothing on this box has to be prepared first: the deployment's relay
		// client identity is minted when the run starts.
		$run = $v['cloud_run'] ?? null;
		$run_status = $run ? (string)$run->get('rcl_status') : '';
		$status_lines = array(
			'ready'          => 'Creating the server in your account…',
			'draining'       => 'Emptying the relay\'s spool before it is re-imaged…',
			'rebuilding'     => 'Re-imaging the relay from the current release…',
			'booting'        => 'Server created in your account — waiting for it to boot…',
			'provisioning'   => 'The new server is building itself and will report in when it is done (several minutes)…',
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
				echo mailbox_relay_token_steps();
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
				if ((string)$run->get('rcl_error') !== '') {
					echo '<p class="text-muted small">' . htmlspecialchars((string)$run->get('rcl_error')) . '</p>';
				}
			}
		} else {
			if ($run !== null && $run_status === 'failed') {
				echo '<p class="text-danger">' . htmlspecialchars((string)$run->get('rcl_error')) . '</p>';
				echo mailbox_relay_action_button(0, 'relay_cloud_dismiss', 'Dismiss', 'btn-secondary');
			}

			$cform = $page->getFormWriter('relay_cloud');
			echo '<div id="relay-create-fields">';
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
			// and Linode's own interface can resize it later if ever needed. The
			// button opens the Linode step with the hostname and region in it.
			echo '<button type="button" class="btn btn-primary" data-relay-grant="relay-create" data-copy-from="relay-create-fields">'
				. 'Provision into my Linode account</button>';
			echo $cform->end_form();
			echo '</div>';
			echo mailbox_relay_grant_modal($page, 'relay-create',
				'<h5>Create a relay</h5><p>This creates one small server (1 GB Nanode) in your Linode account, billed to you, '
				. 'and builds the relay on it automatically. It takes several minutes and reports in here when it is done.</p>',
				array('action' => 'relay_cloud_begin'), !empty($v['cloud_oauth_configured']), 'Start',
				array('cloud_mail_hostname', 'cloud_region'));
			echo '<p class="text-muted small">Creates one small instance (1 GB Nanode) in your Linode account, '
				. 'billed to you, and builds the relay on it automatically. It can be resized later at Linode if ever needed.</p>';
		}

		// The standalone floor: any VPS, by hand.
		if (empty($v['relays']) && !$cloud_run_live) {
			echo '<p class="text-muted small">Or by hand on any fresh VPS: run '
				. '<code>provisioning/provision_relay.sh &lt;mail-hostname&gt; --client-public-key &lt;this site\'s relay client key&gt;</code> '
				. 'as root (see the plugin docs); such a relay cannot be updated from here.</p>';
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
