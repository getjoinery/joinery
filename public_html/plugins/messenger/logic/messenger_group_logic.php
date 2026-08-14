<?php
/**
 * API action: messenger/messenger_group — make and manage group conversations.
 *
 * POST /api/v1/action/messenger/messenger_group (browser session). Params:
 *   action           create | rename | add_member | remove_member | leave
 *                    | set_admin | set_photo | remove_photo
 *   conversation_id  for everything but `create`
 *   member_ids       for `create` — who is in it besides the caller
 *   name             for `create` / `rename`
 *   member_id        for add_member / remove_member / set_admin
 *   is_admin         for set_admin
 *   file_id          for set_photo — an id from messenger_upload
 *   protection_level for `create`
 *
 * Membership rules live on the Conversation model (core), not here: the creator
 * is the first admin, only admins manage membership and the name, anyone may
 * leave, and every change writes a system message so the group can see its own
 * history.
 *
 * @version 1.0.0
 */


function messenger_group_logic(array $input): LogicResult {

	try {
		$user_id = Messenger::requireMember();
	} catch (MessengerRefusal $e) {
		return LogicResult::error($e->getMessage());
	}

	$action = isset($input['action']) ? (string)$input['action'] : '';

	if ($action === 'create') {
		return messenger_group_create($user_id, $input);
	}

	try {
		$conversation = Messenger::conversationFor($input['conversation_id'] ?? 0, $user_id);
	} catch (MessengerRefusal $e) {
		return LogicResult::error($e->getMessage());
	}

	try {
		switch ($action) {
			case 'rename':
				$conversation->rename($input['name'] ?? '', $user_id);
				break;

			case 'add_member':
				$conversation->add_participant($input['member_id'] ?? 0, $user_id);
				break;

			case 'remove_member':
				$conversation->remove_participant($input['member_id'] ?? 0, $user_id);
				break;

			case 'leave':
				$conversation->leave($user_id);
				return LogicResult::render(array(
					'action'          => 'leave',
					'conversation_id' => (int)$conversation->key,
					'left'            => true,
				));

			case 'set_admin':
				$conversation->set_admin($input['member_id'] ?? 0, !empty($input['is_admin']), $user_id);
				break;

			case 'set_photo':
				return messenger_group_set_photo($conversation, $user_id, $input);

			case 'remove_photo':
				return messenger_group_remove_photo($conversation, $user_id);

			default:
				return LogicResult::error('Unknown action.');
		}
	} catch (ConversationException $e) {
		return LogicResult::error($e->getMessage());
	}

	$conversation->load();
	return LogicResult::render(array(
		'action'          => $action,
		'conversation_id' => (int)$conversation->key,
		'conversation'    => Messenger::conversationPayload($conversation, $user_id),
	));
}

