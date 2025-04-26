<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/DbConnector.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	
	$user = new User($_REQUEST['usr_user_id'], TRUE);
	
	if($_POST){
	
		try {
			$user->set('usr_password', User::GeneratePassword($_POST['usr_password']));
			$user->save();
		} catch (UserException $e) {
			$errorhandler = new ErrorHandler();
			$errorhandler->handle_general_error($e->getMessage());
		}
		
		
	
		
	}

	
	$page = new AdminPage();

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$page->admin_header(	
	array(
		'menu-id'=> 'users-list',
		'page_title' => 'Change Password',
		'readable_title' => 'Change Password',
		'breadcrumbs' => array(
			'Users'=>'/admin/admin_users', 
			'User '.$user->display_name() => '/admin/admin_user?usr_user_id='.$user->key,
			'Change Password'=>'',
		),
		'session' => $session,
	)
	);
	
	$pageoptions['title'] = 'Change Password';
	$page->begin_box($pageoptions);

	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	echo $formwriter->begin_form("form1", "post", "/admin/admin_users_password_edit");
	echo $formwriter->hiddeninput("usr_user_id", $user->key);


	echo '<fieldset><h4>Change password for '.$user->display_name().'</h4>';
		echo '<div class="fields full">';

			if($_POST){
				echo 'password updated successfully';
			}
			else {
				echo $formwriter->textinput("New Password", "usr_password", NULL, 20, NULL , NULL, 255, "");

				echo $formwriter->start_buttons();
				echo $formwriter->new_form_button('Submit');
				echo $formwriter->end_buttons();
			}
		echo '</div>';
	echo $formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

?>