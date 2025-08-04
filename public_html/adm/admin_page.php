<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	// ErrorHandler.php no longer needed - using new ErrorManager system
	
	PathHelper::requireOnce('/includes/AdminPage.php');
	PathHelper::requireOnce('/includes/SessionControl.php');
	PathHelper::requireOnce('/includes/LibraryFunctions.php');

	PathHelper::requireOnce('/data/users_class.php');
	PathHelper::requireOnce('/data/pages_class.php');
	PathHelper::requireOnce('/data/page_contents_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$page = new Page($_GET['pag_page_id'], TRUE);
	
	$search_criteria = array();
	
	$search_criteria['page_id'] = $page->key;

	$page_contents = new MultiPageContent(
		$search_criteria,
		//array($sort=>$sdirection),
		//$numperpage,
		//$offset
		);	
	$numrecords = $page_contents->count_all();	
	$page_contents->load();

	if($_REQUEST['action'] == 'delete'){
		$page->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page->soft_delete();

		header("Location: /admin/admin_pages");
		exit();				
	}
	else if($_REQUEST['action'] == 'undelete'){
		$page->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$page->undelete();

		header("Location: /admin/admin_pages");
		exit();				
	}

	
	$paget = new AdminPage();
	$paget->admin_header(	
	array(
		'menu-id'=> 'pages',
		'breadcrumbs' => array(
			'Pages'=>'/admin/admin_pages', 
			$page->get('pag_title')=>'',
		),
		'session' => $session,
	)
	);	
	
	$options['title'] = $page->get('pag_title');
	$options['altlinks'] = array('Edit Page' => '/admin/admin_page_edit?pag_page_id='.$page->key);
	$options['altlinks'] += array('Permanent Delete' => '/admin/admin_page_permanent_delete?pag_page_id='.$page->key);
	if(!$page->get('pag_delete_time') && $_SESSION['permission'] >= 8) {
		$options['altlinks']['Soft Delete'] = '/admin/admin_page?action=delete&pag_page_id='.$page->key;
	}
	else if($_SESSION['permission'] >= 8){
		$options['altlinks']['Restore Soft Delete'] = '/admin/admin_page?action=undelete&pag_page_id='.$page->key;
	}

	$paget->begin_box($options);
	echo '<strong>Title:</strong> '.$page->get('pag_title').'<br />';
	echo '<strong>Created:</strong> '.LibraryFunctions::convert_time($page->get('pag_create_time'), 'UTC', $session->get_timezone()) .'<br />';
	if($page->get('pag_delete_time')){
		echo '<strong>Status: Deleted</strong> at '.LibraryFunctions::convert_time($page->get('pag_delete_time'), 'UTC', $session->get_timezone()).'<br />';
	}
	else if($page->get('pag_published_time')){
		echo '<strong>Published:</strong> ' . LibraryFunctions::convert_time($page->get('pag_published_time'), 'UTC', $session->get_timezone()). '<br />';
	}
	else{
		echo '<strong>UNPUBLISHED</strong><br />';
	}
	

	echo '<strong>Link:</strong> <a href="'.$page->get_url().'">'.$page->get_url('short').'</a><br />';	


	$headers = array("Content",  "Published", "Creator", "Status");
	$altlinks = array('New Content'=>'/admin/admin_page_content_edit?pag_page_id='.$page->key);
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Page Content',
		//'search_on' => TRUE
	);
	$paget->tableheader($headers, $table_options, NULL);


	foreach ($page_contents as $page_content){
		
		$user = new User($page_content->get('pac_usr_user_id'), TRUE);
		
		$title = $page_content->get('pac_location_name');
		if(!$title){
			$title = 'Untitled';
		}
		
		$rowvalues = array();
		array_push($rowvalues, "<a href='/admin/admin_page_content?pac_page_content_id=$page_content->key'>".$title."</a>");	
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

		$paget->disprow($rowvalues);
	}
	$paget->endtable($pager);



	echo '<h3>Preview</h3>';
	echo '<iframe src="'.$page->get_url().'" width="100%" height="500" style="border:1px solid black;"></iframe>';


	$paget->end_box();		
	
	$paget->admin_footer();
?>


