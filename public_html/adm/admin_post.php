<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/posts_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();
	$settings = Globalvars::get_instance(); 

	$post = new Post($_GET['pst_post_id'], TRUE);


	if($_REQUEST['action'] == 'delete'){
		$post->authenticate_write($session);
		$post->soft_delete();

		header("Location: /admin/admin_posts");
		exit();				
	}
	else if($_REQUEST['action'] == 'undelete'){
		$post->authenticate_write($session);
		$post->soft_delete();

		header("Location: /admin/admin_posts");
		exit();				
	}
	
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 26,
		'breadcrumbs' => array(
			'Posts'=>'/admin/admin_posts', 
			$post->get('pst_title')=>'',
		),
		'session' => $session,
	)
	);	
	
	$options['title'] = $post->get('pst_title');
	$options['altlinks'] = array('Edit Post' => '/admin/admin_post_edit?pst_post_id='.$post->key);
	$options['altlinks'] += array('Delete Post' => '/admin/admin_post_permanent_delete?pst_post_id='.$post->key);
	if(!$post->get('pst_delete_time') && $_SESSION['permission'] >= 8) {
		$options['altlinks']['Soft Delete'] = '/admin/admin_post?action=delete&pst_post_id='.$post->key;
	}

	$page->begin_box($options);

	if($post->get('pst_delete_time')){
		echo 'Status: Deleted at '.LibraryFunctions::convert_time($post->get('pst_delete_time'), 'UTC', $session->get_timezone()).'<br />';
	}
	else if($post->get('pst_is_published')){
		echo '<strong>Published:</strong> ' . LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', $session->get_timezone()). '<br />';
	}
	else{
		echo '<strong>UNPUBLISHED</strong><br />';
	}
	
	echo '<strong>Link:</strong> <a href="'.$post->get_url().'">'.$settings->get_setting('webDir_SSL').$post->get_url().'</a><br />';	
	echo '<strong>Created:</strong> '.LibraryFunctions::convert_time($post->get('pst_create_time'), 'UTC', $session->get_timezone()) .'<br />';
	echo '<strong>Short description:</strong> <p>'.$post->get('pst_short_description').'</p><br />';
	
	echo '<h2> '.$post->get('pst_title').'</h2>';
	echo '<iframe src="/ajax/blog_post_preview_ajax?pst_post_id='.$post->key.'" width="100%" height="500" style="border:1px solid black;"></iframe>';

	$page->end_box();		
	
	$page->admin_footer();
?>


