<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('/data/posts_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'post_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

	$search_criteria = array();

	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}

	$posts = new MultiPost(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);
	$numrecords = $posts->count_all();
	$posts->load();

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'blog-posts',
		'breadcrumbs' => array(
			'Posts'=>'',
		),
		'session' => $session,
	)
	);

	$headers = array("Post",  "Created", "Published", "By", "Post Status");
	$altlinks = array('New Post'=>'/admin/admin_post_edit');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Posts',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($posts as $post){

		$deleted = '';
		if($post->get('pst_delete_time')){
			$deleted = ' DELETED ';
		}

		$user = new User($post->get('pst_usr_user_id'), TRUE);

		$title = $post->get('pst_title');
		if(!$title){
			$title = 'Untitled';
		}

		$rowvalues = array();
		array_push($rowvalues, "<a href='/admin/admin_post?pst_post_id=$post->key'>".$title."</a>". $deleted);
		array_push($rowvalues, LibraryFunctions::convert_time($post->get('pst_create_time'), 'UTC', $session->get_timezone()));
		array_push($rowvalues, LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', $session->get_timezone()));
		array_push($rowvalues, '<a href="/admin/admin_user?usr_user_id='.$user->key.'">'.$user->display_name() .'</a> ');

		if($post->get('pst_delete_time')) {
			$status = 'Deleted';
		} else {
			$status = 'Active';
		}
		array_push($rowvalues, $status);

		$page->disprow($rowvalues);
	}

	$page->endtable($pager);
	$page->admin_footer();
?>

