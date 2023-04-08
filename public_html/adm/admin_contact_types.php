<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/contact_types_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'contact_type_id', 0, '');	
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	

	
	$search_criteria = array();

	$contact_types = new MultiContactType(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);	
	$numrecords = $contact_types->count_all();	
	$contact_types->load();
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'contact-types',
		'breadcrumbs' => array(
			'Emails'=>'/admin/admin_emails', 
			'Contact Types' => '',
		),
		'session' => $session,
	)
	);
		

	$headers = array("Contact Type",  "Description", "Deleted");
	$altlinks = array();
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));	
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Contact Types',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);


	foreach ($contact_types as $contact_type){
		
		$rowvalues = array();
		array_push($rowvalues, '<a href="/admin/admin_contact_type?ctt_contact_type_id='.$contact_type->key.'">'.$contact_type->get('ctt_name').'</a>');	
		array_push($rowvalues, $contact_type->get('ctt_description'));	
		//array_push($rowvalues, LibraryFunctions::convert_time($contact_type->get('ctt_delete_time'), 'UTC', $session->get_timezone()));
		//array_push($rowvalues, '('.$user->key.') <a href="/admin/admin_user?usr_user_id='.$user->key.'">'.$user->display_name() .'</a> ');

		$status = 'Active';
		if($contact_type->get('ctt_delete_time')) {
			$status = 'Deleted';
		} 		
		array_push($rowvalues, $status);

/*
		if($contact_type->get('ctt_delete_time')){
			$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_contact_type?ctt_contact_type_id='. $contact_type->key.'">
			<input type="hidden" class="hidden" name="action" id="action" value="undelete" />
			<input type="hidden" class="hidden" name="ctt_contact_type_id" id="ctt_contact_type_id" value="'.$contact_type->key.'" />
			<button class="uk-button" type="submit">Undelete</button>
			</form>';
		}
		else{
			$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_contact_type?ctt_contact_type_id='. $contact_type->key.'">
			<input type="hidden" class="hidden" name="action" id="action" value="delete" />
			<input type="hidden" class="hidden" name="ctt_contact_type_id" id="ctt_contact_type_id" value="'.$contact_type->key.'" />
			<button class="uk-button" type="submit">Delete</button>
			</form>';			
		}
		array_push($rowvalues, $delform);	
*/

		$page->disprow($rowvalues);
	}


	$page->endtable($pager);
	$page->admin_footer();
?>


