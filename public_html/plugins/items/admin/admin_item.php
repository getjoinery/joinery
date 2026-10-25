<?php
/** @joinery-admin class=content */

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->set_return();
	$settings = Globalvars::get_instance(); 

	// The Actions menu's buttons POST the id; opening the page carries it in the URL.
	$item = new Item($_REQUEST['itm_item_id'] ?? NULL, TRUE);

	// Delete and undelete are those POST buttons; opening the page is a GET.
	$action = LibraryFunctions::isFormSubmission() ? ($_POST['action'] ?? '') : '';
	if($action == 'delete'){
		$item->assert_can_write($session);
		$item->soft_delete();

		header("Location: /plugins/items/admin/admin_items");
		exit();				
	}
	else if($action == 'undelete'){
		$item->assert_can_write($session);
		$item->undelete();

		header("Location: /plugins/items/admin/admin_items");
		exit();				
	}

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'blog-items',
		'breadcrumbs' => array(
			'Items'=>'/plugins/items/admin/admin_items', 
			$item->get('itm_name')=>'',
		),
		'session' => $session,
	)
	);	
	
	$options['title'] = $item->get('itm_name');
	$options['altlinks'] = array('Edit Item' => '/plugins/items/admin/admin_item_edit?itm_item_id='.$item->key);
	if(!$item->get('itm_delete_time')){
		$options['altlinks']['Soft Delete'] = array('post' => '/plugins/items/admin/admin_item', 'hidden' => array('action' => 'delete', 'itm_item_id' => $item->key));
	}
	else{
		$options['altlinks']['Undelete'] = array('post' => '/plugins/items/admin/admin_item', 'hidden' => array('action' => 'undelete', 'itm_item_id' => $item->key));
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

