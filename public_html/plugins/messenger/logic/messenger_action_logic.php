<?php
/**
 * API action: messenger/messenger_action — the small things a member does to a
 * conversation or a message.
 *
 * POST /api/v1/action/messenger/messenger_action (browser session). Params:
 *   action           one of open | open_remote | reachability | mute | unmute
 *                    | delete | mark_read | react | delete_message | protection
 *   conversation_id  for everything but `open`
 *   to               for `open` — the member to start (or resume) a 1:1 with
 *   message_id       for react / delete_message
 *   emoji            for react
 *
 * `delete` is the member clearing a conversation out of their own inbox — the
 * conversation itself and everyone else's copy are untouched, and a new message
 * brings it back. Leaving a group is a membership change and lives in
 * messenger_group.
 *
 * @version 1.0.0
 */


function messenger_action_logic(array $input): LogicResult {

	try {
		$user_id = Messenger::requireMember();
	} catch (MessengerRefusal $e) {
		return LogicResult::error($e->getMessage());
	}

	$action = isset($input['action']) ? (string)$input['action'] : '';

	// `open` is the only action that does not start from an existing
	// conversation — it is how a member begins one.
	if ($action === 'open') {
		$to = isset($input['to']) ? (int)$input['to'] : 0;
		if ($to === $user_id) {
			return LogicResult::error('You cannot message yourself.');
		}
		if (!$to || !User::check_if_exists($to)) {
			return LogicResult::error('That member does not exist.');
		}
		try {
			$conversation = Conversation::get_or_create_conversation($user_id, $to);
		} catch (ConversationException $e) {
			return LogicResult::error($e->getMessage());
		}
		return LogicResult::render(array(
			'action'          => $action,
			'conversation_id' => (int)$conversation->key,
			'conversation'    => Messenger::conversationPayload($conversation, $user_id),
		));
	}

	// Opening a conversation with someone on another Joinery instance, and
	// asking beforehand whether that is even possible. Both live before the
	// conversation lookup because neither starts from one.
	if ($action === 'reachability' || $action === 'open_remote') {
		$address = strtolower(trim((string)($input['address'] ?? '')));
		$reach = MessengerFederation::reachability($address);

		if ($action === 'reachability') {
			return LogicResult::render(array(
				'action'    => 'reachability',
				'address'   => $address,
				'reachable' => $reach['reachable'],
				'reason'    => $reach['reason'],
				// The honest alternative, offered rather than silently taken.
				'email_url' => '/profile/mailbox/mailbox?compose=' . rawurlencode($address),
			));
		}

		if (!$reach['reachable']) {
			return LogicResult::error($reach['reason']);
		}
		if (MessengerFederation::addressFor($user_id) === null) {
			return LogicResult::error('You need a Joinery email address on this site before you can chat across sites.');
		}

		$conversation = Conversation::find_remote_conversation($user_id, $address);
		if ($conversation === null) {
			try {
				$conversation = Conversation::create_remote_conversation($user_id, $address);
			} catch (ConversationException $e) {
				return LogicResult::error($e->getMessage());
			}
		}
		return LogicResult::render(array(
			'action'          => 'open_remote',
			'conversation_id' => (int)$conversation->key,
			'conversation'    => Messenger::conversationPayload($conversation, $user_id),
		));
	}

	try {
		$conversation = Messenger::conversationFor($input['conversation_id'] ?? 0, $user_id);
	} catch (MessengerRefusal $e) {
		return LogicResult::error($e->getMessage());
	}

	switch ($action) {
		case 'mute':
		case 'unmute':
			$row = $conversation->participant_row($user_id);
			$row->set('cnp_is_muted', $action === 'mute');
			$row->save();
			break;

		case 'delete':
			$row = $conversation->participant_row($user_id);
			$row->set('cnp_delete_time', gmdate('Y-m-d H:i:s'));
			$row->save();
			$_SESSION['message_unread_count'] = null;
			break;

		case 'mark_read':
			Messenger::markRead($conversation, $user_id);
			break;

		case 'react':
			return messenger_action_react($conversation, $user_id, $input);

		case 'delete_message':
			return messenger_action_delete_message($conversation, $user_id, $input);

		case 'protection':
			return messenger_action_protection($conversation, $user_id, $input);

		default:
			return LogicResult::error('Unknown action.');
	}

	return LogicResult::render(array(
		'action'          => $action,
		'conversation_id' => (int)$conversation->key,
		'unread_total'    => Conversation::get_unread_count($user_id),
	));
}

/** Tap an emoji on a message; tapping the same one again takes it back off. */
function messenger_action_react(Conversation $conversation, int $user_id, array $input): LogicResult {
	$message_id = isset($input['message_id']) ? (int)$input['message_id'] : 0;
	if (!$message_id || !Message::check_if_exists($message_id)) {
		return LogicResult::error('That message is no longer here.');
	}
	$message = new Message($message_id, TRUE);
	if ((int)$message->get('msg_cnv_conversation_id') !== (int)$conversation->key) {
		return LogicResult::error('That message is no longer here.');
	}
	if ($message->get('msg_delete_time')) {
		return LogicResult::error('That message was deleted.');
	}

	try {
		$now_on = MessageReaction::toggle($message_id, $user_id, $input['emoji'] ?? '');
	} catch (MessageReactionException $e) {
		return LogicResult::error($e->getMessage());
	}

	messenger_action_federate_control($conversation, $user_id, array(
		'type'     => MessengerFederation::TYPE_REACTION,
		'cnv_guid' => (string)$conversation->get('cnv_guid'),
		'msg_guid' => (string)$message->get('msg_guid'),
		'emoji'    => MessageReaction::normalize_emoji($input['emoji'] ?? ''),
		'on'       => $now_on,
	));

	$reactions = Messenger::reactionsFor(array($message_id), $user_id);

	return LogicResult::render(array(
		'action'     => 'react',
		'message_id' => $message_id,
		'is_on'      => $now_on,
		'reactions'  => $reactions[$message_id] ?? array(),
	));
}

