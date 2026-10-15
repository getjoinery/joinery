<?php
/**
 * API action: support_desk/desk_feedback — thumbs up or down on an answer.
 *
 * POST { site, thread_id, token, message_id, verdict: up|down, note? }.
 * Stored on the message; a thumbs-down lands the turn on the gap report.
 *
 * @version 0.1.0
 */
function desk_feedback_logic(array $input): LogicResult {
	if ($limited = SupportApi::rateLimit('desk_feedback')) {
		return $limited;
	}
	try {
		$thread = SupportApi::thread($input);
		$verdict = (string)($input['verdict'] ?? '');
		if (!in_array($verdict, array('up', 'down'), true)) {
			throw new SupportRefusal('Say up or down.', 'bad_verdict');
		}
		$msg = new AiConversationMessage((int)($input['message_id'] ?? 0), TRUE);
		if (!$msg->key || (int)$msg->get('aim_aic_conversation_id') !== (int)$thread->get('sth_aic_conversation_id')
				|| $msg->get('aim_role') !== AiConversationMessage::ROLE_ASSISTANT) {
			throw new SupportRefusal('That answer is not in this thread.', 'no_message', 404);
		}
		$note = trim((string)($input['note'] ?? ''));
		AiConversationMessage::updateColumns((int)$msg->key, array(
			'aim_feedback'      => $verdict,
			'aim_feedback_note' => $note !== '' ? mb_substr($note, 0, 1000) : null,
		));
		return SupportApi::ok('desk_feedback', array('saved' => true));
	} catch (SupportRefusal $e) {
		return SupportApi::fail('desk_feedback', $e);
	}
}

function desk_feedback_logic_descriptor(): array {
	return array(
		'description'      => 'Thumbs up or down on a support answer, with an optional line.',
		'requires_session' => false,
		'mutates'          => true,
		'input'            => SupportApi::siteInput() + array(
			'message_id' => array('type' => 'int', 'required' => true, 'label' => 'Answer'),
			'verdict'    => array('type' => 'text', 'required' => true, 'max_length' => 8, 'label' => 'up or down'),
			'note'       => array('type' => 'text', 'required' => false, 'max_length' => 1000, 'label' => 'Note'),
		),
	);
}
