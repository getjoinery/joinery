<?php
/**
 * Inbound Email - Guided Setup & Verification
 *
 * Presents InboundEmailSetupCheck's results as an ordered, focused wizard:
 * the engine's layers are grouped into five dependency-ordered steps, only the
 * first unfinished step is expanded ("Do this next"), and the rest collapse to
 * a one-line summary.
 *
 * @version 1.6
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/InboundEmailSetupCheck.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/logic/admin_inbound_email_setup_logic.php'));

$page_vars = process_logic(admin_inbound_email_setup_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'incoming',
		'breadcrumbs' => array(
			'Inbound Email' => '/plugins/inbound_email/admin/admin_inbound_email',
			'Setup' => '',
		),
		'session' => $session,
	)
);

echo AdminPage::tab_menu(inbound_email_admin_tabs(), 'Setup');

// The per-check "Details & how to fix" disclosure reads as a link, not a field.
echo '<style>'
	. 'summary.fix-toggle{cursor:pointer;color:#6c757d}'
	. 'summary.fix-toggle:hover{color:#0d6efd;text-decoration:underline}'
	. '</style>';

// Session messages
$display_messages = $session->get_messages('/plugins\/inbound_email\/admin\//');
if (!empty($display_messages)) {
	foreach ($display_messages as $msg) {
		echo '<div class="alert alert-success">' . htmlspecialchars($msg->message) . '</div>';
	}
	$session->clear_clearable_messages();
}

// --- Step 1: Inbound provider picker ---
$page->begin_box(array('title' => 'Inbound provider'));
echo '<p class="mb-2">Choose how this site receives inbound mail. Switching is a single setting change — '
	. 'the same domain, alias and store machinery runs underneath.</p>';
echo '<form method="post" class="d-inline-block">';
echo '<input type="hidden" name="action" value="set_provider">';
echo '<div class="d-flex gap-2 align-items-center">';
echo '<select name="provider" class="form-select form-select-sm">';
foreach ($provider_options as $key => $label) {
	$sel = ($key === $active_provider_key) ? ' selected' : '';
	echo '<option value="' . htmlspecialchars($key) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
}
echo '</select>';
echo '<button type="submit" class="btn btn-sm btn-primary">Use this provider</button>';
echo '</div>';
echo '</form>';
if ($active_provider_is_webhook && $webhook_url !== '') {
	echo '<p class="mt-3 mb-0"><strong>Webhook URL.</strong> Configure your provider to POST inbound mail to:<br>';
	echo '<code style="word-break:break-all">' . htmlspecialchars($webhook_url) . '</code></p>';
}
$page->end_box();

// --- Step 2: address + mail-server identity ---
$page->begin_box(array('title' => 'What are you setting up?'));

$formwriter = $page->getFormWriter('setup_form');
echo $formwriter->begin_form();

$formwriter->textinput('address', 'Email address you are setting up', [
	'value'       => $address,
	'placeholder' => 'info@example.com',
	'help_text'   => 'The checks below are scoped to this address and its domain. Leave blank to check every registered domain.',
]);

$formwriter->textinput('mail_hostname', 'Mail server hostname', [
	'value'       => $mail_hostname,
	'placeholder' => 'mail.example.com',
	'help_text'   => 'The fully-qualified name of THIS mail server — the target of your MX records, its HELO name, and its reverse-DNS name. One per server, separate from the mail domains it serves.',
]);

if ($public_ip_private) {
	$formwriter->addError('public_ip',
		'Auto-detection found ' . $public_ip . ', a private address. Enter this server\'s public IP here.');
}
$formwriter->textinput('public_ip', 'Mail server public IP', [
	'value'       => $configured_public_ip,
	'placeholder' => $public_ip !== '' ? 'auto-detected: ' . $public_ip : 'auto-detected',
	'help_text'   => 'Leave blank to auto-detect. Set this only if the server is behind NAT and auto-detection finds a private address.',
]);

$formwriter->submitbutton('btn_save', 'Save & Run Checks');
echo $formwriter->end_form();

$page->end_box();

// --- Provider-supplied DNS records for the focused domain ---
if (!empty($dns_records) && $focus_domain !== '') {
	$page->begin_box(array('title' => 'DNS records to publish for ' . htmlspecialchars($focus_domain)));
	echo '<p class="mb-2">Copy these into your DNS provider for <code>' . htmlspecialchars($focus_domain) . '</code>:</p>';
	echo '<table class="table table-sm table-bordered" style="max-width:900px">';
	echo '<thead><tr><th>Type</th><th>Name</th><th>Value</th><th>Note</th></tr></thead><tbody>';
	foreach ($dns_records as $rec) {
		echo '<tr>';
		echo '<td>' . htmlspecialchars($rec['type']) . '</td>';
		echo '<td><code>' . htmlspecialchars($rec['name']) . '</code></td>';
		echo '<td><input type="text" class="form-control form-control-sm" readonly '
			. 'style="cursor:pointer;background:#fff" value="' . htmlspecialchars($rec['value'])
			. '" onclick="this.select()"></td>';
		echo '<td class="text-muted small">' . htmlspecialchars($rec['note'] ?? '') . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	$page->end_box();
}

// --- Shared check-row renderers ---
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
		echo '<table class="table table-sm table-bordered mb-2" style="max-width:760px">';
		echo '<thead><tr><th>Type</th><th>Name</th><th>Value</th></tr></thead><tbody><tr>';
		echo '<td>' . htmlspecialchars($rec['type']) . '</td>';
		echo '<td><code>' . htmlspecialchars($rec['name']) . '</code></td>';
		echo '<td><input type="text" class="form-control form-control-sm" readonly '
			. 'style="cursor:pointer;background:#fff" value="' . htmlspecialchars($rec['value'])
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

// --- Step model: group the engine's layers into ordered setup steps ---
$steps = array(
	array('key' => 'host',     'title' => 'Mail server software',       'layers' => array('host')),
	array('key' => 'mailhost', 'title' => "This server's mail identity", 'layers' => array('mailhost')),
	array('key' => 'domain',   'title' => 'DNS for your domain',         'layers' => array('domain')),
	array('key' => 'plugin',   'title' => 'Plugin configuration',        'layers' => array('plugin')),
	array('key' => 'delivery', 'title' => 'Delivery target & test',      'layers' => array('address', 'e2e')),
);

foreach ($steps as &$step) {
	$rows = array_values(array_filter($results, function ($r) use ($step) {
		return in_array($r['layer'], $step['layers'], true);
	}));
	$req_fail = 0; $req_unknown = 0; $recommend_open = 0; $pass = 0;
	foreach ($rows as $r) {
		if ($r['status'] === InboundEmailSetupCheck::PASS) { $pass++; }
		if ($r['severity'] === InboundEmailSetupCheck::REQUIRED) {
			if ($r['status'] === InboundEmailSetupCheck::FAIL)    { $req_fail++; }
			if ($r['status'] === InboundEmailSetupCheck::UNKNOWN) { $req_unknown++; }
		} elseif ($r['status'] !== InboundEmailSetupCheck::PASS) {
			$recommend_open++;
		}
	}
	$step['rows']           = $rows;
	$step['needs_address']  = empty($rows);
	$step['done']           = (!empty($rows) && $req_fail === 0 && $req_unknown === 0);
	$step['req_open']       = $req_fail + $req_unknown;
	$step['recommend_open'] = $recommend_open;
	$step['pass']           = $pass;
	$step['total']          = count($rows);
}
unset($step);

// The active step is the first one that is not done.
$active = null;
foreach ($steps as $i => $step) {
	if (!$step['done']) { $active = $i; break; }
}

$recheck_url = '/plugins/inbound_email/admin/admin_inbound_email_setup'
	. ($address !== '' ? '?address=' . urlencode($address) : '');

// --- Progress strip ---
echo '<div class="card mb-3"><div class="card-body py-2">';
echo '<div class="d-flex flex-wrap" style="gap:1.5rem">';
foreach ($steps as $i => $step) {
	if ($step['done']) {
		$marker = '<span class="badge bg-success">&#10003;</span>';
		$cls = 'text-success';
	} elseif ($i === $active) {
		$marker = '<span class="badge bg-primary">' . ($i + 1) . '</span>';
		$cls = 'fw-bold';
	} else {
		$marker = '<span class="badge bg-light text-dark border">' . ($i + 1) . '</span>';
		$cls = 'text-muted';
	}
	echo '<span class="' . $cls . '">' . $marker . ' ' . htmlspecialchars($step['title']) . '</span>';
}
echo '</div>';
echo '<div class="d-flex justify-content-between align-items-center mt-2">';
if ($active === null) {
	echo '<span class="text-success"><strong>All five steps complete</strong> — inbound email is fully set up.</span>';
} else {
	echo '<span>Step ' . ($active + 1) . ' of ' . count($steps) . ': <strong>'
		. htmlspecialchars($steps[$active]['title']) . '</strong> &mdash; see the highlighted panel below.</span>';
}
echo '<a href="' . htmlspecialchars($recheck_url) . '" class="btn btn-sm btn-outline-secondary">Re-check</a>';
echo '</div>';
echo '</div></div>';

// --- Step panels ---
foreach ($steps as $i => $step) {
	$is_active = ($i === $active);

	if ($step['done']) {
		$head_class = 'bg-success text-white';
		$state_text = 'all required checks pass'
			. ($step['recommend_open'] > 0 ? ' · ' . $step['recommend_open'] . ' optional improvement(s)' : '');
	} elseif ($is_active) {
		$head_class = 'bg-primary text-white';
		$state_text = $step['needs_address']
			? 'enter the email address above to run this step'
			: $step['req_open'] . ' required item(s) to address'
				. ($step['recommend_open'] > 0 ? ' · ' . $step['recommend_open'] . ' optional' : '');
	} else {
		$head_class = 'bg-light text-muted';
		$state_text = $step['needs_address'] ? 'waiting on the email address above' : 'not started';
	}

	echo '<details class="card mb-2"' . ($is_active ? ' open' : '') . '>';
	echo '<summary class="card-header ' . $head_class . '" style="cursor:pointer">';
	echo '<strong>Step ' . ($i + 1) . ' &middot; ' . htmlspecialchars($step['title']) . '</strong>';
	echo ' &mdash; ' . htmlspecialchars($state_text);
	if ($is_active) { echo ' <span class="badge bg-white text-primary ms-1">Do this next</span>'; }
	echo '</summary>';
	echo '<div class="card-body">';

	if ($step['needs_address']) {
		echo '<p class="mb-0 text-muted">This step checks the domain of the address you are setting up. '
			. 'Enter that address in the form above and press "Save &amp; Run Checks".</p>';
	} elseif ($step['key'] === 'domain') {
		$by_scope = array();
		foreach ($step['rows'] as $r) { $by_scope[$r['scope']][] = $r; }
		foreach ($by_scope as $scope => $scope_rows) {
			echo '<h6 class="text-muted">' . htmlspecialchars($scope) . '</h6>';
			foreach ($scope_rows as $r) { $render_check($r); }
		}
	} else {
		foreach ($step['rows'] as $r) { $render_check($r); }
	}

	echo '</div></details>';
}

$page->admin_footer();
?>
