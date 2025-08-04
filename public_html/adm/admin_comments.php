<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	// ErrorHandler.php no longer needed - using new ErrorManager system
	
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/users_class.php');
	PathHelper::requireOnce('data/comments_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'comment_id', 0, '');	
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	

	
	$search_criteria = array();
	
	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$search_criteria['deleted'] = false;
	}

	$comments = new MultiComment(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);	
	$numrecords = $comments->count_all();	
	$comments->load();
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'comments',
		'page_title' => 'Comments',
		'readable_title' => 'Comments',
		'breadcrumbs' => array(
			'Comments'=>'',
		),
		'session' => $session,
	)
	);
		

	$headers = array("Comment",  "By", "Created", "Status");
	$altlinks = array();
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));	
	$table_options = array(
		//'sortoptions'=>array("User ID"=>"user_id", "Last Name"=>"last_name", "First Name"=>"first_name"),
		'altlinks' => $altlinks,
		'title' => $title,
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);


	foreach ($comments as $comment){
		//$user = new User($comment->get('cmt_usr_user_id'), TRUE);
		
		$title = $comment->get('cmt_title');
		if(!$title){
			$title = 'Untitled';
		}
		
		$rowvalues = array();
		array_push($rowvalues, "($comment->key) <a href='/admin/admin_comment?cmt_comment_id=$comment->key'>".substr($comment->get('cmt_body'), 0,40)."</a>");	
		array_push($rowvalues, $comment->get('cmt_author_name'));	
		array_push($rowvalues, LibraryFunctions::convert_time($comment->get('cmt_created_time'), 'UTC', $session->get_timezone()));
		//array_push($rowvalues, '('.$user->key.') <a href="/admin/admin_user?usr_user_id='.$user->key.'">'.$user->display_name() .'</a> ');

		if($comment->get('cmt_delete_time')) {
			$status = 'Deleted';
		} 
		else {
			if($comment->get('cmt_is_approved')) {
				$status = 'Approved';
			}
			else{
				$status = 'Unapproved';
			}
		}		
		array_push($rowvalues, $status);

		if($comment->get('cmt_delete_time')){
			$formwriter = LibraryFunctions::get_formwriter_object('form2', 'admin');
			$delform = $formwriter->begin_form('form2', 'POST', '/admin/admin_comment?cmt_comment_id='. $comment->key);
			$delform .= $formwriter->hiddeninput('action', 'undelete');
			$delform .= $formwriter->hiddeninput('cmt_comment_id', $comment->key);
			$delform .= $formwriter->new_form_button('Undelete', 'secondary');
			$delform .= $formwriter->end_form();
		}
		else{
			$formwriter = LibraryFunctions::get_formwriter_object('form2', 'admin');
			$delform = $formwriter->begin_form('form2', 'POST', '/admin/admin_comment?cmt_comment_id='. $comment->key);
			$delform .= $formwriter->hiddeninput('action', 'delete');
			$delform .= $formwriter->hiddeninput('cmt_comment_id', $comment->key);
			$delform .= $formwriter->new_form_button('Delete', 'secondary');
			$delform .= $formwriter->end_form();
		}
		array_push($rowvalues, $delform);	

		if($comment->get('cmt_is_approved')){
			$formwriter = LibraryFunctions::get_formwriter_object('form2', 'admin');
			$delform = $formwriter->begin_form('form2', 'POST', '/admin/admin_comment?cmt_comment_id='. $comment->key);
			$delform .= $formwriter->hiddeninput('action', 'unapprove');
			$delform .= $formwriter->hiddeninput('cmt_comment_id', $comment->key);
			$delform .= $formwriter->new_form_button('Unapprove', 'secondary');
			$delform .= $formwriter->end_form();
		}
		else{
			$formwriter = LibraryFunctions::get_formwriter_object('form2', 'admin');
			$delform = $formwriter->begin_form('form2', 'POST', '/admin/admin_comment?cmt_comment_id='. $comment->key);
			$delform .= $formwriter->hiddeninput('action', 'approve');
			$delform .= $formwriter->hiddeninput('cmt_comment_id', $comment->key);
			$delform .= $formwriter->new_form_button('Approve', 'secondary');
			$delform .= $formwriter->end_form();					
		}
		array_push($rowvalues, $delform);	

		$page->disprow($rowvalues);
	}


	$page->endtable($pager);
	$page->admin_footer();
?>


