<?php

	require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('/includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('/data/users_class.php'));
	require_once(PathHelper::getIncludePath('/data/messages_class.php'));
	require_once(PathHelper::getIncludePath('/includes/MessageContextRegistry.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$message = new Message($_GET['msg_message_id'], TRUE);
	$sender = new User($message->get('msg_usr_user_id_sender'), TRUE);
	if($message->get('msg_usr_user_id_recipient')){
		$recipient = new User($message->get('msg_usr_user_id_recipient'), TRUE);
	}
	$context = null;
	if($message->get('msg_context_type') && $message->get('msg_context_id')){
		$context = MessageContextRegistry::resolve($message->get('msg_context_type'), (int)$message->get('msg_context_id'));
	}

	if($_REQUEST['action'] == 'delete'){
		$message->assert_can_write($session);
		$message->soft_delete();

		header("Location: /admin/admin_posts");
		exit();
	}
	else if($_REQUEST['action'] == 'undelete'){
		$message->assert_can_write($session);
		$message->soft_delete();

		header("Location: /admin/admin_posts");
		exit();
	}

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'emails',
		'page_title' => 'Messages',
		'readable_title' => 'Messages',
		'breadcrumbs' => NULL,
		'session' => $session,
	)
	);

	$options['title'] = 'Message';

	if(!$message->get('msg_delete_time') && $_SESSION['permission'] >= 8) {
		$options['altlinks']['Soft Delete'] = array('post' => '/admin/admin_message', 'hidden' => array('action' => 'delete', 'msg_message_id' => $message->key));
	}
	$page->begin_box($options);

	/*
 	echo '<div id="actionmenu"><div id="actiontitle">Page Actions</div><ul>'
	echo '<li><a class="sortlink" href="/admin/admin_message_edit?msg_message_id='.$message->key.'">[Edit Message]</a></li>';
	echo '</ul></div>';
	*/

	$settings = Globalvars::get_instance();
	if(!$settings->get_setting('mailgun_domain') || !$settings->get_setting('mailgun_api_key')){
		echo '<div style="border: 3px solid red; padding: 10px; margin: 10px;">Mailgun credentials are not in the db or settings.</div>';
	}

	echo '<strong>From:</strong> ('.$sender->key.') <a href="/admin/admin_user?usr_user_id='.$sender->key.'">'.$sender->display_name() .'</a><br />';
	if($message->get('msg_usr_user_id_recipient')){
		echo '<strong>To:</strong> ('.$recipient->key.') <a href="/admin/admin_user?usr_user_id='.$recipient->key.'">'.$recipient->display_name() .'</a><br />';
	}
	if($message->get('msg_context_type') && $message->get('msg_context_id')){
		if($context && !empty($context['url'])){
			echo '<strong>Context:</strong> <a href="'.htmlspecialchars($context['url']).'">'.htmlspecialchars($context['label']).'</a><br />';
		} elseif($context){
			echo '<strong>Context:</strong> '.htmlspecialchars($context['label']).'<br />';
		} else {
			echo '<strong>Context:</strong> '.htmlspecialchars($message->get('msg_context_type').' #'.$message->get('msg_context_id')).'<br />';
		}
	}
	echo '<strong>Sent:</strong> '.$message->get_local('msg_sent_time') .'<br />';
	if($message->get('msg_cnv_conversation_id')){
		echo '<strong>Conversation:</strong> <a href="/admin/admin_conversation?cnv_conversation_id='.$message->get('msg_cnv_conversation_id').'">#'.$message->get('msg_cnv_conversation_id').'</a><br />';
	}
	echo '<strong>Message:</strong><br /> '.$message->get('msg_body').'<br />';
	if($message->get('msg_delete_time')){
		echo 'Status: Deleted at '.$message->get_local('msg_delete_time').'<br />';
	}
	$page->end_box();

	$page->admin_footer();
?>

