<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);

	$user = new User($_REQUEST['usr_user_id'], TRUE);

	if($_POST){

		try {
			$user->set('usr_password', User::GeneratePassword($_POST['usr_password']));
			$user->save();
		} catch (UserException $e) {
			require_once(__DIR__ . '/../includes/Exceptions/ValidationException.php');
			throw new ValidationException($e->getMessage());
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

	$formwriter = $page->getFormWriter('form1', 'v2');
	$formwriter->begin_form();
	$formwriter->hiddeninput('usr_user_id', ['value' => $user->key]);

	echo '<fieldset><h4>Change password for '.$user->display_name().'</h4>';
		echo '<div class="fields full">';

			if($_POST){
				echo 'password updated successfully';
			}
			else {
				$formwriter->textinput('usr_password', 'New Password', [
					'validation' => ['required' => true]
				]);

				$formwriter->submitbutton('submit_button', 'Submit');
			}
		echo '</div>';
	$formwriter->end_form();

	$page->end_box();

	$page->admin_footer();

?>