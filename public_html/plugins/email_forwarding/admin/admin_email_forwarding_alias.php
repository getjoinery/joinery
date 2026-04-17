<?php
/**
 * Email Forwarding - Create/Edit Alias
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/email_forwarding/logic/admin_email_forwarding_alias_logic.php'));

$page_vars = process_logic(admin_email_forwarding_alias_logic($_GET, $_POST));
extract($page_vars);

$is_edit = ($alias->key) ? true : false;

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'incoming',
		'breadcrumbs' => array(
			'Email Forwarding' => '/plugins/email_forwarding/admin/admin_email_forwarding',
			($is_edit ? 'Edit Alias' : 'New Alias') => '',
		),
		'session' => $session,
	)
);

// Tab navigation
echo '<ul class="nav nav-tabs mb-3">';
echo '<li class="nav-item"><a class="nav-link active" href="/plugins/email_forwarding/admin/admin_email_forwarding">Forwarding Aliases</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/email_forwarding/admin/admin_email_forwarding_domains">Domains</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/email_forwarding/admin/admin_email_forwarding_logs">Logs</a></li>';
echo '</ul>';

if (isset($error)) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

$pageoptions['title'] = $is_edit ? 'Edit Alias' : 'New Alias';
$page->begin_box($pageoptions);

// Build domain dropdown options
$domain_options = array();
$domains->load();
foreach ($domains as $d) {
	$domain_options[$d->get('efd_domain')] = $d->key;
}

$formwriter = $page->getFormWriter('form1', [
	'model' => $alias,
	'edit_primary_key_value' => $alias->key,
]);

echo $formwriter->begin_form();

$formwriter->dropinput('efa_efd_email_forwarding_domain_id', 'Domain', [
	'options' => $domain_options,
	'validation' => ['required' => true],
]);

$formwriter->textinput('efa_alias', 'Alias (local part)', [
	'validation' => ['required' => true],
	'help_text' => 'The part before the @ sign (e.g., "info" for info@example.com)',
]);

$formwriter->textbox('efa_destinations', 'Destination Addresses', [
	'rows' => 4,
	'htmlmode' => 'no',
	'validation' => ['required' => true],
	'help_text' => 'One email address per line, or comma-separated',
]);

$formwriter->textinput('efa_description', 'Description', [
	'help_text' => 'Optional note (e.g., "Main contact form inbox")',
]);

$formwriter->checkboxinput('efa_is_enabled', 'Enabled', []);

$formwriter->submitbutton('btn_submit', 'Save Alias');

echo $formwriter->end_form();

$page->end_box();
$page->admin_footer();
?>
