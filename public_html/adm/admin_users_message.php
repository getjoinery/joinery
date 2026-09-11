<?php
	
	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_users_message_logic.php'));

	$page_vars = process_logic(admin_users_message_logic(array_merge($_GET, $_POST)));

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
		$n = (int)$page_vars['numrecipients'];
		$email_link = '<a href="/admin/admin_email?eml_email_id='.$page_vars['email']->key.'">Email '.$page_vars['email']->key.'</a>';
		if($n > 0){
			echo '<p>Queued to '.$n.' recipient'.($n === 1 ? '' : 's').'. It goes out in the background; delivery per person is on '.$email_link.'.</p>';
		}
		else{
			echo '<p>Not queued: nobody is in this audience. '.$email_link.' was saved with no recipients.</p>';
		}
		if($page_vars['event']){
			echo '<p><a href="/plugins/event_manager/admin/admin_event?evt_event_id='.$page_vars['event']->key.'">Return to the event page</a></p>';
		}
		else if($page_vars['group']){
			echo '<p><a href="/admin/admin_group_members?grp_group_id='.$page_vars['group']->key.'">Return to the group members page</a></p>';
		}
		else{
			echo '<p><a href="/admin/admin_user?usr_user_id='.$page_vars['recipient']->key.'">Return to the user page</a></p>';
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

	$formwriter = $page->getFormWriter('form1');
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

	if($page_vars['waiting_list']){
		$formwriter->hiddeninput('waiting_list', '', ['value' => 1]);
	}

	if($page_vars['event']){
		$formwriter->hiddeninput('evt_event_id', '', ['value' => $page_vars['event']->key]);
	}
	else if($page_vars['group']){
		$formwriter->hiddeninput('grp_group_id', '', ['value' => $page_vars['group']->key]);
	}
	else{
		$formwriter->hiddeninput('usr_user_id', '', ['value' => $page_vars['recipient']->key]);
	}

	$formwriter->submitbutton('submit_button', 'Submit');
	$formwriter->end_form();

	$page->end_box();

	$page->admin_footer();
?>
