<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_email_view_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$page_vars = process_logic(admin_email_view_logic($_GET, $_POST));

$session = $page_vars['session'];
$email = $page_vars['email'];

$page = new AdminPage();
$page->admin_header(31);

echo '<h2>Email</h2>';
echo '<a href="/admin/admin_user?usr_user_id='.$email->get('eml_usr_user_id').'">back to user</a><br /><br />';

if($email->get('eml_delete_time')){
	echo 'Status: Deleted<br>';
}
echo '<p>Sent: '.LibraryFunctions::convert_time( $email->get('eml_subject'), "UTC", $session->get_timezone(), '%m/%d/%Y').'</p>';
echo '<p>Subject: '.$email->get('eml_subject').'</p>';

echo '<p>'.$email->get('eml_message_html').'</p>';

$page->admin_footer();

?>
