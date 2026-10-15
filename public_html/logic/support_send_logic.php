<?php
/**
 * API action: support_send — ask the support desk, from the Help panel.
 *
 * POST { message, thread_id? (this site's thread), route? }. The site's
 * server sends it on (SupportChatClient::ask): the first message of a thread
 * with this site's facts when it allowed them, and a one-time code the desk
 * calls back to confirm. Answers {thread_id, message_id, status, state};
 * poll support_poll while status is running.
 *
 * @version 1.0.0
 */
function support_send_logic(array $input): LogicResult {
	if ($refused = SupportPanel::guard()) {
		return $refused;
	}
	$message = trim((string)($input['message'] ?? ''));
	if ($message === '') {
		return LogicResult::error('Ask a question.');
	}
	if (mb_strlen($message) > 8000) {
		return LogicResult::error('Keep a question under 8,000 characters.');
	}
	try {
		return LogicResult::render(SupportChatClient::ask(SessionControl::get_instance(), (int)($input['thread_id'] ?? 0),
			$message, (string)($input['route'] ?? '')));
	} catch (SupportChatException $e) {
		return SupportPanel::fail($e);
	}
}

function support_send_logic_descriptor(): array {
	return array(
		'description' => 'Ask the support desk a question from the Help panel.',
		'mutates'     => true,
		'input'       => array(
			'message'   => array('type' => 'text', 'required' => true, 'max_length' => 8000, 'label' => 'Question'),
			'thread_id' => array('type' => 'int', 'required' => false, 'label' => 'Thread'),
			'route'     => array('type' => 'text', 'required' => false, 'max_length' => 500, 'label' => 'Page or setup step'),
		),
		'auth'        => array('requires_browser_session' => true, 'min_user_permission' => 5),
	);
}
