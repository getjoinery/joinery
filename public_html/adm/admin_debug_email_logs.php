<?php
	
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/debug_email_logs_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	//$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'debug_email_log_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');

	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'email-debug-logs',
		'page_title' => 'Users',
		'readable_title' => 'Users',
		'breadcrumbs' => array(
			'DebugEmailLogs'=>'', 
		),
		'session' => $session,
	)
	);	

	$search_criteria = array();
	//$search_criteria['debug_email_log_like'] = $searchterm;

	$debug_email_logs = new MultiDebugEmailLog(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset,
		'OR');
	$numrecords = $debug_email_logs->count_all();
	$debug_email_logs->load();	

	$headers = array('Debug Email', 'Time');
	$altlinks = array('Delete All' => '/admin/admin_debug_email_logs?action=delete_all');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Debug Email Logs',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach($debug_email_logs as $debug_email_log) {
		
		$rowvalues = array();
		array_push($rowvalues, '<a href="/admin/admin_debug_email_log?del_debug_email_log_id='.$debug_email_log->key.'">'.$debug_email_log->get('del_subject').'</a>');

		$time = 'Sent: '. LibraryFunctions::convert_time($debug_email_log->get('del_create_time'), "UTC", $session->get_timezone());
		array_push($rowvalues, $time);		
	
		$page->disprow($rowvalues);
	}
		
	$page->endtable($pager);		

	$page->admin_footer();

?>
