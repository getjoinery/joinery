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
	
	if($_REQUEST['action'] == 'soft_delete'){
		$url->authenticate_write($session);
		$url->soft_delete();

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_urls");
		exit();		
	}
	if($_REQUEST['action'] == 'undelete'){
		$url->authenticate_write($session);
		$url->undelete();

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_urls");
		exit();		
	}		
	if($_REQUEST['action'] == 'permanent_delete'){
		$url->authenticate_write($session);
		$url->permanent_delete();

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_urls");
		exit();		
	}
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'urls',
		'page_title' => 'Urls',
		'readable_title' => 'Urls',
		'breadcrumbs' => array(
			'Urls'=>'/admin/admin_urls', 
			'Url' => '',
		),
		'session' => $session,
	)
	);
	
	$options['title'] = 'Url';
	$options['altlinks'] = array('Edit'=>'/admin/admin_url_edit?url_url_id='.$url->key);
	if(!$url->get('url_delete_time')){
		$options['altlinks']['Soft Delete'] = '/admin/admin_url?action=soft_delete&url_url_id='.$url->key;
	}
	else{
		$options['altlinks']['Undelete'] = '/admin/admin_url?action=undelete&url_url_id='.$url->key;
	}
	
	if($_SESSION['permission'] >= 8) {
		$options['altlinks'] += array('Permanent Delete' => '/admin/admin_url?action=permanent_delete&url_url_id='.$url->key);
	}

	$page->begin_box($options);


	
	
	echo '<strong>Created:</strong> '.LibraryFunctions::convert_time($url->get('url_create_time'), 'UTC', $session->get_timezone()) .'<br />';

	echo '<br /><strong>Incoming:</strong> <a href="'.$url->get('url_incoming') .'">'.$url->get('url_incoming').'</a><br />';	
	echo '<strong>Redirect:</strong> ';
	
		if($url->get('url_redirect_url')){
			echo '<a href="'.$url->get('url_redirect_url').'">'.$url->get('url_redirect_url').'</a>';
		}
		else{
			echo '<a href="'.$url->get('url_redirect_file').'">'.$url->get('url_redirect_file').'</a>';
		}	
	echo '<br />';
	
	if($url->get('url_type') == 301){
		echo 'Type: Permanent';
	}
	else if($url->get('url_type') == 302){
		echo 'Type: Temporary';
	}
	echo '<br />';
	$page->end_box();

	$page->admin_footer();
?>


