<?php
/**
 * IMAP-feed delete choice — Keep (materialize local) vs Remove (delete rows).
 * Shown only when the feed has reference-backed messages that would otherwise be
 * stranded (specs/mailbox_data_loss_fixes.md, Fix 8). FormWriter only.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/admin_tabs.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/logic/admin_mailbox_imap_delete_logic.php'));

$page_vars = process_logic(admin_mailbox_imap_delete_logic(array_merge($_GET, $_POST, $params ?? [])));
extract($page_vars);

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'incoming',
	'breadcrumbs' => array(
		'Inbound Email' => '/plugins/mailbox/admin/admin_mailbox',
		'Accounts'      => $accounts_url,
		'Remove IMAP feed' => '',
	),
	'session' => $session,
));

echo AdminPage::tab_menu(mailbox_admin_tabs(), 'Accounts');

$label = trim((string)$account->get('iia_label')) ?: trim((string)$account->get('iia_username')) ?: 'IMAP feed';
$page->begin_box(array('title' => 'Remove ' . htmlspecialchars($label)));

$formwriter = $page->getFormWriter('form1');
echo $formwriter->begin_form();

$formwriter->hiddeninput('_submitted', '', array('value' => '1'));
$formwriter->hiddeninput('iia_inbound_imap_account_id', '', array('value' => (string)$account->key));
if (!empty($cascade_alias_id)) {
	$formwriter->hiddeninput('also_permadelete_alias_id', '', array('value' => (string)$cascade_alias_id));
}

// The choice. Keep is the default; the connectable state is fed to helptext so the
// operator knows up front whether Keep can run.
$keep_help = $connectable
	? 'Copy each message into this system (attachments become local files), then remove the feed.'
	: 'Unavailable — this account is not connected. Connect it first, or choose Remove.';

$formwriter->radioinput('delete_mode', $ref_count . ' message(s) still fetch their attachments from this account', array(
	'options' => array(
		'keep'   => 'Keep them — copy the mail into this system',
		'remove' => 'Remove them — delete the mirrored messages',
	),
	'value'   => 'keep',
	'helptext' => $keep_help . ' Remove deletes the mirrored messages here; the mail stays on the source server.',
));

$formwriter->submitbutton('btn_submit', 'Continue');
echo ' <a class="btn btn-outline-secondary" href="' . htmlspecialchars($accounts_url) . '">Cancel</a>';

echo $formwriter->end_form();

$page->end_box();
$page->admin_footer();
?>
