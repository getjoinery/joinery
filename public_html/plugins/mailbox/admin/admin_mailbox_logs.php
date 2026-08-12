<?php
/**
 * Inbound Email - Logs
 *
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_log_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(5);

$numperpage = 50;
$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
$sort = LibraryFunctions::fetch_variable('sort', 'iel_inbound_email_log_id', 0, '');
$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
$filter_status = LibraryFunctions::fetch_variable('status', '', 0, '');

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'incoming',
		'breadcrumbs' => array(
			'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox',
			'Logs' => '',
		),
		'session' => $session,
	)
);

echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Logs');

// Joinery Direct, if it is on (docs/joinery_direct.md § Blocking and abuse).
// This panel exists because Direct's characteristic failure is invisible: a
// drifted clock or an unpublished record makes every attempt fall back to
// ordinary email, so mail keeps flowing, nothing is ever marked verified, and
// nobody notices. Refusals and downgrades are counted so that state is
// diagnosable at a glance.
require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectSettings.php'));
if (DirectSettings::enabled()) {
	require_once(PathHelper::getIncludePath('includes/joinery_direct/DirectStats.php'));
	$direct = DirectStats::summary(24);
	echo '<div class="card mb-3"><div class="card-body">';
	echo '<h2 class="h6 mb-2">Joinery Direct — last 24 hours</h2>';
	echo '<p class="mb-2">' . htmlspecialchars(DirectStats::headline($direct)) . '</p>';
	echo '<ul class="list-inline mb-0 small text-muted">';
	echo '<li class="list-inline-item">Delivered directly: <strong>' . (int)$direct['delivered'] . '</strong></li>';
	echo '<li class="list-inline-item">Fell back to email: <strong>' . (int)$direct['downgrade_total'] . '</strong></li>';
	echo '<li class="list-inline-item">Inbound attempts turned away: <strong>' . (int)$direct['refused'] . '</strong></li>';
	echo '<li class="list-inline-item">Other instances that reached us: <strong>' . (int)$direct['peers'] . '</strong></li>';
	echo '<li class="list-inline-item">Waiting for an unlock: <strong>' . (int)$direct['held'] . '</strong> ('
		. number_format($direct['held_bytes'] / 1048576, 1) . ' MB)</li>';
	echo '</ul>';
	if (!empty($direct['reasons'])) {
		echo '<p class="small text-muted mt-2 mb-0">Why inbound attempts were turned away: ';
		$parts = array();
		foreach ($direct['reasons'] as $reason => $n) {
			$parts[] = htmlspecialchars($reason) . ' (' . (int)$n . ')';
		}
		echo implode(', ', $parts) . '</p>';
	}
	echo '</div></div>';
}

// Status filter
echo '<form class="mb-3" method="get">';
echo '<div class="row g-2 align-items-center">';
echo '<div class="col-auto"><label class="col-form-label">Status:</label></div>';
echo '<div class="col-auto"><select name="status" class="form-select form-select-sm">';
echo '<option value="">All</option>';
$statuses = array('forwarded', 'stored', 'rejected', 'discarded', 'rate_limited', 'store_capped', 'bounce_forwarded', 'error');
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

$logs = new MultiInboundEmailLog(
	$search_criteria,
	array($sort => $sdirection),
	$numperpage,
	$offset
);
$numrecords = $logs->count_all();
$logs->load();

$headers = array('Time', 'From', 'To', 'Subject', 'Destinations', 'Status', 'Error');
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
$table_options = array('title' => 'Inbound Email Logs');
$page->tableheader($headers, $table_options, $pager);

foreach ($logs as $log) {
	$status = $log->get('iel_status');
	$status_class = 'bg-secondary';
	if ($status === 'forwarded' || $status === 'bounce_forwarded' || $status === 'stored') $status_class = 'bg-success';
	elseif ($status === 'rejected' || $status === 'error') $status_class = 'bg-danger';
	elseif ($status === 'rate_limited' || $status === 'store_capped') $status_class = 'bg-warning text-dark';

	$rowvalues = array();
	array_push($rowvalues, LibraryFunctions::convert_time($log->get('iel_create_time'), 'UTC', $session->get_timezone(), 'M j g:i A'));
	array_push($rowvalues, htmlspecialchars(substr($log->get('iel_from_address'), 0, 50)));
	array_push($rowvalues, htmlspecialchars(substr($log->get('iel_to_address'), 0, 50)));
	array_push($rowvalues, htmlspecialchars(substr($log->get('iel_subject'), 0, 60)));
	array_push($rowvalues, htmlspecialchars(substr($log->get('iel_destinations') ?: '-', 0, 50)));
	array_push($rowvalues, '<span class="badge ' . $status_class . '">' . htmlspecialchars($status) . '</span>');
	array_push($rowvalues, htmlspecialchars(substr($log->get('iel_error_message') ?: '', 0, 80)));

	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
?>
