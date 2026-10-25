<?php
/** @joinery-admin class=content */

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/videos_class.php'));

	$session = SessionControl::get_instance();
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'video_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$source = LibraryFunctions::fetch_variable('source', NULL, 0, '', TRUE, 'int');

	$search_criteria = array();
	if($source){
		$search_criteria['source'] = $source;
	}

	//SHOW DELETED ONLY TO THOSE WHO MAY UNDELETE
	if(!Access::permits(Video::access_level('soft_delete'), $session)){
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

		array_push($rowvalues, $video->get_local('vid_create_time'));

		array_push($rowvalues, PublicPageBase::admin_user_link($user) . ' ');

		$page->disprow($rowvalues);
	}

	$page->endtable($pager);
	$page->admin_footer();
?>

