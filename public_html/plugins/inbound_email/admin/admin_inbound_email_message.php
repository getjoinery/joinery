<?php
/**
 * Inbound Email - Single Message Detail
 *
 * Displays a stored inbound message. The HTML body is rendered inside a
 * sandboxed iframe (no allow-scripts) because stored bodies are fully
 * attacker-controlled. A "view raw" toggle shows the original MIME, and
 * an .eml download is available.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/logic/admin_inbound_email_message_logic.php'));

$page_vars = process_logic(admin_inbound_email_message_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$show_raw = isset($_GET['view']) && $_GET['view'] === 'raw';
$show_html = isset($_GET['view']) && $_GET['view'] === 'html';

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'incoming',
		'breadcrumbs' => array(
			'Inbound Email' => '/plugins/inbound_email/admin/admin_inbound_email',
			'Mailbox' => '/plugins/inbound_email/admin/admin_inbound_email_mailbox',
			'Message #' . intval($message->key) => '',
		),
		'session' => $session,
	)
);

// Tab navigation
echo '<ul class="nav nav-tabs mb-3">';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/inbound_email/admin/admin_inbound_email_setup">Setup</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/inbound_email/admin/admin_inbound_email">Forwarding Aliases</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/inbound_email/admin/admin_inbound_email_domains">Domains</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/inbound_email/admin/admin_inbound_email_logs">Logs</a></li>';
echo '<li class="nav-item"><a class="nav-link active" href="/plugins/inbound_email/admin/admin_inbound_email_mailbox">Mailbox</a></li>';
echo '</ul>';

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
echo '<dt class="col-sm-2">DKIM</dt><dd class="col-sm-10">' . htmlspecialchars($message->get('iem_dkim_result') ?: 'none') . '</dd>';
echo '<dt class="col-sm-2">Size</dt><dd class="col-sm-10">' . intval($message->get('iem_size_bytes')) . ' bytes</dd>';
echo '</dl>';
echo '</div></div>';

// View toggle
$base = '/plugins/inbound_email/admin/admin_inbound_email_message?iem_inbound_email_message_id=' . intval($message->key);
echo '<div class="mb-3">';
echo '<a class="btn btn-sm ' . (!$show_raw && !$show_html ? 'btn-primary' : 'btn-outline-primary') . '" href="' . $base . '">Plain text</a> ';
$has_html = $message->get('iem_body_html') !== '';
if ($has_html) {
	echo '<a class="btn btn-sm ' . ($show_html ? 'btn-primary' : 'btn-outline-primary') . '" href="' . $base . '&amp;view=html">HTML (sandboxed)</a> ';
}
echo '<a class="btn btn-sm ' . ($show_raw ? 'btn-primary' : 'btn-outline-primary') . '" href="' . $base . '&amp;view=raw">Raw MIME</a> ';
echo '<form method="post" action="' . $base . '" style="display:inline">';
echo '<input type="hidden" name="action" value="download_eml">';
echo '<button type="submit" class="btn btn-sm btn-outline-secondary">Download .eml</button>';
echo '</form> ';
echo '<form method="post" action="' . $base . '" style="display:inline" onsubmit="return confirm(\'Delete this message?\')">';
echo '<input type="hidden" name="action" value="delete">';
echo '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>';
echo '</form>';
echo '</div>';

if ($show_raw) {
	echo '<div class="card"><div class="card-body">';
	echo '<pre style="white-space:pre-wrap;word-break:break-word;font-family:monospace;font-size:12px;">'
		. htmlspecialchars($message->get('iem_raw_message')) . '</pre>';
	echo '</div></div>';
} elseif ($show_html && $has_html) {
	echo '<div class="card"><div class="card-body">';
	echo '<div class="alert alert-warning mb-3"><strong>Sandboxed HTML.</strong> '
		. 'Stored mail is fully attacker-controlled — links and scripts are disabled inside this frame.</div>';
	// Use srcdoc + sandbox without allow-scripts: no JS, no top-nav.
	$html = $message->get('iem_body_html');
	echo '<iframe sandbox="" srcdoc="' . htmlspecialchars($html, ENT_QUOTES | ENT_HTML5)
		. '" style="width:100%;min-height:500px;border:1px solid #ccc;background:#fff;"></iframe>';
	echo '</div></div>';
} else {
	echo '<div class="card"><div class="card-body">';
	$plain = $message->get('iem_body_plain');
	if ($plain === '' || $plain === null) {
		echo '<em class="text-muted">No plain-text body. Use the HTML or Raw view.</em>';
	} else {
		echo '<pre style="white-space:pre-wrap;word-break:break-word;">' . htmlspecialchars($plain) . '</pre>';
	}
	echo '</div></div>';
}

$page->admin_footer();
?>
