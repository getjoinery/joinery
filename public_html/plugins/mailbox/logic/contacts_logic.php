<?php
/**
 * API action: mailbox/contacts — the caller's contact list (autocomplete + management).
 *
 * POST /api/v1/action/mailbox/contacts (session credential). No params. Returns
 * {contacts: [{id, address, name, use_count, source}], locked?}. The whole (small) list
 * is returned decrypted and de-duplicated by address, ranked by use frequency then
 * recency — the client filters it locally, which is what keeps a sealed contact store
 * compatible with autocomplete (no server prefix-search over ciphertext). A vault holder
 * with a closed window gets {locked:true} and no contacts (autocomplete silently absent).
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function contacts_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxContacts.php'));

	$session = SessionControl::get_instance();
	$uid = $session->get_user_id();
	if (!$uid) {
		return LogicResult::error('Sign in required.');
	}

	$contacts = new MailboxContacts();
	try {
		$result = $contacts->listForUser(intval($uid));
	} catch (Throwable $e) {
		error_log('mailbox/contacts: ' . $e->getMessage());
		return LogicResult::render(array('contacts' => array()));
	}
	return LogicResult::render($result);
}

function contacts_logic_api() {
	return array(
		'requires_session' => true,
		'description' => 'The caller\'s email contacts (decrypted, ranked) for autocomplete + management',
	);
}
?>
