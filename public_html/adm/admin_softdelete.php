<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_softdelete_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$page_vars = process_logic(admin_softdelete_logic($_GET, $_POST));

$session = $page_vars['session'];
$user = $page_vars['user'];
$usr_user_id = $page_vars['usr_user_id'];

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

$formwriter = $page->getFormWriter('form1');
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
