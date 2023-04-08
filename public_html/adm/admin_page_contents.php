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

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'page_content_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');
	
	
	$search_criteria = array();

	$page_contents = new MultiPageContent(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);	
	$numrecords = $page_contents->count_all();	
	$page_contents->load();
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'pages',
		'breadcrumbs' => array(
			'Page Contents'=>'', 
		),
		'session' => $session,
	)
	);	
	


	$headers = array("Content",  "Created", "Published", "By", "Status");
	$altlinks = array('New Content'=>'/admin/admin_page_content_edit');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Page Content',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($page_contents as $page_content){
		$user = new User($page_content->get('pac_usr_user_id'), TRUE);
		
		$title = $page_content->get('pac_location_name');
		if(!$title){
			$title = 'Untitled';
		}
		
		$rowvalues = array();
		array_push($rowvalues, "<a href='/admin/admin_page_content?pac_page_content_id=$page_content->key'>".$title."</a>");	
		array_push($rowvalues, LibraryFunctions::convert_time($page_content->get('pac_create_time'), 'UTC', $session->get_timezone()));
		array_push($rowvalues, LibraryFunctions::convert_time($page_content->get('pac_published_time'), 'UTC', $session->get_timezone()));
		array_push($rowvalues, '<a href="/admin/admin_user?usr_user_id='.$user->key.'">'.$user->display_name() .'</a> ');

		if($page_content->get('pac_delete_time')) {
			$status = 'Deleted';
		} 
		else {
			if($page_content->get('pac_published_time')) {
				$status = 'Published';
			}
			else{
				$status = 'Unpublished';
			}
		}		
		array_push($rowvalues, $status);

		$page->disprow($rowvalues);
	}


	$page->endtable($pager);	
	$page->admin_footer();
?>


