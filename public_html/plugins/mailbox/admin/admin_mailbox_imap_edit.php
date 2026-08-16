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
 * @version 1.4
 * @changelog 1.3 - the day-window field renders the stored value whatever the
 *   current scope, so a save while it is hidden cannot reset it.
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_imap_edit_logic.php'));

$page_vars = process_logic(admin_mailbox_imap_edit_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$is_edit = (bool)$account->key;

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'incoming',
	'breadcrumbs' => array(
		'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox',
		'IMAP Accounts' => '/plugins/mailbox/admin/admin_mailbox_imap',
		($is_edit ? 'Edit Account' : 'New Account') => '',
	),
	'session' => $session,
));

echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Accounts');

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
			. '<a href="/plugins/mailbox/admin/admin_mailbox_alias">create one</a> first.</div>';
	}
	$formwriter->dropinput('iia_iea_inbound_email_alias_id', 'Bound mailbox', array(
		'options' => $alias_options,
		'validation' => array('required' => true),
		'empty_option' => '-- Select a mailbox --',
	));
}

// Every provider in the catalog signs in with the email address itself. Only a
// self-hosted server might want something else, so that is the only case that
// mentions a username at all.
$formwriter->textinput('iia_username', 'Email address', array(
	'validation' => array('required' => true),
	'helptext' => $combined
		? 'The address whose mail is collected (e.g. me@' . $domain->get('ied_domain') . ') — this becomes the mailbox.'
		: 'The address whose mail is collected. Some self-hosted servers sign in with a plain '
			. 'username instead; use that if yours does.',
));

// Import scope — changeable at any time. Changing how far back the feed reaches
// re-seeds it on the next fetch; the day window shows only for the middle choice.
$formwriter->dropinput('import_scope', 'Existing mail', array(
	'options' => array(
		InboundImapAccount::SCOPE_FUTURE => 'Import only future emails',
		InboundImapAccount::SCOPE_DAYS   => 'Import the last few days of email',
		InboundImapAccount::SCOPE_FULL   => 'Import full email history',
	),
	'value' => $account->importScope(),
	'helptext' => 'Reaching further back starts a backfill on the next fetch — mail is imported '
		. 'oldest-first over many fetches, and mail already imported is never duplicated.',
	'visibility_rules' => array(
		InboundImapAccount::SCOPE_FUTURE => array('hide' => array('iia_import_days')),
		InboundImapAccount::SCOPE_DAYS   => array('show' => array('iia_import_days')),
		InboundImapAccount::SCOPE_FULL   => array('hide' => array('iia_import_days')),
	),
));

