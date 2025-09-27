<?php

	PathHelper::requireOnce('includes/AdminPage.php');

	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/users_class.php');

if ($_POST){

	$session = SessionControl::get_instance();
	$session->check_permission(10);

	$usr_user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL, 1, 'You must provide a user to undelete here.');
	$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.');

	$usr_user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL, 0, '');

	if($confirm){
		$user = new User($usr_user_id, TRUE);

		$user->set('usr_is_admin_disabled', FALSE);
		$user->set('usr_is_disabled', FALSE);

		try {
			$user->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
			$user->save();
		} catch (TTClassException $e) {
			require_once(__DIR__ . '/../includes/Exceptions/AuthorizationException.php');
			throw new AuthorizationException($e->getMessage());
		}

	}

	//NOW REDIRECT
	$session = SessionControl::get_instance();
	$returnurl = $session->get_return();
	header("Location: $returnurl");
	exit();

}
else{
	$usr_user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL, 1, 'You must provide a user to edit.');

	$user = new User($usr_user_id, TRUE);

	$session = SessionControl::get_instance();
	$session->set_return("/admin/admin_users");

	$page = new AdminPage();

	$page->admin_header(
	array(
		'menu-id'=> 'users-list',
		'page_title' => 'Undelete',
		'readable_title' => 'Undelete',
		'breadcrumbs' => array(
			'Users'=>'/admin/admin_users',
			'User '.$user->display_name() => '/admin/admin_user?usr_user_id='.$user->key,
			'Undelete'=>'',
		),
		'session' => $session,
	)
	);

	$pageoptions['title'] = 'Undelete User '.$user->display_name();
	$page->begin_box($pageoptions);

	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	echo $formwriter->begin_form("form", "post", "/admin/admin_users_undelete");

	echo '<fieldset><h4>Confirm undelete</h4>';
		echo '<div class="fields full">';
		echo '<p>This will undelete this user ('.$user->display_name() . ').</p>';

	echo $formwriter->hiddeninput("confirm", 1);
	echo $formwriter->hiddeninput("usr_user_id", $usr_user_id);

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();

		echo '</div>';
	echo '</fieldset>';
	echo $formwriter->end_form();

	$page->admin_footer();

}
?>
