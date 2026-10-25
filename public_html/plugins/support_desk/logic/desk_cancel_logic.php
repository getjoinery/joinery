<?php
/**
 * API action: support_desk/desk_cancel — stop an answer being written.
 *
 * POST { site, thread_id, token, message_id }. Sets the running row's cancel
 * flag; the turn stops at its next step and keeps what it wrote.
 *
 * @version 0.1.1
 * @changelog 0.1.1 - the cancel flag is written under rule_checked_write()
 */
function desk_cancel_logic(array $input): LogicResult {
	if ($limited = SupportApi::limit('desk_cancel')) {
		return $limited;
	}
	try {
		$thread = SupportApi::thread($input);
		$msg = SupportIntake::answerIn($thread, (int)($input['message_id'] ?? 0));
		if ((string)$msg->get('aim_status') === AiConversationMessage::STATUS_RUNNING) {
			// The thread's token (SupportApi::thread()) and the answer's place
			// in it (answerIn()) are proven above; nobody is signed in.
			SystemBase::rule_checked_write(function () use ($msg) {
				AiConversationMessage::updateColumns((int)$msg->key, array('aim_cancel_requested' => true));
			});
		}
		return SupportApi::ok('desk_cancel', array('cancelling' => true));
	} catch (SupportRefusal $e) {
		return SupportApi::fail('desk_cancel', $e);
	}
}

function desk_cancel_logic_descriptor(): array {
	return array(
		'auth' => array('min_user_permission' => 0),
		'description'      => 'Stop a support answer being written.',
		'requires_session' => false,
		'mutates'          => true,
		'input'            => SupportApi::siteInput() + array(
			'message_id' => array('type' => 'int', 'required' => true, 'label' => 'Answer'),
		),
	);
}
