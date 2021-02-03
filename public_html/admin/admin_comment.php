<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/comments_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/posts_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);


	$comment = new Comment($_GET['cmt_comment_id'], TRUE);
	$post = new Post($comment->get('cmt_pst_post_id'), TRUE);

	if($_POST['action'] == 'approve'){

		$comment = new Comment($_POST['cmt_comment_id'], TRUE);
		$comment->set('cmt_is_approved', true);
		$comment->prepare();
		$comment->authenticate_write($session);
		$comment->save();

		header("Location: /admin/admin_comments");
		exit();				
	}
	else if($_POST['action'] == 'unapprove'){

		$comment = new Comment($_POST['cmt_comment_id'], TRUE);
		$comment->set('cmt_is_approved', false);
		$comment->prepare();
		$comment->authenticate_write($session);		
		$comment->save();

		header("Location: /admin/admin_comments");
		exit();				
	}
	else if($_POST['action'] == 'delete'){

		$comment = new Comment($_POST['cmt_comment_id'], TRUE);
		$comment->set('cmt_is_deleted', true);
		$comment->prepare();
		$comment->authenticate_write($session);
		$comment->save();

		header("Location: /admin/admin_comments");
		exit();				
	}
	else if($_POST['action'] == 'undelete'){

		$comment = new Comment($_POST['cmt_comment_id'], TRUE);
		$comment->set('cmt_is_deleted', false);
		$comment->prepare();
		$comment->authenticate_write($session);
		$comment->save();

		header("Location: /admin/admin_comments");
		exit();				
	}

	$session->set_return();


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 26,
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

	$settings = Globalvars::get_instance();
	$CDN = $settings->get_setting('CDN');
	$webDir = $settings->get_setting('webDir');



	$options['title'] = substr($comment->get('cmt_body'), 0, 40). '...';
	$options['altlinks'] = array();
	if(!$comment->get('cmt_is_deleted')) {
		$options['altlinks'] += array('Edit Comment' => '/admin/admin_comment_edit?cmt_comment_id='.$comment->key);
	}

		
	$page->begin_box($options);
	
	echo '<p>By: '.$comment->get('cmt_author_name').' at '.LibraryFunctions::convert_time($comment->get('cmt_created_time'), 'UTC', $session->get_timezone()).'<br>';
	echo 'On: <a href="'.$post->get_url().'">'.$post->get('pst_title').'</a><br>';
	if($comment->get('cmt_is_deleted')){
		echo 'Status: Deleted';
	}
	else if($comment->get('cmt_is_approved')){
		echo 'Status: Approved';
	}
	else{
		echo 'Status: Unapproved';
	}
	echo '<br><br>';
	?><p><?php echo $comment->get('cmt_body'); ?></p>


<?php 
	$page->end_box();

	$page->admin_footer();
?>
