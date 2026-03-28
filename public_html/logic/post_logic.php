<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function post_logic($get_vars, $post_vars, $post){
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));
	require_once(PathHelper::getIncludePath('data/posts_class.php'));
	require_once(PathHelper::getIncludePath('data/comments_class.php'));

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;


	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('blog_active')){
		//TURNED OFF
		return LogicResult::error('This feature is turned off');
	}
	
	$page_vars['post'] = $post;

	if(!$post){
		require_once(LibraryFunctions::display_404_page());	
	}
	else if ($post && $session->get_user_id() && $session->get_permission() > 4) {
		//SHOW IT EVEN IF UNPUBLISHED OR DELETED
	}
	else {
		if(!$post->get('pst_is_published') || $post->get('pst_delete_time')){
			require_once(LibraryFunctions::display_404_page());		
		}
	}
	
	//GET AUTHOR
	$author = new User($post->get('pst_usr_user_id'), TRUE);
	$page_vars['author'] = $author;
	$tags = Group::get_groups_for_member($post->key, 'post_tag', false, 'names');
	$page_vars['tags'] = $tags;
		

	if($post_vars){
		
		$new_comment = Comment::add_comment($post->key, $session, $post_vars);

		//IF AUTHOR IS COMMENTER
		if($author->key == $new_comment->get('cmt_usr_user_id')){
			$new_comment->set('cmt_is_approved', TRUE);
			$new_comment->save();
		}

		//SEND NOTIFICATION
		if($settings->get_setting('comment_notification_emails')){
			$notify_emails = explode(',', $settings->get_setting('comment_notification_emails'));
			foreach($notify_emails as $notify_email){
				try {
					$notify_user = User::GetByEmail($notify_email);
					$body = '<p>Comment '.$new_comment->key.' was added by "'.htmlspecialchars($new_comment->get('cmt_author_name')).'".</p>';
					$body .= '<p>Link: <a href="'. LibraryFunctions::get_absolute_url($post->get_url()).'">' . LibraryFunctions::get_absolute_url($post->get_url()).'</a>';
					$email_inner_template = $settings->get_setting('individual_email_inner_template');
					EmailSender::sendTemplate($email_inner_template,
						$notify_user->get('usr_email'),
						[
							'subject' => 'New Comment',
							'body' => $body,
							'recipient' => $notify_user->export_as_array()
						]
					);
				}					
				catch (Exception $e) {
					//DO NOTHING
					$error = "";
				}
			}
		}

		return LogicResult::redirect($_SERVER['REQUEST_URI']);
	}
	
	// Load top-level comments
	$comments = new MultiComment(
		array('post_id'=>$post->key, 'approved'=>true, 'deleted'=>false, 'has_parent_id'=>false),
		array('comment_id'=>'DESC'),
		NULL,
		NULL);
	$comments->load();
	$page_vars['comments'] = $comments;
	$numcomments = $comments->count_all();

	// Load replies (comments with a parent) and group by parent ID
	$replies_by_parent = array();
	$all_replies = new MultiComment(
		array('post_id'=>$post->key, 'approved'=>true, 'deleted'=>false, 'has_parent_id'=>true),
		array('comment_id'=>'ASC'),
		NULL,
		NULL);
	if ($all_replies->count_all()) {
		$all_replies->load();
		foreach ($all_replies as $reply) {
			$parent_id = $reply->get('cmt_comment_id_parent');
			if (!isset($replies_by_parent[$parent_id])) {
				$replies_by_parent[$parent_id] = array();
			}
			$replies_by_parent[$parent_id][] = $reply;
		}
		$numcomments += $all_replies->count_all();
	}
	$page_vars['replies_by_parent'] = $replies_by_parent;
	$page_vars['numcomments'] = $numcomments;	
	
	
	return LogicResult::render($page_vars);
}
?>