<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/emails_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	//$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'email_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');

	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 11,
		'page_title' => 'Users',
		'readable_title' => 'Users',
		'breadcrumbs' => array(
			'Emails'=>'', 
		),
		'session' => $session,
	)
	);	
	
	$search_criteria = array();
	$search_criteria['status'] = Email::EMAIL_QUEUED;

	$emails = new MultiEmail(
		$search_criteria,
		array($asort=>$asdirection),
		$numperpage,
		$aoffset,
		'OR');
	$numqueued = $emails->count_all();
	
	
	
	$search_criteria = array();
	//$search_criteria['email_like'] = $searchterm;

	$emails = new MultiEmail(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset,
		'OR');
	$numrecords = $emails->count_all();
	$emails->load();	

		
	$headers = array('Email', 'Subject', 'Author', 'Status', 'Details');
	$altlinks = array('New Email'=>'/admin/admin_email_edit');
	if($numqueued){
		$altlinks += array('Send Queued Emails'=>'/admin/admin_emails_send');
	}
	if($searchterm){
		$title = 'Emails matching "'.$searchterm.'"';
	}
	else{
		$title = 'Emails';
	}
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => $title,
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach($emails as $email) {
		$user = new User($email->get('eml_usr_user_id'), TRUE);
		
		$rowvalues = array();
		array_push($rowvalues, '<a href="/admin/admin_email?eml_email_id='.$email->key.'">Email '.$email->key.' - '.$email->get('eml_subject').'</a>');
		array_push($rowvalues, $email->get('eml_subject'));
		array_push($rowvalues, $user->display_name());
		
		if($email->get('eml_delete_time')) {
			$status = 'Deleted';
		} 
		else{
			$status = $email->get_status_text();
		}
		array_push($rowvalues, $status);
		
		$time= '';
		if($email->get('eml_status') == 10){
			$time = 'Sent: '. LibraryFunctions::convert_time($email->get('eml_sent_time'), "UTC", $session->get_timezone());
		}
		else if($email->get('eml_status') == 5){
			$time = 'Scheduled: '. LibraryFunctions::convert_time($email->get('eml_scheduled_time'), "UTC", $session->get_timezone());	
		}
		array_push($rowvalues, $time);		
	
		$page->disprow($rowvalues);
	}
		
	$page->endtable($pager);		
		

	$page->admin_footer();

?>
