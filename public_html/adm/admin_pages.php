<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/pages_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'page_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');
	
	
	$search_criteria = array();

	$pages = new MultiPage(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);	
	$numrecords = $pages->count_all();	
	$pages->load();
	
	$paget = new AdminPage();
	$paget->admin_header(	
	array(
		'menu-id'=> 24,
		'breadcrumbs' => array(
			'Pages'=>'', 
		),
		'session' => $session,
	)
	);	
	


	$headers = array("Content",  "Created", "Published", "By", "Status");
	$altlinks = array('New Page'=>'/admin/admin_page_edit');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Page',
		//'search_on' => TRUE
	);
	$paget->tableheader($headers, $table_options, $pager);

	foreach ($pages as $page){
		
		
		$title = $page->get('pag_title');
		if(!$title){
			$title = 'Untitled';
		}
		
		$rowvalues = array();
		array_push($rowvalues, "<a href='/admin/admin_page?pag_page_id=$page->key'>".$title."</a>");	
		array_push($rowvalues, LibraryFunctions::convert_time($page->get('pag_create_time'), 'UTC', $session->get_timezone()));
		array_push($rowvalues, LibraryFunctions::convert_time($page->get('pag_published_time'), 'UTC', $session->get_timezone()));
		
		$user = new User($page->get('pag_usr_user_id'), TRUE);
		array_push($rowvalues, '<a href="/admin/admin_user?usr_user_id='.$user->key.'">'.$user->display_name() .'</a> ');

		if($page->get('pag_delete_time')) {
			$status = 'Deleted';
		} 
		else {
			if($page->get('pag_published_time')) {
				$status = 'Published';
			}
			else{
				$status = 'Unpublished';
			}
		}		
		array_push($rowvalues, $status);

		$paget->disprow($rowvalues);
	}


	$paget->endtable($pager);	
	$paget->admin_footer();
?>


