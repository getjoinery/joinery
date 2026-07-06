<?php
/**
 * Inbound Email - Single Message Detail
 *
 * Displays a stored inbound message. The HTML body is rendered inside a
 * sandboxed iframe (no allow-scripts) because stored bodies are fully
 * attacker-controlled. Attachments are listed from the per-message manifest and
 * each links to the per-attachment download endpoint (bytes fetched on demand;
 * never stored). There is no raw/.eml view — retired for every transport.
 * Inline cid: images are already resolved to short-lived signed URLs by the
 * logic layer before the body reaches this view (the sandboxed iframe sends
 * no cookies, so the URLs must authorize themselves).
 *
 * @version 1.5
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_message_logic.php'));

$page_vars = process_logic(admin_mailbox_message_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$show_html = isset($_GET['view']) && $_GET['view'] === 'html';

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'incoming',
		'breadcrumbs' => array(
			'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox',
			'Mailbox' => '/plugins/mailbox/admin/admin_mailbox_reader',
			'Message #' . intval($message->key) => '',
		),
		'session' => $session,
	)
);

echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Mailboxes');

$received_local = LibraryFunctions::convert_time(
	$message->get('iem_received_time'), 'UTC', $session->get_timezone(), 'M j, Y g:i:s A T'
);

// Header card
echo '<div class="card mb-3">';
echo '<div class="card-body">';
echo '<dl class="row mb-0">';
echo '<dt class="col-sm-2">From</dt><dd class="col-sm-10">' . htmlspecialchars($message->get('iem_sender') ?: '-') . '</dd>';
echo '<dt class="col-sm-2">To</dt><dd class="col-sm-10">' . htmlspecialchars($message->get('iem_recipient') ?: '-') . '</dd>';
echo '<dt class="col-sm-2">Subject</dt><dd class="col-sm-10">' . htmlspecialchars($message->get('iem_subject') ?: '(no subject)') . '</dd>';
echo '<dt class="col-sm-2">Received</dt><dd class="col-sm-10">' . htmlspecialchars($received_local) . '</dd>';
echo '<dt class="col-sm-2">Domain</dt><dd class="col-sm-10">' . htmlspecialchars($domain_name ?: '-') . '</dd>';
echo '<dt class="col-sm-2">Alias</dt><dd class="col-sm-10">' . htmlspecialchars($alias_name ?: '(catch-all)') . '</dd>';
echo '<dt class="col-sm-2">Message-ID</dt><dd class="col-sm-10">' . htmlspecialchars($message->get('iem_message_id_header') ?: '(none)') . '</dd>';

// Authentication: SPF/DKIM/DMARC are READ from the verifying MTA's
// Authentication-Results header (iem_auth_source = 'milter'), never computed
// here. With no verifying milter the message is honestly "unverified" — we
// never render a bare red fail the app can't stand behind.
$auth_source = $message->get('iem_auth_source') ?: 'none';
$auth_verified = ($auth_source === 'milter' || $auth_source === 'mailgun');
echo '<dt class="col-sm-2">Authentication</dt><dd class="col-sm-10">';
if ($auth_verified) {
	$verdict_cols = array('SPF' => 'iem_spf_result', 'DKIM' => 'iem_dkim_result', 'DMARC' => 'iem_dmarc_result');
	foreach ($verdict_cols as $lbl => $col) {
		$v = strtolower((string)$message->get($col));
		if ($v === '') { $v = 'none'; }
		$cls = ($v === 'pass') ? 'bg-success'
			: (in_array($v, array('fail', 'softfail', 'permerror', 'temperror'), true) ? 'bg-danger' : 'bg-secondary');
		echo '<span class="badge ' . $cls . ' me-1">' . htmlspecialchars($lbl . ': ' . $v) . '</span>';
	}
	$src_text = ($auth_source === 'milter') ? 'verified by this mail server' : 'verified by ' . $auth_source;
	echo ' <span class="text-muted small">(' . htmlspecialchars($src_text) . ')</span>';
} else {
	echo '<span class="badge bg-secondary">unverified</span> '
		. '<span class="text-muted small">no verifying milter installed — SPF/DKIM/DMARC were not checked on receipt</span>';
}
echo '</dd>';

echo '<dt class="col-sm-2">Size</dt><dd class="col-sm-10">' . intval($message->get('iem_size_bytes')) . ' bytes</dd>';
echo '</dl>';
echo '</div></div>';

// Attachment list (from the manifest; inline cid: parts already excluded).
if (!empty($attachments) && count($attachments)) {
	echo '<div class="card mb-3"><div class="card-body">';
	echo '<h6 class="mb-2">Attachments</h6>';
	echo '<ul class="list-unstyled mb-0">';
	foreach ($attachments as $att) {
		$dl = '/plugins/mailbox/admin/admin_mailbox_attachment?ima_inbound_message_attachment_id=' . intval($att->key);
		$fname = $att->get('ima_filename') ?: 'attachment';
		$size = intval($att->get('ima_size_bytes'));
		$size_disp = $size >= 1024 ? round($size / 1024) . ' KB' : $size . ' B';
		echo '<li class="mb-1">';
		echo '<a href="' . htmlspecialchars($dl) . '">' . htmlspecialchars($fname) . '</a> ';
		echo '<span class="text-muted small">(' . htmlspecialchars($att->get('ima_content_type') ?: 'unknown')
			. ', ' . htmlspecialchars($size_disp) . ')</span>';
		echo '</li>';
	}
	echo '</ul>';
	echo '</div></div>';
}

// View toggle
$base = '/plugins/mailbox/admin/admin_mailbox_message?iem_inbound_email_message_id=' . intval($message->key);
echo '<div class="mb-3">';
echo '<a class="btn btn-sm ' . (!$show_html ? 'btn-primary' : 'btn-outline-primary') . '" href="' . $base . '">Plain text</a> ';
$has_html = $body_html !== '' && $body_html !== null;
if ($has_html) {
	echo '<a class="btn btn-sm ' . ($show_html ? 'btn-primary' : 'btn-outline-primary') . '" href="' . $base . '&amp;view=html">HTML (sandboxed)</a> ';
}
echo '<form method="post" action="' . $base . '" class="iem-inline-form" onsubmit="return confirm(\'Delete this message?\')">';
echo '<input type="hidden" name="action" value="delete">';
echo '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>';
echo '</form>';
echo '</div>';

if ($show_html && $has_html) {
	echo '<div class="card"><div class="card-body">';
	echo '<div class="alert alert-warning mb-3"><strong>Sandboxed HTML.</strong> '
		. 'Stored mail is fully attacker-controlled — links and scripts are disabled inside this frame.</div>';
	// Use srcdoc + sandbox without allow-scripts: no JS, no top-nav.
	// (body_html has cid: inline-image references already rewritten to signed URLs.)
	echo '<iframe sandbox="" srcdoc="' . htmlspecialchars($body_html, ENT_QUOTES | ENT_HTML5)
		. '" class="iem-msg-iframe"></iframe>';
	echo '</div></div>';
} else {
	echo '<div class="card"><div class="card-body">';
	$plain = $message->get('iem_body_plain');
	if ($plain === '' || $plain === null) {
		echo '<em class="text-muted">No plain-text body. Use the HTML or Raw view.</em>';
	} else {
		echo '<pre class="iem-msg-plain">' . htmlspecialchars($plain) . '</pre>';
	}
	echo '</div></div>';
}

$page->admin_footer();
?>
