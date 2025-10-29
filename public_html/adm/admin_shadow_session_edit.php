<?php
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_shadow_session_edit_logic.php'));

// Process logic
$page_vars = process_logic(admin_shadow_session_edit_logic($_GET, $_POST));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(31);

if ($error_message) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error_message) . '</div>';
}

if ($user) {
	echo '<h2>Edit sessions for ' . htmlspecialchars($user->display_name()) . '</h2>';
} else {
	echo '<h2>Edit Session</h2>';
}

// Initialize FormWriter V2
$formwriter = $page->getFormWriter('form1', 'v2', [
	'model' => $product_detail,
	'edit_primary_key_value' => ($product_detail && $product_detail->key) ? $product_detail->key : null
]);

$formwriter->begin_form();

$formwriter->textinput('prd_num_used', 'Sessions used');
$formwriter->textbox('prd_notes', 'Notes (dates when used, etc)', [
	'htmlmode' => 'no'
]);

$formwriter->submitbutton('submit_button', 'Submit');
$formwriter->end_form();

$page->admin_footer();
?>
