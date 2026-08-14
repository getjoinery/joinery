<?php
/**
 * API action: messenger/messenger_thread — open a conversation, or reach
 * further back in one.
 *
 * POST /api/v1/action/messenger/messenger_thread (browser session). Params:
 *   conversation_id     the conversation to read
 *   before_message_id   page backwards from here ("load earlier messages")
 *   mark_read           mark it read (true when the member opened it)
 *
 * Opening returns the newest page plus everything the thread header needs; a
 * `before_message_id` returns only the older page, so scrolling back never
 * re-sends the participant list.
 *
 * @version 1.0.0
 */


function messenger_thread_logic(array $input): LogicResult {

	try {
		$user_id = Messenger::requireMember();
		$conversation = Messenger::conversationFor($input['conversation_id'] ?? 0, $user_id);
	} catch (MessengerRefusal $e) {
		return LogicResult::error($e->getMessage());
	}

	$before = isset($input['before_message_id']) ? (int)$input['before_message_id'] : 0;
	$page = Messenger::threadPage($conversation, $before
		? array('before_message_id' => $before)
		: array());

	$out = array(
		'conversation_id' => (int)$conversation->key,
		'messages'        => Messenger::messagesPayload($page['messages'], $user_id),
		'has_more'        => $page['has_more'],
		'is_page_back'    => (bool)$before,
	);

	if (!$before) {
		if (!empty($input['mark_read'])) {
			Messenger::markRead($conversation, $user_id);
		}
		$out['conversation'] = Messenger::conversationPayload($conversation, $user_id);

		$typing = array();
		foreach (MessengerTyping::who($conversation->key, $user_id) as $typist_id) {
			$typing[] = array('user_id' => $typist_id, 'name' => Messenger::person($typist_id)['name']);
		}
		$out['typing'] = $typing;
		$out['unread_total'] = Conversation::get_unread_count($user_id);
	}

	return LogicResult::render($out);
}

function messenger_thread_logic_descriptor(): array {
	return array(
		'requires_session' => true,
		'requires_setting' => 'messenger_active',
		'description' => 'One conversation: the newest page of messages plus its header, or an older page when before_message_id is given.',
		'input' => array(
			'conversation_id'   => array('type' => 'int',  'required' => true,  'label' => 'Conversation'),
			'before_message_id' => array('type' => 'int',  'required' => false, 'label' => 'Page back from this message'),
			'mark_read'         => array('type' => 'bool', 'required' => false, 'label' => 'Mark it read'),
		),
	);
}
?>
