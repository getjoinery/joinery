<?php
/**
 * Mailbox - deployment receive mode (the relay-or-direct choice).
 *
 * Every hosted domain's DNS prescription hangs on one deployment-level fact:
 * does mail come straight to this server, or does a relay front it so the
 * server's address stays hidden? This helper resolves that fact.
 *
 * IT IS A SETTING, NOT A GATE (specs/mailbox_relay_surface_simplification.md).
 * An undecided deployment receives directly and works; the choice lives in the
 * Setup tab's Advanced section and can be changed at any time. A relay is only
 * load-bearing at the Fortress security level, so demanding the answer before
 * any domain has a level asked the operator to decide about infrastructure they
 * may never need, in front of every mailbox page.
 *
 * The choice belongs to the admin: a relay provisioned as part of setup does
 * NOT silently decide it. Resolution order:
 *   1. The stored choice (mailbox_receive_mode setting) => its value.
 *   2. Live domains already registered => report what the deployment is
 *      actually doing (relay row => 'relay', else 'direct').
 *   3. Otherwise '' — undecided, which every consumer treats as direct.
 *
 * @version 1.4 - a settled deployment reads its state in a sentence; the
 *                comparison is a decision aid and waits behind a disclosure
 * @version 1.3 - the choice no longer gates the mailbox surfaces
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
 * How mail reaches this server: what it is now, and how to change it.
 *
 * A DECIDED DEPLOYMENT IS NOT ASKED AGAIN. The pros-and-cons comparison is a
 * decision aid, and it reads as one — two columns and two big choose buttons is
 * a question. In front of a deployment that answered it long ago and has a relay
 * carrying live mail, it reads as though the choice were still open, or worse as
 * though something were unfinished. So a settled deployment gets one sentence
 * saying what is true, and the comparison waits behind a disclosure for the
 * operator who actually wants to change it.
 *
 * An undecided deployment gets the comparison straight away — there, it is the
 * question, and the whole point of the surface.
 */
function mailbox_receive_gate_render(): string {
	$mode = mailbox_receive_mode();
	$table = mailbox_receive_mode_comparison();

	if ($mode === '') {
		return $table;
	}

	$relay = null;
	if ($mode === 'relay') {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
		$relay = mailbox_receive_relay_exists() ? MailboxRelay::active() : null;
	}

	if ($mode === 'relay') {
		$now = $relay !== null
			? 'A relay fronts this server. Mail arrives at the relay, is sealed there, and is passed here over '
				. 'a private tunnel — this server\'s address stays out of DNS.'
			: 'This server is set up to receive through a relay, but no relay is enabled yet. Until one is, '
				. 'mail cannot reach it.';
	} else {
		$now = 'Mail comes straight to this server. Your domains\' DNS names it, and the internet connects to '
			. 'it directly.';
	}

	return '<p class="mb-2">' . htmlspecialchars($now) . '</p>'
		. '<details><summary class="fix-toggle small">Change how mail reaches this server</summary>'
		. '<div class="mt-2">' . $table . '</div></details>';
}

/**
 * The pros-and-cons comparison, with one choose button per column. Both forms
 * post back to the page showing it.
 */
function mailbox_receive_mode_comparison(): string {
	$active_relay = false;
	if (mailbox_receive_relay_exists()) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
		$active_relay = (MailboxRelay::active() !== null);
	}
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
			// A relay that is live is not "a spot reserved, choosing this continues
			// its setup" — that told an operator whose relay was already carrying
			// mail that they had work left to do.
			$active_relay
				? 'Already running — this server has a relay in front of it now.'
				: ($relay_ready
					? 'A relay is set up but not enabled; choosing this continues where it left off.'
					: (mailbox_hosted_relay_offered()
						? 'A relay to set up — a hosted slot, or one you run yourself.'
						: 'A relay to set up on a server you control.'))),
		array('Your server\'s address',
			'Public. DNS names this server and the internet connects to it directly.',
			'Hidden. DNS names the relay; mail is passed along over a private tunnel.'),
		array('Fortress email security',
			'Not available.',
			'Required — the Fortress security level needs a relay in front.'),
	);

	$h = '<div class="iem-receive-gate" style="max-width:900px;">'
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
