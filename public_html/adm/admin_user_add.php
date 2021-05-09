<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');

require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

$settings = Globalvars::get_instance();
$session = SessionControl::get_instance();
$session->check_permission(8);

if ($_POST){
	

	if($_POST['usr_password']){
		$password = $_POST['usr_password'];
	}
	else{
		$password = NULL;
	}
	$user = User::CreateNewUser($_POST['usr_first_name'], $_POST['usr_last_name'], $_POST['usr_email'], $password, $_POST['send_activation_email']);
	
	$user->set('usr_nickname', trim($_POST['usr_nickname']));
	$user->set('usr_timezone', $_POST['usr_timezone']);
	$user->prepare();
	$user->save();

	if($_POST['mailing_list']){
		$status = $user->add_to_mailing_list();	
	}


	//NOW REDIRECT
	$session = SessionControl::get_instance();
	header("Location: /admin/admin_user?usr_user_id=$user->key");
	exit();

}
else{

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 1,
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

	$formwriter = new FormWriterMaster("form1");
	
	$validation_rules = array();
	$validation_rules['usr_email']['required']['value'] = 'true';
	echo $formwriter->set_validate($validation_rules);		
	
	echo $formwriter->begin_form("form1", "post", "/admin/admin_user_add");


	echo $formwriter->textinput("First Name", "usr_first_name", "ctrlHolder", 20, NULL, "",255, "");
	echo $formwriter->textinput("Last Name", "usr_last_name", "ctrlHolder", 20, NULL, "" , 255, "");
	$nickname_display = $settings->get_setting('nickname_display_as');
	if($nickname_display){
		echo $formwriter->textinput($nickname_display, "usr_nickname", "ctrlHolder", 20, @$form_fields->usr_nickname, "" , 255, "");
	}
	echo $formwriter->textinput("Email", "usr_email", "ctrlHolder", 20, NULL, "" , 255, "");
	echo $formwriter->textinput("Password ", "usr_password", "ctrlHolder", 20, NULL, "" , 255, "");
	$optionvals = Address::get_timezone_drop_array();
	echo $formwriter->dropinput("Time Zone", "usr_timezone", "ctrlHolder", $optionvals, NULL, '', FALSE);	
	
	echo $formwriter->checkboxinput("Add to the mailing list", "mailing_list", "ctrlHolder", "normal", NULL, "yes", '');	
	echo $formwriter->checkboxinput("Send an activation email", "send_activation_email", "ctrlHolder", "normal", "yes", "yes", '');

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();
	echo $formwriter->end_form();

	$page->end_box();

	$page->admin_footer();
}
?>
