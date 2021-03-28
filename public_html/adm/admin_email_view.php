<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/emails_class.php');

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
