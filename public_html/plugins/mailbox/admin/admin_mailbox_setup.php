<?php
/**
 * Inbound Email - Setup & Verification (mailbox-first)
 *
 * Pick a registered mailbox; the page checks its setup, grouped into Receiving
 * (always) plus Forwarding (when the mailbox forwards) or Sending (relay and
 * DKIM signing, when it stores only — composed replies still leave the
 * server). Server-wide
 * diagnostics — the inbound provider, this server's mail hostname/IP, and the
 * full Postfix/relay health run — live behind the Advanced disclosure so they
 * don't clutter the per-mailbox view.
 *
 * The picker also lists any domain that has no mailbox yet. Its setup is
 * domain-level, so that state renders the guided steps, the publish box and the
 * domain-level checks, and skips the per-address ones.
 *
 * This tab is also the whole surface for outbound send protection — there is no
 * separate ceremony page — but ALL of it lives under Advanced, in the Sending
 * identity box: the offer, the cost, the publish step, the switch, and the
 * lifecycle afterwards. The guided box never mentions it. Send protection is an
 * advanced opt-in, and a Fortress domain resting without it is finished, not
 * half-configured (specs/mailbox_relay_surface_simplification.md).
 *
 * Every control on the page posts to $self_url, which carries the focused
 * mailbox or domain: a form that posts to the bare path loses the focus and the
 * redirect lands the operator back on the picker.
 *
 * @version 3.12 - the machine sender ceremony (specs/mailbox_machine_sender_card.md):
 *                 register mail.<domain>, publish its records, switch system
 *                 mail — with the switch offered only once the checks verify
 * @version 3.11 - a ceremony step with nothing left to do says so instead of
 *                 offering to configure DNS that is already live
 * @version 3.10 - the ceremony states its two steps and publishes only step one:
 *                 the strict records are offered after signing is on, not before
 * @version 3.9 - the Sending identity panel is a cutout, not the next section:
 *                it renders as a focus box, with its publish box nested in it
 * @version 3.8 - key ownership leaves this tab entirely; it is a property of
 *                the domain, decided on the domain editor
 * @version 3.7 - only one DNS publish box renders at a time: the page's own is
 *                suppressed while the ceremony shows its protected-shape one
 * @version 3.6 - the send-protection ceremony renders at the TOP of the page
 *                while it is open: whatever you are working on should be the
 *                first thing you see after a reload, not buried in Advanced
 * @version 3.5 - the full health run under Advanced renders only what is not
 *                already on screen, and the provider-supplied records table is
 *                removed: it prescribed the colocated shape on a relay-fronted
 *                domain, next to the publish box showing the correct one
 * @version 3.4 - send protection leaves the guided box entirely and becomes an
 *                explicit opt-in ceremony under Advanced; the unlock gate is
 *                shown rather than discovered on the press
 * @version 3.3 - names WHICH protection: outbound send protection, distinct from
 *                the arrival sealing a Fortress domain already has
 * @version 3.2 - the turn-protection-on step stops asking for DNS that is already
 *                published, so the remaining action reads as unlock-and-press
 * @version 3.1 - the protected-setup box carries OUTSTANDING work only and does
 *                not render when there is none; completed steps read as cards
 *                and their controls live under Advanced
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/InboundEmailSetupCheck.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_setup_logic.php'));

$page_vars = process_logic(admin_mailbox_setup_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'incoming',
	'breadcrumbs' => array(
		'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox_accounts',
		'Setup' => '',
	),
	'session' => $session,
));

echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Setup');

// How mail reaches this server is a deployment fact, not a question every page
// has to have answered first: an undecided deployment receives directly and
// works. The choice lives in the Setup tab's Advanced section
// (specs/mailbox_relay_surface_simplification.md).
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));

// The relay's setup and lifecycle machinery lives in Advanced, with the rest of
// the server-wide plumbing. Its STATE reads as a card in Receiving and one in
// Sending, so the common path sees the relay where the relay matters instead of
// a provisioning panel above every page.

// The per-check "Details & how to fix" disclosure reads as a link, not a field.

// Flash messages render in the AdminPage header (admin pages must not
// fetch or render session messages themselves).

// --- Shared check-row renderers (used by scoped sections and Advanced) ---
$status_badge = function ($status) {
	switch ($status) {
		case InboundEmailSetupCheck::PASS:    return '<span class="badge bg-success">PASS</span>';
		case InboundEmailSetupCheck::FAIL:    return '<span class="badge bg-danger">FAIL</span>';
		case InboundEmailSetupCheck::WARN:    return '<span class="badge bg-warning text-dark">WARN</span>';
		// badge-info / badge-secondary, not bg-*: the admin theme colours badges
		// through .badge-{variant} and defines no .bg-info or .bg-secondary, so
		// those two rendered as unstyled text.
		case InboundEmailSetupCheck::INFO:    return '<span class="badge badge-info">INFO</span>';
		// Grey, because nothing is wrong: a capability nobody turned on.
		case InboundEmailSetupCheck::OPTIONAL: return '<span class="badge badge-secondary">OPTIONAL</span>';
		default:                              return '<span class="badge badge-secondary">UNKNOWN</span>';
	}
};

$render_fix = function ($fix) use ($address) {
	if (!$fix) { return; }
	if (!empty($fix['text'])) {
		echo '<p class="mb-2">' . htmlspecialchars($fix['text']) . '</p>';
	}
	if (!empty($fix['command'])) {
		echo '<pre class="bg-light p-2 mb-2"><code>' . htmlspecialchars($fix['command']) . '</code></pre>';
	}
	if (!empty($fix['dns_record'])) {
		$rec = $fix['dns_record'];
		$has_priority = array_key_exists('priority', $rec);
		echo '<table class="table table-sm table-bordered mb-2 iem-table-760">';
		echo '<thead><tr><th>Type</th><th>Name</th>'
			. ($has_priority ? '<th>Priority</th>' : '') . '<th>Value</th></tr></thead><tbody><tr>';
		echo '<td>' . htmlspecialchars($rec['type']) . '</td>';
		echo '<td><code>' . htmlspecialchars($rec['name']) . '</code></td>';
		if ($has_priority) {
			echo '<td><code>' . htmlspecialchars((string)$rec['priority']) . '</code></td>';
		}
		echo '<td>' . PublicPageBase::copy_field($rec['value']) . '</td>';
		echo '</tr></tbody></table>';
	}
	if (!empty($fix['link'])) {
		echo '<p class="mb-2"><a class="btn btn-sm btn-outline-secondary" href="'
			. htmlspecialchars($fix['link']['url']) . '">'
			. htmlspecialchars($fix['link']['label']) . '</a></p>';
	}
	if (!empty($fix['action'])) {
		$act = $fix['action'];
		echo '<form method="post" class="mb-1">';
		echo '<input type="hidden" name="action" value="' . htmlspecialchars($act['action']) . '">';
		if (!empty($act['domain'])) {
			echo '<input type="hidden" name="domain" value="' . htmlspecialchars($act['domain']) . '">';
		}
		echo '<input type="hidden" name="address" value="' . htmlspecialchars($address) . '">';
		$label = !empty($act['label']) ? $act['label']
			: ($act['action'] === 'enable_plugin' ? 'Enable inbound email'
			: ($act['action'] === 'add_domain' ? 'Add this domain' : 'Apply fix'));
		echo '<button type="submit" class="btn btn-sm btn-primary">' . htmlspecialchars($label) . '</button>';
		echo '</form>';
	}
};

// Every check row this page has actually put on screen, so the full health run
// under Advanced can render what is left rather than everything again. Recorded
// at the point of rendering rather than derived from the row lists, so it can
// never drift from what the operator is looking at.
$rendered_checks = array();

$render_check = function ($c) use ($status_badge, $render_fix, &$rendered_checks) {
	// id alone is not unique — mailhost rows repeat per hostname — so the scope
	// is part of the identity.
	$rendered_checks[($c['id'] ?? '') . '|' . ($c['scope'] ?? '')] = true;
	$border = $c['status'] === InboundEmailSetupCheck::FAIL ? 'border-danger'
		: ($c['status'] === InboundEmailSetupCheck::WARN ? 'border-warning'
		: ($c['status'] === InboundEmailSetupCheck::PASS ? 'border-success'
		: ($c['status'] === InboundEmailSetupCheck::INFO ? 'border-info' : 'border-secondary')));
	echo '<div class="card mb-2 ' . $border . '"><div class="card-body py-2">';
	echo '<div>' . $status_badge($c['status']) . ' <strong>' . htmlspecialchars($c['label']) . '</strong>';
	// "OPTIONAL recommended" contradicts itself — the grey badge already says
	// there is nothing to do here.
	if ($c['severity'] === InboundEmailSetupCheck::RECOMMENDED
			&& $c['status'] !== InboundEmailSetupCheck::OPTIONAL) {
		echo ' <span class="badge badge-subtle-secondary">recommended</span>';
	}
	echo '</div>';
	echo '<div class="mt-1">' . htmlspecialchars($c['summary']) . '</div>';
	if (!empty($c['detail']) || !empty($c['fix'])) {
		echo '<details class="mt-1"><summary class="fix-toggle small">Details &amp; how to fix</summary>';
		echo '<div class="mt-2">';
		if (!empty($c['detail'])) {
			echo '<p class="text-muted small mb-2">' . htmlspecialchars($c['detail']) . '</p>';
		}
		$render_fix($c['fix']);
		echo '</div></details>';
	}
	echo '</div></div>';
};

// =====================================================================
// Mailbox picker
// =====================================================================
$page->begin_box(array('title' => 'What to set up'));
if (empty($focus_options)) {
	echo '<div class="alert alert-info mb-0">Nothing registered yet. Add a domain or mailbox on the '
		. '<a href="/plugins/mailbox/admin/admin_mailbox_accounts">Accounts</a> tab, then come back to set it up.</div>';
} else {
	$mbform = $page->getFormWriter('mbform', array('method' => 'GET', 'action' => $base));
	echo $mbform->begin_form();
	// The empty first option is the default state: checking a mailbox is real
	// work (DNS lookups, host probes), so the page does none until asked.
	$mbform->dropinput('focus', 'Mailbox or domain', array(
		'options' => array('' => 'Choose one…') + $focus_options,
		'value'   => $focus_value,
	));
	$mbform->submitbutton('btn_view', 'Set up');
	echo $mbform->end_form();
	if (!$selected && !$domain_selected) {
		echo '<p class="text-muted small mb-0">Pick a mailbox to check how it receives and sends mail, '
			. 'or a domain to finish its setup before it has one.</p>';
	}
}
$page->end_box();

// --- Sending identity (Fortress) -------------------------------------------
// WHATEVER YOU ARE DOING BELONGS AT THE TOP OF THE PAGE. The ceremony normally
// lives under Advanced, which is right for a control you have gone looking for
// — but the moment an operator opens it, it is the task, and leaving it buried
// meant every press reloaded the page to the top and sent them scrolling back
// down to find where they were. So the same box renders in one of two places:
// at the top while the ceremony is open, and under Advanced otherwise.
//
// Defined once as a closure rather than written twice, because two copies of a
// 170-line panel is how they drift apart.
$prot_hidden = array('ied_inbound_email_domain_id' => (int)($focus_domain_id ?? 0));
$setup_open  = !empty($protect_setup) && !empty($protect)
	&& empty($protect['is_protected']) && !empty($protect['has_key']);
$adv_focus_qs = $selected_alias_id ? '?alias_id=' . (int)$selected_alias_id
	: ($selected_domain_id ? '?domain_id=' . (int)$selected_domain_id : '');
$adv_base    = $base . $adv_focus_qs;
// Opening the ceremony does NOT open Advanced: the panel moves to the top of the
// page instead, so expanding the server-wide section as well would bury the task
// under everything the operator was not asking about.
$setup_url   = $adv_base . ($adv_focus_qs !== '' ? '&' : '?') . 'protect_setup=1';
// Leaving puts it back where it lives, with Advanced open around it.
$leave_url   = $adv_base . ($adv_focus_qs !== '' ? '&' : '?') . 'advanced=1';

$render_sending_identity = function () use ($page, $protect, $focus_domain, $focus_domain_id,
		$self_url, $prot_hidden, $setup_open, $setup_url, $leave_url, $protect_dns_box,
		$protect_preflight, $protect_signing_ready, $protect_vault_unlocked, $render_check) {
// --- Sending identity (Fortress) ---
// THE WHOLE SEND-PROTECTION CEREMONY LIVES HERE, and nowhere else
// (specs/mailbox_relay_surface_simplification.md). It is an advanced opt-in,
// not a setup step: the guided box above never mentions it, so this is the
// only place it is offered, explained, carried out, and later changed.
if (!empty($protect) && $focus_domain !== '') {
	// A CUTOUT, NOT THE NEXT SECTION. Everything else on this page is one
	// deployment's setup, read top to bottom; this is a separate, optional
	// undertaking with its own ceremony, its own consequences and its own way
	// out. Rendered as another flat box it read as step five of the checklist.
	// The focus variant lifts it onto its own surface inside a recessed stage —
	// a modal that stayed in the flow of the page, so the checks it depends on
	// are still visible below it.
	$page->begin_box(array(
		'title'   => 'Sending identity — ' . $focus_domain,
		'variant' => 'focus',
	));

	if ($protect['is_protected']) {
		echo '<p class="mb-2">Send protection is on. While you are signed out, nothing on this server can send '
			. 'mail as ' . htmlspecialchars($focus_domain) . ' that anyone will accept.</p>';
	} elseif ($protect['has_key'] && !$setup_open) {
		// THE RESTING STATE, AND IT IS A FINISHED ONE. Every Fortress domain
		// has a sealed key the moment it is raised, so "has a key, not
		// enforcing" is not a job half done — it is the normal state of a
		// working domain. Say what turning it on would buy and what it would
		// cost, then leave it alone.
		echo '<p class="mb-2">Mail arriving for ' . htmlspecialchars($focus_domain) . ' is sealed at the relay '
			. 'and unreadable without your vault. That is Fortress, and it is working.</p>';
		echo '<p class="mb-2"><strong>Send protection is a separate, optional step</strong> that covers mail going '
			. 'OUT. Turn it on and every other mail server on the internet rejects anything claiming to be from '
			. 'this domain that your sealed key did not sign — including anything sent by someone who breaks into '
			. 'this server.</p>';
		echo '<p class="mb-2">It costs you two things, and they are the reason it is not switched on for you:</p>';
		echo '<ul class="mb-2">';
		echo '<li>You have to unlock your vault to send mail as this domain — every time.</li>';
		echo '<li>Automated mail (order confirmations, notifications) can no longer come from this domain, '
			. 'because nobody is signed in when it is sent. It moves to a Standard subdomain'
			. (_setup_has_standard_subdomain($focus_domain)
				? ' — you already have one, so this part is covered.'
				: ', which you do not have yet.') . '</li>';
		echo '</ul>';
		echo '<p class="mb-3"><a class="btn btn-primary" href="' . htmlspecialchars($setup_url) . '">'
			. 'Set up send protection</a></p>';
	}

	// --- The ceremony, once the operator has explicitly opened it -----------
	if ($setup_open) {
		echo '<p class="mb-2"><strong>Setting up send protection for ' . htmlspecialchars($focus_domain)
			. '.</strong> Nothing is enforced and your mail is unaffected until the last step, and you can '
			. 'stop at any point. <a href="' . htmlspecialchars($leave_url) . '">Leave this for now</a></p>';
		// THE ORDER IS THE SAFETY, so it is stated rather than left to be inferred
		// from whichever controls happen to be on screen. Publishing the records
		// that reject unsigned mail before anything signs takes the domain's own
		// mail down for as long as DNS takes to travel, and nothing else on the
		// page would warn an operator who did the two halves the other way round.
		echo '<p class="text-muted small mb-2">This happens in two steps, and taking them in this order is what '
			. 'keeps your mail flowing the whole way through:</p>';
		echo '<ol class="text-muted small mb-3">';
		echo '<li><strong>Now:</strong> publish the records below, then turn signing on. None of them asks any '
			. 'mail server to reject anything, so nothing changes for the mail you send today.</li>';
		echo '<li><strong>After that:</strong> publish the SPF and DMARC records that make other servers reject '
			. 'forgeries. This page offers them once signing is on — by which time the signature they demand '
			. 'is already on every message you send.</li>';
		echo '</ol>';

		if (!empty($protect_dns_box)) {
			require_once(PathHelper::getIncludePath('includes/dns/dns_publish_box.php'));
			// Nested: these records are a step of the ceremony above them, not a
			// second offer to configure the domain's DNS.
			//
			// THIS BOX CARRIES THE SIGNING-STAGE PLAN ONLY. It must never carry
			// the strict SPF and DMARC: Apply is one click, and one click is all
			// it would take to start rejecting the domain's own mail.
			dns_publish_box_render($page, $protect_dns_box,
				'Step 1 — publish the records that let your key sign', 'nested');
		} elseif (!empty($protect_signing_ready)) {
			// The box is absent because there is nothing for it to do. Say that,
			// rather than leaving a gap where a step used to be — a numbered step
			// that renders nothing reads as one that failed to load.
			echo '<p class="mb-2"><strong>Step 1 is already done.</strong> Every record your key needs is '
				. 'published and live, so there is nothing to configure here.</p>';
		}

		if (!empty($protect_preflight)) {
			echo '<h5 class="mt-3 mb-2">' . (!empty($protect_signing_ready)
					? 'Confirmed live' : 'What has to be live before signing starts') . '</h5>';
			foreach ($protect_preflight as $r) { $render_check($r); }
		}

		// THE UNLOCK GATE IS SHOWN, NOT DISCOVERED. A button that accepts a
		// press and then explains why it did nothing is the same defect as a
		// checklist step that is already done.
		if (empty($protect_vault_unlocked)) {
			echo '<p class="alert alert-warning mb-2">Your vault is locked. Turning send protection on decides what '
				. 'the rest of the world will accept as this domain, so we need to know you are really here. '
				. '<a class="btn btn-sm btn-primary ms-2" href="/profile/security">Unlock your vault</a></p>';
		} else {
			echo '<div class="mb-2">' . PublicPageBase::action_button('Check DNS and turn on send protection',
				$self_url,
				array('hidden' => $prot_hidden + array('action' => 'protect_activate'),
					'class' => 'btn btn-primary',
					'confirm' => 'Turn send protection on for ' . $focus_domain . '? From this moment only your '
						. 'key can send as this domain, and you will need your vault unlocked to send mail.'))
				. '</div>';
		}
	}

	// No key at all. The Fortress raise seals one automatically, so this only
	// happens where it could not guess: a domain whose mailboxes already have
	// holders, where the admin raising the level need not be the person who
	// reads the mail. The ANSWER is given on the domain editor, because who owns
	// the key is a property of the domain rather than a step in setting it up.
	if (!$protect['is_protected'] && empty($protect['has_key'])) {
		echo '<p class="mb-2">This domain has no signing key yet, because more than one person could own it — '
			. 'and only its owner will ever be able to send as ' . htmlspecialchars($focus_domain) . '. '
			. 'That is decided on the domain itself; nothing here can start until it is answered.</p>';
		echo '<p class="mb-0"><a class="btn btn-primary" '
			. 'href="/plugins/mailbox/admin/admin_mailbox_domains?ied_inbound_email_domain_id='
			. (int)$focus_domain_id . '">Choose who this key belongs to</a></p>';
	}

	if (!empty($protect['has_pending'])) {
		echo '<p class="alert alert-warning">A replacement key is staged under <code>'
			. htmlspecialchars($protect['pending_selector']) . '</code>. The old key is still signing, so '
			. 'nothing is broken — publish the new record, then switch over.</p>';
	}

	echo '<div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem">';
	if ($protect['is_protected'] && !empty($protect['has_pending'])) {
		echo PublicPageBase::action_button('Check DNS and switch over', $self_url,
			array('hidden' => $prot_hidden + array('action' => 'protect_activate_rotation'),
				'class' => 'btn btn-primary'));
		echo PublicPageBase::action_button('Forget the new key', $self_url,
			array('hidden' => $prot_hidden + array('action' => 'protect_cancel_rotation'),
				'class' => 'btn btn-soft-default',
				'confirm' => 'Throw away the replacement key? Your current key was never touched and keeps working.'));
	} elseif ($protect['is_protected']) {
		echo PublicPageBase::action_button('Replace my key', $self_url,
			array('hidden' => $prot_hidden + array('action' => 'protect_rotate'),
				'class' => 'btn btn-soft-default',
				'confirm' => 'Make a replacement key? Your current one keeps working the whole time — you publish '
					. 'the new record, check it, and only then switch over.'));
	}
	// "Start over with a new key" USED TO BE HERE, and is deliberately gone. Its
	// only real use was a key sealed to the wrong person — and it was the one
	// control that never asked who the new owner should be, so it silently
	// resealed the domain to whoever pressed it. On a domain with two holders
	// that took the sending identity away from the other, with no warning.
	//
	// Who the key belongs to is a property of the domain, so it is decided on the
	// domain editor, where the level is chosen and the owner can be named.
	if ($protect['is_protected']) {
		// "Send protection", not "protection": turning this off does not stop
		// arriving mail being sealed, and a confirm that reads as though it
		// might would stop somebody making a change they are entitled to make.
		//
		// The confirm states every consequence concretely, including the one
		// that bites afterwards: the published records reject unsigned mail,
		// so they have to come down too or this domain sends nothing.
		echo PublicPageBase::action_button('Turn send protection off', $self_url,
			array('hidden' => $prot_hidden + array('action' => 'protect_disable'),
				'class' => 'btn btn-soft-danger',
				'confirm' => 'Turn send protection off for ' . $focus_domain . '?' . "\n\n"
					. 'This server will be able to send as this domain again without you signed in — and so '
					. 'will anyone who breaks into it.' . "\n\n"
					. 'Your DNS currently tells other servers to reject mail this domain sends that your key '
					. 'did not sign. Those records have to come down as well, or this domain will send '
					. 'nothing. You will be taken straight to them.' . "\n\n"
					. 'Arriving mail stays sealed and still needs your vault. This only affects sending.'));
	}
	echo '</div>';

	// Nobody sees this name and fwd.<domain> is right for everyone, so it is
	// folded away rather than asked for. It stays changeable for whoever has
	// a reason, with the explanation next to the control.
	echo '<details><summary class="fix-toggle small">Change the return address for outgoing mail</summary>';
	echo '<div class="mt-2">';
	echo '<p class="text-muted small">Every message has two senders: the one people see, and a hidden one '
		. 'servers use to report delivery problems. Your protected name tells the world that no server may send '
		. 'as it, so the hidden sender travels under this name instead. Nobody sees it and there is nothing to '
		. 'register. Changing it means republishing its two DNS records.</p>';
	$ra_form = $page->getFormWriter('return_address_form', array('action' => $self_url));
	echo $ra_form->begin_form();
	$ra_form->hiddeninput('ied_inbound_email_domain_id', '', array('value' => (int)$focus_domain_id));
	$ra_form->hiddeninput('action', '', array('value' => 'protect_return_address'));
	$ra_form->textinput('ied_forwarding_subdomain', 'Return address', array(
		'value'    => $protect['return_address'],
		'helptext' => 'Must end in .' . $focus_domain . '.',
	));
	$ra_form->submitbutton('btn_return_address', 'Save this name');
	echo $ra_form->end_form();
	echo '</div></details>';
	$page->end_box();
}
};

// The active task, first on the page.
if ($setup_open) {
	$render_sending_identity();
}

// --- Machine sender ceremony (specs/mailbox_machine_sender_card.md) ---------
// The guided path for moving automated mail onto its own subdomain identity
// (mail.<domain>), so the bare domain stays reserved for people — and can be
// protected without breaking reminders and receipts. Opened from the
// Automated mail identity card; like the protect ceremony, the open task
// renders at the top of the page.
if (!empty($machine_setup) && $focus_domain !== '') {
	$machine_leave_url = $adv_base;
	$page->begin_box(array(
		'title'   => 'Automated mail identity — ' . $focus_domain,
		'variant' => 'focus',
	));
	if (!empty($machine_on)) {
		echo '<p class="mb-2"><strong>The machine sender is set up.</strong> System mail sends as a machine '
			. 'identity under ' . htmlspecialchars($focus_domain) . '; its health reads as cards below, and a '
			. 'record that drifts fails its check there.</p>';
		echo '<p class="mb-0"><a class="btn btn-sm btn-outline-secondary" href="'
			. htmlspecialchars($machine_leave_url) . '">Back to the checks</a></p>';
	} else {
		echo '<p class="mb-2"><strong>Setting up a machine sender for ' . htmlspecialchars($focus_domain)
			. '.</strong> Automated mail (reminders, receipts, notifications) moves to its own identity under '
			. '<code>' . htmlspecialchars($machine_domain) . '</code>; people keep sending as '
			. htmlspecialchars($focus_domain) . '. Nothing changes for your mail until the last step. '
			. '<a href="' . htmlspecialchars($machine_leave_url) . '">Leave this for now</a></p>';

		// Step 1 — register at the provider. A settled step is stated, not offered.
		$machine_registered = false;
		foreach ($machine_readiness as $r) {
			if ($r['id'] === 'domain.machine_sender'
					&& in_array($r['status'], array(InboundEmailSetupCheck::PASS, InboundEmailSetupCheck::INFO), true)) {
				$machine_registered = true;
			}
		}
		echo '<h5 class="mt-3 mb-2">Step 1 — register ' . htmlspecialchars($machine_domain) . '</h5>';
		if ($machine_registered) {
			echo '<p class="mb-2">Already done — ' . htmlspecialchars($machine_domain)
				. ' is registered as a sending domain.</p>';
		} elseif (!empty($machine_can_register)) {
			echo '<form method="post" action="' . htmlspecialchars($self_url . (strpos($self_url, '?') !== false ? '&' : '?') . 'machine_setup=1') . '" class="mb-2">';
			echo '<input type="hidden" name="action" value="machine_register">';
			echo '<input type="hidden" name="domain" value="' . htmlspecialchars($machine_domain) . '">';
			echo '<button type="submit" class="btn btn-sm btn-primary">Register '
				. htmlspecialchars($machine_domain) . ' at ' . htmlspecialchars($machine_provider_label) . '</button>';
			echo '</form>';
			echo '<p class="text-muted small mb-2">One press. DKIM authority stays on the subdomain itself, so '
				. 'its keys align strictly with mail it sends.</p>';
		} else {
			echo '<p class="mb-2">Your outbound provider'
				. ($machine_provider_label !== '' ? ' (' . htmlspecialchars($machine_provider_label) . ')' : '')
				. ' cannot register sending domains from here. Add <code>' . htmlspecialchars($machine_domain)
				. '</code> as a sending domain in its dashboard, then reload this page.</p>';
		}

		// Step 2 — publish DNS. The page's publish box (below) carries the
		// machine identity's records while this ceremony is open.
		echo '<h5 class="mt-3 mb-2">Step 2 — publish its DNS records</h5>';
		echo '<p class="text-muted small mb-2">The DNS publish box below includes '
			. htmlspecialchars($machine_domain) . '\'s SPF and DKIM records while this setup is open — publish '
			. 'from there, or copy the records from the checks below. DNS takes a while to travel.</p>';

		if (!empty($machine_readiness)) {
			echo '<h5 class="mt-3 mb-2">' . (!empty($machine_ready)
				? 'Confirmed' : 'What has to verify before the switch') . '</h5>';
			foreach ($machine_readiness as $r) { $render_check($r); }
		}

		// Step 3 — the switch. Offered only once 1–2 verify; the action
		// re-checks server-side so a stale tab cannot flip early.
		echo '<h5 class="mt-3 mb-2">Step 3 — switch system mail</h5>';
		if (empty($machine_ready)) {
			echo '<p class="text-muted mb-2">Offered once the checks above verify.</p>';
		} else {
			$ms_form = $page->getFormWriter('machine_switch_form', array(
				'action' => $self_url . (strpos($self_url, '?') !== false ? '&' : '?') . 'machine_setup=1'));
			echo $ms_form->begin_form();
			$ms_form->hiddeninput('action', '', array('value' => 'machine_switch'));
			$ms_form->hiddeninput('domain', '', array('value' => $focus_domain));
			$ms_form->textinput('machine_local', 'Address name', array(
				'value'    => 'notifications',
				'helptext' => 'Automated mail will send as this name @ ' . $machine_domain . '.',
			));
			$ms_form->textinput('machine_replyto', 'Replies go to', array(
				'value'    => $machine_replyto_prefill,
				'helptext' => 'When someone replies to an automated email, it lands here instead of the '
					. 'machine address. Clear it only if you want replies to dead-end.',
			));
			$ms_form->submitbutton('btn_machine_switch', 'Switch system mail to the machine identity');
			echo $ms_form->end_form();
		}
	}
	$page->end_box();
}


// =====================================================================
// Scoped results for the chosen mailbox or domain
// =====================================================================
// ---- Guided setup for this domain's security level (Phase 3) ----
// Standard needs nothing beyond the per-mailbox checks below. Private adds the
// one-time vault ceremony; Fortress adds the relay that seals mail at the door.
// Those two are the whole guided path, because those two are what the levels
// cannot work without. These reuse the built flows — link, never reimplement.
// All of it is domain-level, so it renders for a focused domain exactly as it
// does for a focused mailbox, and must not depend on a mailbox existing.
$level        = $security_level ?? 'standard';
$dom_id       = (int)($focus_domain_id ?? 0);
$has_vault    = !empty($acting_has_vault);
$is_protected = !empty($focus_is_protected);
if ($dom_id && ($level === 'private' || $level === 'fortress')) {
	// THIS BOX CARRIES OUTSTANDING WORK ONLY, and disappears when there is none.
	//
	// The same rule the DNS publish box follows below: a finished domain is not
	// led with an offer to configure it. A step that has been completed is not a
	// step — narrating it back ("the relay is set up", "your vault is ready")
	// turns a to-do list into a status report, and a list where most entries are
	// already done is one the operator learns to skim past, taking the entries
	// that DO need action with it.
	//
	// Completed state is not lost by omission: it reads as a card in Receiving
	// and Sending, and the lifecycle controls live under Advanced.
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));
	$active_relay = mailbox_receive_relay_exists() ? MailboxRelay::active() : null;

	// Each entry is a closure so a step can render a form, and so nothing is
	// echoed until we know the box has any reason to exist at all.
	$steps = array();

	// The vault — one-time, across every protected domain.
	if (!$has_vault) {
		$steps[] = function () {
			echo '<li class="mb-2"><strong>Set up your vault</strong> — a one-time step for every protected domain. '
				. 'Enroll a passkey and print your recovery codes: lose every unlocker and the mail is gone for good. '
				. '<a class="btn btn-sm btn-primary" href="/profile/security">Set up your vault</a></li>';
		};
	}

	if ($level === 'fortress') {
		// The relay, shared by every Fortress domain — so on the second domain
		// onward this is normally already done and says nothing.
		if ($active_relay === null) {
			$disabled = mailbox_receive_relay_exists();
			$steps[] = function () use ($disabled) {
				if ($disabled) {
					// Set up and then switched off: a different problem from never
					// having done it, and a different fix.
					echo '<li class="mb-2"><strong>Your relay is not enabled.</strong> Until it is, mail reaches '
						. 'Joinery without being sealed at the door. '
						. '<a class="btn btn-sm btn-outline-secondary" href="#relay-section">Relay setup</a></li>';
				} else {
					echo '<li class="mb-2"><strong>The relay</strong> fronts every Fortress domain and seals fresh mail '
						. 'before it reaches Joinery. Provision it once (shared by all Fortress domains). '
						. '<a class="btn btn-sm btn-outline-secondary" href="#relay-section">Relay setup</a></li>';
				}
			};
		}

		// FORTRESS IS NOT FINISHED UNTIL SENDING IS LOCKED TOO
		// (specs/mailbox_fortress_send_protection_completion.md). Fortress is a
		// two-sided promise — nobody can read your mail, nobody can send as you.
		// The raise delivers the first half; this is the second, and until it is
		// done the domain is one anybody can still impersonate. The raise ceremony
		// has always said so ("one step still remains") and this is the same fact
		// where the operator is actually working.
		//
		// ORDERED LAST, AND GATED. Offering the sending half before mail is
		// arriving through the relay asks someone to finish what has not started.
		// It renders only once the relay is live and this domain's MX points at
		// it — and it disappears the moment protection is on, so it never narrates
		// back a step already taken.
		if (!$is_protected && $active_relay !== null && _setup_domain_mx_is_cut_over($domain_rows ?? array())) {
			$steps[] = function () use ($setup_url) {
				echo '<li class="mb-0"><strong>Finish Fortress: lock sending to your key.</strong> '
					. 'Arriving mail is sealed, which is half of what Fortress promises. The other half is that '
					. 'nobody can send mail claiming to be you — including someone who has broken into this '
					. 'server. Until this is done, anyone can.'
					. '<div class="mt-2"><a class="btn btn-sm btn-primary" href="'
					. htmlspecialchars($setup_url) . '">Finish it under Sending identity</a></div>'
					. '<p class="text-muted small mb-0 mt-1">Nothing changes for your mail until the last step, '
					. 'and you can stop at any point.</p></li>';
			};
		}
	}

	if (!empty($steps)) {
		// "Still to set up", not "Protected setup": the box only ever holds
		// outstanding work, and heading it with the level's name made a finished
		// domain's remaining unrelated item read as though protection itself were
		// unfinished.
		$level_name = $level === 'fortress' ? 'Fortress' : 'Private';
		$page->begin_box(array('title' => 'Still to set up — ' . $level_name . ' · ' . $focus_domain));
		echo '<ol class="mb-0">';
		foreach ($steps as $step) {
			$step();
		}
		echo '</ol>';
		$page->end_box();
	}
}

/**
 * Has this domain's MX actually moved to the relay?
 *
 * Read off the domain.mx check row the page already computed rather than asked
 * again — the answer must be the same one the operator is looking at, and a
 * second lookup could disagree with it. Absent rows mean "not established", so
 * the finishing step waits rather than being offered prematurely.
 */
