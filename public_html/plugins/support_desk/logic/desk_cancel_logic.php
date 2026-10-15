<?php
/**
 * API action: support_desk/desk_cancel — stop an answer being written.
 *
 * POST { site, thread_id, token, message_id }. Sets the running row's cancel
 * flag; the turn stops at its next step and keeps what it wrote.
 *
 * @version 0.1.0
 */
function desk_cancel_logic(array $input): LogicResult {
	if ($limited = SupportApi::rateLimit('desk_cancel')) {
		return $limited;
	}
	try {
		$thread = SupportApi::thread($input);
		$msg = new AiConversationMessage((int)($input['message_id'] ?? 0), TRUE);
		if (!$msg->key || (int)$msg->get('aim_aic_conversation_id') !== (int)$thread->get('sth_aic_conversation_id')) {
			throw new SupportRefusal('That answer is not in this thread.', 'no_message', 404);
		}
		if ((string)$msg->get('aim_status') === AiConversationMessage::STATUS_RUNNING) {
			AiConversationMessage::updateColumns((int)$msg->key, array('aim_cancel_requested' => true));
		}
		return SupportApi::ok('desk_cancel', array('cancelling' => true));
	} catch (SupportRefusal $e) {
		return SupportApi::fail('desk_cancel', $e);
	}
}

function desk_cancel_logic_descriptor(): array {
	return array(
		'description'      => 'Stop a support answer being written.',
		'requires_session' => false,
		'mutates'          => true,
		'input'            => SupportApi::siteInput() + array(
			'message_id' => array('type' => 'int', 'required' => true, 'label' => 'Answer'),
		),
	);
}
