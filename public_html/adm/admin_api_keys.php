<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_api_keys_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));

$page_vars = process_logic(admin_api_keys_logic($_GET, $_POST));

$session = $page_vars['session'];
$api_keys = $page_vars['api_keys'];
$numrecords = $page_vars['numrecords'];
$numperpage = $page_vars['numperpage'];

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'api_keys',
	'page_title' => 'Add User',
	'readable_title' => 'Add User',
	'breadcrumbs' => array(
		'ApiKeys'=>''
	),
	'session' => $session,
)
);

$headers = array("Name", "Public Key", 'Owner', "Start Time", "Expires Time", 'Status');
$altlinks = array();
$altlinks += array('Add ApiKey'=> '/admin/admin_api_key_edit');
$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
$table_options = array(
	'altlinks' => $altlinks,
	'title' => 'ApiKeys',
);
$page->tableheader($headers, $table_options, $pager);

foreach ($api_keys as $api_key){
	$owner = new User($api_key->get('apk_usr_user_id'), TRUE);
	$now_utc = gmdate('Y-m-d H:i:s');
	$rowvalues = array();

	array_push($rowvalues,  " <a href='/admin/admin_api_key?apk_api_key_id=$api_key->key'>".$api_key->get('apk_name')."</a>");

	array_push($rowvalues, $api_key->get('apk_public_key'));
	array_push($rowvalues, $owner->display_name());

	array_push($rowvalues, LibraryFunctions::convert_time($api_key->get('apk_start_time'), "UTC", $session->get_timezone(), 'M j, Y'));
	array_push($rowvalues, LibraryFunctions::convert_time($api_key->get('apk_expires_time'), "UTC", $session->get_timezone(), 'M j, Y'));

	if($api_key->get('apk_delete_time')){
		array_push($rowvalues, '<b>Deleted</b>');
	}
	else if(!$api_key->get('apk_is_active')){
		array_push($rowvalues, '<b>Inactive</b>');
	}
	else if($api_key->get('apk_expires_time') && $api_key->get('apk_expires_time') < $now_utc){
		array_push($rowvalues, '<b>Expired</b>');
	}
	else if($api_key->get('apk_start_time') && $api_key->get('apk_start_time') > $now_utc){
		array_push($rowvalues, '<b>Scheduled</b>');
	}
	else{
		array_push($rowvalues, '<b>Active</b>');
	}

	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
?>
