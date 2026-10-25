<?php
/**
 * API action: support_desk/widget_ask — a visitor's question on this node's
 * public pages (SupportWidget). Browser session, guests allowed; the thread's
 * token stays in the session. The first question of a session passes the
 * captcha. Off unless support_desk_widget_enabled.
 *
 * POST { message, thread_id?, h-captcha-response? | g-recaptcha-response? }
 *
 * @version 0.1.0
 */
function widget_ask_logic(array $input): LogicResult {
	if ($limited = SupportApi::limit('widget_ask', empty($input['thread_id']))) {
		return $limited;
	}
	try {
		return SupportApi::ok('widget_ask', SupportWidget::ask($input));
	} catch (SupportRefusal $e) {
		return SupportApi::fail('widget_ask', $e);
	}
}

function widget_ask_logic_descriptor(): array {
	return array(
		'description'      => 'Ask the support desk from the public pages, with no site.',
		'mutates'          => true,
		'requires_setting' => 'support_desk_widget_enabled',
		'input'            => array(
			'message'   => array('type' => 'text', 'required' => true, 'max_length' => 8000, 'label' => 'Question'),
			'thread_id' => array('type' => 'int', 'required' => false, 'label' => 'Conversation'),
		),
		'auth'             => array('min_user_permission' => 0, 'allow_guest' => true, 'session_write' => true, 'requires_browser_session' => true),
	);
}
