<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_form_error_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$page_vars = process_logic(admin_form_error_logic($_GET, $_POST));

$session = $page_vars['session'];
$form_error = $page_vars['form_error'];
$user = $page_vars['user'];

$page = new AdminPage();

$page->admin_header(
array(
	'menu-id'=> null,
	'page_title' => 'Error',
	'readable_title' => 'Error',
	'breadcrumbs' => NULL,
	'session' => $session,
)
);

if ($form_error->get('lfe_page')) {
	echo '<p>Page w/Error: ' . $form_error->get('lfe_page') . '</p>';
}

echo '<pre>'. $form_error->display_time($session) . "\n\n" . 'Errors:<br>-------------------<br>' . htmlspecialchars($form_error->get('lfe_error')).'<br><br>';
echo 'All formfields:<br>-------------------<br><br>' . htmlspecialchars($form_error->get('lfe_form')).'</pre>';

$page->admin_footer();

?>
