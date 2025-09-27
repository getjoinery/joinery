<?php
	
	PathHelper::requireOnce('includes/Activation.php');
	// ErrorHandler.php no longer needed - using new ErrorManager system
	
	PathHelper::requireOnce('includes/AdminPage.php');

	PathHelper::requireOnce('data/debug_email_logs_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	
	$debug_email_log = new DebugEmailLog($_REQUEST['del_debug_email_log_id'], TRUE);

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
