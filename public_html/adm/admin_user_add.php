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

	$validation_rules = array();
	$validation_rules['usr_first_name']['required']['value'] = 'true';
	$validation_rules['usr_first_name']['minlength']['value'] = 1;
	$validation_rules['usr_first_name']['maxlength']['value'] = 32;
	$validation_rules['usr_first_name']['required']['message'] = "'Please enter your first name.'";
	$validation_rules['usr_last_name']['required']['value'] = 'true';
	$validation_rules['usr_last_name']['minlength']['value'] = 2;
	$validation_rules['usr_last_name']['maxlength']['value'] = 32;
	$validation_rules['usr_email']['required']['value'] = 'true';
	$validation_rules['usr_email']['email']['value'] = 'true';
	$validation_rules['usr_email']['maxlength']['value'] = 64;
	$validation_rules['usr_email']['remote']['value'] = "'/ajax/email_check_ajax'";
	$validation_rules['usr_email']['remote']['message'] = "'This email already exists.'";
	if($nickname_display){
		$validation_rules['usr_nickname']['maxlength']['value'] = 32;
	}
	echo $formwriter->set_validate($validation_rules);

	echo $formwriter->begin_form("form1", "post", "/admin/admin_user_add");

	echo $formwriter->textinput("First Name", "usr_first_name", "ctrlHolder", 20, NULL, "",32, "");
	echo $formwriter->textinput("Last Name", "usr_last_name", "ctrlHolder", 20, NULL, "" , 32, "");
	$nickname_display = $settings->get_setting('nickname_display_as');
	if($nickname_display){
		echo $formwriter->textinput($nickname_display, "usr_nickname", "ctrlHolder", 20, @$form_fields->usr_nickname, "" , 32, "");
	}
	echo $formwriter->textinput("Email", "usr_email", "ctrlHolder", 20, NULL, "" , 64, "");
	echo $formwriter->textinput("Password ", "password", "ctrlHolder", 20, NULL, "" , 255, "");
	$optionvals = Address::get_timezone_drop_array();
	$default_timezone = $settings->get_setting('default_timezone');
	echo $formwriter->dropinput("Time Zone", "usr_timezone", "ctrlHolder", $optionvals, $default_timezone, '', FALSE);

	echo $formwriter->checkboxinput("Add to the mailing list", "newsletter", "ctrlHolder", "normal", NULL, "yes", '');
	echo $formwriter->checkboxinput("Send an activation email", "send_activation_email", "ctrlHolder", "normal", "yes", "yes", '');

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	$page->end_box();

	$page->admin_footer();
?>
