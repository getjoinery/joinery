<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('data/emails_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$email = new Email($_REQUEST['eml_email_id'], TRUE);

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
