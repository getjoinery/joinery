<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/comments_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	if (isset($_REQUEST['cmt_comment_id'])) {
		$comment = new Comment($_REQUEST['cmt_comment_id'], TRUE);
	} else {
		$comment = new Comment(NULL);
	}

	if($_POST){
		
		$editable_fields = array('cmt_body', 'cmt_author_name', 'cmt_is_approved');

		foreach($editable_fields as $field) {
			$comment->set($field, $_REQUEST[$field]);
		}
				
		
		
		$comment->prepare();
		$comment->save();
		$comment->load();
		
		LibraryFunctions::redirect('/admin/admin_comment?cmt_comment_id='. $comment->key);
		exit;
	}


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 26,
		'breadcrumbs' => array(
			'Comments'=>'/admin/admin_comments', 
			'Edit Comment' => '',
		),
		'session' => $session,
	)
	);	

	
	$pageoptions['title'] = "Edit Comment";
	$page->begin_box($pageoptions);

	// Editing an existing email
	$formwriter = new FormWriterMaster('form1');
	
	$validation_rules = array();
	$validation_rules['cmt_body']['required']['value'] = 'true';
	$validation_rules['cmt_body']['minlength']['value'] = 10;
	echo $formwriter->set_validate($validation_rules);	



	echo $formwriter->begin_form('form', 'POST', '/admin/admin_comment_edit');

	if($comment->key){
		echo $formwriter->hiddeninput('cmt_comment_id', $comment->key);
		echo $formwriter->hiddeninput('action', 'edit');
	}
	
	echo $formwriter->textinput('Commenter Name', 'cmt_author_name', NULL, 100, $comment->get('cmt_author_name'), '', 255, '');		
	
	
	$optionvals = array("No"=>0, "Yes"=>1);
	echo $formwriter->dropinput("Approved", "cmt_is_approved", "ctrlHolder", $optionvals, $comment->get('cmt_is_approved'), '', FALSE);
	
	
	echo $formwriter->textbox('Comment', 'cmt_body', 'ctrlHolder', 5, 80, $comment->get('cmt_body'), '', 'no');


	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();


	$page->end_box();
	

	$page->admin_footer();

?>
