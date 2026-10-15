<?php
/**
 * API action: support_poll — Poll one answer while it is written.
 *
 * POST { thread_id (this site's thread), message_id }. Sent on to the desk's
 * desk_poll with the thread's token.
 *
 * @version 1.0.0
 */
function support_poll_logic(array $input): LogicResult {
	if ($refused = SupportPanel::guard()) {
		return $refused;
	}
	try {
		return LogicResult::render(SupportChatClient::about('desk_poll', (int)($input['thread_id'] ?? 0),
			array('message_id' => (int)($input['message_id'] ?? 0))));
	} catch (SupportChatException $e) {
		return SupportPanel::fail($e);
	}
}

function support_poll_logic_descriptor(): array {
	return array(
		'description' => 'Poll one answer while it is written.',
		'mutates'     => false,
		'input'       => array(
			'thread_id'  => array('type' => 'int', 'required' => true, 'label' => 'Thread'),
			'message_id' => array('type' => 'int', 'required' => true, 'label' => 'Answer'),
		),
		'auth'        => array('requires_browser_session' => true, 'min_user_permission' => 5),
	);
}
