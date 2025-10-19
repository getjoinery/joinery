<?php

require_once(PathHelper::getIncludePath('/includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_users_edit_logic.php'));

$page_vars = process_logic(admin_users_edit_logic($_GET, $_POST));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'users-list',
	'page_title' => 'User Edit',
	'readable_title' => 'User Edit',
	'breadcrumbs' => array(
		'Users'=>'/admin/admin_users',
		'User '.$user->display_name() => '/admin/admin_user?usr_user_id='.$user->key,
		'User Edit'=>'',
	),
	'session' => $session,
)
);

$pageoptions['title'] = "User Edit";
$page->begin_box($pageoptions);

$formwriter = $page->getFormWriter('form1');

$validation_rules = array();
$validation_rules['usr_email_new']['required']['value'] = 'true';
$validation_rules['usr_timezone']['required']['value'] = 'true';
echo $formwriter->set_validate($validation_rules);

echo $formwriter->begin_form("form1", "post", "/admin/admin_users_edit");

/*
$optionvals = array(""=>NULL, "Male"=>0, "Female"=>1);
echo $formwriter->dropinput("Gender (optional)", "usr_gender", "ctrlHolder", $optionvals, $user->get('usr_gender'), '', FALSE);

$optionvals = array('Unknown'=>NULL, 'True'=>FALSE, 'False'=>TRUE);
echo $formwriter->dropinput3boolean("Name is Bad", "usr_name_is_bad", "ctrlHolder",$user->get('usr_name_is_bad'), '');
*/

echo $formwriter->textinput("First Name", "usr_first_name", "ctrlHolder", 20, $user->get('usr_first_name') , "",255, "");
echo $formwriter->textinput("Last Name", "usr_last_name", "ctrlHolder", 20, $user->get('usr_last_name'), "" , 255, "");

echo $formwriter->textinput("Organization Name", "usr_organization_name", "ctrlHolder", 20, $user->get('usr_organization_name'), "" , 255, "");

$nickname_display = $settings->get_setting('nickname_display_as');
if($nickname_display){
	echo $formwriter->textinput($nickname_display, "usr_nickname", "ctrlHolder", 20, $user->get('usr_nickname'), "" , 255, "");
}

$optionvals = $mailing_lists->get_dropdown_array();
$checkedvals = $user_subscribed_list;
$readonlyvals = array(); //DEFAULT
$disabledvals = array();

echo $formwriter->checkboxList("Mailing list subscriptions:", 'new_list_subscribes', "ctrlHolder", $optionvals, $checkedvals, $disabledvals, $readonlyvals);

$optionvals = array("On"=>0, "Off"=>1);
echo $formwriter->dropinput("Password recovery", "usr_password_recovery_disabled", "ctrlHolder", $optionvals, $user->get('usr_password_recovery_disabled'), '', FALSE);

require_once(PathHelper::getIncludePath('/includes/Activation.php'));
if(Activation::CheckForActiveCode($user->key, Activation::EMAIL_CHANGE)) {
	echo '<b>*Email change pending*</b><br />';
}
echo $formwriter->textinput("Email", "usr_email_new", "ctrlHolder", 20, $user->get('usr_email'), "" , 255, "");

$optionvals = Address::get_timezone_drop_array();
echo $formwriter->dropinput("Time Zone", "usr_timezone", "ctrlHolder", $optionvals, $user->get('usr_timezone'), '', FALSE);

if($_SESSION['permission'] == 10){
	$optionvals = array('Regular User (0)'=>0, 'Assistant (5)'=>5, 'Admin (8)'=>8, 'Master Admin (10)' => 10);
	echo $formwriter->dropinput("Permission level", "usr_permission", "ctrlHolder", $optionvals, $user->get('usr_permission'), FALSE);
}

echo $formwriter->textinput("Calendly User URI (for calendly integration)", "usr_calendly_uri", "ctrlHolder", 20, $user->get('usr_calendly_uri'), "" , 255, "");

echo $formwriter->hiddeninput("usr_user_id", $user->key);

echo $formwriter->start_buttons();
echo $formwriter->new_form_button('Submit');
echo $formwriter->end_buttons();
echo $formwriter->end_form();

$page->end_box();

$page->admin_footer();
?>
