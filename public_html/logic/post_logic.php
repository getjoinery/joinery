<?php

function post_logic($get_vars, $post_vars, $post){
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/posts_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/comments_class.php');

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;


	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	if(!$settings->get_setting('blog_active')){
		//TURNED OFF
		header("HTTP/1.0 404 Not Found");
		echo 'This setting is turned off';
		exit();			
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
					$body .= '<p>Link: <a href="'. $post->get_url('full').'">'.$post->get_url('full').'</a>';
					$email_inner_template = $settings->get_setting('individual_email_inner_template');
					$email = new EmailTemplate($email_inner_template, $notify_user);
					$email->fill_template(array(
						'subject' => 'New Comment',
						'body' => $body,
					));	
					$result = $email->send();
				}					
				catch (Exception $e) {
					//DO NOTHING
					$error = "";
				}
			}
		}

		header('Location: '.$_SERVER['REQUEST_URI']);
		exit();
	}
	
	//TODO: HANDLE COMMENT THREADING
	$comments = new MultiComment(
		array('post_id'=>$post->key, 'approved'=>true, 'deleted'=>false, 'has_parent_id'=>false),
		array('comment_id'=>'DESC'),
		NULL,
		NULL);	
	$comments->load();
	$page_vars['comments'] = $comments;
	$numcomments = $comments->count_all();		
	$page_vars['numcomments'] = $numcomments;	
	
	
	return $page_vars;
}
?>