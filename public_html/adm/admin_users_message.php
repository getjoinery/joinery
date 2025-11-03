<?php
	
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('data/emails_class.php'));
	require_once(PathHelper::getIncludePath('data/email_recipients_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/groups_class.php'));
	require_once(PathHelper::getIncludePath('data/messages_class.php'));
	require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
	require_once(PathHelper::getIncludePath('data/event_waiting_lists_class.php'));
	require_once(PathHelper::getIncludePath('includes/EmailTemplate.php'));
	require_once(PathHelper::getIncludePath('includes/EmailMessage.php'));
	require_once(PathHelper::getIncludePath('includes/EmailSender.php'));

	require_once(PathHelper::getIncludePath('adm/logic/admin_users_message_logic.php'));

	$page_vars = process_logic(admin_users_message_logic($_GET, $_POST));

	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();

	if($page_vars['show_success']){
		$page = new AdminPage();
		$page->admin_header(	
		array(
			'menu-id'=> 'users',
			'page_title' => 'Email Users',
			'readable_title' => 'Email Users',
			'breadcrumbs' => NULL,
			'session' => $session,
		)
		);
		$page->begin_box();
		if($page_vars['event']){
			echo '<p>Your email was successfully sent to '.$page_vars['numrecipients'].' recipients.  <a href="/admin/admin_event?evt_event_id='.$page_vars['event']->key.'">Return to the event registrants page</a>';
		}
		else if($page_vars['group']){
			echo '<p>Your email was successfully sent to '.$page_vars['numrecipients'].' recipients.  <a href="/admin/admin_groups">Return to the groups page</a>';
		}
		else{
			echo '<p>Your email was successfully sent to '.$page_vars['numrecipients'].' recipients.  <a href="/admin/admin_user?usr_user_id='.$page_vars['recipient']->key.'">Return to the user page</a>';
		}
		$page->end_box();
		$page->admin_footer();
		exit();
	}
	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'users',
		'page_title' => 'Email Users',
		'readable_title' => $page_vars['title'],
		'breadcrumbs' => NULL,
		'session' => $session,
	)
	);

	$page->begin_box();

	$formwriter = $page->getFormWriter('form1', 'v2');
	$formwriter->begin_form();

	echo '<p><strong>To:</strong> ' . htmlspecialchars($page_vars['to_field']) . '</p>';

	$placeholder = 'RE: ';
	if($page_vars['event']){
		$placeholder = $page_vars['event']->get('evt_name');
	}
	else if($page_vars['group']){
		$placeholder = $page_vars['group']->get('grp_name');
	}
	$formwriter->textinput('eml_subject', 'Subject', [
		'value' => $placeholder,
		'validation' => ['required' => true, 'minlength' => 10]
	]);

	$formwriter->textbox('eml_message', 'Message', [
		'htmlmode' => 'yes',
		'validation' => ['required' => true, 'minlength' => 10]
	]);

	if(isset($_REQUEST['waiting_list'])){
		$formwriter->hiddeninput('waiting_list', ['value' => 1]);
	}

	if($page_vars['event']){
		$formwriter->hiddeninput('evt_event_id', ['value' => $page_vars['event']->key]);
	}
	else if($page_vars['group']){
		$formwriter->hiddeninput('grp_group_id', ['value' => $page_vars['group']->key]);
	}
	else{
		$formwriter->hiddeninput('usr_user_id', ['value' => $page_vars['recipient']->key]);
	}

	$formwriter->submitbutton('submit_button', 'Submit');
	$formwriter->end_form();

	$page->end_box();

	$page->admin_footer();
?>
