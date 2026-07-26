<?php
require_once(__DIR__ . '/../../../includes/PathHelper.php');

function admin_mailbox_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_domain_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$settings = Globalvars::get_instance();

	// Handle delete action
	if ($input && isset($input['action']) && $input['action'] === 'delete') {
		$alias = new InboundEmailAlias($input['iea_inbound_email_alias_id'], TRUE);
		$alias->soft_delete();

		$session->save_message(new DisplayMessage(
			'Alias deleted.',
			'Deleted',
			'~/plugins/mailbox/admin/~',
			DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_accounts');
	}

	// Handle restore of a soft-deleted mailbox. Stored messages keep their
	// pointer to the alias through soft delete, so restoring the alias brings
	// its mail back in the reader with no re-attachment step.
	if ($input && isset($input['action']) && $input['action'] === 'undelete') {
		$alias = new InboundEmailAlias($input['iea_inbound_email_alias_id'], TRUE);
		$alias->undelete();

		$session->save_message(new DisplayMessage(
			'Mailbox restored. Its stored mail is back in the reader.',
			'Restored',
			'~/plugins/mailbox/admin/~',
			DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_accounts');
	}

	if ($input && isset($input['action']) && $input['action'] === 'permanent_delete') {
		$session->check_permission(10);
		$alias = new InboundEmailAlias($input['iea_inbound_email_alias_id'], TRUE);

		// Permanently deleting the alias cascades to permanent-delete its IMAP
		// account, which would strand that account's reference-backed ('remote')
		// messages (their attachments fetch from the account on demand). Route
		// through the keep/remove choice first, which deletes the alias after
		// (specs/mailbox_data_loss_fixes.md, Fix 8).
		$stranded_account_id = _mailbox_alias_reference_backed_account($alias);
		if ($stranded_account_id > 0) {
			return LogicResult::redirect(
				'/plugins/mailbox/admin/admin_mailbox_imap_delete?iia_inbound_imap_account_id='
				. $stranded_account_id . '&also_permadelete_alias_id=' . intval($alias->key));
		}

		$alias->permanent_delete();

		$session->save_message(new DisplayMessage(
			'Mailbox permanently deleted.',
			'Deleted',
			'~/plugins/mailbox/admin/~',
			DisplayMessage::MESSAGE_ANNOUNCEMENT,
			DisplayMessage::MESSAGE_DISPLAY_IN_PAGE
		));
		return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_accounts');
	}

	// Handle enable/disable toggle
	if ($input && isset($input['action']) && $input['action'] === 'toggle_enabled') {
		$alias = new InboundEmailAlias($input['iea_inbound_email_alias_id'], TRUE);
		$alias->set('iea_is_enabled', $alias->get('iea_is_enabled') ? false : true);
		$alias->save();
		return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_accounts');
	}

	// The standalone alias list is retired — mailboxes live in the Accounts tree.
	// This page is now only an action handler; any bare visit bounces to Accounts.
	return LogicResult::redirect('/plugins/mailbox/admin/admin_mailbox_accounts');
}

/**
 * If this alias owns an IMAP account that has reference-backed ('remote')
 * messages, return that account's id (so the caller can route the delete through
 * the keep/remove choice). Returns 0 when there is nothing to strand.
 */
function _mailbox_alias_reference_backed_account(InboundEmailAlias $alias): int {
	if (!$alias->key) { return 0; }
	$db = DbConnector::get_instance()->get_db_link();
	$stmt = $db->prepare(
		"SELECT a.iia_inbound_imap_account_id
		 FROM iia_inbound_imap_accounts a
		 WHERE a.iia_iea_inbound_email_alias_id = ?
		 AND EXISTS (
			SELECT 1 FROM iem_inbound_email_messages m
			WHERE m.iem_iia_inbound_imap_account_id = a.iia_inbound_imap_account_id
			AND m.iem_raw_storage_driver = 'remote'
		 )
		 LIMIT 1");
	$stmt->execute(array(intval($alias->key)));
	return intval($stmt->fetchColumn());
}
?>