$formwriter->numberinput('iia_import_days', 'Days of email to import', array(
	// Always the STORED window, never the default: the field is submitted even
	// while hidden (scope full/future), so rendering a default here would
	// silently overwrite a configured window on any unrelated save.
	'value' => min(max(intval($account->get('iia_import_days')), 0), InboundImapAccount::IMPORT_DAYS_MAX)
		?: InboundImapAccount::IMPORT_DAYS_DEFAULT,
	'min' => 1,
	'max' => InboundImapAccount::IMPORT_DAYS_MAX,
	'helptext' => 'How far back to reach, counting from when the feed starts reading. 30 days is '
		. 'usually enough to work with; the rest of the archive stays on the source untouched.',
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

// Folder names come from the server, with their real capitalisation and in the
// account's own language — a German Gmail's folders are not the English ones —
// so there is no honest list to offer before connecting, and no useful decision
// to ask for either: new mail arrives in the inbox, which is the default. The
// field appears once the folders are known.
//
// Hidden rather than absent: FormWriter posts what it renders, and a field that
// vanished would save the model's default over a configured folder.
if (!empty($folder_names)) {
	$formwriter->dropinput('iia_imap_folder', 'Collect mail from', array(
		'options' => $folder_names,
		'value'   => $account->get('iia_imap_folder') ?: 'INBOX',
		'helptext' => 'Inbox is where new mail arrives, and is what most people want. '
			. 'Choosing another folder collects that folder instead, not as well.',
	));
} else {
	$formwriter->hiddeninput('iia_imap_folder', '', array(
		'value' => $account->get('iia_imap_folder') ?: 'INBOX',
	));
}

$formwriter->numberinput('iia_poll_interval_seconds', 'Check for new mail every (seconds)', array(
	'helptext' => 'How often this mailbox is checked. 300 (5 minutes) suits almost everyone.',
));

$formwriter->checkboxinput('iia_is_enabled', 'Enabled', array());

// A dropdown holding one value is not a choice, and it was rendering as one
// whenever the answer was not yet known. Whether a server can keep two copies in
// step is a capability it advertises on connection, not something the provider
// name reliably predicts — and offering a mode the server turns out to refuse is
// worse than offering it a moment later. So the control appears only once there
// is something to choose between.
if ($sync_supported) {
	$formwriter->dropinput('iia_sync_mode', 'Keep in step with the original', array(
		'options' => $sync_options,
		'value' => $account->get('iia_sync_mode') ?: 'off',
		'visibility_rules' => $sync_visibility,
		'helptext' => 'Off: bring mail in once and leave the original alone. '
			. 'Read-only: keep this copy matching the original, following it as mail is read, filed or deleted there. '
			. 'Two-way: changes made here are sent back to the original as well.',
	));
	// Both of these only mean anything while sync is on, and the sync control's
	// own visibility rules reveal them. They are rendered here so those rules
	// have something to act on.
	$formwriter->checkboxinput('iia_sync_deletes', 'Also sync deletions', array(
		'helptext' => 'Deleting here moves the source message to Trash; a deletion in the source removes it here.',
	));
	$formwriter->checkboxinput('iia_show_compose', 'Enable compose / Sent sync', array(
		'helptext' => 'Show reply/forward in the reader and file sent copies into the source Sent folder.',
	));
} else {
	// Same reason as the folder field: hidden, not absent, so a save cannot
	// reset settings that are already stored. Without the sync control there are
	// no visibility rules to hide these two, and shown on their own they offer to
	// sync deletions for a mailbox that does not sync at all.
	$formwriter->hiddeninput('iia_sync_mode', '', array(
		'value' => $account->get('iia_sync_mode') ?: InboundImapAccount::SYNC_OFF,
	));
	$formwriter->hiddeninput('iia_sync_deletes', '', array(
		'value' => $account->get('iia_sync_deletes') ? '1' : '',
	));
	$formwriter->hiddeninput('iia_show_compose', '', array(
		'value' => $account->get('iia_show_compose') ? '1' : '',
	));
}

// Tracked folders (membership). The \All coverage view is tracked silently and is
// not in this list. Appears once folders are discovered (after a Test or poll).
if (!empty($folder_options)) {
	$formwriter->hiddeninput('_folders_present', '', array('value' => '1'));
	$formwriter->checkboxList('tracked_folders', 'Tracked folders', array(
		'options' => $folder_options,
		'checked' => $tracked_folder_ids,
		'helptext' => 'Folders synced when sync is on. Special-use folders (Inbox, Sent, Trash) are pre-selected.',
	));
}

// Combined mode folds the mailbox's access grants into this one editor.
// On a sealing domain this is a single choice, not a list: mail is encrypted to
// one person as it arrives, so a second grantee would hold a mailbox they cannot
// read. Offering checkboxes there invites a state the platform refuses.
if ($combined && !empty($user_options)) {
	if ($domain->seals_content()) {
		$formwriter->dropinput('users_with_access', 'Who reads this mailbox', array(
			'options' => $user_options,
			'value' => $granted_user_ids[0] ?? '',
			'empty_option' => '-- Select a person --',
			'helptext' => 'Mail here is encrypted to one person as it arrives, so one person reads it. '
				. 'To share a mailbox, put it on a Standard domain instead.',
		));
	} else {
		$formwriter->checkboxList('users_with_access', 'Users with access', array(
			'options' => $user_options,
			'checked' => $granted_user_ids ?? array(),
			'helptext' => 'Staff who can read this mailbox in the reader. Superadmins always see every mailbox.',
		));
	}
}

$formwriter->submitbutton('btn_submit', $combined ? 'Save Mailbox' : 'Save Account');

echo $formwriter->end_form();

$page->end_box();
$page->admin_footer();
?>
