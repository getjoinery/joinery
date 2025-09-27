<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/items_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();
	$settings = Globalvars::get_instance(); 

	$item = new Item($_GET['itm_item_id'], TRUE);

	if($_REQUEST['action'] == 'delete'){
		$item->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$item->soft_delete();

		header("Location: /admin/admin_items");
		exit();				
	}
	else if($_REQUEST['action'] == 'undelete'){
		$item->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$item->undelete();

		header("Location: /admin/admin_items");
		exit();				
	}

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'blog-items',
		'breadcrumbs' => array(
			'Items'=>'/admin/admin_items', 
			$item->get('itm_name')=>'',
		),
		'session' => $session,
	)
	);	
	
	$options['title'] = $item->get('itm_name');
	$options['altlinks'] = array('Edit Item' => '/admin/admin_item_edit?itm_item_id='.$item->key);
	if(!$item->get('itm_delete_time')){
		$options['altlinks']['Soft Delete'] = '/admin/admin_item?action=delete&itm_item_id='.$item->key;
	}
	else{
		$options['altlinks']['Undelete'] = '/admin/admin_item?action=undelete&itm_item_id='.$item->key;
	}
	
	if($_SESSION['permission'] >= 8) {
		$options['altlinks'] += array('Permanent Delete' => '/admin/admin_item_permanent_delete?itm_item_id='.$item->key);
	}

	$page->begin_box($options);

	echo '<strong>Title: </strong> '.$item->get('itm_name').'<br />';
	echo '<strong>Created:</strong> '.LibraryFunctions::convert_time($item->get('itm_create_time'), 'UTC', $session->get_timezone()) .'<br />';
	if($item->get('itm_delete_time')){
		echo 'Status: Deleted at '.LibraryFunctions::convert_time($item->get('itm_delete_time'), 'UTC', $session->get_timezone()).'<br />';
	}
	else if($item->get('itm_is_published')){
		echo '<strong>Published:</strong> ' . LibraryFunctions::convert_time($item->get('itm_published_time'), 'UTC', $session->get_timezone()). '<br />';
	}
	else{
		echo '<strong>UNPUBLISHED</strong><br />';
	}
	
	echo '<strong>Link:</strong> <a href="'.$item->get_url().'">'.$item->get_url('full').'</a><br />';	

	if($item->get('itm_short_description')){
		echo '<strong>Short description:</strong> <p>'.$item->get('itm_short_description').'</p><br />';
	}

	echo '<iframe src="'.$item->get_url().'" width="100%" height="500" style="border:1px solid black;"></iframe>';

	$page->end_box();		
	
	$page->admin_footer();
?>

