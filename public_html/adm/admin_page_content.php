<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/page_contents_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$page_content = new PageContent($_GET['pac_page_content_id'], TRUE);

	if($_REQUEST['action'] == 'delete'){
		$page_content->authenticate_write($session);
		$page_content->soft_delete();

		header("Location: /admin/admin_page_contents");
		exit();				
	}
	else if($_REQUEST['action'] == 'undelete'){
		$page_content->authenticate_write($session);
		$page_content->undelete();

		header("Location: /admin/admin_page_contents");
		exit();				
	}

	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 24,
		'breadcrumbs' => array(
			'Page Contents'=>'/admin/admin_page_contents', 
			$page_content->get('pac_location_name')=>'',
		),
		'session' => $session,
	)
	);	
	
	$options['title'] = $page_content->get('pac_location_name');
	$options['altlinks'] = array('Edit Content' => '/admin/admin_page_content_edit?pac_page_content_id='.$page_content->key);
	$options['altlinks'] += array('Delete Content' => '/admin/admin_page_content_permanent_delete?pac_page_content_id='.$page_content->key);
	if(!$page_content->get('pac_delete_time') && $_SESSION['permission'] >= 8) {
		$options['altlinks']['Soft Delete'] = '/admin/admin_page_content?action=delete&pac_page_content_id='.$page_content->key;
	}
	else if($_SESSION['permission'] >= 8){
		$options['altlinks']['Restore Soft Delete'] = '/admin/admin_page_content?action=undelete&pac_page_content_id='.$page_content->key;
	}

	$page->begin_box($options);
	echo '<strong>Title:</strong> '.$page_content->get('pac_title').'<br />';
	echo '<strong>Label:</strong> '.$page_content->get('pac_location_name').'<br />';	
	echo '<strong>Created:</strong> '.LibraryFunctions::convert_time($page_content->get('pac_create_time'), 'UTC', $session->get_timezone()) .'<br />';
	if($page_content->get('pac_delete_time')){
		echo 'Status: Deleted at '.LibraryFunctions::convert_time($page_content->get('pac_delete_time'), 'UTC', $session->get_timezone()).'<br />';
	}
	else if($page_content->get('pac_is_published')){
		echo '<strong>Published:</strong> ' . LibraryFunctions::convert_time($page_content->get('pac_published_time'), 'UTC', $session->get_timezone()). '<br />';
	}
	else{
		echo '<strong>UNPUBLISHED</strong><br />';
	}
	

	echo '<strong>Link:</strong> <a href="'.$page_content->get_url().'">'.$settings->get_setting('webDir_SSL'). $page_content->get_url().'</a><br />';	


	echo '<iframe src="'.$page_content->get_url().'" width="100%" height="500" style="border:1px solid black;"></iframe>';


	$page->end_box();		
	
	$page->admin_footer();
?>


