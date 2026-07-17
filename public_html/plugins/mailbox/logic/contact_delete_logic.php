<?php
/**
 * API action: mailbox/contact_delete — remove a contact from the caller's store.
 *
 * POST /api/v1/action/mailbox/contact_delete (session credential). Param: contact_id.
 * Scoped to the caller — a contact id owned by another user is a no-op. Returns
 * {deleted: bool}.
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function contact_delete_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxContacts.php'));

	$session = SessionControl::get_instance();
	$uid = $session->get_user_id();
	if (!$uid) {
		return LogicResult::error('Sign in required.');
	}

	$contact_id = intval($input['contact_id'] ?? 0);
	if ($contact_id <= 0) {
		return LogicResult::error('No contact specified.');
	}

	$contacts = new MailboxContacts();
	$deleted = $contacts->deleteContact(intval($uid), $contact_id);
	return LogicResult::render(array('deleted' => (bool)$deleted));
}

function contact_delete_logic_api() {
	return array(
		'requires_session' => true,
		'description' => 'Delete one of the caller\'s contacts',
	);
}
?>
