<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/EmailTemplate.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/posts_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/comments_class.php');

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();
	if(!$settings->get_setting('blog_active')){
		//TURNED OFF
		header("HTTP/1.0 404 Not Found");
		echo 'This setting is turned off';
		exit();			
	}

	if ($session->get_user_id() && $session->get_permission() > 0) {
		//SHOW IT EVEN IF UNPUBLISHED OR DELETED
	}
	else {
		if(!$post || !$post->get('pst_is_published') || $post->get('pst_delete_time')){
			require_once(LibraryFunctions::display_404_page());		
		}
	}
	
	$settings = Globalvars::get_instance();
	$site_template = $settings->get_setting('site_template');
	
	//GET AUTHOR
	$author = new User($post->get('pst_usr_user_id'), TRUE);
	$tags = $post->get_tags();
	
	//GET OTHER POSTS
	/*
	$numperpage = 3;
	$page_offset = LibraryFunctions::fetch_variable('page_offset', 0, 0, '');
	$page_sort = LibraryFunctions::fetch_variable('page_sort', 'post_id', 0, '');	
	$page_direction = LibraryFunctions::fetch_variable('page_direction', 'DESC', 0, '');
	$search_criteria = array('published'=>TRUE, 'deleted'=>FALSE);
	$posts = new MultiPost(
		$search_criteria,
		array($page_sort=>$page_direction),
		$numperpage,
		$page_offset);	
	$numrecords = $posts->count_all();	
	$posts->load();	
	*/

	$session = SessionControl::get_instance();

	if($_POST){
		
		$new_comment = Comment::add_comment($post->key, $session, $_POST);

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
					$body .= '<p>Link: <a href="'. $settings->get_setting('webDir') . $post->get_url().'">'.$settings->get_setting('webDir') . $post->get_url().'</a>';
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

?>