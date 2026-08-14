<?php
/**
 * API action: messenger/messenger_poll — everything that changed since last time.
 *
 * POST /api/v1/action/messenger/messenger_poll (browser session). One action
 * carries the whole app's liveness: new messages in the open conversation,
 * reaction and tombstone changes on the bubbles already on screen, where each
 * participant has read to, who is typing, and which conversations in the list
 * have moved. The browser adapts how often it calls (fast with a conversation
 * open, slow on the list, not at all while the tab is hidden) — see
 * assets/js/joinery-poll.js.
 *
 * The caller's own typing state rides along in the request, so "Alice is
 * typing…" needs no endpoint and no table of its own.
 *
 * Params:
 *   conversation_id   the open conversation, if any
 *   after_message_id  cursor — everything above this id is new to the caller
 *   list_since        UTC timestamp; conversations touched since then
 *   typing            true while the caller is composing, false when they stop
 *   mark_read         mark the open conversation read (the tab is focused)
 *
 * @version 1.0.0
 */


function messenger_poll_logic(array $input): LogicResult {

	try {
		$user_id = Messenger::requireMember();
	} catch (MessengerRefusal $e) {
		return LogicResult::error($e->getMessage());
	}

	$out = array(
		'now'          => gmdate('Y-m-d H:i:s'),
		'conversation' => null,
		'inbox'        => null,
		'unread_total' => Conversation::get_unread_count($user_id),
	);

	// ---- The open conversation ----------------------------------------
	if (!empty($input['conversation_id'])) {
		try {
			$conversation = Messenger::conversationFor($input['conversation_id'], $user_id);
		} catch (MessengerRefusal $e) {
			// The conversation went away under the caller (deleted, or they were
			// removed from the group). Say so rather than erroring the whole poll
			// — the inbox half of the answer is still good.
			return LogicResult::render($out + array('conversation_gone' => true));
		}

		// Typing rides the poll: record the caller's state, read back everyone
		// else's. Both halves are APCu only and expire on their own.
		if (array_key_exists('typing', $input)) {
			MessengerTyping::set($conversation->key, $user_id, !empty($input['typing']));
		}

		$after = isset($input['after_message_id']) ? (int)$input['after_message_id'] : 0;
		$page  = Messenger::threadPage($conversation, array('after_message_id' => $after, 'limit' => 100));

		$typing = array();
		foreach (MessengerTyping::who($conversation->key, $user_id) as $typist_id) {
			$typing[] = array('user_id' => $typist_id, 'name' => Messenger::person($typist_id)['name']);
		}

		if (!empty($input['mark_read'])) {
			Messenger::markRead($conversation, $user_id);
		}

		$conversation_payload = Messenger::conversationPayload($conversation, $user_id);

		$out['conversation'] = array(
			'id'           => (int)$conversation->key,
			'messages'     => Messenger::messagesPayload($page['messages'], $user_id),
			'updates'      => messenger_poll_recent_updates($conversation, $user_id),
			'participants' => $conversation_payload['participants'],
			'typing'       => $typing,
			'title'        => $conversation_payload['title'],
			'is_group'     => $conversation_payload['is_group'],
			'is_admin'     => $conversation_payload['is_admin'],
			'protection_level' => $conversation_payload['protection_level'],
		);
	}

	// ---- The conversation list ----------------------------------------
	if (array_key_exists('list_since', $input)) {
		$since = trim((string)$input['list_since']);
		$options = array('participant_user_id' => $user_id, 'deleted' => false);
		if ($since !== '') {
			$options['activity_since'] = $since;
		}
		$conversations = new MultiConversation($options, array(), Messenger::INBOX_SIZE);

		$rows = array();
		foreach ($conversations as $conversation) {
			$rows[] = Messenger::conversationPayload($conversation, $user_id);
		}
		$out['inbox'] = array(
			'conversations' => $rows,
			'is_full_list'  => ($since === ''),
		);
	}

	return LogicResult::render($out);
}

/**
 * Reaction and tombstone state for the bubbles a reader is plausibly looking at.
 *
 * A reaction or a delete changes a message that is already on screen, so the
 * cursor — which only ever moves forward — would never carry it. Rather than
 * asking the browser to enumerate what it is displaying, the poll re-states the
 * mutable parts of the most recent stretch of the thread. Bounded by
 * construction: two indexed queries whatever the thread's length.
 */
function messenger_poll_recent_updates(Conversation $conversation, int $user_id): array {
	$page = Messenger::threadPage($conversation, array('limit' => 60));

	$ids = array();
	foreach ($page['messages'] as $message) {
		$ids[] = (int)$message->key;
	}
	if (!$ids) {
		return array();
	}

	$reactions = Messenger::reactionsFor($ids, $user_id);

	$out = array();
	foreach ($page['messages'] as $message) {
		$id = (int)$message->key;
		$out[] = array(
			'id'         => $id,
			'is_deleted' => (bool)$message->get('msg_delete_time'),
			'reactions'  => $reactions[$id] ?? array(),
		);
	}
	return $out;
}

function messenger_poll_logic_descriptor(): array {
	return array(
		'requires_session' => true,
		'requires_setting' => 'messenger_active',
		// Not declared read-only: the poll carries the caller's typing state and
		// can mark the open conversation read, so it writes on the way through.
		'description' => 'Everything that changed in the messenger since the caller last asked: new messages, reaction and delete updates, read positions, who is typing, and conversation-list movement.',
		'input' => array(
			'conversation_id'  => array('type' => 'int',    'required' => false, 'label' => 'Open conversation'),
			'after_message_id' => array('type' => 'int',    'required' => false, 'label' => 'Message cursor'),
			'list_since'       => array('type' => 'string', 'required' => false, 'label' => 'Inbox cursor (UTC timestamp)'),
			'typing'           => array('type' => 'bool',   'required' => false, 'label' => 'Caller is typing'),
			'mark_read'        => array('type' => 'bool',   'required' => false, 'label' => 'Mark the open conversation read'),
		),
	);
}
?>
