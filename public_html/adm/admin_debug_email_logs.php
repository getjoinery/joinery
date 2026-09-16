<?php
/**
 * admin_debug_email_logs — the lines EmailSender wrote while email_debug_mode
 * was on: which service each message went to, dry-run and test-mode
 * suppressions, fallbacks. Diagnostic scratch, hence the one action: clear it.
 *
 * @version 2.0 - lists what the sender writes (message, service, status); Clear
 *   is handled here (the button used to post an action nothing read)
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$session = SessionControl::get_instance();
$session->check_permission(8);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'delete_all') {
	DebugEmailLog::deleteAll();
	header('Location: /admin/admin_debug_email_logs');
	exit;
}

$numperpage = 50;
$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'email-debug-logs',
	'page_title' => 'Debug Email Logs',
	'readable_title' => 'Debug Email Logs',
	'breadcrumbs' => array('Debug Email Logs' => ''),
	'session' => $session,
));

$settings = Globalvars::get_instance();
$debug_on = (string)$settings->get_setting('email_debug_mode') === '1';

$logs = new MultiDebugEmailLog(array(), array('del_create_time' => 'DESC'), $numperpage, $offset);
$numrecords = $logs->count_all();

$headers = array('Time', 'Service', 'Status', 'Message');
$altlinks = array('Clear log' => array('post' => '/admin/admin_debug_email_logs',
	'hidden' => array('action' => 'delete_all'), 'confirm' => 'Delete every debug email log line?'));
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));
echo '<p>' . ($debug_on
	? 'Email debug mode is on: every send writes its steps here.'
	: 'Email debug mode is off, so nothing new is written. Turn on <code>email_debug_mode</code> in Email settings to record the steps each send takes.')
	. '</p>';
$page->tableheader($headers, array('altlinks' => $altlinks, 'title' => 'Debug Email Logs'), $pager);

foreach ($logs as $log) {
	$page->disprow(array(
		LibraryFunctions::convert_time($log->get('del_create_time'), 'UTC', $session->get_timezone()),
		htmlspecialchars((string)$log->get('del_service')),
		htmlspecialchars((string)$log->get('del_status')),
		htmlspecialchars((string)$log->get('del_message')),
	));
}

$page->endtable($pager);
$page->admin_footer();
?>
