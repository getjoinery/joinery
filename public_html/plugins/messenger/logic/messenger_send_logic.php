<?php
/**
 * API action: messenger/messenger_send — say something in a conversation.
 *
 * POST /api/v1/action/messenger/messenger_send (browser session). Params:
 *   conversation_id   an existing conversation, OR
 *   to                a member id — opens (or reuses) the 1:1 with them
 *   body              the text
 *   reply_to_message_id  the message being quoted, if any
 *   attachment_ids    ids from messenger_upload, attached to this message
 *
 * Returns the stored message in the same shape the poll uses, so the browser
 * appends it with the code path it already has rather than a second renderer.
 *
 * @version 1.0.0
 */


function messenger_send_logic(array $input): LogicResult {

	try {
		$user_id = Messenger::requireMember();
	} catch (MessengerRefusal $e) {
		return LogicResult::error($e->getMessage());
	}

	$body = isset($input['body']) ? trim((string)$input['body']) : '';
	$attachment_ids = isset($input['attachment_ids']) ? (array)$input['attachment_ids'] : array();
	$attachment_ids = array_values(array_filter(array_map('intval', $attachment_ids)));

	if ($body === '' && !$attachment_ids) {
		return LogicResult::error('Type something first.');
	}

	try {
		// Where does this go?
		if (!empty($input['conversation_id'])) {
			$conversation = Messenger::conversationFor($input['conversation_id'], $user_id);
		} elseif (!empty($input['to'])) {
			$to = (int)$input['to'];
			if ($to === $user_id) {
				return LogicResult::error('You cannot message yourself.');
			}
			if (!User::check_if_exists($to)) {
				return LogicResult::error('That member does not exist.');
			}
			$conversation = Conversation::get_or_create_conversation($user_id, $to);
		} else {
			return LogicResult::error('Say who this is for.');
		}

		// Claim the uploads: an upload is a loose file until a message carries
		// it, and it may only be claimed by the member who made it.
		$files = MessengerUploads::claim($attachment_ids, $user_id, $conversation);

		$message = $conversation->add_message($user_id, $body, array(
			'reply_to_message_id' => $input['reply_to_message_id'] ?? null,
			'attachments'         => $files,
		));
	} catch (MessengerRefusal $e) {
		return LogicResult::error($e->getMessage());
	} catch (ConversationException $e) {
		return LogicResult::error($e->getMessage());
	}

	// Sending is the end of typing.
	MessengerTyping::set($conversation->key, $user_id, false);
	Messenger::markRead($conversation, $user_id);

	// A conversation with someone on another instance also has to leave this
	// one. The first attempt happens here so the sender sees a real tick rather
	// than a spinner; anything that does not go through is queued and retried.
	if ($conversation->is_federated()) {
		$message->set('msg_delivery_state', Message::DELIVERY_QUEUED);
		$message->save();
		MessengerFederation::sendMessage($conversation, $message);
		$message->load();
	}

	$payload = Messenger::messagesPayload(array($message), $user_id);

	return LogicResult::render(array(
		'conversation_id' => (int)$conversation->key,
		'message'         => $payload[0] ?? null,
	));
}

function messenger_send_logic_descriptor(): array {
	return array(
		'requires_session' => true,
		'requires_setting' => 'messenger_active',
		'description' => 'Send a message in a conversation (conversation_id, or to for a new/existing 1:1), optionally quoting a message and carrying uploaded attachments.',
		'input' => array(
			'conversation_id'     => array('type' => 'int',    'required' => false, 'label' => 'Conversation'),
			'to'                  => array('type' => 'int',    'required' => false, 'label' => 'Member to open a 1:1 with'),
			'body'                => array('type' => 'string', 'required' => false, 'label' => 'Message'),
			'reply_to_message_id' => array('type' => 'int',    'required' => false, 'label' => 'Message being quoted'),
			'attachment_ids'      => array('type' => 'array',  'required' => false, 'label' => 'Uploaded attachment ids',
				'items' => array('type' => 'int'), 'max_items' => 20),
		),
	);
}
?>
