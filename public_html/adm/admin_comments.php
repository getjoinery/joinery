<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_comments_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$page_vars = process_logic(admin_comments_logic($_GET, $_POST));

$session = $page_vars['session'];
$comments = $page_vars['comments'];
$numrecords = $page_vars['numrecords'];
$numperpage = $page_vars['numperpage'];

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'comments',
	'page_title' => 'Comments',
	'readable_title' => 'Comments',
	'breadcrumbs' => array(
		'Comments' => '',
	),
	'session' => $session,
)
);

$headers = array("Comment",  "By", "Created", "Status", "Delete", "Approve");
$altlinks = array();
$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
$table_options = array(
	'altlinks' => $altlinks,
	'title' => 'Comments',
);
$page->tableheader($headers, $table_options, $pager);

foreach ($comments as $comment){

	$title = $comment->get('cmt_title');
	if(!$title){
		$title = 'Untitled';
	}

	$rowvalues = array();
	array_push($rowvalues, "($comment->key) <a href='/admin/admin_comment?cmt_comment_id=$comment->key'>".substr($comment->get('cmt_body'), 0,40)."</a>");
	array_push($rowvalues, $comment->get('cmt_author_name'));
	array_push($rowvalues, LibraryFunctions::convert_time($comment->get('cmt_created_time'), 'UTC', $session->get_timezone()));

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
		$formwriter = $page->getFormWriter('form2');
		$delform = $formwriter->begin_form('form2', 'POST', '/admin/admin_comment?cmt_comment_id='. $comment->key);
		$delform .= $formwriter->hiddeninput('action', 'undelete');
		$delform .= $formwriter->hiddeninput('cmt_comment_id', $comment->key);
		$delform .= $formwriter->new_form_button('Undelete', 'secondary');
		$delform .= $formwriter->end_form();
	}
	else{
		$formwriter = $page->getFormWriter('form2');
		$delform = $formwriter->begin_form('form2', 'POST', '/admin/admin_comment?cmt_comment_id='. $comment->key);
		$delform .= $formwriter->hiddeninput('action', 'delete');
		$delform .= $formwriter->hiddeninput('cmt_comment_id', $comment->key);
		$delform .= $formwriter->new_form_button('Delete', 'secondary');
		$delform .= $formwriter->end_form();
	}
	array_push($rowvalues, $delform);

	if($comment->get('cmt_is_approved')){
		$formwriter = $page->getFormWriter('form2');
		$delform = $formwriter->begin_form('form2', 'POST', '/admin/admin_comment?cmt_comment_id='. $comment->key);
		$delform .= $formwriter->hiddeninput('action', 'unapprove');
		$delform .= $formwriter->hiddeninput('cmt_comment_id', $comment->key);
		$delform .= $formwriter->new_form_button('Unapprove', 'secondary');
		$delform .= $formwriter->end_form();
	}
	else{
		$formwriter = $page->getFormWriter('form2');
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
