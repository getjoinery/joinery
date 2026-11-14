<?php
/** @joinery-admin class=restricted */
/**
 * Inbound Email - Setup & Verification (mailbox-first)
 *
 * Pick a registered mailbox; the page checks its setup, grouped into Receiving
 * (always) plus Forwarding (when the mailbox forwards) or Sending (relay and
 * DKIM signing, when it stores only — composed replies still leave the
 * server). Everything that belongs to the whole server — the relay, this
 * server's mail hostname/IP, the full health run — is on the Server tab
 * (admin_mailbox_server.php), so nothing here waits on a picker it does not need.
 *
 * The picker also lists any domain that has no mailbox yet. Its setup is
 * domain-level, so that state renders the guided steps, the publish box and the
 * domain-level checks, and skips the per-address ones.
 *
 * This tab is also the whole surface for outbound send protection — there is no
 * separate ceremony page — ALL of it lives in the Sending identity box, at the
 * foot of the focused domain's setup (at its top while it is the task): who owns
 * the key, the offer, the cost, the publish step, the switch, and the
 * lifecycle afterwards. Send protection is the "Only send while I'm signed in"
 * add-on, switched on in the domain editor: a Private domain that never asked
 * for it is finished as it is, and this box appears only once it has been asked
 * for (specs/mailbox_relay_surface_simplification.md).
 *
 * Every control on the page posts to $self_url, which carries the focused
 * mailbox or domain: a form that posts to the bare path loses the focus and the
 * redirect lands the operator back on the picker.
 *
 * @version 3.20 - who owns the signing key is chosen in the Sending identity box (and changed there
 *                until protection is on); while it is unanswered the box leads the page
 * @version 3.19 - no Advanced section: server-wide work is the Server tab; the Sending identity
 *                box and a finished domain's publish box sit at the foot of the domain's setup
 * @version 3.18 - a site container has no mail identity box (it has no mail server)
 * @version 3.17 - the full health run groups Domain DNS by domain; a domain with nothing to
 *                act on folds to one line
 * @version 3.16 - Advanced: one Relay section (the "How mail reaches this server" box folds into
 *                it); this server's mail identity shows its values with an Edit link
 * @version 3.15 - protection is Private plus add-ons: the relay step follows Seal at
 *                the relay, the finishing step follows a requested sending lock
 * @version 3.14 - SRV fields are their own columns (Priority/Weight/Port), matching provider forms
 * @version 3.13 - a fix can carry several records (dns_records): one table, one row each
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
 *                the arrival sealing a relay-sealed domain already has
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
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/setup_check_render.php'));
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

// How mail reaches this server — direct or through a relay — is a deployment
// fact that lives on the Server tab. An undecided deployment receives directly
// and works. The relay's STATE still reads as a card in Receiving, so the
// common path sees the relay where it matters.
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));

// The per-check "Details & how to fix" disclosure reads as a link, not a field.

// Flash messages render in the AdminPage header (admin pages must not
// fetch or render session messages themselves).

// Check rows render through setup_check_render.php, shared with the Server tab.
$render_check = function ($c) use ($address) {
	mailbox_setup_render_check($c, $address);
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

// --- Sending identity (the sending-lock add-on) -----------------------------
// WHATEVER YOU ARE DOING BELONGS AT THE TOP OF THE PAGE. The box normally sits
// at the foot of the domain's setup, which is right for a control you have gone
// looking for — but the moment an operator opens the ceremony, it is the task,
// and leaving it at the foot meant every press reloaded the page to the top and
// sent them scrolling back down to find where they were. So the same box
// renders in one of two places: at the top while the ceremony is open or the
// key's owner is still to be chosen, and at the foot otherwise.
//
// Defined once as a closure rather than written twice, because two copies of a
// 170-line panel is how they drift apart.
$prot_hidden = array('ied_inbound_email_domain_id' => (int)($focus_domain_id ?? 0));
$setup_open  = !empty($protect_setup) && !empty($protect)
	&& empty($protect['is_protected']) && !empty($protect['has_key']);
// Asked for, and waiting on who owns the key: nothing else about send
// protection can start until that is answered, so it is the task too.
$owner_needed = !empty($protect) && $focus_domain !== ''
	&& empty($protect['is_protected']) && empty($protect['has_key']);
$si_at_top   = $setup_open || $owner_needed;
$focus_qs    = $selected_alias_id ? '?alias_id=' . (int)$selected_alias_id
	: ($selected_domain_id ? '?domain_id=' . (int)$selected_domain_id : '');
$focus_base  = $base . $focus_qs;
$setup_url   = $focus_base . ($focus_qs !== '' ? '&' : '?') . 'protect_setup=1';
// Leaving puts the box back at the foot of the page, and lands on it.
$leave_url   = $focus_base . '#sending-identity';

// Who the key belongs to. The owner is the only person who can ever send as the
// domain, so it is asked rather than guessed whenever more than one person
// could be it; the answer is given here, beside the steps it unblocks. The form
// carries protect_setup, so making the key lands on the next step, opened at
// the top of the page.
$render_owner_form = function (string $button, int $value) use ($page, $protect, $self_url, $focus_domain_id) {
	$own_form = $page->getFormWriter('domain_owner_form', array('action' => $self_url));
	echo $own_form->begin_form();
	$own_form->hiddeninput('ied_inbound_email_domain_id', '', array('value' => (int)$focus_domain_id));
	$own_form->hiddeninput('action', '', array('value' => 'protect_generate'));
	$own_form->hiddeninput('protect_setup', '', array('value' => '1'));
	$own_form->dropinput('owner_user_id', 'Key belongs to', array(
		'options' => $protect['owner_options'],
		'value'   => $value,
	));
	$own_form->submitbutton('btn_domain_owner', $button);
	echo $own_form->end_form();
};

$render_sending_identity = function () use ($page, $protect, $focus_domain, $focus_domain_id,
		$self_url, $prot_hidden, $setup_open, $setup_url, $leave_url, $protect_dns_box,
		$protect_preflight, $protect_signing_ready, $protect_vault_unlocked, $render_check, $render_owner_form) {
// --- Sending identity (the sending-lock add-on) ---
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
		// ASKED FOR, NOT YET FINISHED. The domain editor's "Only send while
		// I'm signed in" switch sealed a key the moment it was turned on, so
		// "has a key, not enforcing" is the one outstanding step. Say what
		// finishing it buys and what it costs, and where to switch it off
		// instead.
		echo '<p class="mb-2">You asked for <strong>Only send while I\'m signed in</strong> on '
			. htmlspecialchars($focus_domain) . '. Your signing key is made and locked in your vault; two steps '
			. 'are left before it protects anything. Once it is on, every other mail server on the internet '
			. 'rejects anything claiming to be from this domain that your sealed key did not sign — including '
			. 'anything sent by someone who breaks into this server.</p>';
		echo '<p class="mb-2">It costs you two things. If you would rather not pay them, switch it off on the '
			. 'domain itself and the domain is finished as it is:</p>';
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

	// No key at all. Switching the lock on seals one automatically, so this
	// only happens where it could not guess: a domain whose mailboxes already
	// have holders, where the admin switching it on need not be the person who
	// reads the mail. Asked here, first thing in the box.
	if (!$protect['is_protected'] && empty($protect['has_key'])) {
		if (!empty($protect['owner_obvious']) && empty($protect['default_owner_has_vault'])) {
			// The key would have been made when the switch was flipped; the only
			// thing missing is the vault it is locked inside.
			echo '<p class="mb-2"><strong>First, set up your vault.</strong> The key that signs mail as '
				. htmlspecialchars($focus_domain) . ' is locked inside it, so it has to exist before the key can '
				. 'be made. <a class="btn btn-sm btn-primary ms-2" href="/profile/security">Set up my vault</a></p>';
			echo '<p class="text-muted small mb-2">Then make the key here. It publishes nothing and changes no mail.</p>';
		} else {
			echo '<p class="mb-2"><strong>First, choose who this domain\'s signing key belongs to.</strong> Only '
				. 'the key\'s owner will ever be able to send as ' . htmlspecialchars($focus_domain) . ', and more '
				. 'than one person could be it. Making the key publishes nothing and changes no mail.</p>';
			if (empty($protect['default_owner_has_vault'])) {
				echo '<p class="text-muted small mb-2">The owner needs a vault first: the key is locked inside it.</p>';
			}
		}
		$render_owner_form('Make the key', (int)$protect['default_owner_id']);
	} elseif (!$protect['is_protected']) {
		// CHANGING THE OWNER MEANS A NEW KEY, unavoidably: the current one is
		// sealed to its owner's vault, and re-sealing it to somebody else would
		// need the plaintext, which only that owner can produce. Say so, because
		// the published DNS record has to be replaced afterwards. Once
		// protection is on, protect_generate refuses: lift it first.
		echo '<details class="mb-2"><summary class="fix-toggle small">Change who this key belongs to</summary>';
		echo '<div class="mt-2">';
		echo '<p class="text-muted small">The key that exists is locked to its owner\'s vault and cannot be '
			. 'handed to anyone else, so choosing a different person makes a fresh one. Its DNS record changes '
			. 'with it and has to be republished. Nothing is enforced yet, so no mail is affected.</p>';
		$render_owner_form('Make a new key for this person', (int)$protect['default_owner_id']);
		echo '</div></details>';
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
	// Who the key belongs to is asked by name, in the owner form above.
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
if ($si_at_top) {
	echo '<div id="sending-identity">';
	$render_sending_identity();
	echo '</div>';
}

// --- Machine sender ceremony (specs/mailbox_machine_sender_card.md) ---------
// The guided path for moving automated mail onto its own subdomain identity
// (mail.<domain>), so the bare domain stays reserved for people — and can be
// protected without breaking reminders and receipts. Opened from the
// Automated mail identity card; like the protect ceremony, the open task
// renders at the top of the page.
if (!empty($machine_setup) && $focus_domain !== '') {
	$machine_leave_url = $focus_base;
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
// ---- Guided setup for this domain's protection (Phase 3) ----
// Standard needs nothing beyond the per-mailbox checks below. Private adds the
// one-time vault ceremony; the Seal at the relay add-on adds the relay that
// seals mail at the door; the Only send while I'm signed in add-on, once asked
// for, adds the send-protection ceremony. These reuse the built flows — link,
// never reimplement. All of it is domain-level, so it renders for a focused
// domain exactly as it does for a focused mailbox, and must not depend on a
// mailbox existing.
$level          = $security_level ?? 'standard';
$dom_id         = (int)($focus_domain_id ?? 0);
$has_vault      = !empty($acting_has_vault);
$is_protected   = !empty($focus_is_protected);
$relay_addon_on = !empty($focus_relay_on);
$send_requested = !empty($focus_send_requested);
if ($dom_id && $level === 'private') {
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
	// and Sending, and the relay's lifecycle controls live on the Server tab.
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relays_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domains_class.php'));
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

	if ($relay_addon_on && $active_relay === null) {
		// The relay, shared by every domain that seals at the relay — so on the
		// second domain onward this is normally already done and says nothing.
		$disabled = mailbox_receive_relay_exists();
		$steps[] = function () use ($disabled) {
			if ($disabled) {
				// Set up and then switched off: a different problem from never
				// having done it, and a different fix.
				echo '<li class="mb-2"><strong>Your relay is not enabled.</strong> Until it is, mail reaches '
					. 'Joinery without being sealed at the door. '
					. '<a class="btn btn-sm btn-outline-secondary" href="/plugins/mailbox/admin/admin_mailbox_server#relay-section">Relay setup</a></li>';
			} else {
				echo '<li class="mb-2"><strong>The relay</strong> seals fresh mail before it reaches Joinery, for '
					. 'every domain with Seal at the relay on. Provision it once. '
					. '<a class="btn btn-sm btn-outline-secondary" href="/plugins/mailbox/admin/admin_mailbox_server#relay-section">Relay setup</a></li>';
			}
		};
	}

	// ASKED FOR, NOT YET FINISHED (specs/mailbox_fortress_send_protection_completion.md).
	// The member switched on "Only send while I'm signed in"; until the protect
	// ceremony finishes, anybody can still send as this domain. A Private
	// domain that never asked has nothing outstanding here.
	//
	// ORDERED LAST, AND GATED. Offering the sending half before mail is
	// arriving where it should asks someone to finish what has not started. It
	// renders once this domain's MX points where it should (and, with relay
	// sealing on, once the relay is live) — and it disappears the moment
	// protection is on, so it never narrates back a step already taken.
	if ($send_requested && !$is_protected
			&& (!$relay_addon_on || $active_relay !== null)
			&& _setup_domain_mx_is_cut_over($domain_rows ?? array())) {
		$steps[] = function () use ($setup_url) {
			echo '<li class="mb-0"><strong>Finish Only send while I\'m signed in.</strong> '
				. 'You asked for nobody to be able to send mail claiming to be you — including someone who has '
				. 'broken into this server. Until this is done, anyone can.'
				. '<div class="mt-2"><a class="btn btn-sm btn-primary" href="'
				. htmlspecialchars($setup_url) . '">Finish it under Sending identity</a></div>'
				. '<p class="text-muted small mb-0 mt-1">Nothing changes for your mail until the last step, '
				. 'and you can stop at any point.</p></li>';
		};
	}

	if (!empty($steps)) {
		// "Still to set up", not "Protected setup": the box only ever holds
		// outstanding work, and heading it with the protection's name made a
		// finished domain's remaining unrelated item read as though protection
		// itself were unfinished.
		$page->begin_box(array('title' => 'Still to set up — Private · ' . $focus_domain));
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
 * A domain with the sending lock cannot send unless its owner is signed in, so the Sending
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
// Once there is no DNS left to fix it moves to the foot of the page — a
// finished domain should not be led with an offer to configure it.
// NOT WHILE THE CEREMONY IS OPEN. The ceremony renders its own publish box, for
// the protected shape, and this one renders the ordinary shape — two panels with
// the same heading showing records that contradict each other, which is the exact
// failure the provider-supplied table was removed for. Mid-ceremony there is one
// correct set of records to publish, and it is the ceremony's.
if (!$setup_open && ($selected || $domain_selected) && !empty($dns_box) && empty($dns_box_last)) {
	require_once(PathHelper::getIncludePath('includes/dns/dns_publish_box.php'));
	dns_publish_box_render($page, $dns_box);
}

// A focused domain gets the domain-level checks — for a domain with the sending
// lock these ARE the protected-shape verification, so publishing has something to prove
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
// The foot of the domain's setup: optional undertakings and finished business
// =====================================================================
// Rendered at the top instead while it is the task — see the closure.
if (!$si_at_top) {
	echo '<div id="sending-identity">';
	$render_sending_identity();
	echo '</div>';
}

// A finished domain's publish box: nothing to fix, so it does not lead the page,
// but it stays reachable for re-publishing or checking the records.
if (!$setup_open && ($selected || $domain_selected) && !empty($dns_box) && !empty($dns_box_last)) {
	require_once(PathHelper::getIncludePath('includes/dns/dns_publish_box.php'));
	dns_publish_box_render($page, $dns_box);
}

$page->admin_footer();
?>
