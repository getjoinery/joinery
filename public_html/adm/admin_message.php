<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/messages_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return(); 

	$message = new Message($_GET['msg_message_id'], TRUE);
	$sender = new User($message->get('msg_usr_user_id_sender'), TRUE);
	if($message->get('msg_usr_user_id_recipient')){
		$recipient = new User($message->get('msg_usr_user_id_recipient'), TRUE);
	}
	if($message->get('msg_evt_event_id')){
		$event = new Event($message->get('msg_evt_event_id'), TRUE);
	}


	if($_REQUEST['action'] == 'delete'){
		$message->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		$message->soft_delete();

		header("Location: /admin/admin_posts");
		exit();				
	}
	else if($_REQUEST['action'] == 'undelete'){
		$message->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
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
		$options['altlinks']['Soft Delete'] = '/admin/admin_message?action=delete&msg_message_id='.$message->key;
	}
	$page->begin_box($options);

	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');


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
	if($event){
		echo '<strong>Event:</strong> ('.$event->key.') <a href="/admin/admin_event?evt_event_id='.$event->key.'">'.$event->get('evt_name') .'</a><br />';		
	}
	echo '<strong>Sent:</strong> '.LibraryFunctions::convert_time($message->get('msg_sent_time'), 'UTC', $session->get_timezone()) .'<br />';
	echo '<strong>Message:</strong><br /> '.$message->get('msg_body').'<br />';	
	if($message->get('msg_delete_time')){
		echo 'Status: Deleted at '.LibraryFunctions::convert_time($message->get('msg_delete_time'), 'UTC', $session->get_timezone()).'<br />';
	}
	$page->end_box();
	
	$page->admin_footer();
?>


