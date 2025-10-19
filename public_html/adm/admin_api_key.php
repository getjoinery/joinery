<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/api_keys_class.php'));

	require_once(PathHelper::getIncludePath('adm/logic/admin_api_key_logic.php'));

	$page_vars = process_logic(admin_api_key_logic($_GET, $_POST));

	extract($page_vars);

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

