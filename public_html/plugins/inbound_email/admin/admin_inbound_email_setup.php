<?php
/**
 * Inbound Email - Guided Setup & Verification
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
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

// Tab navigation
echo '<ul class="nav nav-tabs mb-3">';
echo '<li class="nav-item"><a class="nav-link active" href="/plugins/inbound_email/admin/admin_inbound_email_setup">Setup</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/inbound_email/admin/admin_inbound_email">Forwarding Aliases</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/inbound_email/admin/admin_inbound_email_domains">Domains</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/inbound_email/admin/admin_inbound_email_logs">Logs</a></li>';
echo '</ul>';

// Session messages
$display_messages = $session->get_messages('/plugins\/inbound_email\/admin\//');
if (!empty($display_messages)) {
	foreach ($display_messages as $msg) {
		echo '<div class="alert alert-success">' . htmlspecialchars($msg->message) . '</div>';
	}
	$session->clear_clearable_messages();
}

// --- Setup form: the address being set up + the mail-server identity ---
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

$formwriter->textinput('public_ip', 'Public IP override', [
	'value'       => $public_ip,
	'placeholder' => $public_ip !== '' ? $public_ip : 'auto-detected',
	'help_text'   => 'Leave blank to auto-detect. Set this only if the server is behind NAT and auto-detection finds a private address.',
]);

$formwriter->submitbutton('btn_save', 'Save & Run Checks');
echo $formwriter->end_form();

if ($public_ip !== '') {
	echo '<p class="text-muted mb-0"><small>Detected/!configured server IP: <code>' . htmlspecialchars($public_ip) . '</code>';
	if ($public_ip_private) {
		echo ' &mdash; <span class="text-warning">this is a private address; set a public IP override above.</span>';
	}
	echo '</small></p>';
}

$page->end_box();

// --- Summary banner ---
$fail = $counts[InboundEmailSetupCheck::FAIL];
$warn = $counts[InboundEmailSetupCheck::WARN];
$unknown = $counts[InboundEmailSetupCheck::UNKNOWN];

if ($fail === 0 && $unknown === 0) {
	$banner_class = 'alert-success';
	$banner_text = $warn > 0
		? 'All required checks pass. ' . $warn . ' recommended improvement(s) remain.'
		: 'Setup is complete — all checks pass.';
} elseif ($fail === 0) {
	$banner_class = 'alert-warning';
	$banner_text = 'All required checks pass, but ' . $unknown . ' check(s) could not be evaluated (try Re-check).';
} else {
	$banner_class = 'alert-danger';
	$banner_text = $fail . ' required item(s) need attention'
		. ($warn > 0 ? ', plus ' . $warn . ' recommended improvement(s)' : '') . '.';
}
echo '<div class="alert ' . $banner_class . ' d-flex justify-content-between align-items-center">';
echo '<span>' . htmlspecialchars($banner_text) . '</span>';
$recheck_url = '/plugins/inbound_email/admin/admin_inbound_email_setup'
	. ($address !== '' ? '?address=' . urlencode($address) : '');
echo '<a href="' . htmlspecialchars($recheck_url) . '" class="btn btn-sm btn-outline-secondary">Re-check</a>';
echo '</div>';

// --- Checklist ---
$status_badge = function ($status) {
	switch ($status) {
		case InboundEmailSetupCheck::PASS:    return '<span class="badge bg-success">PASS</span>';
		case InboundEmailSetupCheck::FAIL:    return '<span class="badge bg-danger">FAIL</span>';
		case InboundEmailSetupCheck::WARN:    return '<span class="badge bg-warning text-dark">CHECK</span>';
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
		$label = $act['action'] === 'enable_plugin' ? 'Enable inbound email'
			: ($act['action'] === 'add_domain' ? 'Add this domain' : 'Apply fix');
		echo '<button type="submit" class="btn btn-sm btn-primary">' . htmlspecialchars($label) . '</button>';
		echo '</form>';
	}
};

$render_check = function ($c) use ($status_badge, $render_fix) {
	$border = $c['status'] === InboundEmailSetupCheck::FAIL ? 'border-danger'
		: ($c['status'] === InboundEmailSetupCheck::WARN ? 'border-warning'
		: ($c['status'] === InboundEmailSetupCheck::PASS ? 'border-success' : 'border-secondary'));
	echo '<div class="card mb-2 ' . $border . '"><div class="card-body py-2">';
	echo '<div>' . $status_badge($c['status']) . ' <strong>' . htmlspecialchars($c['label']) . '</strong>';
	if ($c['severity'] === InboundEmailSetupCheck::RECOMMENDED) {
		echo ' <span class="badge bg-light text-dark">recommended</span>';
	}
	echo '</div>';
	echo '<div class="mt-1">' . htmlspecialchars($c['summary']) . '</div>';
	if (!empty($c['detail']) || !empty($c['fix'])) {
		echo '<details class="mt-1"><summary class="text-muted small">Details &amp; how to fix</summary>';
		echo '<div class="mt-2">';
		if (!empty($c['detail'])) {
			echo '<p class="text-muted small mb-2">' . htmlspecialchars($c['detail']) . '</p>';
		}
		$render_fix($c['fix']);
		echo '</div></details>';
	}
	echo '</div></div>';
};

$layer_titles = array(
	'host'     => 'Mail server software',
	'mailhost' => "This server's mail identity",
	'domain'   => 'DNS records',
	'plugin'   => 'Plugin configuration',
	'address'  => 'Delivery target',
	'e2e'      => 'End-to-end test',
);

foreach ($layer_titles as $layer => $title) {
	$rows = array_values(array_filter($results, function ($r) use ($layer) {
		return $r['layer'] === $layer;
	}));
	if (empty($rows)) { continue; }

	if ($layer === 'domain') {
		// Group domain-layer rows under a heading per domain.
		$by_scope = array();
		foreach ($rows as $r) { $by_scope[$r['scope']][] = $r; }
		foreach ($by_scope as $scope => $scope_rows) {
			$page->begin_box(array('title' => 'DNS records for ' . $scope));
			foreach ($scope_rows as $r) { $render_check($r); }
			$page->end_box();
		}
		continue;
	}

	$page->begin_box(array('title' => $title));
	foreach ($rows as $r) { $render_check($r); }
	$page->end_box();
}

if ($address === '' && $focus_domain === '') {
	echo '<p class="text-muted"><small>Enter an email address above to also check its delivery target '
		. 'and run the end-to-end test.</small></p>';
}

$page->admin_footer();
?>
