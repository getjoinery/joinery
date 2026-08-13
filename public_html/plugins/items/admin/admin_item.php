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
		$item->assert_can_write($session);
		$item->soft_delete();

		header("Location: /admin/admin_items");
		exit();				
	}
	else if($_REQUEST['action'] == 'undelete'){
		$item->assert_can_write($session);
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
		$options['altlinks']['Soft Delete'] = array('post' => '/admin/admin_item', 'hidden' => array('action' => 'delete', 'itm_item_id' => $item->key));
	}
	else{
		$options['altlinks']['Undelete'] = array('post' => '/admin/admin_item', 'hidden' => array('action' => 'undelete', 'itm_item_id' => $item->key));
	}
	
	if($_SESSION['permission'] >= 8) {
		$options['altlinks'] += array('Permanent Delete' => '/admin/admin_item_permanent_delete?itm_item_id='.$item->key);
	}

	$page->begin_box($options);

	echo '<strong>Title: </strong> '.$item->get('itm_name').'<br />';
	echo '<strong>Created:</strong> '.$item->get_local('itm_create_time') .'<br />';
	if($item->get('itm_delete_time')){
		echo 'Status: Deleted at '.$item->get_local('itm_delete_time').'<br />';
	}
	else if($item->get('itm_is_published')){
		echo '<strong>Published:</strong> ' . $item->get_local('itm_published_time'). '<br />';
	}
	else{
		echo '<strong>UNPUBLISHED</strong><br />';
	}
	
	echo '<strong>Link:</strong> <a href="'.$item->get_url().'">'.$item->get_url('full').'</a><br />';	

	if($item->get('itm_short_description')){
		echo '<strong>Short description:</strong> <p>'.$item->get('itm_short_description').'</p><br />';
	}

	echo '<div class="jy-ui"><iframe src="'.$item->get_url().'" class="jy-items-preview"></iframe></div>';

	$page->end_box();		
	
	$page->admin_footer();
?>

