<?php

	require_once(PathHelper::getIncludePath('includes/Activation.php'));

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('data/comments_class.php'));
	require_once(PathHelper::getIncludePath('data/posts_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$comment = new Comment($_REQUEST['cmt_comment_id'], TRUE);

	if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'approve'){
		$comment->set('cmt_is_approved', true);
		$comment->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$comment->save();

		header("Location: /admin/admin_comments");
		exit();
	}
	else if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'unapprove'){

		$comment->set('cmt_is_approved', false);
		$comment->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$comment->save();

		header("Location: /admin/admin_comments");
		exit();
	}
	else if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'delete'){
		$comment->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$comment->soft_delete();

		header("Location: /admin/admin_comments");
		exit();
	}
	else if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'undelete'){
		$comment->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$comment->undelete();

		header("Location: /admin/admin_comments");
		exit();
	}

	// Only load post if we're displaying the page (not processing an action)
	// Check if post_id exists before trying to load
	$post_id = $comment->get('cmt_pst_post_id');
	if ($post_id) {
		$post = new Post($post_id, TRUE);
	} else {
		$post = null;
	}

	$session->set_return();

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'comments',
		'page_title' => 'Comment',
		'readable_title' => 'Comment',
		'breadcrumbs' => array(
			'Posts'=>'/admin/admin_posts',
			$post->get('pst_title') => '/admin/admin_post?pst_post_id='.$post->key,
			'Comment'=>'',
		),
		'session' => $session,
	)
	);

	$options['title'] = substr($comment->get('cmt_body'), 0, 40). '...';
	$options['altlinks'] = array();
	if(!$comment->get('cmt_delete_time')) {
		$options['altlinks'] += array('Edit Comment' => '/admin/admin_comment_edit?cmt_comment_id='.$comment->key);
	}

	if(!$comment->get('cmt_delete_time') && $_SESSION['permission'] >= 8) {
		$options['altlinks']['Soft Delete'] = '/admin/admin_comment?action=delete&cmt_comment_id='.$comment->key;
	}

	$page->begin_box($options);

	echo '<p>By: '.htmlspecialchars($comment->get('cmt_author_name')).' at '.LibraryFunctions::convert_time($comment->get('cmt_created_time'), 'UTC', $session->get_timezone()).'<br>';
	echo 'On: <a href="'.$post->get_url().'">'.$post->get('pst_title').'</a><br>';
	if($comment->get('cmt_delete_time')){
		echo 'Status: Deleted at '.LibraryFunctions::convert_time($comment->get('cmt_delete_time'), 'UTC', $session->get_timezone()).'<br />';
	}
	else if($comment->get('cmt_is_approved')){
		echo 'Status: Approved';
	}
	else{
		echo 'Status: Unapproved';
	}
	echo '<br><br>';
	?><p><?php echo nl2br(htmlspecialchars($comment->get('cmt_body'))); ?></p>

<?php
	$page->end_box();

	$page->admin_footer();
?>
