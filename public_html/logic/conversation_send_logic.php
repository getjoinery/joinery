<?php
/**
 * API action: conversation_send — send a message in a conversation.
 *
 * POST /api/v1/action/conversation_send (session key). Params:
 * conversation_id (existing thread) OR to (recipient user id, creates or
 * reuses a 1:1 conversation); body. Returns the created message as data
 * — no server-rendered HTML fragment. Replaces the `send_message` action
 * of the legacy /ajax/conversations_ajax.php endpoint; participant
 * authorization matches it exactly (sender or recipient).
 *
 * @version 1.0.0
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

function conversation_send_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/conversations_class.php'));
	require_once(PathHelper::getIncludePath('data/conversation_participants_class.php'));
	require_once(PathHelper::getIncludePath('data/messages_class.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));

	$session = SessionControl::get_instance();
	if (!$session->get_user_id()) {
		return LogicResult::error('Sign in required.');
	}

	$settings = Globalvars::get_instance();
	if (!$settings->get_setting('messaging_active', true, true)) {
		return LogicResult::error('This feature is turned off');
	}

	$current_user_id = $session->get_user_id();
	$body = isset($input['body']) ? trim((string)$input['body']) : '';
	$conversation_id = isset($input['conversation_id']) ? (int)$input['conversation_id'] : 0;
	$to = isset($input['to']) ? (int)$input['to'] : 0;

	if ($body === '') {
		return LogicResult::error('Message cannot be empty.');
	}

	try {
		if ($to) {
			if ($to == $current_user_id) {
				return LogicResult::error('Cannot message yourself.');
			}
			$conversation = Conversation::get_or_create_conversation($current_user_id, $to);
		} elseif ($conversation_id) {
			if (!Conversation::check_if_exists($conversation_id)) {
				return LogicResult::error('Conversation not found.');
			}
			$conversation = new Conversation($conversation_id, TRUE);
			if (!$conversation->has_participant($current_user_id)) {
				return LogicResult::error('You do not have permission to message in this conversation.');
			}
		} else {
			return LogicResult::error('You must provide a conversation_id or to.');
		}

		$message = $conversation->add_message($current_user_id, $body);
	} catch (ConversationException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(array(
		'conversation_id' => (int)$conversation->key,
		'message_id'      => (int)$message->key,
		'body'            => $message->get('msg_body'),
		'sent_time'       => $message->get('msg_sent_time'),
	));
}

function conversation_send_logic_api() {
	return [
		'requires_session' => true,
		'description' => 'Send a message in a conversation (conversation_id or to for a new/existing 1:1)',
	];
}

?>
