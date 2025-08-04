<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	// ErrorHandler.php no longer needed - using new ErrorManager system
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('data/users_class.php');
	PathHelper::requireOnce('data/phone_number_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(9);
	$session->set_return();

	
	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'create_time', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');
	

	$search_criteria = array();

	$errors = new MultiGeneralError(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);	
	$numrecords = $errors->count_all();	
	$errors->load();	
	

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'errors',
		'page_title' => 'Errors',
		'readable_title' => 'Errors',
		'breadcrumbs' => NULL,
		'session' => $session,
	)
	);


	$headers = array("Delete", "User", "Type", "Time", "Error", "File", "Line", "Context");
	$altlinks = array();
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));	
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Errors',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($errors as $error){
		$user_name = '';
		if($error->get('err_usr_user_id')){
			$user = new User($error->get('err_usr_user_id'), TRUE);
			$user_name = $user->display_name();
		}
		
		$rowvalues = array();
		$delete_string = '
		<form action="/admin/admin_errors_delete" method="post">
			<input type="hidden" id="message" name="message" value="'.base64_encode($error->get('err_message')).'">
			<input type="hidden" id="file" name="file" value="'.$error->get('err_file').'">
			<input type="hidden" id="line" name="line" value="'.$error->get('err_line').'">
		  <button type="submit">Delete</button>
		</form>
		';
		
		array_push($rowvalues, $delete_string);		
		array_push($rowvalues, $user_name);
		array_push($rowvalues, $error->get('err_level'));
		array_push($rowvalues, LibraryFunctions::convert_time($error->get('err_create_time'), "UTC", $session->get_timezone(), 'M j, h:ia'));
		array_push($rowvalues, $error->get('err_message'));
		array_push($rowvalues, $error->get('err_file'));		
		array_push($rowvalues, $error->get('err_line'));
		array_push($rowvalues, $error->get('err_context'));
		array_push($rowvalues, $status);

		$page->disprow($rowvalues);
	}

	$page->endtable($pager);

	$page->admin_footer();
?>


