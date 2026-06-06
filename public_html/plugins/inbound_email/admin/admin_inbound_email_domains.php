<?php
/**
 * Inbound Email - Domain editor
 *
 * The add/edit domain form, reached from the Accounts tree (which is the domain
 * list). DNS and host verification live on the Setup tab
 * (admin_inbound_email_setup), driven by InboundEmailSetupCheck.
 *
 * @version 2.1
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/admin_tabs.php'));
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

echo AdminPage::tab_menu(inbound_email_admin_tabs(), 'Accounts');

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

	// A new domain defaults to enabled (the common case).
	if (!$form_domain->key) {
		$form_domain->set('ied_is_enabled', true);
	}

	$page->begin_box(array('title' => $form_title));

	$formwriter = $page->getFormWriter('domain_form', [
		'model' => $form_domain,
		'edit_primary_key_value' => $form_domain->key,
	]);

	echo $formwriter->begin_form();

	// IMAP-source presets hide the domain-name field (the domain is implied); the
	// catch-all block only applies to a hosted (Custom) domain.
	$imap_hide = ['ied_domain', 'ied_catch_all_mode', 'ied_catch_all_address', 'ied_reject_unmatched'];
	$type_visibility = [
		'custom'         => ['show' => ['ied_domain', 'ied_catch_all_mode'], 'hide' => []],
		'imap_gmail'     => ['show' => [], 'hide' => $imap_hide],
		'imap_microsoft' => ['show' => [], 'hide' => $imap_hide],
		'imap_yahoo'     => ['show' => [], 'hide' => $imap_hide],
		'imap_icloud'    => ['show' => [], 'hide' => $imap_hide],
		'imap_fastmail'  => ['show' => [], 'hide' => $imap_hide],
		'imap_generic'   => ['show' => ['ied_domain'], 'hide' => ['ied_catch_all_mode', 'ied_catch_all_address', 'ied_reject_unmatched']],
	];

	$formwriter->dropinput('domain_type', 'Type', [
		'options' => [
			'custom'         => 'Custom domain (hosted — mail arrives by MX)',
			'imap_gmail'     => 'IMAP — Gmail',
			'imap_microsoft' => 'IMAP — Microsoft 365 / Outlook',
			'imap_yahoo'     => 'IMAP — Yahoo',
			'imap_icloud'    => 'IMAP — iCloud',
			'imap_fastmail'  => 'IMAP — Fastmail',
			'imap_generic'   => 'IMAP — Other host',
		],
		'value' => $domain_type,
		'visibility_rules' => $type_visibility,
	]);

	$formwriter->textinput('ied_domain', 'Domain Name', [
		'placeholder' => 'example.com',
	]);

	$formwriter->checkboxinput('ied_is_enabled', 'Enabled', []);

	$formwriter->dropinput('ied_catch_all_mode', 'Catch-All Mode', [
		'options' => [
			'forward' => 'Forward to an address',
			'store'   => 'Store locally (every unmatched recipient)',
		],
		'visibility_rules' => [
			'forward' => ['show' => ['ied_catch_all_address', 'ied_reject_unmatched'], 'hide' => []],
			'store'   => ['show' => [], 'hide' => ['ied_catch_all_address', 'ied_reject_unmatched']],
		],
	]);

	$formwriter->textinput('ied_catch_all_address', 'Catch-All Address', []);

	$formwriter->checkboxinput('ied_reject_unmatched', 'Reject Unmatched', []);

	$formwriter->submitbutton('btn_submit', $edit_domain ? 'Update Domain' : 'Add Domain');

	echo $formwriter->end_form();

	$page->end_box();
} // end show_form

// The active-domain list lives in the Accounts tree; this page is now purely the
// add/edit form (a bare visit is redirected to Accounts by the logic). Soft-
// deleted domain restore is handled from the Accounts tree (superadmin).

$page->admin_footer();
?>
