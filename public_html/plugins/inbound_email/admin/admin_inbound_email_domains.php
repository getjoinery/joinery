<?php
/**
 * Inbound Email - Domain Management
 *
 * Domain CRUD only. DNS and host verification live on the Setup tab
 * (admin_inbound_email_setup), driven by InboundEmailSetupCheck.
 *
 * @version 2.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_domain_class.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/logic/admin_inbound_email_domains_logic.php'));

$page_vars = process_logic(admin_inbound_email_domains_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(
	array(
		'menu-id' => 'incoming',
		'breadcrumbs' => array(
			'Inbound Email' => '/plugins/inbound_email/admin/admin_inbound_email',
			'Domains' => '',
		),
		'session' => $session,
	)
);

// Tab navigation
echo '<ul class="nav nav-tabs mb-3">';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/inbound_email/admin/admin_inbound_email_setup">Setup</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/inbound_email/admin/admin_inbound_email">Forwarding Aliases</a></li>';
echo '<li class="nav-item"><a class="nav-link active" href="/plugins/inbound_email/admin/admin_inbound_email_domains">Domains</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/inbound_email/admin/admin_inbound_email_logs">Logs</a></li>';
echo '<li class="nav-item"><a class="nav-link" href="/plugins/inbound_email/admin/admin_inbound_email_mailbox">Mailbox</a></li>';
echo '</ul>';

// Display session messages
$display_messages = $session->get_messages('/plugins\/inbound_email\/admin\//');
if (!empty($display_messages)) {
	foreach ($display_messages as $msg) {
		echo '<div class="alert alert-success">' . htmlspecialchars($msg->message) . '</div>';
	}
	$session->clear_clearable_messages();
}

if (isset($error)) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

// --- Add/Edit Domain Form (only shown when editing or adding) ---
$show_form = $edit_domain || (isset($_GET['action']) && $_GET['action'] === 'add');

if ($show_form) {
	$form_domain = $edit_domain ?: new InboundEmailDomain(NULL);
	$form_title = $edit_domain ? 'Edit Domain' : 'Add Domain';

	$page->begin_box(array('title' => $form_title));

	$formwriter = $page->getFormWriter('domain_form', [
		'model' => $form_domain,
		'edit_primary_key_value' => $form_domain->key,
	]);

	echo $formwriter->begin_form();

	$formwriter->textinput('ied_domain', 'Domain Name', [
		'validation' => ['required' => true],
		'help_text' => 'e.g., example.com',
	]);

	$formwriter->checkboxinput('ied_is_enabled', 'Enabled', []);

	$formwriter->dropinput('ied_catch_all_mode', 'Catch-All Mode', [
		'options' => [
			'forward' => 'Forward to an address',
			'store'   => 'Store locally (every unmatched recipient)',
		],
		'helptext' => 'Store mode supersedes "reject unmatched" — every unmatched recipient is captured.',
		'visibility_rules' => [
			'forward' => ['show' => ['ied_catch_all_address', 'ied_reject_unmatched'], 'hide' => []],
			'store'   => ['show' => [], 'hide' => ['ied_catch_all_address', 'ied_reject_unmatched']],
		],
	]);

	$formwriter->textinput('ied_catch_all_address', 'Catch-All Address', [
		'help_text' => 'Used only in Forward mode: receive all unmatched mail for this domain at this address.',
	]);

	$formwriter->checkboxinput('ied_reject_unmatched', 'Reject Unmatched', [
		'help_text' => 'Reject mail to non-existent aliases (Forward mode only, when no catch-all address is set). If unchecked, unmatched mail is silently discarded.',
	]);

	$formwriter->submitbutton('btn_submit', $edit_domain ? 'Update Domain' : 'Add Domain');

	echo $formwriter->end_form();

	$page->end_box();
} // end show_form

// --- Domain List ---
require_once(PathHelper::getIncludePath('plugins/inbound_email/data/inbound_email_alias_class.php'));

// Show deleted domains to superadmins
$show_deleted = ($session->get_permission() >= 10);
$domain_filters = $show_deleted ? [] : ['deleted' => false];
$domains = new MultiInboundEmailDomain($domain_filters, array('ied_delete_time' => 'ASC', 'ied_domain' => 'ASC'));
$domains->load();

$headers = array('Domain', 'Status', 'Catch-All', 'Aliases', 'Actions');
$altlinks = array('Add Domain' => '/plugins/inbound_email/admin/admin_inbound_email_domains?action=add');
$table_options = array('title' => 'Inbound Domains', 'altlinks' => $altlinks);
$page->tableheader($headers, $table_options);

foreach ($domains as $d) {
	$domain_name = $d->get('ied_domain');
	$is_deleted = !empty($d->get('ied_delete_time'));
	$alias_count = $is_deleted ? 0 : $d->get_alias_count();

	// Status column — combines enabled + deleted
	if ($is_deleted) {
		$status_display = '<span class="badge bg-dark">Deleted</span>';
	} else if ($d->get('ied_is_enabled')) {
		$status_display = '<span class="badge bg-success">Enabled</span>';
	} else {
		$status_display = '<span class="badge bg-secondary">Disabled</span>';
	}

	// Build row
	$rowvalues = [];
	$rowvalues[] = htmlspecialchars($domain_name);
	$rowvalues[] = $status_display;
	$catch_all_mode = $d->get('ied_catch_all_mode') ?: 'forward';
	if ($catch_all_mode === 'store') {
		$catch_all_display = '<span class="badge bg-info text-dark">Store locally</span>';
	} elseif ($d->get('ied_catch_all_address')) {
		$catch_all_display = htmlspecialchars($d->get('ied_catch_all_address'));
	} else {
		$catch_all_display = '-';
	}
	$rowvalues[] = $catch_all_display;
	$rowvalues[] = $is_deleted ? '-' : $alias_count;

	// Action buttons
	$actions = '';
	if ($is_deleted) {
		$actions .= PublicPageBase::action_button('Restore', '/plugins/inbound_email/admin/admin_inbound_email_domains', [
			'hidden' => ['action' => 'undelete', 'ied_inbound_email_domain_id' => $d->key],
			'confirm' => 'Restore this domain and its aliases?',
			'class' => 'btn btn-sm btn-outline-success',
		]);
		if ($session->get_permission() >= 10) {
			$actions .= ' ' . PublicPageBase::action_button('Permanent Delete', '/plugins/inbound_email/admin/admin_inbound_email_domains', [
				'hidden' => ['action' => 'permanent_delete', 'ied_inbound_email_domain_id' => $d->key],
				'confirm' => 'PERMANENTLY delete this domain and all its aliases? This cannot be undone.',
				'class' => 'btn btn-sm btn-outline-danger',
			]);
		}
	} else {
		$actions .= '<a href="/plugins/inbound_email/admin/admin_inbound_email_domains?ied_inbound_email_domain_id=' . $d->key . '" class="btn btn-sm btn-outline-primary">Edit</a> ';
		$actions .= PublicPageBase::action_button('Delete', '/plugins/inbound_email/admin/admin_inbound_email_domains', [
			'hidden' => ['action' => 'delete', 'ied_inbound_email_domain_id' => $d->key],
			'confirm' => 'Delete this domain and all its aliases?',
			'class' => 'btn btn-sm btn-outline-danger',
		]);
	}
	$rowvalues[] = $actions;

	$page->disprow($rowvalues);
}

$page->endtable();

$page->admin_footer();
?>
