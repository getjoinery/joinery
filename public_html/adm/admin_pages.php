<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('/data/users_class.php'));
	require_once(PathHelper::getIncludePath('/data/pages_class.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_pages_logic.php'));

	$page_vars = process_logic(admin_pages_logic($_GET, $_POST));
	extract($page_vars);

	$paget = new AdminPage();
	$paget->admin_header(
	array(
		'menu-id'=> 'pages',
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

		$deleted = '';
		if($page->get('pag_delete_time')){
			$deleted = ' DELETED ';
		}

		$title = $page->get('pag_title');
		if(!$title){
			$title = 'Untitled';
		}

		$rowvalues = array();
		array_push($rowvalues, "<a href='/admin/admin_page?pag_page_id=$page->key'>".$title."</a>" . $deleted);
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
