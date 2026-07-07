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
?>
