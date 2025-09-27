<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/users_class.php'));
	require_once(PathHelper::getIncludePath('/data/posts_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();
	$settings = Globalvars::get_instance();

	$post = new Post($_GET['pst_post_id'], TRUE);

	if($_REQUEST['action'] == 'delete'){
		$post->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$post->soft_delete();

		header("Location: /admin/admin_posts");
		exit();
	}
	else if($_REQUEST['action'] == 'undelete'){
		$post->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$post->undelete();

		header("Location: /admin/admin_posts");
		exit();
	}

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'blog-posts',
		'breadcrumbs' => array(
			'Posts'=>'/admin/admin_posts',
			$post->get('pst_title')=>'',
		),
		'session' => $session,
	)
	);

	$options['title'] = $post->get('pst_title');
	$options['altlinks'] = array('Edit Post' => '/admin/admin_post_edit?pst_post_id='.$post->key);
	if(!$post->get('pst_delete_time')){
		$options['altlinks']['Soft Delete'] = '/admin/admin_post?action=delete&pst_post_id='.$post->key;
	}
	else{
		$options['altlinks']['Undelete'] = '/admin/admin_post?action=undelete&pst_post_id='.$post->key;
	}

	if($_SESSION['permission'] >= 8) {
		$options['altlinks'] += array('Permanent Delete' => '/admin/admin_post_permanent_delete?pst_post_id='.$post->key);
	}

	$page->begin_box($options);

	echo '<strong>Title: </strong> '.$post->get('pst_title').'<br />';
	echo '<strong>Created:</strong> '.LibraryFunctions::convert_time($post->get('pst_create_time'), 'UTC', $session->get_timezone()) .'<br />';
	if($post->get('pst_delete_time')){
		echo 'Status: Deleted at '.LibraryFunctions::convert_time($post->get('pst_delete_time'), 'UTC', $session->get_timezone()).'<br />';
	}
	else if($post->get('pst_is_published')){
		echo '<strong>Published:</strong> ' . LibraryFunctions::convert_time($post->get('pst_published_time'), 'UTC', $session->get_timezone()). '<br />';
	}
	else{
		echo '<strong>UNPUBLISHED</strong><br />';
	}

	echo '<strong>Link:</strong> <a href="'.$post->get_url().'">'.$post->get_url('short').'</a><br />';

	if($post->get('pst_short_description')){
		echo '<strong>Short description:</strong> <p>'.$post->get('pst_short_description').'</p><br />';
	}

	echo '<iframe src="'.$post->get_url().'" width="100%" height="500" style="border:1px solid black;"></iframe>';

	$page->end_box();

	$page->admin_footer();
?>

