<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/videos_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'video_id', 0, '');	
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$source = LibraryFunctions::fetch_variable('source', NULL, 0, '');
	
	$search_criteria = array();
	if($vid_source){
		$search_criteria['source'] = $source;
	}

	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}

	$videos = new MultiVideo(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);	
	$numrecords = $videos->count_all();	
	$videos->load();
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'videos',
		'page_title' => 'Videos',
		'readable_title' => 'Videos',
		'breadcrumbs' => array(
			'Videos'=>'',
		),
		'session' => $session,
	)
	);
		

	$headers = array("Video",  "Source", "Uploaded", "By");
	$altlinks = array('Add Video'=>'/admin/admin_video_edit');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Videos',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($videos as $video){
		$user = new User($video->get('vid_usr_user_id'), TRUE);
		$deleted = '';
		if($video->get('vid_delete_time')){
			$deleted = 'DELETED';
		}
		
		$rowvalues = array();
		array_push($rowvalues, "<a href='/admin/admin_video?vid_video_id=$video->key'>".$video->get('vid_title')."</a> ".$deleted);	
		array_push($rowvalues, $video->get_source());
		
		array_push($rowvalues, LibraryFunctions::convert_time($video->get('vid_create_time'), 'UTC', $session->get_timezone()));
	
		array_push($rowvalues, '<a href="/admin/admin_user?usr_user_id='.$user->key.'">'.$user->display_name() .'</a> ');


		$page->disprow($rowvalues);
	}


	$page->endtable($pager);
	$page->admin_footer();
?>


