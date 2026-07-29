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
 * separate ceremony page. The guided box carries the one forward action (turn it
 * on), and Advanced carries the lifecycle (replace the key, switch over, turn it
 * off, the return address) next to the relay's, which splits the same way.
 *
 * Every control on the page posts to $self_url, which carries the focused
 * mailbox or domain: a form that posts to the bare path loses the focus and the
 * redirect lands the operator back on the picker.
 *
 * @version 2.9
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

// The relay-or-direct choice comes before everything else — until it is made,
// the mailbox surfaces show only the choice card.
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/receive_mode.php'));
if (mailbox_receive_mode() === '') {
	echo mailbox_receive_gate_render();
	$page->admin_footer();
	return;
}

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

$render_check = function ($c) use ($status_badge, $render_fix) {
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

// =====================================================================
// Scoped results for the chosen mailbox or domain
// =====================================================================
// ---- Guided setup for this domain's security level (Phase 3) ----
// Standard needs nothing beyond the per-mailbox checks below; Private and
// Fortress add the one-time vault ceremony and (Fortress) the protect ceremony,
// relay, and the session-gated-send confirmation. These reuse the built flows —
// link, never reimplement. All of it is domain-level, so it renders for a
// focused domain exactly as it does for a focused mailbox: this box is the way
// back to the protect ceremony, and it must not depend on a mailbox existing.
$level        = $security_level ?? 'standard';
$dom_id       = (int)($focus_domain_id ?? 0);
$has_vault    = !empty($acting_has_vault);
$is_protected = !empty($focus_is_protected);
if ($dom_id && ($level === 'private' || $level === 'fortress')) {
	$level_name = $level === 'fortress' ? 'Fortress' : 'Private';
	$page->begin_box(array('title' => 'Protected setup — ' . $level_name . ' · ' . $focus_domain));
	echo '<ol class="mb-0">';

	// Step 1 (both): the vault, once across all protected domains.
	if ($has_vault) {
		echo '<li class="mb-2">Your vault is ready — new mail to this domain is sealed the moment it arrives. '
			. 'Existing mail converges to sealed form the first time you unlock.</li>';
	} else {
		echo '<li class="mb-2"><strong>Set up your vault</strong> — a one-time step for every protected domain. '
			. 'Enroll a passkey and print your recovery codes: lose every unlocker and the mail is gone for good. '
			. '<a class="btn btn-sm btn-primary" href="/profile/security">Set up your vault</a></li>';
	}

	// Fortress-only steps: turn protection on, the relay, session-gated send.
	if ($level === 'fortress') {
		if ($is_protected) {
			echo '<li class="mb-2">Nobody can send mail as this domain while you are signed out — <strong>protection '
				. 'is on</strong>. Replacing the key or turning it off lives under Advanced, below.</li>';
		} elseif (!empty($protect) && !$protect['has_key']) {
			// The one thing that cannot be worked out: whose key this becomes.
			echo '<li class="mb-2"><strong>Say who the signing key belongs to.</strong> Only that person will ever be '
				. 'able to send as this domain. Mail here already belongs to somebody, so this is not ours to guess.';
			$own_form = $page->getFormWriter('owner_form', array('action' => $self_url));
			echo $own_form->begin_form();
			$own_form->hiddeninput('ied_inbound_email_domain_id', '', array('value' => $dom_id));
			$own_form->hiddeninput('action', '', array('value' => 'protect_generate'));
			$own_form->dropinput('owner_user_id', 'Key belongs to', array(
				'options' => $protect['owner_options'],
				'value'   => $protect['default_owner_id'],
			));
			$own_form->submitbutton('btn_protect_generate', 'Make the key');
			echo $own_form->end_form();
			echo '</li>';
		} else {
			// The signing key already exists — the raise sealed it. All that is
			// left is the part only the operator can do: publish, then enforce.
			echo '<li class="mb-2"><strong>Publish the DNS records below, then turn protection on.</strong> '
				. 'They tell every other mail server to reject mail claiming to be from you that your key did not '
				. 'sign. Nothing is enforced, and your mail is unaffected, until you press this.';
			echo '<div class="mt-2">' . PublicPageBase::action_button('Check DNS and turn on protection', $self_url,
				array('hidden' => array('ied_inbound_email_domain_id' => $dom_id, 'action' => 'protect_activate'),
					'class' => 'btn btn-sm btn-primary')) . '</div>';
			echo '<p class="text-muted small mb-0 mt-1">Needs your vault unlocked — from here on only your key can '
				. 'send as this domain.</p>';
			echo '</li>';
		}
		echo '<li class="mb-2"><strong>The relay</strong> fronts every Fortress domain and seals fresh mail before it reaches Joinery. '
			. 'Provision it once (shared by all Fortress domains). '
			. '<a class="btn btn-sm btn-outline-secondary" href="#relay-section">Relay setup</a></li>';
		echo '<li class="mb-0"><strong>This domain cannot send mail unless you are signed in.</strong> '
			. 'For automated mail (confirmations, notifications), add a Standard subdomain: '
			. '<a class="btn btn-sm btn-outline-secondary" href="/plugins/mailbox/admin/admin_mailbox_domains?action=add&prefill_domain='
			. rawurlencode('mail.' . $focus_domain) . '">Add a Standard subdomain for automated mail</a></li>';
	}
	echo '</ol>';
	$page->end_box();
}

// The DNS publish box: everything below this point is a check, and this is
// the one control that can make those checks pass without leaving the page.
// Once there is no DNS left to fix it moves to Advanced — a finished domain
// should not be led with an offer to configure it.
if (($selected || $domain_selected) && !empty($dns_box) && empty($dns_box_in_advanced)) {
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

	$page->begin_box(array('title' => 'Mailboxes — ' . $focus_domain));
	echo '<p class="mb-2">No mailbox on this domain yet. Receiving and forwarding are verified against a real '
		. 'address, so those checks appear once you add one.</p>';
	echo '<a class="btn btn-primary" href="/plugins/mailbox/admin/admin_mailbox_accounts">Add a mailbox</a>';
	$page->end_box();
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
// already carries the focus and the advanced state.
$adv_focus_qs = $selected_alias_id ? '?alias_id=' . (int)$selected_alias_id
	: ($selected_domain_id ? '?domain_id=' . (int)$selected_domain_id : '');
$adv_base = $base . $adv_focus_qs;
if (!$advanced) {
	$sep = $adv_focus_qs !== '' ? '&' : '?';
	echo '<p class="mt-3"><a href="' . htmlspecialchars($adv_base . $sep . 'advanced=1') . '">Advanced server setup &amp; diagnostics &rarr;</a></p>';
} else {
	echo '<hr class="my-4">';
	echo '<h4 class="mb-1">Advanced server setup</h4>';
	echo '<p class="text-muted small mb-3">Server-wide settings and the full inbound health run — shared by every hosted mailbox. '
		. '<a href="' . htmlspecialchars($adv_base) . '">Hide advanced</a></p>';

	// --- Sending identity (Fortress) ---
	// Same split as the relay below it: the guided box above reports the state
	// and offers the one forward action, and the lifecycle — replacing the key,
	// switching over, turning it off, the return address — lives down here.
	// None of it belongs in a setup path; all of it has to stay reachable.
	if (!empty($protect) && $focus_domain !== '') {
		$page->begin_box(array('title' => 'Sending identity — ' . $focus_domain));
		$prot_hidden = array('ied_inbound_email_domain_id' => (int)$focus_domain_id);

		if ($protect['is_protected']) {
			echo '<p class="mb-2">Protection is on. While you are signed out, nothing on this server can send mail '
				. 'as ' . htmlspecialchars($focus_domain) . ' that anyone will accept.</p>';
		} elseif ($protect['has_key']) {
			echo '<p class="mb-2">A signing key exists but nothing is enforced yet. Finish that from the checklist '
				. 'above.</p>';
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
		} elseif ($protect['has_key']) {
			echo PublicPageBase::action_button('Start over with a new key', $self_url,
				array('hidden' => $prot_hidden + array('action' => 'protect_generate'),
					'class' => 'btn btn-soft-default',
					'confirm' => 'This throws away the key you have and makes a fresh one. You will have to publish a '
						. 'new DNS record for it. Nothing is protected yet, so nothing breaks — continue?'));
		}
		if ($protect['is_protected']) {
			echo PublicPageBase::action_button('Turn protection off', $self_url,
				array('hidden' => $prot_hidden + array('action' => 'protect_disable'),
					'class' => 'btn btn-soft-danger',
					'confirm' => 'Turn protection off? This server will be able to send as this domain again without '
						. 'you being signed in.'));
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

	// --- Relay ---
	// Server-wide, and mostly provisioning: the cards above report its state,
	// this is where one is set up, rebuilt, enabled or removed.
	if (!empty($relay_section)) {
		require_once(PathHelper::getIncludePath('plugins/mailbox/includes/relay_section.php'));
		mailbox_relay_section_render($page, $relay_section);
	}

	// --- Inbound provider ---
	$page->begin_box(array('title' => 'Inbound provider'));
	echo '<p class="mb-2">How this site receives inbound mail. Switching is a single setting change — '
		. 'the same domain, alias and store machinery runs underneath.</p>';
	$pform = $page->getFormWriter('provider_form', array('action' => $self_url));
	echo $pform->begin_form();
	$pform->hiddeninput('action', '', array('value' => 'set_provider'));
	$pform->dropinput('provider', 'Provider', array(
		'options' => $provider_options,
		'value'   => $active_provider_key,
	));
	$pform->submitbutton('btn_provider', 'Use this provider');
	echo $pform->end_form();
	if ($active_provider_is_webhook && $webhook_url !== '') {
		echo '<p class="mt-3 mb-0"><strong>Webhook URL.</strong> Configure your provider to POST inbound mail to:<br>';
		echo '<code class="iem-code-break">' . htmlspecialchars($webhook_url) . '</code></p>';
	}
	$page->end_box();

	// --- Server mail identity ---
	$page->begin_box(array('title' => "This server's mail identity"));
	$formwriter = $page->getFormWriter('setup_form', array('action' => $self_url));
	echo $formwriter->begin_form();
	$formwriter->textinput('mail_hostname', 'Mail server hostname', array(
		'value'       => $mail_hostname,
		'placeholder' => 'mail.example.com',
		'help_text'   => 'The fully-qualified name of THIS mail server — the target of your MX records, its HELO name, and its reverse-DNS name.',
	));
	if ($public_ip_private) {
		$formwriter->addError('public_ip',
			'Auto-detection found ' . $public_ip . ', a private address. Enter this server\'s public IP here.');
	}
	$formwriter->textinput('public_ip', 'Mail server public IP', array(
		'value'       => $configured_public_ip,
		'placeholder' => $public_ip !== '' ? 'auto-detected: ' . $public_ip : 'auto-detected',
		'help_text'   => 'Leave blank to auto-detect. Set this only if the server is behind NAT and auto-detection finds a private address.',
	));
	$formwriter->submitbutton('btn_save', 'Save & Run Checks');
	echo $formwriter->end_form();
	$page->end_box();

	// --- DNS publish box, once there is nothing left to fix ---
	// Above the copy-paste table, which is the manual version of the same thing.
	if (!empty($dns_box) && !empty($dns_box_in_advanced)) {
		require_once(PathHelper::getIncludePath('includes/dns/dns_publish_box.php'));
		dns_publish_box_render($page, $dns_box);
	}

	// --- Provider-supplied DNS records for the focused domain ---
	if (!empty($dns_records) && $focus_domain !== '') {
		$page->begin_box(array('title' => 'DNS records to publish for ' . $focus_domain));
		echo '<p class="mb-2">Copy these into your DNS provider for <code>' . htmlspecialchars($focus_domain) . '</code>:</p>';
		echo '<table class="table table-sm table-bordered iem-table-900">';
		echo '<thead><tr><th>Type</th><th>Name</th><th>Value</th><th>Note</th></tr></thead><tbody>';
		foreach ($dns_records as $rec) {
			echo '<tr>';
			echo '<td>' . htmlspecialchars($rec['type']) . '</td>';
			echo '<td><code>' . htmlspecialchars($rec['name']) . '</code></td>';
			echo '<td>' . PublicPageBase::copy_field($rec['value']) . '</td>';
			echo '<td class="text-muted small">' . htmlspecialchars($rec['note'] ?? '') . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		$page->end_box();
	}

	// --- Full server-wide diagnostic ---
	$page->begin_box(array('title' => 'Full inbound health run'
		. ($focus_domain !== '' ? ' (' . $focus_domain . ')' : '')));
	if (empty($results)) {
		echo '<p class="text-muted mb-0">No checks returned.</p>';
	} else {
		$by_layer = array();
		foreach ($results as $r) { $by_layer[$r['layer']][] = $r; }
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
