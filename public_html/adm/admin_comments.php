<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/comments_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'comment_id', 0, '');	
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	

	
	$search_criteria = array();

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
		'menu-id'=> 26,
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
			$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_comment?cmt_comment_id='. $comment->key.'">
			<input type="hidden" class="hidden" name="action" id="action" value="undelete" />
			<input type="hidden" class="hidden" name="cmt_comment_id" id="cmt_comment_id" value="'.$comment->key.'" />
			<button class="uk-button" type="submit">Undelete</button>
			</form>';
		}
		else{
			$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_comment?cmt_comment_id='. $comment->key.'">
			<input type="hidden" class="hidden" name="action" id="action" value="delete" />
			<input type="hidden" class="hidden" name="cmt_comment_id" id="cmt_comment_id" value="'.$comment->key.'" />
			<button class="uk-button" type="submit">Delete</button>
			</form>';			
		}
		array_push($rowvalues, $delform);	

		if($comment->get('cmt_is_approved')){
			$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_comment?cmt_comment_id='. $comment->key.'">
			<input type="hidden" class="hidden" name="action" id="action" value="unapprove" />
			<input type="hidden" class="hidden" name="cmt_comment_id" id="cmt_comment_id" value="'.$comment->key.'" />
			<button class="uk-button" type="submit">Unapprove</button>
			</form>';
		}
		else{
			$delform = '<form id="form2" class="form2" name="form2" method="POST" action="/admin/admin_comment?cmt_comment_id='. $comment->key.'">
			<input type="hidden" class="hidden" name="action" id="action" value="approve" />
			<input type="hidden" class="hidden" name="cmt_comment_id" id="cmt_comment_id" value="'.$comment->key.'" />
			<button class="uk-button" type="submit">Approve</button>
			</form>';			
		}
		array_push($rowvalues, $delform);	

		$page->disprow($rowvalues);
	}


	$page->endtable($pager);
	$page->admin_footer();
?>


