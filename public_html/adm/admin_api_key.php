<?php

	// ErrorHandler.php no longer needed - using new ErrorManager system
	
	PathHelper::requireOnce('includes/AdminPage.php');
	
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/api_keys_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$api_key = new ApiKey($_GET['apk_api_key_id'], TRUE);
	
	if($_REQUEST['action'] == 'soft_delete'){
		$api_key->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$api_key->soft_delete();

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_api_keys");
		exit();		
	}
	if($_REQUEST['action'] == 'undelete'){
		$api_key->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$api_key->undelete();

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_api_keys");
		exit();		
	}		
	if($_REQUEST['action'] == 'permanent_delete'){
		$api_key->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$api_key->permanent_delete();

		//$returnurl = $session->get_return();
		header("Location: /admin/admin_api_keys");
		exit();		
	}
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'urls',
		'page_title' => 'ApiKeys',
		'readable_title' => 'ApiKeys',
		'breadcrumbs' => array(
			'ApiKeys'=>'/admin/admin_api_keys', 
			'ApiKey' => '',
		),
		'session' => $session,
	)
	);
	
	$options['title'] = 'ApiKey';
	$options['altlinks'] = array('Edit'=>'/admin/admin_api_key_edit?apk_api_key_id='.$api_key->key);
	if(!$api_key->get('apk_delete_time')){
		$options['altlinks']['Soft Delete'] = '/admin/admin_api_key?action=soft_delete&apk_api_key_id='.$api_key->key;
	}
	else{
		$options['altlinks']['Undelete'] = '/admin/admin_api_key?action=undelete&apk_api_key_id='.$api_key->key;
	}
	
	if($_SESSION['permission'] >= 8) {
		$options['altlinks'] += array('Permanent Delete' => '/admin/admin_api_key?action=permanent_delete&apk_api_key_id='.$api_key->key);
	}

	$page->begin_box($options);

	echo '<h3>'.$api_key->get('apk_name').'</h3>';
	
	echo '<strong>Created:</strong> '.LibraryFunctions::convert_time($api_key->get('apk_create_time'), 'UTC', $session->get_timezone()) .'<br />';

	$owner = new User($api_key->get('apk_usr_user_id'), TRUE);
	$now = LibraryFunctions::get_current_time_obj('UTC');
	$rowvalues = array();

	echo '<strong>Public key:</strong> '. $api_key->get('apk_public_key').'<br>';
	echo '<strong>Secret key:</strong> '. $api_key->get('apk_secret_key').'<br>';
	echo '<strong>Owner:</strong> '. $owner->display_name().'<br>';

	if($api_key->get('apk_start_time')){
		echo '<strong>Starts:</strong> '. LibraryFunctions::convert_time($api_key->get('apk_start_time'), "UTC", $session->get_timezone(), 'M j, Y').'<br>';
	}
	
	if($api_key->get('apk_expires_time')){
		echo '<strong>Expires:</strong> '. LibraryFunctions::convert_time($api_key->get('apk_expires_time'), "UTC", $session->get_timezone(), 'M j, Y').'<br>';
	}	 		
	
	if($api_key->get('apk_delete_time')){
		echo '<strong>Status:</strong> <b>Deleted</b>';
	}
	else if(!$api_key->get('apk_is_active')){
		echo '<strong>Status:</strong> <b>Inactive</b>';
	}
	else if($api_key->get('apk_expires_time') && $api_key->get('apk_expires_time') < $now){
		echo '<strong>Status:</strong> <b>Expired</b>';
	}
	else if($api_key->get('apk_start_time') && $api_key->get('apk_start_time') > $now){
		echo '<strong>Status:</strong> <b>Scheduled</b>';
	}
	else{
		echo '<strong>Status:</strong> <b>Active</b>';
	}

	echo '<br />';
	$page->end_box();

	$page->admin_footer();
?>

