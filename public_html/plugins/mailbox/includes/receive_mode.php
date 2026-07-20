<?php
/**
 * Mailbox - deployment receive mode (the relay-or-direct choice).
 *
 * Every hosted domain's DNS prescription hangs on one deployment-level fact:
 * does mail come straight to this server, or does a relay front it so the
 * server's address stays hidden? This helper resolves that fact and gates the
 * mailbox admin surfaces until it is known — the first thing an admin sees is
 * the choice, and domains/mailboxes cannot be created before it is made.
 *
 * The choice belongs to the admin: a relay provisioned as part of setup does
 * NOT silently decide it — the card still appears (annotated that a relay is
 * ready) until the admin picks. Resolution order:
 *   1. The stored choice (mailbox_receive_mode setting) => its value.
 *   2. Live domains already registered => the deployment is running, never
 *      gate it; report what it is doing (relay row => 'relay', else 'direct').
 *   3. Otherwise '' — undecided; gated pages render the choice card only.
 *
 * @version 1.2
 */

/**
 * The resolution matrix as a pure function (unit-tested directly).
 *
 * @return string 'relay' | 'direct' | '' (undecided)
 */
function mailbox_receive_mode_resolve(bool $has_relay, string $setting, bool $has_domains): string {
	if ($setting === 'direct' || $setting === 'relay') {
		return $setting;
	}
	if ($has_domains) {
		return $has_relay ? 'relay' : 'direct';
	}
	return '';
}

/**
 * Whether renting a slot on the operator's shared relay is offered to users.
 * Off for V1 launch — the fleet is not customer-facing yet. Gates every
 * tenant-side hosted-relay surface (the Setup Relay section's Hosted relay
 * block, the Settings connection box, the live fleet-status fetch) in one
 * place; flip to true when the hosted offering launches.
 */
function mailbox_hosted_relay_offered(): bool {
	return false;
}

/** True when a live relay row (hosted slot or self-hosted) exists. */
function mailbox_receive_relay_exists(): bool {
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
	$relays = new MultiMailboxRelay(array('deleted' => false));
	$relays->load();
	return count($relays) > 0;
}

/** The deployment's resolved receive mode. */
function mailbox_receive_mode(): string {
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));

	$domains = new MultiInboundEmailDomain(array('deleted' => false));
	$domains->load();
	$setting = (string)Globalvars::get_instance()->get_setting('mailbox_receive_mode');

	return mailbox_receive_mode_resolve(mailbox_receive_relay_exists(), $setting, count($domains) > 0);
}

/**
 * Handle the choice card's POST. Call first in every gated page's logic;
 * returns a redirect when the action was the choice, null otherwise.
 */
function mailbox_receive_gate_handle(array $input): ?LogicResult {
	if (($input['action'] ?? '') !== 'choose_receive_mode') {
		return null;
	}
	$mode = (string)($input['receive_mode'] ?? '');
	if ($mode !== 'direct' && $mode !== 'relay') {
		return null;
	}

	require_once(PathHelper::getIncludePath('data/settings_class.php'));
	$existing = new MultiSetting(array('setting_name' => 'mailbox_receive_mode'));
	$existing->load();
	if (count($existing)) {
		$setting = $existing->get(0);
	} else {
		$setting = new Setting(NULL);
		$setting->set('stg_name', 'mailbox_receive_mode');
	}
	$setting->set('stg_value', $mode);
	$setting->save();

	$session = SessionControl::get_instance();
	$flash = function (string $msg) use ($session) {
		$session->save_message(new DisplayMessage(
			$msg, 'Done', '~/plugins/mailbox/admin/~',
			DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
	};

	if ($mode === 'relay') {
		$flash(mailbox_receive_relay_exists()
			? 'A relay fronts this server. Finish its setup in the Relay section below.'
			: 'A relay will front this server. Set one up in the Relay section below.');
		return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_setup');
	}
	$flash('Mail comes straight to this server. Add a domain to start receiving.'
		. (mailbox_receive_relay_exists()
			? ' A relay is still reserved for this server — remove it in the Setup tab\'s Relay section.' : ''));
	return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_accounts');
}

/**
 * The choice card: a brief pros/cons comparison with one choose button per
 * column. Gated pages render ONLY this (then the footer) while the mode is
 * undecided. Both forms post back to the page showing the card.
 */
function mailbox_receive_gate_render(): string {
	$relay_ready = mailbox_receive_relay_exists();

	$choose = function (string $mode, string $label): string {
		return '<form method="post">'
			. '<input type="hidden" name="action" value="choose_receive_mode">'
			. '<input type="hidden" name="receive_mode" value="' . htmlspecialchars($mode, ENT_QUOTES) . '">'
			. '<button type="submit" class="btn btn-primary">' . htmlspecialchars($label) . '</button>'
			. '</form>';
	};

	$rows = array(
		array('Setup',
			'Nothing extra — your domains\' DNS points at this server.',
			$relay_ready
				? 'A relay spot is already reserved for this server — choosing this continues its setup.'
				: (mailbox_hosted_relay_offered()
					? 'A relay to set up — a hosted slot, or one you run yourself.'
					: 'A relay to set up on a server you control.')),
		array('Your server\'s address',
			'Public. DNS names this server and the internet connects to it directly.',
			'Hidden. DNS names the relay; mail is passed along over a private tunnel.'),
		array('Fortress email security',
			'Not available.',
			'Required — the Fortress security level needs a relay in front.'),
	);

	$h = '<div class="iem-receive-gate" style="max-width:900px;">'
		. '<h4>How does mail reach this server?</h4>'
		. '<div style="overflow-x:auto;"><table class="table" style="margin-top:1rem;">'
		. '<thead><tr><th style="width:22%;"></th>'
		. '<th>Straight to this server</th><th>Through a relay</th></tr></thead><tbody>';
	foreach ($rows as $row) {
		$h .= '<tr><th>' . htmlspecialchars($row[0]) . '</th>'
			. '<td>' . htmlspecialchars($row[1]) . '</td>'
			. '<td>' . htmlspecialchars($row[2]) . '</td></tr>';
	}
	$h .= '<tr><th></th>'
		. '<td>' . $choose('direct', 'Receive directly') . '</td>'
		. '<td>' . $choose('relay', 'Use a relay') . '</td></tr>'
		. '</tbody></table></div>'
		. '<p class="text-muted small">One choice for the whole server. It can be changed later.</p>'
		. '</div>';
	return $h;
}
?>
