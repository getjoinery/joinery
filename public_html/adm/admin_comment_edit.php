<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_comment_edit_logic.php'));

	$page_vars = process_logic(admin_comment_edit_logic($_GET, $_POST));
	extract($page_vars);

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'comments',
		'breadcrumbs' => array(
			'Comments'=>'/admin/admin_comments',
			'Edit Comment' => '',
		),
		'session' => $session,
	)
	);

	$pageoptions['title'] = "Edit Comment";
	$page->begin_box($pageoptions);

	// Editing an existing comment
	$formwriter = $page->getFormWriter('form1', 'v2', [
		'model' => $comment
	]);

	echo $formwriter->begin_form();

	if($comment->key){
		$formwriter->hiddeninput('cmt_comment_id', '', ['value' => $comment->key]);
		$formwriter->hiddeninput('action', '', ['value' => 'edit']);
		$formwriter->hiddeninput('cmt_pst_post_id', '', ['value' => $comment->get('cmt_pst_post_id')]);
	}

	$formwriter->textinput('cmt_author_name', 'Commenter Name');

	$formwriter->dropinput('cmt_is_approved', 'Approved', [
		'options' => ['No' => 0, 'Yes' => 1]
	]);

	$formwriter->textarea('cmt_body', 'Comment', [
		'rows' => 5,
		'cols' => 80,
		'validation' => ['required' => true, 'minlength' => 10]
	]);

	$formwriter->submitbutton('btn_submit', 'Submit');
	echo $formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

?>
