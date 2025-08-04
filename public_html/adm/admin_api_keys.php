<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

	// ErrorHandler.php no longer needed - using new ErrorManager system
	
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/api_keys_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30; 
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'api_key_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$search_criteria = array();

	//$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}

	$api_keys = new MultiApiKey(
		$search_criteria,  //SEARCH CRITERIA
		array($sort=>$sdirection),  //SORT AND DIRECTION array($usrsort=>$usrsdirection)
		$numperpage,  //NUM PER PAGE
		$offset,  //OFFSET
		'OR');
	$numrecords = $api_keys->count_all();
	$api_keys->load();
	

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
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'ApiKeys',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);	

	foreach ($api_keys as $api_key){
		$owner = new User($api_key->get('apk_usr_user_id'), TRUE);
		$now = LibraryFunctions::get_current_time_obj('UTC');
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
		else if($api_key->get('apk_expires_time') && $api_key->get('apk_expires_time') < $now){
			array_push($rowvalues, '<b>Expired</b>');
		}
		else if($api_key->get('apk_start_time') && $api_key->get('apk_start_time') > $now){
			array_push($rowvalues, '<b>Scheduled</b>');
		}
		else{
			array_push($rowvalues, '<b>Active</b>');
		}
/*		
		else{
			$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_api_key_permanent_delete?apk_api_key_id='. $api_key->key.'">
			<input type="hidden" class="hidden" name="action" value="removeapi_key" />
			<input type="hidden" class="hidden" name="apk_api_key_id" value="'.$api_key->key.'" />
			<button type="submit">Delete</button>
			</form>';
			array_push($rowvalues, $delform);
		}
*/

		
		$page->disprow($rowvalues);
	}
	$page->endtable($pager);

	$page->admin_footer();
?>


