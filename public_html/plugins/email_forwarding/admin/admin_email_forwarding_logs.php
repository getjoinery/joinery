<?php
/**
 * Email Forwarding - Forwarding Logs
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/email_forwarding/data/email_forwarding_log_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);

$numperpage = 50;
$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
$sort = LibraryFunctions::fetch_variable('sort', 'efl_email_forwarding_log_id', 0, '');
$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
$filter_status = LibraryFunctions::fetch_variable('status', '', 0, '');

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'incoming',
		'breadcrumbs' => array(
			'Email Forwarding' => '/plugins/email_forwarding/admin/admin_email_forwarding',
			'Logs' => '',
		),
		'session' => $session,
	)
);

// Tab navigation
echo '<ul class="nav nav-tabs mb-3">';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/email_forwarding/admin/admin_email_forwarding">Forwarding Aliases</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/email_forwarding/admin/admin_email_forwarding_domains">Domains</a></li>';
echo '<li class="nav-item"><a class="nav-link active" href="/plugins/email_forwarding/admin/admin_email_forwarding_logs">Logs</a></li>';
echo '</ul>';

// Status filter
echo '<form class="mb-3" method="get">';
echo '<div class="row g-2 align-items-center">';
echo '<div class="col-auto"><label class="col-form-label">Status:</label></div>';
echo '<div class="col-auto"><select name="status" class="form-select form-select-sm">';
echo '<option value="">All</option>';
$statuses = array('forwarded', 'rejected', 'discarded', 'rate_limited', 'bounce_forwarded', 'error');
foreach ($statuses as $s) {
	$sel = ($filter_status === $s) ? ' selected' : '';
	echo '<option value="' . $s . '"' . $sel . '>' . $s . '</option>';
}
echo '</select></div>';
echo '<div class="col-auto"><button type="submit" class="btn btn-sm btn-outline-primary">Filter</button></div>';
echo '</div></form>';

// Build search criteria
$search_criteria = array('deleted' => false);
if ($filter_status) {
	$search_criteria['status'] = $filter_status;
}

$logs = new MultiEmailForwardingLog(
	$search_criteria,
	array($sort => $sdirection),
	$numperpage,
	$offset
);
$numrecords = $logs->count_all();
$logs->load();

$headers = array('Time', 'From', 'To', 'Subject', 'Destinations', 'Status', 'Error');
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
$table_options = array('title' => 'Forwarding Logs');
$page->tableheader($headers, $table_options, $pager);

foreach ($logs as $log) {
	$status = $log->get('efl_status');
	$status_class = 'bg-secondary';
	if ($status === 'forwarded' || $status === 'bounce_forwarded') $status_class = 'bg-success';
	elseif ($status === 'rejected' || $status === 'error') $status_class = 'bg-danger';
	elseif ($status === 'rate_limited') $status_class = 'bg-warning text-dark';

	$rowvalues = array();
	array_push($rowvalues, LibraryFunctions::convert_time($log->get('efl_create_time'), 'UTC', $session->get_timezone(), 'M j g:i A'));
	array_push($rowvalues, htmlspecialchars(substr($log->get('efl_from_address'), 0, 50)));
	array_push($rowvalues, htmlspecialchars(substr($log->get('efl_to_address'), 0, 50)));
	array_push($rowvalues, htmlspecialchars(substr($log->get('efl_subject'), 0, 60)));
	array_push($rowvalues, htmlspecialchars(substr($log->get('efl_destinations') ?: '-', 0, 50)));
	array_push($rowvalues, '<span class="badge ' . $status_class . '">' . htmlspecialchars($status) . '</span>');
	array_push($rowvalues, htmlspecialchars(substr($log->get('efl_error_message') ?: '', 0, 80)));

	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
?>
