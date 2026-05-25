<?php
require_once(__DIR__ . '/../../includes/PathHelper.php');

function admin_comment_edit_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/comments_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);

	if (isset($input['edit_primary_key_value'])) {
		$comment = new Comment($input['edit_primary_key_value'], TRUE);
	} elseif (isset($input['cmt_comment_id'])) {
		$comment = new Comment($input['cmt_comment_id'], TRUE);
	} else {
		$comment = new Comment(NULL);
	}

	if($input){

		$editable_fields = array('cmt_body', 'cmt_author_name', 'cmt_is_approved', 'cmt_pst_post_id');

		foreach($editable_fields as $field) {
			$comment->set($field, $input[$field]);
		}

		$comment->prepare();
		$comment->save();
		$comment->load();

		return LogicResult::redirect('/admin/admin_comment?cmt_comment_id='. $comment->key);
	}

	$page_vars = array(
		'comment' => $comment,
		'session' => $session,
	);

	return LogicResult::render($page_vars);
}

?>
