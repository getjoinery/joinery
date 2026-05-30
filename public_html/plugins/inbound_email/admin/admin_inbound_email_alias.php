<?php
/**
 * Inbound Email - Create/Edit Alias
 *
 * @version 1.5
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/admin_tabs.php'));
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

echo AdminPage::tab_menu(inbound_email_admin_tabs(), 'Forwarding Aliases');

if (isset($error)) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

$pageoptions['title'] = $is_edit ? 'Edit Alias' : 'New Alias';
$page->begin_box($pageoptions);

// Build domain dropdown options
$domain_options = array();
$domains->load();
foreach ($domains as $d) {
	$domain_options[$d->key] = $d->get('ied_domain');
}
$example_domain = !empty($domain_options) ? reset($domain_options) : 'example.com';

$formwriter = $page->getFormWriter('form1', [
	'model' => $alias,
	'edit_primary_key_value' => $alias->key,
]);

echo $formwriter->begin_form();

$formwriter->dropinput('iea_ied_inbound_email_domain_id', 'Domain', [
	'options' => $domain_options,
	'validation' => ['required' => true],
]);

$formwriter->textinput('iea_alias', 'Mailbox', [
	'validation' => ['required' => true],
	'placeholder' => 'info',
	'helptext' => 'The name before the @ — for example, "info" creates the mailbox info@' . $example_domain . '.',
]);

$formwriter->dropinput('iea_delivery_mode', 'Delivery Mode', [
	'options' => [
		'forward'           => 'Forward to destination address(es)',
		'store'             => 'Store locally (no forwarding)',
		'forward_and_store' => 'Forward and store a copy',
	],
	'helptext' => 'Forward mode requires at least one destination. Store mode persists messages '
		. 'to the local mailbox (visible on the Mailbox tab) and does not forward.',
	'visibility_rules' => [
		'forward'           => ['show' => ['iea_destinations'], 'hide' => []],
		'store'             => ['show' => [], 'hide' => ['iea_destinations']],
		'forward_and_store' => ['show' => ['iea_destinations'], 'hide' => []],
	],
]);

$formwriter->textbox('iea_destinations', 'Destination Addresses', [
	'rows' => 4,
	'htmlmode' => 'no',
	'helptext' => 'Required for Forward / Forward and store modes. Enter one full email address per line, '
		. 'or separate them with commas — for example, you@gmail.com. Every address is validated when you save. '
		. 'Leave empty for "Store locally" mode.',
]);

$formwriter->textinput('iea_description', 'Notes', [
	'helptext' => 'Optional. A private label so you remember what this mailbox is for '
		. '(e.g. "Main contact form inbox"). Never shown to anyone sending mail.',
]);

$formwriter->checkboxinput('iea_is_enabled', 'Enabled', []);

// Mailbox access grants. Read/star state on a shared mailbox is shared among
// everyone listed here (team-inbox semantics). Empty = nobody is granted; a
// permission-10 superadmin still sees every mailbox without a grant.
if (!empty($user_options)) {
	$formwriter->checkboxList('users_with_access', 'Users with access', [
		'options' => $user_options,
		'checked' => $granted_user_ids ?? [],
		'helptext' => 'Staff who can read this mailbox in the Mailbox reader. Read and star '
			. 'state is shared among everyone granted access. Superadmins always see every mailbox.',
	]);
} else {
	echo '<div class="alert alert-info">No staff users are available to grant mailbox access to yet.</div>';
}

$formwriter->submitbutton('btn_submit', 'Save Alias');

echo $formwriter->end_form();

$page->end_box();
$page->admin_footer();
?>
