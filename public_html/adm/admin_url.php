<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/urls_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$url = new Url($_GET['url_url_id'], TRUE);
	
	if($_REQUEST['action'] == 'remove'){
		$url->authenticate_write($session);
		$url->permanent_delete();

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_urls");
		exit();		
	}	
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 32,
		'page_title' => 'Urls',
		'readable_title' => 'Urls',
		'breadcrumbs' => array(
			'Urls'=>'/admin/admin_urls', 
			'Url',
		),
		'session' => $session,
	)
	);
	
	$options['title'] = 'Url';
	$options['altlinks'] = array('Edit Url'=>'/admin/admin_url_edit?url_url_id='.$url->key);
	$options['altlinks'] += array('Delete Url' => '/admin/admin_url?action=remove&url_url_id='.$url->key);
	$page->begin_box($options);


	
	
	echo '<strong>Created:</strong> '.LibraryFunctions::convert_time($url->get('url_create_time'), 'UTC', $session->get_timezone()) .'<br />';
	echo '<strong>Status:</strong> ';
	if($url->get('url_is_deleted')) {
		echo 'Deleted';
	} else {
		echo 'Active';
	}
	echo '<br /><strong>Incoming:</strong> '.$url->get('url_incoming') .'<br />';	
	echo '<strong>Redirect:</strong> ';
	
		if($url->get('url_redirect_url')){
			echo $url->get('url_redirect_url');
		}
		else{
			echo $url->get('url_redirect_file');
		}	
	echo '<br />';
	
	$page->end_box();

	$page->admin_footer();
?>


