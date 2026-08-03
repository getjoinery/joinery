<?php
/**
 * API action: mailbox/contacts — one mailbox's contact list (autocomplete + management).
 *
 * POST /api/v1/action/mailbox/contacts (session credential). Param: alias_id (the mailbox).
 * Returns {contacts: [{id, address, name, use_count, source}], alias_id, locked?}. Contacts
 * are per-mailbox, so the caller must name one and must hold a grant for it; composing from
 * one mailbox never surfaces addresses harvested in another.
 *
 * The whole (small) list is returned decrypted and de-duplicated by address, ranked by use
 * frequency then recency — the client filters it locally, which is what keeps a sealed
 * contact store compatible with autocomplete (no server prefix-search over ciphertext). A
 * vault holder with a closed window gets {locked:true} and no contacts (autocomplete
 * silently absent).
 *
 * @version 1.1.0
 */

require_once(__DIR__ . '/../../../includes/PathHelper.php');

function contacts_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxContacts.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));

	$session = SessionControl::get_instance();
	$uid = $session->get_user_id();
	if (!$uid) {
		return LogicResult::error('Sign in required.');
	}

	$alias_id = intval($input['alias_id'] ?? 0);
	if ($alias_id <= 0) {
		return LogicResult::error('No mailbox specified.');
	}
	// This gate answers "may you open this mailbox at all" — and for an all-access
	// viewer that is every mailbox. What keeps one person's contacts private from
	// another is not this check but the query itself: listForMailbox() is scoped to
	// the CALLING user's rows, so an operator opening someone else's mailbox sees
	// their own (usually empty) store for it, never the grantee's.
	$viewer = MailboxViewer::fromSession($session);
	if (!$viewer->canAccess($alias_id)) {
		return LogicResult::error('Not authorized.');
	}

	$contacts = new MailboxContacts();
	try {
		$result = $contacts->listForMailbox(intval($uid), $alias_id);
	} catch (Throwable $e) {
		error_log('mailbox/contacts: ' . $e->getMessage());
		return LogicResult::render(array('contacts' => array(), 'alias_id' => $alias_id));
	}
	$result['alias_id'] = $alias_id;
	return LogicResult::render($result);
}

function contacts_logic_api() {
	return array(
		'requires_session' => true,
		'description' => 'One mailbox\'s email contacts (decrypted, ranked) for autocomplete + management',
	);
}
?>
