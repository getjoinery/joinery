<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/posts_class.php');
	
if ($_POST['confirm']){

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$pst_post_id = LibraryFunctions::fetch_variable('pst_post_id', NULL, 1, 'You must provide a post to delete here.');
	$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.');	
	
	if ($confirm) {
		$post = new Post($pst_post_id, TRUE);
		$post->authenticate_write($session);
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
		'menu-id'=> 1,
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


	$formwriter = new FormWriterMaster("form1");
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
