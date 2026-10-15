<?php
/**
 * API action: support_escalate — "Talk to a person". The thread waits on a
 * person at the desk; their reply comes back in the thread.
 *
 * @version 1.0.0
 */
function support_escalate_logic(array $input): LogicResult {
	if ($refused = SupportPanel::guard()) {
		return $refused;
	}
	try {
		return LogicResult::render(SupportChatClient::about('desk_escalate', (int)($input['thread_id'] ?? 0), array(
			'note' => (string)($input['note'] ?? ''),
		)));
	} catch (SupportChatException $e) {
		return SupportPanel::fail($e);
	}
}

function support_escalate_logic_descriptor(): array {
	return array(
		'description' => 'Hand a support thread to a person at the support desk.',
		'mutates'     => true,
		'input'       => array(
			'thread_id' => array('type' => 'int', 'required' => true, 'label' => 'Thread'),
			'note'      => array('type' => 'text', 'required' => false, 'max_length' => 1000, 'label' => 'Note'),
		),
		'auth'        => array('requires_browser_session' => true, 'min_user_permission' => 5),
	);
}
