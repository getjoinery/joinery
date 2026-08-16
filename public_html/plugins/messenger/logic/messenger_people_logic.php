<?php
/**
 * API action: messenger/messenger_people — find someone to message.
 *
 * POST /api/v1/action/messenger/messenger_people (browser session). Params:
 *   q                  what the member typed (at least two characters)
 *   exclude_conversation_id  leave out people already in this group
 *
 * Returns names and pictures only. Email addresses are deliberately not in the
 * answer: a member picking someone to talk to needs to recognise them, not to
 * learn how to reach them off-platform. The staff typeahead (`user_search`)
 * remains the surface that shows addresses, and it stays permission-5.
 *
 * The member's OWN contacts (the mailbox address book) are the exception: those
 * addresses were saved by the member, so showing them back reveals nothing.
 * When the mailbox plugin is active and cross-site chat is set up, matching
 * contacts ride along as `contacts`, and picking one runs the same
 * reachability-then-start flow as a typed address.
 *
 * @version 1.2.0
 * @changelog 1.2.0 - Reachability spec: an exact typed address of a local member resolves to that member (via_address); contacts gate on siteReady() so S4 members still see them
 * @changelog 1.1.0 - The member's own mailbox contacts match too (name or address), enabling chat with someone on another Joinery site straight from the picker
 */


function messenger_people_logic(array $input): LogicResult {

	try {
		$user_id = Messenger::requireMember();
	} catch (MessengerRefusal $e) {
		return LogicResult::error($e->getMessage());
	}

	$q = trim((string)($input['q'] ?? ''));
	if (mb_strlen($q) < 2) {
		return LogicResult::render(array('people' => array()));
	}

	// Who is already in the group the member is adding to — offering them again
	// would only produce a refusal.
	$exclude = array($user_id);
	if (!empty($input['exclude_conversation_id'])) {
		try {
			$conversation = Messenger::conversationFor($input['exclude_conversation_id'], $user_id);
			$exclude = array_merge($exclude, $conversation->participant_user_ids());
		} catch (MessengerRefusal $e) {
			// Not their conversation — the search still works, it just excludes
			// nothing extra.
		}
	}

	$words = preg_split('/\s+/', $q);
	$criteria = count($words) >= 2
		? array('name_like' => $q)
		: array(
			'first_name_like' => $q,
			'last_name_like'  => $q,
			'nickname_like'   => $q,
		);

	$users = new MultiUser(
		$criteria,
		array('usr_first_name' => 'ASC', 'usr_last_name' => 'ASC'),
		40, 0,
		count($words) >= 2 ? 'AND' : 'OR'
	);

	$people = array();
	foreach ($users as $user) {
		$id = (int)$user->key;
		if (in_array($id, $exclude, true)) {
			continue;
		}
		if ($id === User::USER_SYSTEM || $id === User::USER_DELETED) {
			continue;
		}
		if ($user->get('usr_delete_time') || $user->get('usr_is_disabled')
			|| $user->get('usr_is_admin_disabled')) {
			continue;
		}
		$people[] = array(
			'user_id' => $id,
			'name'    => $user->display_name(),
			'avatar'  => $user->get_picture_link('avatar'),
		);
		if (count($people) >= 20) {
			break;
		}
	}

	// An EXACT typed address on this site's own mail domains names a member
	// (spec R1). Only a full address ever matches — partials never search
	// member emails, so membership cannot be enumerated. `via_address` tells
	// the picker not to also offer the same string as a raw address.
	if (strpos($q, '@') !== false && filter_var($q, FILTER_VALIDATE_EMAIL)) {
		$member = MessengerFederation::resolveLocalMember($q);
		if ($member !== null && !in_array($member['user_id'], $exclude, true)) {
			$already = false;
			foreach ($people as $person) {
				if ($person['user_id'] === $member['user_id']) {
					$already = true;
					break;
				}
			}
			if (!$already) {
				$member['via_address'] = true;
				array_unshift($people, $member);
			}
		}
	}

	// The member's own contacts, matched on name or address. Offered when the
	// SITE can chat across instances (S1–S3 hide the surface); a member who
	// personally lacks a sendable address still sees them — the pick explains
	// S4 rather than the results lying about what exists.
	$contacts = array();
	if (class_exists('MailboxContacts') && MessengerFederation::siteReady()) {
		$needle = mb_strtolower($q);
		$store = new MailboxContacts();
		foreach ($store->listForUser($user_id)['contacts'] as $contact) {
			if (mb_strpos(mb_strtolower($contact['name']), $needle) === false
				&& mb_strpos($contact['address'], $needle) === false) {
				continue;
			}
			$contacts[] = $contact;
			if (count($contacts) >= 10) {
				break;
			}
		}
	}

	return LogicResult::render(array('people' => $people, 'contacts' => $contacts));
}

function messenger_people_logic_descriptor(): array {
	return array(
		'requires_session' => true,
		'requires_setting' => 'messenger_active',
		'description' => 'Search members by name to start a conversation or add to a group. Names and pictures only.',
		'input' => array(
			'q' => array('type' => 'string', 'required' => true, 'label' => 'Search term'),
			'exclude_conversation_id' => array('type' => 'int', 'required' => false, 'label' => 'Group to exclude existing members of'),
		),
	);
}
?>
