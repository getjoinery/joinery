<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
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
	
	
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 2,
		'page_title' => 'Messages',
		'readable_title' => 'Messages',
		'breadcrumbs' => NULL,
		'session' => $session,
	)
	);
	
	$options['title'] = 'Message';
	$page->begin_box($options);

	$formwriter = new FormWriterMaster("form1");


	/*
 	echo '<div id="actionmenu"><div id="actiontitle">Page Actions</div><ul>'
	echo '<li><a class="sortlink" href="/admin/admin_message_edit?msg_message_id='.$message->key.'">[Edit Message]</a></li>';
	echo '</ul></div>';	
	*/
	

	
	echo '<strong>From:</strong> ('.$sender->key.') <a href="/admin/admin_user?usr_user_id='.$sender->key.'">'.$sender->display_name() .'</a><br />';	
	if($message->get('msg_usr_user_id_recipient')){
		echo '<strong>To:</strong> ('.$recipient->key.') <a href="/admin/admin_user?usr_user_id='.$recipient->key.'">'.$recipient->display_name() .'</a><br />';
	}	
	if($event){
		echo '<strong>Event:</strong> ('.$event->key.') <a href="/admin/admin_event?evt_event_id='.$event->key.'">'.$event->get('evt_name') .'</a><br />';		
	}
	echo '<strong>Sent:</strong> '.LibraryFunctions::convert_time($message->get('msg_sent_time'), 'UTC', $session->get_timezone()) .'<br />';
	echo '<strong>Message:</strong><br /> '.$message->get('msg_body').'<br />';	

	$page->end_box();
	
	$page->admin_footer();
?>


