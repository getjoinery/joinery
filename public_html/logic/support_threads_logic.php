<?php
/**
 * API action: support_threads — this site's support threads and their state
 * at the desk, and with thread_id that thread's transcript (opening it clears
 * its unread mark). The Help button's unread mark comes from here.
 *
 * @version 1.0.0
 */
function support_threads_logic(array $input): LogicResult {
	if ($refused = SupportPanel::guard()) {
		return $refused;
	}
	$session = SessionControl::get_instance();
	try {
		return LogicResult::render(SupportChatClient::threads((int)$session->get_user_id(), (int)($input['thread_id'] ?? 0)));
	} catch (SupportChatException $e) {
		return SupportPanel::fail($e);
	}
}

function support_threads_logic_descriptor(): array {
	return array(
		'description' => 'This site\'s support threads; with thread_id, its transcript.',
		'input'       => array(
			'thread_id' => array('type' => 'int', 'required' => false, 'label' => 'Thread'),
		),
		'auth'        => array('capability' => null, 'requires_browser_session' => true, 'min_user_permission' => 5),
	);
}
