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
 * @version 1.0.0
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

	return LogicResult::render(array('people' => $people));
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
