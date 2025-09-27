<?php
	
	// ErrorHandler.php no longer needed - using new ErrorManager system
	
	PathHelper::requireOnce('/includes/AdminPage.php');
	
	PathHelper::requireOnce('/includes/LibraryFunctions.php');

	PathHelper::requireOnce('/data/posts_class.php');
	
if ($_POST['confirm']){

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$pst_post_id = LibraryFunctions::fetch_variable('pst_post_id', NULL, 1, 'You must provide a post to delete here.');
	$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.');	
	
	if ($confirm) {
		$post = new Post($pst_post_id, TRUE);
		$post->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$post->permanent_delete();
	}

	//NOW REDIRECT
	$session = SessionControl::get_instance();
	$returnurl = $session->get_return();
	header("Location: $returnurl");
	exit();

}
else{
	$pst_post_id = LibraryFunctions::fetch_variable('pst_post_id', NULL, 1, 'You must provide a post to edit.');

	$post = new Post($pst_post_id, TRUE);
	
	$session = SessionControl::get_instance();
	$session->set_return("/admin/admin_posts");

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'blog-posts',
		'page_title' => 'Post',
		'readable_title' => 'Delete Post',
		'breadcrumbs' => array(
			'Posts'=>'/admin/admin_posts', 
			'Delete ' . $post->get('pst_title') => '',
		),
		'session' => $session,
	)
	);

	$pageoptions['title'] = 'Delete Post '.$post->get('pst_title');
	$page->begin_box($pageoptions);

	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	echo $formwriter->begin_form("form", "post", "/admin/admin_post_permanent_delete");

	echo '<fieldset><h4>Confirm Delete</h4>';
		echo '<div class="fields full">';
		echo '<p>WARNING:  This will permanently delete this post ('.$post->get('pst_title') . ').</p>';

	echo $formwriter->hiddeninput("confirm", 1);
	echo $formwriter->hiddeninput("pst_post_id", $pst_post_id);

			echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_buttons();

		echo '</div>';
	echo '</fieldset>';
	echo $formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

}
?>
