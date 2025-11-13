<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_debug_email_log_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$page_vars = process_logic(admin_debug_email_log_logic($_GET, $_POST));

$session = $page_vars['session'];
$debug_email_log = $page_vars['debug_email_log'];

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'email-debug-logs',
	'breadcrumbs' => array(
		'DebugEmailLogs'=>'/admin/admin_debug_email_logs',
		$debug_email_log->get('del_subject') => '',
	),
	'session' => $session,
)
);

$pageoptions['title'] = 'DebugEmailLog: '.$debug_email_log->get('del_subject');
$altlinks = array();
$pageoptions['altlinks'] = $altlinks;
$page->begin_box($pageoptions);
echo '<iframe src="/ajax/debug_email_log_preview_ajax?del_debug_email_log_id='.$debug_email_log->key.'" width="100%" height="300" style="border:1px solid gray;"></iframe>';
$page->end_box();

$page->admin_footer();

?>
