<?php
/**
 * Inbound Email - Mailbox (local store)
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_message_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/logic/admin_inbound_email_mailbox_logic.php'));

$page_vars = process_logic(admin_inbound_email_mailbox_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$numperpage = 50;
$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
$sort = LibraryFunctions::fetch_variable('sort', 'iem_received_time', 0, '');
$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
$filter_recipient = LibraryFunctions::fetch_variable('recipient', '', 0, '');
$filter_sender = LibraryFunctions::fetch_variable('sender', '', 0, '');
$filter_domain = intval(LibraryFunctions::fetch_variable('domain_id', '', 0, ''));

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'incoming',
		'breadcrumbs' => array(
			'Inbound Email' => '/plugins/inbound_email/admin/admin_inbound_email',
			'Mailbox' => '',
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

// Display session messages
$display_messages = $session->get_messages('/plugins\/inbound_email\/admin\//');
if (!empty($display_messages)) {
	foreach ($display_messages as $msg) {
		echo '<div class="alert alert-success">' . htmlspecialchars($msg->message) . '</div>';
	}
	$session->clear_clearable_messages();
}

// Filter form
echo '<form class="mb-3" method="get">';
echo '<div class="row g-2 align-items-center">';
echo '<div class="col-auto"><input type="text" name="recipient" class="form-control form-control-sm" placeholder="Recipient contains..." value="' . htmlspecialchars($filter_recipient) . '"></div>';
echo '<div class="col-auto"><input type="text" name="sender" class="form-control form-control-sm" placeholder="Sender contains..." value="' . htmlspecialchars($filter_sender) . '"></div>';
echo '<div class="col-auto"><select name="domain_id" class="form-select form-select-sm">';
echo '<option value="">All domains</option>';
foreach ($domains as $d) {
	$sel = ($filter_domain == $d->key) ? ' selected' : '';
	echo '<option value="' . intval($d->key) . '"' . $sel . '>' . htmlspecialchars($d->get('ied_domain')) . '</option>';
}
echo '</select></div>';
echo '<div class="col-auto"><button type="submit" class="btn btn-sm btn-outline-primary">Filter</button></div>';
echo '</div></form>';

// Build search criteria
$search_criteria = array('deleted' => false);
if ($filter_recipient !== '') {
	$search_criteria['recipient'] = $filter_recipient;
}
if ($filter_sender !== '') {
	$search_criteria['sender'] = $filter_sender;
}
if ($filter_domain > 0) {
	$search_criteria['domain_id'] = $filter_domain;
}

$messages = new MultiInboundEmailMessage(
	$search_criteria,
	array($sort => $sdirection),
	$numperpage,
	$offset
);
$numrecords = $messages->count_all();
$messages->load();

$headers = array('Received', 'Recipient', 'Sender', 'Subject', 'Size', 'DKIM', 'Actions');
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));

$altlinks = array();
if ($numrecords > 0) {
	$altlinks['Purge All'] = '#purge-all';
}

$table_options = array(
	'title' => 'Stored Inbound Messages (' . $numrecords . ')',
	'altlinks' => $altlinks,
);
$page->tableheader($headers, $table_options, $pager);

foreach ($messages as $m) {
	$detail_url = '/plugins/inbound_email/admin/admin_inbound_email_message?iem_inbound_email_message_id=' . intval($m->key);

	$dkim = $m->get('iem_dkim_result') ?: 'none';
	$dkim_class = 'bg-secondary';
	if ($dkim === 'pass') $dkim_class = 'bg-success';
	elseif ($dkim === 'fail') $dkim_class = 'bg-danger';

	$size_bytes = intval($m->get('iem_size_bytes'));
	$size_str = $size_bytes > 1024 ? round($size_bytes / 1024, 1) . ' KB' : $size_bytes . ' B';

	$rowvalues = array();
	array_push($rowvalues, LibraryFunctions::convert_time($m->get('iem_received_time'), 'UTC', $session->get_timezone(), 'M j g:i A'));
	array_push($rowvalues, '<a href="' . $detail_url . '">' . htmlspecialchars(substr($m->get('iem_recipient'), 0, 60)) . '</a>');
	array_push($rowvalues, htmlspecialchars(substr($m->get('iem_sender'), 0, 50)));
	array_push($rowvalues, htmlspecialchars(substr($m->get('iem_subject') ?: '(no subject)', 0, 60)));
	array_push($rowvalues, $size_str);
	array_push($rowvalues, '<span class="badge ' . $dkim_class . '">' . htmlspecialchars($dkim) . '</span>');

	$actions = '<a href="' . $detail_url . '" class="btn btn-sm btn-outline-secondary">View</a> '
		. '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this message?\')">'
		. '<input type="hidden" name="action" value="delete">'
		. '<input type="hidden" name="iem_inbound_email_message_id" value="' . intval($m->key) . '">'
		. '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>'
		. '</form>';
	array_push($rowvalues, $actions);

	$page->disprow($rowvalues);
}

$page->endtable($pager);

// Purge-all form
if ($numrecords > 0) {
	echo '<form id="purge-all" method="post" class="mt-3" onsubmit="return confirm(\'Soft-delete EVERY stored message? They will be hard-deleted by the retention purge task.\')">';
	echo '<input type="hidden" name="action" value="purge_all">';
	echo '<button type="submit" class="btn btn-outline-danger btn-sm">Purge all stored messages</button>';
	echo '</form>';
}

$page->admin_footer();
?>
