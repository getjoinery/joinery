<?php
/**
 * Inbound Email - Setup & Verification (mailbox-first)
 *
 * Pick a registered mailbox; the page checks its setup, grouped into Receiving
 * (always) and Forwarding (only when the mailbox forwards). Server-wide
 * diagnostics — the inbound provider, this server's mail hostname/IP, and the
 * full Postfix/relay health run — live behind the Advanced disclosure so they
 * don't clutter the per-mailbox view.
 *
 * @version 2.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundEmailSetupCheck.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/logic/admin_inbound_email_setup_logic.php'));

$page_vars = process_logic(admin_inbound_email_setup_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'incoming',
	'breadcrumbs' => array(
		'Inbound Email' => '/plugins/inbound_email/admin/admin_inbound_email_accounts',
		'Setup' => '',
	),
	'session' => $session,
));

echo AdminPage::tab_menu(inbound_email_admin_tabs(), 'Setup');

// The per-check "Details & how to fix" disclosure reads as a link, not a field.

// Session messages
$display_messages = $session->get_messages('/plugins\/inbound_email\/admin\//');
if (!empty($display_messages)) {
	foreach ($display_messages as $msg) {
		echo '<div class="alert alert-success">' . htmlspecialchars($msg->message) . '</div>';
	}
	$session->clear_clearable_messages();
}

// --- Shared check-row renderers (used by scoped sections and Advanced) ---
$status_badge = function ($status) {
	switch ($status) {
		case InboundEmailSetupCheck::PASS:    return '<span class="badge bg-success">PASS</span>';
		case InboundEmailSetupCheck::FAIL:    return '<span class="badge bg-danger">FAIL</span>';
		case InboundEmailSetupCheck::WARN:    return '<span class="badge bg-warning text-dark">WARN</span>';
		case InboundEmailSetupCheck::INFO:    return '<span class="badge bg-info text-dark">INFO</span>';
		default:                              return '<span class="badge bg-secondary">UNKNOWN</span>';
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
		echo '<table class="table table-sm table-bordered mb-2 iem-table-760">';
		echo '<thead><tr><th>Type</th><th>Name</th><th>Value</th></tr></thead><tbody><tr>';
		echo '<td>' . htmlspecialchars($rec['type']) . '</td>';
		echo '<td><code>' . htmlspecialchars($rec['name']) . '</code></td>';
		echo '<td><input type="text" class="form-control form-control-sm iem-copyfield" readonly '
			. 'value="' . htmlspecialchars($rec['value'])
			. '" onclick="this.select()"></td>';
		echo '</tr></tbody></table>';
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
	if ($c['severity'] === InboundEmailSetupCheck::RECOMMENDED) {
		echo ' <span class="badge bg-light text-dark">recommended</span>';
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
$page->begin_box(array('title' => 'Mailbox'));
if (empty($mailbox_options)) {
	echo '<div class="alert alert-info mb-0">No mailboxes yet. Add one on the '
		. '<a href="/plugins/inbound_email/admin/admin_inbound_email_accounts">Accounts</a> tab, then come back to verify it.</div>';
} else {
	$mbform = $page->getFormWriter('mbform', array('method' => 'GET', 'action' => $base));
	echo $mbform->begin_form();
	$mbform->dropinput('alias_id', 'Mailbox to check', array(
		'options' => $mailbox_options,
		'value'   => $selected_alias_id,
	));
	$mbform->submitbutton('btn_view', 'Check this mailbox');
	echo $mbform->end_form();
}
$page->end_box();

// =====================================================================
// Scoped results for the chosen mailbox
// =====================================================================
if ($selected) {
	$arrival_label = $arrival === 'imap' ? 'pulled by IMAP'
		: ($arrival === 'webhook' ? 'received via webhook provider' : 'received by this mail server');

	$recheck_url = $base . '?alias_id=' . (int)$selected_alias_id;
	echo '<p><a href="' . htmlspecialchars($recheck_url) . '" class="btn btn-sm btn-outline-secondary">Re-check</a></p>';

	$page->begin_box(array('title' => 'Receiving — ' . $address));
	echo '<p class="text-muted small mb-3">Mail for this address is ' . htmlspecialchars($arrival_label) . '.</p>';
	if (empty($receiving_rows)) {
		echo '<p class="text-muted mb-0">No receiving checks apply to this mailbox.</p>';
	} else {
		foreach ($receiving_rows as $r) { $render_check($r); }
	}
	$page->end_box();

	if ($forwards) {
		$page->begin_box(array('title' => 'Forwarding'));
		echo '<p class="text-muted small mb-3">This mailbox forwards mail back out, so outbound delivery must work too.</p>';
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
$adv_base = $base . ($selected_alias_id ? '?alias_id=' . (int)$selected_alias_id : '');
if (!$advanced) {
	$sep = $selected_alias_id ? '&' : '?';
	echo '<p class="mt-3"><a href="' . htmlspecialchars($adv_base . $sep . 'advanced=1') . '">Advanced server setup &amp; diagnostics &rarr;</a></p>';
} else {
	echo '<hr class="my-4">';
	echo '<h4 class="mb-1">Advanced server setup</h4>';
	echo '<p class="text-muted small mb-3">Server-wide settings and the full inbound health run — shared by every hosted mailbox. '
		. '<a href="' . htmlspecialchars($adv_base) . '">Hide advanced</a></p>';

	// --- Inbound provider ---
	$page->begin_box(array('title' => 'Inbound provider'));
	echo '<p class="mb-2">How this site receives inbound mail. Switching is a single setting change — '
		. 'the same domain, alias and store machinery runs underneath.</p>';
	$pform = $page->getFormWriter('provider_form', array('action' => $adv_base));
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
	$formwriter = $page->getFormWriter('setup_form', array('action' => $adv_base));
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
			echo '<td><input type="text" class="form-control form-control-sm iem-copyfield" readonly '
				. 'value="' . htmlspecialchars($rec['value'])
				. '" onclick="this.select()"></td>';
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
