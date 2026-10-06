<?php
/**
 * Logic for the member Contacts page (/profile/mailbox/contacts).
 *
 * Contacts belong to a person AND a mailbox, so the page shows one mailbox's at a
 * time: the mailboxes the member holds a grant for, plus the one named in
 * ?mailbox= when they may open it (an all-access reader arriving from the contacts
 * pane of a mailbox they hold no grant on manages their own contacts for it, as
 * the pane does). ?edit= names a contact to open for editing, as the pane's pencil
 * does.
 *
 * The list is read here, decrypted in the member's vault window
 * (MailboxContacts::listForMailbox); a sealed store with the window closed comes
 * back locked and the page offers the unlock. Every change goes through the API
 * actions (contacts_import, contact_save, contact_delete) from the page's script.
 *
 * @version 1.0.0
 */

function mailbox_contacts_page_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxContacts.php'));
	require_once(PathHelper::getIncludePath('plugins/mailbox/includes/MailboxViewer.php'));

	$session = SessionControl::get_instance();
	$user_id = intval($session->get_user_id());
	if (!$user_id) {
		return LogicResult::redirect('/login?return=' . urlencode('/profile/mailbox/contacts'));
	}

	$ids = array_map('intval', InboundEmailMailboxGrant::alias_ids_for_user($user_id));
	$wanted = intval($input['mailbox'] ?? 0);
	if ($wanted > 0 && !in_array($wanted, $ids, true)
			&& MailboxViewer::fromSession($session)->canAccess($wanted)) {
		$ids[] = $wanted;
	}

	$mailboxes = array();
	foreach ($ids as $alias_id) {
		$alias = new InboundEmailAlias($alias_id, TRUE);
		if (!$alias->key || $alias->get('iea_delete_time')) {
			continue;
		}
		$mailboxes[] = array(
			'alias_id' => intval($alias->key),
			'address'  => (string)$alias->get_full_address(),
		);
	}
	usort($mailboxes, function ($a, $b) { return strcasecmp($a['address'], $b['address']); });

	$current = null;
	foreach ($mailboxes as $mb) {
		if ($mb['alias_id'] === $wanted) {
			$current = $mb;
		}
	}
	if ($current === null && $mailboxes) {
		$current = $mailboxes[0];
	}

	$list = array('contacts' => array());
	if ($current !== null) {
		try {
			$list = (new MailboxContacts())->listForMailbox($user_id, $current['alias_id']);
		} catch (Throwable $e) {
			error_log('mailbox contacts page: ' . $e->getMessage());
		}
	}
	$contacts = $list['contacts'] ?? array();
	usort($contacts, function ($a, $b) {
		return strcasecmp($a['name'] !== '' ? $a['name'] : $a['address'], $b['name'] !== '' ? $b['name'] : $b['address']);
	});

	return LogicResult::render(array(
		'session'   => $session,
		'mailboxes' => $mailboxes,
		'current'   => $current,
		'contacts'  => $contacts,
		'locked'    => !empty($list['locked']),
		'edit_id'   => intval($input['edit'] ?? 0),
	));
}
?>
