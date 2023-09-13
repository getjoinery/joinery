<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$usr_user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL);
	$act_code = LibraryFunctions::fetch_variable('act_code', NULL);
	if ($act_code) {
		Activation::ActivateUser($act_code);
	} 
	else if ($usr_user_id) {
		$user = new User($usr_user_id, TRUE);
		
		if($user->get('usr_email_is_verified')){
			throw new SystemDisplayableError('This user is already verified.');
		}
		Activation::email_activate_send($user);


		$page = new AdminPage();
		$page->admin_header(	
		array(
			'menu-id'=> 'emails-list',
			'page_title' => 'User',
			'readable_title' => 'User',
			'breadcrumbs' => array(
				'Users'=>'/admin/admin_users', 
				'Resend Activation Email' => '',
			),
			'session' => $session,
		)
		);
		$pageoptions['title'] = 'Resend Activation Email to '.$user->display_name();
		$page->begin_box($pageoptions);
		
		echo '<p>Activation email sent.</p>';
		
		
		$page->end_box();

		$page->admin_footer();
	}

?>