/** Start a group: the caller plus at least one other member. */
function messenger_group_create(int $user_id, array $input): LogicResult {
	$member_ids = isset($input['member_ids']) ? (array)$input['member_ids'] : array();
	$member_ids = array_values(array_unique(array_filter(array_map('intval', $member_ids))));
	$member_ids = array_values(array_diff($member_ids, array($user_id)));

	if (!$member_ids) {
		return LogicResult::error('Choose at least one person for the group.');
	}

	$max = Messenger::clientSettings()['max_group_size'];
	if (count($member_ids) + 1 > $max) {
		return LogicResult::error('A group can hold up to ' . $max . ' people.');
	}

	foreach ($member_ids as $member_id) {
		if (!User::check_if_exists($member_id)) {
			return LogicResult::error('One of those members does not exist.');
		}
	}

	$name = trim(strip_tags((string)($input['name'] ?? '')));
	if (mb_strlen($name) > 255) {
		$name = mb_substr($name, 0, 255);
	}

	$level = ProtectionLevel::normalize(
		$input['protection_level'] ?? Messenger::clientSettings()['default_level']);
	// Only the rungs the messenger offers (Fortress is deliberately not one of
	// them — a multi-party room cannot honestly promise client custody).
	if (!in_array($level, array(ProtectionLevel::STANDARD, ProtectionLevel::PRIVATE_, ProtectionLevel::GUARDED), true)) {
		$level = ProtectionLevel::STANDARD;
	}

	try {
		// Created plain, then raised: protecting a conversation is a ceremony
		// (mint a key, hand it to everyone, seal the history), and starting a
		// group at Private is that same ceremony over an empty history.
		$conversation = Conversation::create_conversation(
			array_merge(array($user_id), $member_ids),
			$name !== '' ? $name : null,
			array('admin_user_id' => $user_id)
		);

		$creator = new User($user_id, TRUE);
		$conversation->add_system_message($creator->display_name() . ' started the group');

		if ($level !== ProtectionLevel::STANDARD) {
			$conversation->raise($level, $user_id);
		}
	} catch (ConversationException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(array(
		'action'          => 'create',
		'conversation_id' => (int)$conversation->key,
		'conversation'    => Messenger::conversationPayload($conversation, $user_id),
	));
}

/**
 * Give the group a picture. The bytes arrive through messenger_upload like any
 * attachment, so there is one upload path; this claims one as the group's photo.
 */
function messenger_group_set_photo(Conversation $conversation, int $user_id, array $input): LogicResult {

	if (!$conversation->is_group()) {
		return LogicResult::error('Only a group has a picture.');
	}
	if (!$conversation->is_admin($user_id)) {
		return LogicResult::error('Only a group admin can do that.');
	}

	$file_id = isset($input['file_id']) ? (int)$input['file_id'] : 0;
	try {
		$files = MessengerUploads::claim(array($file_id), $user_id, $conversation);
	} catch (MessengerRefusal $e) {
		return LogicResult::error($e->getMessage());
	}
	$file = $files[0];

	if (strpos((string)$file->get('fil_type'), 'image/') !== 0) {
		return LogicResult::error('A group picture has to be an image.');
	}

	// One picture at a time: the old one stops being the group's.
	messenger_group_clear_photos($conversation);

	$photo = new EntityPhoto(NULL);
	$photo->set('eph_entity_type', 'conversation');
	$photo->set('eph_entity_id', (int)$conversation->key);
	$photo->set('eph_fil_file_id', (int)$file->key);
	$photo->set('eph_sort_order', 0);
	$photo->save();

	$actor = new User($user_id, TRUE);
	$conversation->add_system_message($actor->display_name() . ' changed the group picture',
		$conversation->change_key());

	return LogicResult::render(array(
		'action'          => 'set_photo',
		'conversation_id' => (int)$conversation->key,
		'conversation'    => Messenger::conversationPayload($conversation, $user_id),
	));
}

function messenger_group_remove_photo(Conversation $conversation, int $user_id): LogicResult {

	if (!$conversation->is_admin($user_id)) {
		return LogicResult::error('Only a group admin can do that.');
	}
	messenger_group_clear_photos($conversation);

	return LogicResult::render(array(
		'action'          => 'remove_photo',
		'conversation_id' => (int)$conversation->key,
		'conversation'    => Messenger::conversationPayload($conversation, $user_id),
	));
}

function messenger_group_clear_photos(Conversation $conversation): void {
	$photos = new MultiEntityPhoto(array(
		'entity_type' => 'conversation',
		'entity_id'   => (int)$conversation->key,
		'deleted'     => false,
	));
	foreach ($photos as $photo) {
		$photo->set('eph_delete_time', gmdate('Y-m-d H:i:s'));
		$photo->save();
	}
}

function messenger_group_logic_descriptor(): array {
	return array(
		'requires_session' => true,
		'requires_setting' => 'messenger_active',
		'description' => 'Create a group conversation, rename it, add or remove members, leave it, change who administers it, or set its picture.',
		'input' => array(
			'action'           => array('type' => 'string', 'required' => true,  'label' => 'Action'),
			'conversation_id'  => array('type' => 'int',    'required' => false, 'label' => 'Conversation'),
			'member_ids'       => array('type' => 'array',  'required' => false, 'label' => 'Members for a new group',
				'items' => array('type' => 'int'), 'max_items' => 512),
			'member_id'        => array('type' => 'int',    'required' => false, 'label' => 'Member being added, removed or promoted'),
			'name'             => array('type' => 'string', 'required' => false, 'label' => 'Group name'),
			'is_admin'         => array('type' => 'bool',   'required' => false, 'label' => 'Admin rights'),
			'file_id'          => array('type' => 'int',    'required' => false, 'label' => 'Uploaded picture'),
			'protection_level' => array('type' => 'string', 'required' => false, 'label' => 'Protection level'),
		),
	);
}
?>
