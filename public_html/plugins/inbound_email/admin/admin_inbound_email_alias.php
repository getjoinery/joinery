<?php
/**
 * Inbound Email - Create/Edit Alias
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/logic/admin_inbound_email_alias_logic.php'));

$page_vars = process_logic(admin_inbound_email_alias_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$is_edit = ($alias->key) ? true : false;

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'incoming',
		'breadcrumbs' => array(
			'Inbound Email' => '/plugins/inbound_email/admin/admin_inbound_email',
			($is_edit ? 'Edit Alias' : 'New Alias') => '',
		),
		'session' => $session,
	)
);

// Tab navigation
echo '<ul class="nav nav-tabs mb-3">';
echo '<li class="nav-item"><a class="nav-link active" href="/plugins/inbound_email/admin/admin_inbound_email">Forwarding Aliases</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/inbound_email/admin/admin_inbound_email_domains">Domains</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/inbound_email/admin/admin_inbound_email_logs">Logs</a></li>';
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
	$domain_options[$d->get('ied_domain')] = $d->key;
}

$formwriter = $page->getFormWriter('form1', [
	'model' => $alias,
	'edit_primary_key_value' => $alias->key,
]);

echo $formwriter->begin_form();

$formwriter->dropinput('iea_ied_inbound_email_domain_id', 'Domain', [
	'options' => $domain_options,
	'validation' => ['required' => true],
]);

$formwriter->textinput('iea_alias', 'Alias (local part)', [
	'validation' => ['required' => true],
	'help_text' => 'The part before the @ sign (e.g., "info" for info@example.com)',
]);

$formwriter->textbox('iea_destinations', 'Destination Addresses', [
	'rows' => 4,
	'htmlmode' => 'no',
	'validation' => ['required' => true],
	'help_text' => 'One email address per line, or comma-separated',
]);

$formwriter->textinput('iea_description', 'Description', [
	'help_text' => 'Optional note (e.g., "Main contact form inbox")',
]);

$formwriter->checkboxinput('iea_is_enabled', 'Enabled', []);

$formwriter->submitbutton('btn_submit', 'Save Alias');

echo $formwriter->end_form();

$page->end_box();
$page->admin_footer();
?>
