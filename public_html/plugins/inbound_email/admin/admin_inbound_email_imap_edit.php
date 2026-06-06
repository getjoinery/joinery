<?php
/**
 * Inbound Email - IMAP Account editor
 *
 * Add/edit a polled IMAP account. Picking a provider drives which fields show
 * (host/port/encryption only for the generic provider; the password field only
 * for password-auth providers — OAuth providers use the "Connect" button on the
 * list after saving). FormWriter only.
 *
 * When no mailboxes (store-mode aliases) exist yet, the editor shows a callout
 * linking to the alias editor so the bound-mailbox requirement isn't a dead-end.
 *
 * @version 1.2
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/inbound_email/logic/admin_inbound_email_imap_edit_logic.php'));

$page_vars = process_logic(admin_inbound_email_imap_edit_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$is_edit = (bool)$account->key;

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'incoming',
	'breadcrumbs' => array(
		'Inbound Email' => '/plugins/inbound_email/admin/admin_inbound_email',
		'IMAP Accounts' => '/plugins/inbound_email/admin/admin_inbound_email_imap',
		($is_edit ? 'Edit Account' : 'New Account') => '',
	),
	'session' => $session,
));

echo AdminPage::tab_menu(inbound_email_admin_tabs(), 'Accounts');

if (isset($error)) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error) . '</div>';
}

if ($combined) {
	// An existing mailbox (bound_alias_id) is being edited even when its feed is
	// being created for the first time.
	$box_title = (($is_edit || $bound_alias_id > 0) ? 'Edit mailbox — ' : 'New mailbox — ') . $domain->get('ied_domain');
} else {
	$box_title = $is_edit ? 'Edit IMAP feed' : 'New IMAP feed';
}
$page->begin_box(array('title' => $box_title));

$formwriter = $page->getFormWriter('form1', array(
	'model' => $account,
	'edit_primary_key_value' => $account->key,
));

echo $formwriter->begin_form();

$formwriter->hiddeninput('_submitted', '', array('value' => '1'));

$formwriter->textinput('iia_label', 'Label', array(
	'helptext' => 'A name for this account, e.g. "Support Gmail".',
));

$formwriter->dropinput('iia_provider_key', 'Provider', array(
	'options' => $provider_options,
	'validation' => array('required' => true),
	'helptext' => 'Gmail and Microsoft connect with OAuth (use "Connect" after saving). '
		. 'Yahoo, iCloud, and Fastmail use an app password. Generic IMAP lets you enter any host.',
	'visibility_rules' => $visibility,
));

if ($combined) {
	// Mailbox is created from the username under the IMAP-source domain — no
	// bound-mailbox picker. domain_id (and alias_id when editing) ride the POST.
	$formwriter->hiddeninput('domain_id', '', array('value' => $domain->key));
	$formwriter->hiddeninput('alias_id', '', array('value' => $bound_alias_id));
} else {
	if (empty($alias_options)) {
		echo '<div class="alert alert-warning">No mailboxes exist yet &mdash; '
			. '<a href="/plugins/inbound_email/admin/admin_inbound_email_alias">create one</a> first.</div>';
	}
	$formwriter->dropinput('iia_iea_inbound_email_alias_id', 'Bound mailbox', array(
		'options' => $alias_options,
		'validation' => array('required' => true),
		'empty_option' => '-- Select a mailbox --',
	));
}

$formwriter->textinput('iia_username', 'Mailbox login (username)', array(
	'validation' => array('required' => true),
	'helptext' => $combined
		? 'The full email address to poll (e.g. me@' . $domain->get('ied_domain') . ') — this becomes the mailbox.'
		: 'The full email address / username used to log in to the IMAP server.',
));

// Import scope — changeable at any time. Switching to full history backfills the
// existing mailbox on the next fetch.
$formwriter->dropinput('import_history', 'Existing mail', array(
	'options' => array(
		'future' => 'Import only future emails',
		'full'   => 'Import full email history',
	),
	'value' => $account->get('iia_import_history') ? 'full' : 'future',
));

// Password — shown only for password-auth providers (visibility rules above).
$formwriter->passwordinput('imap_password', 'App / mailbox password', array(
	'helptext' => 'For Yahoo / iCloud / Fastmail / generic IMAP, use an app-specific password. '
		. 'Stored encrypted. Leave blank when editing to keep the existing password.',
	'autocomplete' => 'new-password',
));

// Generic-only connection details (visibility rules above).
$formwriter->textinput('iia_imap_host', 'IMAP host', array(
	'helptext' => 'e.g. imap.example.com',
));
$formwriter->numberinput('iia_imap_port', 'IMAP port', array(
	'helptext' => 'Usually 993 for SSL.',
));
$formwriter->dropinput('iia_imap_encryption', 'Encryption', array(
	'options' => array('ssl' => 'SSL/TLS (993)', 'tls' => 'STARTTLS (143)', 'none' => 'None'),
));

$formwriter->textinput('iia_imap_folder', 'Folder', array(
	'helptext' => 'The folder to poll. Default INBOX.',
));

$formwriter->numberinput('iia_poll_interval_seconds', 'Fetch interval (seconds)', array(
	'helptext' => 'How often this mailbox is fetched. Default 300 (5 minutes).',
));

$formwriter->checkboxinput('iia_is_enabled', 'Enabled', array());

// Combined mode folds the mailbox's access grants into this one editor.
if ($combined && !empty($user_options)) {
	$formwriter->checkboxList('users_with_access', 'Users with access', array(
		'options' => $user_options,
		'checked' => $granted_user_ids ?? array(),
		'helptext' => 'Staff who can read this mailbox in the reader. Superadmins always see every mailbox.',
	));
}

$formwriter->submitbutton('btn_submit', $combined ? 'Save Mailbox' : 'Save Account');

echo $formwriter->end_form();

$page->end_box();
$page->admin_footer();
?>
