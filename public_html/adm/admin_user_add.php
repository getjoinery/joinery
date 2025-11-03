<?php

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

require_once(PathHelper::getIncludePath('includes/Activation.php'));

require_once(PathHelper::getIncludePath('data/users_class.php'));

require_once(PathHelper::getIncludePath('adm/logic/admin_user_add_logic.php'));

$page_vars = process_logic(admin_user_add_logic($_GET, $_POST));

$settings = Globalvars::get_instance();
$session = SessionControl::get_instance();

	$page = new AdminPage();
	$page->admin_header(
	array(
		'menu-id'=> 'users-list',
		'page_title' => 'Add User',
		'readable_title' => 'Add User',
		'breadcrumbs' => array(
			'Users'=>'/admin/admin_users',
			'Add User'=>'',
		),
		'session' => $session,
	)
	);

	$pageoptions['title'] = 'Add User';
	$page->begin_box($pageoptions);

	$formwriter = $page->getFormWriter('form1');

	$formwriter->begin_form();

	$formwriter->textinput('usr_first_name', 'First Name', [
		'validation' => [
			'required' => true,
			'minlength' => 1,
			'maxlength' => 32,
			'custom' => ['rule' => 'required', 'message' => 'Please enter your first name.']
		]
	]);
	$formwriter->textinput('usr_last_name', 'Last Name', [
		'validation' => [
			'required' => true,
			'minlength' => 2,
			'maxlength' => 32
		]
	]);

	$nickname_display = $settings->get_setting('nickname_display_as');
	if($nickname_display){
		$formwriter->textinput('usr_nickname', $nickname_display, [
			'validation' => ['maxlength' => 32]
		]);
	}

	$formwriter->textinput('usr_email', 'Email', [
		'validation' => [
			'required' => true,
			'email' => true,
			'maxlength' => 64,
			'custom' => [
				'rule' => 'email_check',
				'message' => 'This email already exists.',
				'url' => '/ajax/email_check_ajax'
			]
		]
	]);

	$formwriter->textinput('password', 'Password', [
		'validation' => ['required' => true]
	]);

	$optionvals = Address::get_timezone_drop_array();
	$default_timezone = $settings->get_setting('default_timezone');
	$formwriter->dropinput('usr_timezone', 'Time Zone', [
		'options' => $optionvals,
		'value' => $default_timezone
	]);

	$formwriter->checkboxinput('newsletter', 'Add to the mailing list', [
		'checked' => false
	]);
	$formwriter->checkboxinput('send_activation_email', 'Send an activation email', [
		'checked' => true
	]);

	$formwriter->submitbutton('submit_button', 'Submit');
	$formwriter->end_form();

	$page->end_box();

	$page->admin_footer();
?>
