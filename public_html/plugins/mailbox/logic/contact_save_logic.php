<?php
/**
 * API action: mailbox/contact_save — change one of the caller's contacts.
 *
 * POST /api/v1/action/mailbox/contact_save (session credential). Params: contact_id,
 * address (the email), name (may be empty). Scoped to the caller — a contact id owned
 * by another user is "not found". A new address on the same mailbox replaces the old
 * one, joining an existing contact for that address if there is one
 * (MailboxContacts::updateContact). Returns {saved: true}.
 *
 * @version 1.0
 */

function contact_save_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxContacts.php'));

	$session = SessionControl::get_instance();
	$uid = intval($session->get_user_id());
	if (!$uid) {
		return LogicResult::error('Sign in required.');
	}

	$contact_id = intval($input['contact_id'] ?? 0);
	if ($contact_id <= 0) {
		return LogicResult::error('No contact specified.');
	}

	$contacts = new MailboxContacts();
	$outcome = $contacts->updateContact($uid, $contact_id,
		(string)($input['address'] ?? ''), (string)($input['name'] ?? ''));
	switch ($outcome) {
		case 'saved':
			return LogicResult::render(array('saved' => true));
		case 'invalid':
			return LogicResult::error('That is not a valid email address.');
		case 'missing':
			return LogicResult::error('That contact no longer exists.');
		default:
			return LogicResult::error('Your contacts for this mailbox are locked. Unlock your vault and try again.');
	}
}

function contact_save_logic_descriptor() {
	return array(
		'requires_session' => true,
		'description' => 'Change one of the caller\'s contacts: its name, its address, or both',
		'input' => [
			'contact_id' => ['type' => 'int', 'required' => true, 'label' => 'Contact ID'],
			'address' => ['type' => 'string', 'required' => true, 'label' => 'Email address'],
			'name' => ['type' => 'string', 'required' => false, 'label' => 'Name'],
		],
	);
}
?>
