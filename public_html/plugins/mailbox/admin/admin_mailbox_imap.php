<?php
/**
 * Inbound Email - IMAP Accounts (list)
 *
 * Lists the polled IMAP mailboxes: label, provider, bound mailbox, enabled, last
 * poll, last status. Add/edit/delete, plus per-row "Test", "Poll now", and (for
 * OAuth accounts) "Connect". Superadmin-only — these rows hold full-mailbox
 * credentials.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_imap_logic.php'));

$page_vars = process_logic(admin_mailbox_imap_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'incoming',
	'breadcrumbs' => array(
		'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox',
		'IMAP Accounts' => '',
	),
	'session' => $session,
));

echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Accounts');

// Session messages (action results)
$display_messages = $session->get_messages('/plugins\/inbound_email\/admin\//');
if (!empty($display_messages)) {
	foreach ($display_messages as $msg) {
		echo '<div class="alert alert-info">' . htmlspecialchars($msg->message) . '</div>';
	}
	$session->clear_clearable_messages();
}

echo '<p class="text-muted">Poll an existing mailbox (Gmail, Microsoft 365, Yahoo, iCloud, Fastmail, or any '
	. 'IMAP host) and ingest its mail into a bound local mailbox. Gmail and Microsoft connect with OAuth; '
	. 'everyone else uses an app password. Attachment bytes are never stored — they are fetched on demand.</p>';

$base = '/plugins/mailbox/admin/admin_mailbox_imap';
$edit_base = '/plugins/mailbox/admin/admin_mailbox_imap_edit';

$headers = array('Label', 'Provider', 'Mailbox', 'Enabled', 'Last Poll', 'Status', 'Actions');
$altlinks = array('Add IMAP Account' => $edit_base);
$page->tableheader($headers, array('title' => 'IMAP Accounts', 'altlinks' => $altlinks));

foreach ($accounts as $acct) {
	$provider_key = $acct->get('iia_provider_key');
	$provider_label = $presets[$provider_key]['label'] ?? $provider_key;
	$aid = intval($acct->get('iia_iea_inbound_email_alias_id'));
	$mailbox = $alias_labels[$aid] ?? '(unbound)';

	$enabled = $acct->get('iia_is_enabled')
		? '<span class="badge bg-success">Enabled</span>'
		: '<span class="badge bg-secondary">Disabled</span>';

	$last_poll = $acct->get('iia_last_poll_time')
		? htmlspecialchars(LibraryFunctions::convert_time($acct->get('iia_last_poll_time'), 'UTC', $session->get_timezone(), 'M j, g:i A T'))
		: '<span class="text-muted">never</span>';

	$status = $acct->get('iia_last_status') ? htmlspecialchars($acct->get('iia_last_status')) : '<span class="text-muted">-</span>';

	// OAuth connection state
	$needs_connect = $acct->isOAuth() && !$acct->hasOAuthToken();
	if ($acct->isOAuth()) {
		$conn = $acct->hasOAuthToken()
			? ' <span class="badge bg-success">Connected</span>'
			: ' <span class="badge bg-warning text-dark">Not connected</span>';
		$provider_label .= $conn;
	}

	$actions = '<a href="' . $edit_base . '?iia_inbound_imap_account_id=' . $acct->key . '" class="btn btn-sm btn-outline-primary">Edit</a> ';

	if ($acct->isOAuth()) {
		$connect_label = $needs_connect ? 'Connect' : 'Reconnect';
		$actions .= PublicPageBase::action_button($connect_label, $base, array(
			'hidden' => array('action' => 'connect', 'iia_inbound_imap_account_id' => $acct->key),
			'class' => 'btn btn-sm ' . ($needs_connect ? 'btn-warning' : 'btn-outline-secondary'),
		)) . ' ';
	}

	$actions .= PublicPageBase::action_button('Test', $base, array(
		'hidden' => array('action' => 'test', 'iia_inbound_imap_account_id' => $acct->key),
		'class' => 'btn btn-sm btn-outline-secondary',
	)) . ' ';

	$actions .= PublicPageBase::action_button('Poll now', $base, array(
		'hidden' => array('action' => 'poll_now', 'iia_inbound_imap_account_id' => $acct->key),
		'class' => 'btn btn-sm btn-outline-secondary',
	)) . ' ';

	$actions .= PublicPageBase::action_button('Delete', $base, array(
		'hidden' => array('action' => 'delete', 'iia_inbound_imap_account_id' => $acct->key),
		'confirm' => 'Delete this IMAP account? Already-ingested mail is kept.',
		'class' => 'btn btn-sm btn-outline-danger',
	));

	$page->disprow(array(
		htmlspecialchars($acct->get('iia_label') ?: $acct->get('iia_username') ?: '(unnamed)'),
		$provider_label,
		htmlspecialchars($mailbox),
		$enabled,
		$last_poll,
		$status,
		$actions,
	));
}

$page->endtable();
$page->admin_footer();
?>