function _setup_domain_mx_is_cut_over(array $domain_rows): bool {
	foreach ($domain_rows as $r) {
		if (($r['id'] ?? '') === 'domain.mx') {
			return ($r['status'] ?? '') === InboundEmailSetupCheck::PASS;
		}
	}
	return false;
}

/**
 * Does this domain already have a Standard subdomain to send automated mail from?
 *
 * A Fortress domain cannot send unless its owner is signed in, so the Sending
 * identity box offers a Standard subdomain for confirmations and notifications.
 * Once one exists the offer has been taken and must stop being made — any
 * Standard subdomain counts, not just the suggested mail.* name, because the
 * operator was free to pick their own.
 */
function _setup_has_standard_subdomain(string $domain): bool {
	$domain = strtolower(trim($domain));
	if ($domain === '') {
		return false;
	}
	try {
		$all = new MultiInboundEmailDomain(array('deleted' => false));
		$all->load();
		foreach ($all as $d) {
			$name = strtolower(trim((string)$d->get('ied_domain')));
			if ($name === $domain || substr($name, -strlen('.' . $domain)) !== '.' . $domain) {
				continue;
			}
			if ($d->security_level() === InboundEmailDomain::LEVEL_STANDARD) {
				return true;
			}
		}
	} catch (\Throwable $e) {
		// Never let a lookup failure hide the rest of the checklist; the worst
		// case is offering a subdomain that already exists.
		return false;
	}
	return false;
}

