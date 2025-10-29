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

$formwriter = $page->getFormWriter('form1', 'v2', [
	'model' => $user
]);

$formwriter->begin_form();

$formwriter->textinput('usr_first_name', 'First Name');
$formwriter->textinput('usr_last_name', 'Last Name');
$formwriter->textinput('usr_organization_name', 'Organization Name');

$nickname_display = $settings->get_setting('nickname_display_as');
if($nickname_display){
	$formwriter->textinput('usr_nickname', $nickname_display);
}

$optionvals = $mailing_lists->get_dropdown_array();
$formwriter->checkboxList('new_list_subscribes', 'Mailing list subscriptions', [
	'options' => $optionvals,
	'checked' => $user_subscribed_list
]);

$optionvals = array('On'=>0, 'Off'=>1);
$formwriter->dropinput('usr_password_recovery_disabled', 'Password recovery', [
	'options' => $optionvals
]);

require_once(PathHelper::getIncludePath('/includes/Activation.php'));
if(Activation::CheckForActiveCode($user->key, Activation::EMAIL_CHANGE)) {
	echo '<b>*Email change pending*</b><br />';
}
$formwriter->textinput('usr_email_new', 'Email', [
	'value' => $user->get('usr_email'),
	'validation' => ['required' => true]
]);

$optionvals = Address::get_timezone_drop_array();
$formwriter->dropinput('usr_timezone', 'Time Zone', [
	'options' => $optionvals,
	'validation' => ['required' => true]
]);

if($_SESSION['permission'] == 10){
	$optionvals = array('Regular User (0)'=>0, 'Assistant (5)'=>5, 'Admin (8)'=>8, 'Master Admin (10)' => 10);
	$formwriter->dropinput('usr_permission', 'Permission level', [
		'options' => $optionvals
	]);
}

$formwriter->textinput('usr_calendly_uri', 'Calendly User URI (for calendly integration)');

$formwriter->hiddeninput('usr_user_id', ['value' => $user->key]);

$formwriter->submitbutton('submit_button', 'Submit');
$formwriter->end_form();

$page->end_box();

$page->admin_footer();
?>
