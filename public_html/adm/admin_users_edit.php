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

$formwriter = $page->getFormWriter('form1', [
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

$optionvals = array(0=>'On', 1=>'Off');
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
	$optionvals = array(0=>'Regular User (0)', 5=>'Assistant (5)', 8=>'Admin (8)', 10 => 'Master Admin (10)');
	$formwriter->dropinput('usr_permission', 'Permission level', [
		'options' => $optionvals
	]);
}

$formwriter->textinput('usr_calendly_uri', 'Calendly User URI (for calendly integration)');

// IP restriction field - convert JSON array to newline-separated list for display
$allowed_ips_raw = $user->get('usr_allowed_ips');
$allowed_ips_display = '';
if (!empty($allowed_ips_raw)) {
	$allowed_ips_array = is_string($allowed_ips_raw) ? json_decode($allowed_ips_raw, true) : $allowed_ips_raw;
	if (is_array($allowed_ips_array)) {
		$allowed_ips_display = implode("\n", $allowed_ips_array);
	}
}
$formwriter->textarea('usr_allowed_ips', 'Allowed Login IPs', [
	'value' => $allowed_ips_display,
	'rows' => 4,
	'helptext' => 'One IP per line or comma-separated. Leave blank to allow login from any IP.'
]);

$formwriter->hiddeninput('usr_user_id', ['value' => $user->key]);

$formwriter->submitbutton('submit_button', 'Submit');
$formwriter->end_form();

$page->end_box();

$page->admin_footer();
?>