// The DNS publish box: everything below this point is a check, and this is
// the one control that can make those checks pass without leaving the page.
// Once there is no DNS left to fix it moves to Advanced — a finished domain
// should not be led with an offer to configure it.
// NOT WHILE THE CEREMONY IS OPEN. The ceremony renders its own publish box, for
// the protected shape, and this one renders the ordinary shape — two panels with
// the same heading showing records that contradict each other, which is the exact
// failure the provider-supplied table was removed for. Mid-ceremony there is one
// correct set of records to publish, and it is the ceremony's.
if (!$setup_open && ($selected || $domain_selected) && !empty($dns_box) && empty($dns_box_in_advanced)) {
	require_once(PathHelper::getIncludePath('includes/dns/dns_publish_box.php'));
	dns_publish_box_render($page, $dns_box);
}

// A focused domain gets the domain-level checks — for a Fortress domain these
// ARE the protected-shape verification, so publishing has something to prove
// itself against without a mailbox existing first.
if ($domain_selected) {
	$page->begin_box(array('title' => 'Domain checks — ' . $focus_domain));
	if (empty($domain_rows)) {
		echo '<p class="text-muted mb-0">No domain checks returned.</p>';
	} else {
		echo '<p class="text-muted small mb-3">DNS takes a while to travel. If you just published, wait a few '
			. 'minutes and check again.</p>';
		foreach ($domain_rows as $r) { $render_check($r); }
	}
	$page->end_box();

	if (empty($focus_domain_has_mailboxes)) {
		$page->begin_box(array('title' => 'Mailboxes — ' . $focus_domain));
		echo '<p class="mb-2">No mailbox on this domain yet. Receiving and forwarding are verified against a real '
			. 'address, so those checks appear once you add one.</p>';
		echo '<a class="btn btn-primary" href="/plugins/mailbox/admin/admin_mailbox_accounts">Add a mailbox</a>';
		$page->end_box();
	}
}

