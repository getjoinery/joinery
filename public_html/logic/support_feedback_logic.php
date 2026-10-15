<?php
/**
 * API action: support_feedback — Helpful or Not helpful on an answer, with an
 * optional line. Sent on to the desk's desk_feedback with the thread's token.
 *
 * @version 1.0.0
 */
function support_feedback_logic(array $input): LogicResult {
	if ($refused = SupportPanel::guard()) {
		return $refused;
	}
	try {
		return LogicResult::render(SupportChatClient::about('desk_feedback', (int)($input['thread_id'] ?? 0), array(
			'message_id' => (int)($input['message_id'] ?? 0),
			'verdict'    => (string)($input['verdict'] ?? ''),
			'note'       => (string)($input['note'] ?? ''),
		)));
	} catch (SupportChatException $e) {
		return SupportPanel::fail($e);
	}
}

function support_feedback_logic_descriptor(): array {
	return array(
		'description' => 'Say whether a support answer helped.',
		'mutates'     => true,
		'input'       => array(
			'thread_id'  => array('type' => 'int', 'required' => true, 'label' => 'Thread'),
			'message_id' => array('type' => 'int', 'required' => true, 'label' => 'Answer'),
			'verdict'    => array('type' => 'text', 'required' => true, 'max_length' => 8, 'label' => 'up or down'),
			'note'       => array('type' => 'text', 'required' => false, 'max_length' => 1000, 'label' => 'Note'),
		),
		'auth'        => array('requires_browser_session' => true, 'min_user_permission' => 5),
	);
}