/**
 * Delete-for-everyone.
 *
 * Only the sender, and only their own message. The row is soft-deleted and the
 * thread renders a tombstone rather than closing the gap — the people who
 * already read it know something was there, which is the honest thing to show.
 */
function messenger_action_delete_message(Conversation $conversation, int $user_id, array $input): LogicResult {
	$message_id = isset($input['message_id']) ? (int)$input['message_id'] : 0;
	if (!$message_id || !Message::check_if_exists($message_id)) {
		return LogicResult::error('That message is no longer here.');
	}
	$message = new Message($message_id, TRUE);
	if ((int)$message->get('msg_cnv_conversation_id') !== (int)$conversation->key) {
		return LogicResult::error('That message is no longer here.');
	}
	if ((int)$message->get('msg_usr_user_id_sender') !== $user_id) {
		return LogicResult::error('You can only delete your own messages.');
	}
	if ($message->get('msg_message_type') === Conversation::TYPE_SYSTEM) {
		return LogicResult::error('That is a record of something that happened, not a message.');
	}

	if (!$message->get('msg_delete_time')) {
		$message->set('msg_delete_time', gmdate('Y-m-d H:i:s'));
		$message->save();

		// The conversation changed, so its list row has to be recomputed — a
		// preview still quoting a deleted message is the one place a tombstone
		// would fail to hide what it says.
		$conversation->set('cnv_update_time', gmdate('Y-m-d H:i:s'));
		$conversation->save();

		messenger_action_federate_control($conversation, $user_id, array(
			'type'     => MessengerFederation::TYPE_DELETE,
			'cnv_guid' => (string)$conversation->get('cnv_guid'),
			'msg_guid' => (string)$message->get('msg_guid'),
		));
	}

	return LogicResult::render(array(
		'action'     => 'delete_message',
		'message_id' => $message_id,
	));
}

/**
 * Tell the other instance about a reaction or a delete.
 *
 * Best-effort by design: these are small corrections to something already
 * delivered, so a failure costs a missing chip on the far side rather than a
 * lost message, and queueing them would build a retry system for the least
 * important payload on the wire.
 */
function messenger_action_federate_control(Conversation $conversation, int $user_id, array $header): void {
	if (!$conversation->is_federated()) {
		return;
	}
	MessengerFederation::sendControl($conversation, $header, $user_id);
}

/**
 * Read or raise a conversation's protection level.
 *
 * Without a `protection_level` this reports the current state and what would
 * stand in the way of raising — which is what the picker needs to show before
 * the member commits to something that cannot be undone.
 *
 * With one, it raises. Any participant may; nobody may lower.
 */
function messenger_action_protection(Conversation $conversation, int $user_id, array $input): LogicResult {
	$requested = isset($input['protection_level']) ? (string)$input['protection_level'] : '';

	if ($requested === '') {
		$missing = array_values($conversation->members_without_vault());
		return LogicResult::render(array(
			'action'           => 'protection',
			'conversation_id'  => (int)$conversation->key,
			'protection_level' => $conversation->protection_level(),
			'levels'           => Conversation::LEVELS,
			// Raising to Private or Guarded needs everyone to hold a vault. Say
			// who is missing one rather than refusing without a reason.
			'members_without_protection' => $missing,
			'can_seal'         => empty($missing),
		));
	}

	try {
		$conversation->raise($requested, $user_id);
	} catch (ConversationException $e) {
		return LogicResult::error($e->getMessage());
	} catch (VaultLockedException $e) {
		return LogicResult::error('Unlock your vault first, then set the protection level.');
	}

	$conversation->load();

	return LogicResult::render(array(
		'action'           => 'protection',
		'conversation_id'  => (int)$conversation->key,
		'protection_level' => $conversation->protection_level(),
		'conversation'     => Messenger::conversationPayload($conversation, $user_id),
	));
}

function messenger_action_logic_descriptor(): array {
	return array(
		'requires_session' => true,
		'requires_setting' => 'messenger_active',
		'description' => 'Open a 1:1, mute/unmute, remove a conversation from your own inbox, mark it read, react to a message, or delete your own message for everyone.',
		'input' => array(
			'action'          => array('type' => 'string', 'required' => true,  'label' => 'Action'),
			'conversation_id' => array('type' => 'int',    'required' => false, 'label' => 'Conversation'),
			'to'              => array('type' => 'int',    'required' => false, 'label' => 'Member to open a 1:1 with'),
			'message_id'      => array('type' => 'int',    'required' => false, 'label' => 'Message'),
			'emoji'           => array('type' => 'string', 'required' => false, 'label' => 'Reaction emoji'),
			'protection_level' => array('type' => 'string', 'required' => false, 'label' => 'Protection level to raise to'),
			'address'         => array('type' => 'string', 'required' => false, 'label' => 'Address on another Joinery site'),
		),
	);
}
?>