if ($selected) {
	$arrival_label = $arrival === 'imap' ? 'pulled by IMAP'
		: ($arrival === 'webhook' ? 'received via webhook provider' : 'received by this mail server');

	$page->begin_box(array('title' => 'Receiving — ' . $address));
	echo '<p class="text-muted small mb-3">Mail for this address is ' . htmlspecialchars($arrival_label) . '.</p>';
	if (empty($receiving_rows)) {
		echo '<p class="text-muted mb-0">No receiving checks apply to this mailbox.</p>';
	} else {
		foreach ($receiving_rows as $r) { $render_check($r); }
	}
	$page->end_box();

	if ($forwards || !empty($forwarding_rows)) {
		$page->begin_box(array('title' => $forwards ? 'Forwarding' : 'Sending'));
		echo '<p class="text-muted small mb-3">' . ($forwards
			? 'This mailbox forwards mail back out, so outbound delivery must work too.'
			: 'Replies and new mail composed from this mailbox leave through the outbound stack.') . '</p>';
		if (empty($forwarding_rows)) {
			echo '<p class="text-muted mb-0">No forwarding checks available.</p>';
		} else {
			foreach ($forwarding_rows as $r) { $render_check($r); }
		}
		$page->end_box();
	}
}

// =====================================================================
// Advanced — server-wide setup & diagnostics
// =====================================================================
// Links only — every form and button on the page posts to $self_url, which
// already carries the focus and the advanced state. $adv_base and $adv_focus_qs
// are built once, above, where the sending-identity closure also needs them.
if (!$advanced) {
	$sep = $adv_focus_qs !== '' ? '&' : '?';
	echo '<p class="mt-3"><a href="' . htmlspecialchars($adv_base . $sep . 'advanced=1') . '">Advanced server setup &amp; diagnostics &rarr;</a></p>';
} else {
	echo '<hr class="my-4">';
	echo '<h4 class="mb-1">Advanced server setup</h4>';
	echo '<p class="text-muted small mb-3">Server-wide settings and the full inbound health run — shared by every hosted mailbox. '
		. '<a href="' . htmlspecialchars($adv_base) . '">Hide advanced</a></p>';

	// Rendered at the top instead while the ceremony is open — see the closure.
	if (!$setup_open) {
		$render_sending_identity();
	}

	// --- How mail reaches this server ---
	// A deployment-wide fact with a sensible default, so it is a setting rather
	// than a question in the way. It used to gate every mailbox surface until it
	// was answered, which asked the operator to decide about relay
	// infrastructure before they had a domain that needed any.
	$page->begin_box(array('title' => 'How mail reaches this server'));
	echo mailbox_receive_gate_render();
	$page->end_box();

	// --- Relay ---
	// Server-wide, and mostly provisioning: the cards above report its state,
	// this is where one is set up, rebuilt, enabled or removed.
	if (!empty($relay_section)) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/relay_section.php'));
		mailbox_relay_section_render($page, $relay_section);
	}

	// THE INBOUND PROVIDER LIVES ON THE SETTINGS TAB, with how sent mail leaves.
	// It was always a declared setting — group `inbound`, options resolved from
	// InboundProviderRegistry — and this tab hand-rendered it anyway. Setup is
	// where you come to see whether things work; changing which stack receives
	// your mail is not that. The webhook URL moved with it, because it is useless
	// without the control that produces it.

	// --- Server mail identity ---
	$page->begin_box(array('title' => "This server's mail identity"));
	$formwriter = $page->getFormWriter('setup_form', array('action' => $self_url));
	echo $formwriter->begin_form();
	$formwriter->textinput('mail_hostname', 'Mail server hostname', array(
		'value'       => $mail_hostname,
		'placeholder' => 'mail.example.com',
		'helptext'   => 'The fully-qualified name of THIS mail server — the target of your MX records, its HELO name, and its reverse-DNS name.',
	));
	if ($public_ip_private) {
		$formwriter->addError('public_ip',
			'Auto-detection found ' . $public_ip . ', a private address. Enter this server\'s public IP here.');
	}
	$formwriter->textinput('public_ip', 'Mail server public IP', array(
		'value'       => $configured_public_ip,
		'placeholder' => $public_ip !== '' ? 'auto-detected: ' . $public_ip : 'auto-detected',
		'helptext'   => 'Leave blank to auto-detect. Set this only if the server is behind NAT and auto-detection finds a private address.',
	));
	$formwriter->submitbutton('btn_save', 'Save & Run Checks');
	echo $formwriter->end_form();
	$page->end_box();

	// --- DNS publish box, once there is nothing left to fix ---
	// Above the copy-paste table, which is the manual version of the same thing.
	if (!$setup_open && !empty($dns_box) && !empty($dns_box_in_advanced)) {
		require_once(PathHelper::getIncludePath('includes/dns/dns_publish_box.php'));
		dns_publish_box_render($page, $dns_box);
	}

	// THE PROVIDER-SUPPLIED RECORDS TABLE IS GONE, and must not come back.
	//
	// It rendered $active_provider_class::getDnsRecords(), a fixed list that
	// predates both the relay and the security levels and reads neither. On a
	// relay-fronted Fortress domain it prescribed the colocated shape — MX at
	// mail.<domain>, SPF publishing this server's own IP, DMARC p=none — under
	// the heading "DNS records to publish". Copying those three records moves the
	// MX off the relay so mail arrives unsealed, publishes the address the relay
	// exists to hide, and drops the domain's impersonation protection. It sat
	// directly beneath the publish box, which was showing the correct records at
	// the same time.
	//
	// Everything it offered, dnsPlan() already produces correctly: the publish box
	// renders each value in a copy field, and where no driver covers the domain's
	// DNS host the check rows carry the same records in their fix. One source of
	// truth for what to publish, which is what docs/dns_management.md has always
	// said it must be.

	// --- Full server-wide diagnostic ---
	//
	// WHAT IS LEFT, NOT EVERYTHING AGAIN. The sections above are a filter over
	// this same battery, so rendering the unfiltered run here repeated almost all
	// of them: on a focused mailbox, 14 of the 15 cards above came back a second
	// time and the page ran to 40 cards for 26 facts. A page that says everything
	// twice is one an operator learns to skim, which is how the row that mattered
	// gets missed.
	//
	// The subtraction is against what was actually rendered, not a fixed list, so
	// a focus that shows nothing above (a domain with no mailbox, no selection at
	// all) still gets the complete run here.
	$remaining = array();
	foreach ($results as $r) {
		if (empty($rendered_checks[($r['id'] ?? '') . '|' . ($r['scope'] ?? '')])) {
			$remaining[] = $r;
		}
	}
	$page->begin_box(array('title' => 'Full inbound health run'
		. ($focus_domain !== '' ? ' (' . $focus_domain . ')' : '')));
	if (empty($results)) {
		echo '<p class="text-muted mb-0">No checks returned.</p>';
	} elseif (empty($remaining)) {
		echo '<p class="text-muted mb-0">Every check for this mailbox is already shown above &mdash; '
			. 'there is nothing server-wide left to report.</p>';
	} else {
		if (count($remaining) < count($results)) {
			echo '<p class="text-muted small mb-3">The server-wide checks. '
				. (count($results) - count($remaining))
				. ' more are shown above, scoped to this mailbox, and are not repeated here.</p>';
		}
		$by_layer = array();
		foreach ($remaining as $r) { $by_layer[$r['layer']][] = $r; }
		$layer_titles = array(
			'host'     => 'Mail server software',
			'mailhost' => "This server's mail identity",
			'domain'   => 'Domain DNS',
			'plugin'   => 'Plugin configuration',
			'address'  => 'Delivery target',
			'e2e'      => 'End-to-end',
		);
		foreach ($layer_titles as $layer => $title) {
			if (empty($by_layer[$layer])) { continue; }
			echo '<h6 class="text-muted mt-3">' . htmlspecialchars($title) . '</h6>';
			foreach ($by_layer[$layer] as $r) { $render_check($r); }
		}
		// Any layers not in the title map (defensive).
		foreach ($by_layer as $layer => $rows) {
			if (isset($layer_titles[$layer])) { continue; }
			echo '<h6 class="text-muted mt-3">' . htmlspecialchars($layer) . '</h6>';
			foreach ($rows as $r) { $render_check($r); }
		}
	}
	$page->end_box();
}

$page->admin_footer();
?>
