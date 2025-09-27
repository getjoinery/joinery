<?php

	PathHelper::requireOnce('/includes/AdminPage.php');

	PathHelper::requireOnce('/includes/LibraryFunctions.php');

	PathHelper::requireOnce('/data/users_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(9);
	$session->set_return("/admin/admin_users");

	$usr_user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL, 1, 'You must provide a user to edit.');

	$user = new User($usr_user_id, TRUE);

	$page = new AdminPage();

	$page->admin_header(
	array(
		'menu-id'=> 'users',
		'page_title' => 'Soft Delete',
		'readable_title' => 'Soft Delete',
		'breadcrumbs' => NULL,
		'session' => $session,
	)
	);

		echo '<h1>Delete User</h1>';

	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	echo $formwriter->begin_form("form", "post", "/profile/users_delete?disptype=return");

	echo '<fieldset><h4>Confirm Delete</h4>';
		echo '<div class="fields full">';
		echo '<p>WARNING:  This will delete this user ('.$user->display_name() . ').</p>';

	echo $formwriter->hiddeninput("confirm", 1);
	echo $formwriter->hiddeninput("usr_user_id", $usr_user_id);

	echo $formwriter->start_buttons();
	echo $formwriter->new_form_button('Submit');
	echo $formwriter->end_buttons();

		echo '</div>';
	echo '</fieldset>';
	echo $formwriter->end_form();

	$page->admin_footer();

?>