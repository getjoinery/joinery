<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once(PathHelper::getIncludePath('plugins/items/data/items_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'item_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

	$search_criteria = array();

	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}
	
	$items = new MultiItem(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);	
	$numrecords = $items->count_all();	
	$items->load();
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'blog-items',
		'breadcrumbs' => array(
			'Items'=>'', 
		),
		'session' => $session,
	)
	);	

	$headers = array("Item",  "Created", "Published", "By", "Item Status");
	$altlinks = array('New Item'=>'/admin/admin_item_edit');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => 'Items',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($items as $item){
		
		$deleted = '';
		if($item->get('itm_delete_time')){
			$deleted = ' DELETED ';
		}
		
		$user = new User($item->get('itm_usr_user_id'), TRUE);
		
		$title = $item->get('itm_name');
		if(!$title){
			$title = 'Untitled';
		}
		
		$rowvalues = array();
		array_push($rowvalues, "<a href='/admin/admin_item?itm_item_id=$item->key'>".$title."</a>". $deleted);	
		array_push($rowvalues, LibraryFunctions::convert_time($item->get('itm_create_time'), 'UTC', $session->get_timezone()));
		array_push($rowvalues, LibraryFunctions::convert_time($item->get('itm_published_time'), 'UTC', $session->get_timezone()));
		array_push($rowvalues, '<a href="/admin/admin_user?usr_user_id='.$user->key.'">'.$user->display_name() .'</a> ');

		if($item->get('itm_delete_time')) {
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

