<?php
/**
 * API action: support_desk/widget_poll — the state of one answer in a
 * visitor's conversation (SupportWidget). Browser session, guests allowed.
 *
 * POST { thread_id, message_id }
 *
 * @version 0.1.0
 */
function widget_poll_logic(array $input): LogicResult {
	if ($limited = SupportApi::limit('widget_poll')) {
		return $limited;
	}
	try {
		return SupportApi::ok('widget_poll', SupportWidget::poll((int)($input['thread_id'] ?? 0), (int)($input['message_id'] ?? 0)), false);
	} catch (SupportRefusal $e) {
		return SupportApi::fail('widget_poll', $e);
	}
}

function widget_poll_logic_descriptor(): array {
	return array(
		'description'      => 'Poll one answer in a visitor\'s support conversation.',
		'requires_setting' => 'support_desk_widget_enabled',
		'input'            => array(
			'thread_id'  => array('type' => 'int', 'required' => true, 'label' => 'Conversation'),
			'message_id' => array('type' => 'int', 'required' => true, 'label' => 'Answer'),
		),
		'auth'             => array('allow_guest' => true, 'capability' => null, 'requires_browser_session' => true),
	);
}
