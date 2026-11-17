<?php
/**
 * API action: support_desk/widget_escalate — a signed-in member hands their
 * widget conversation to a person (SupportWidget::escalate). The person's
 * reply comes back in the conversation and by email
 * (specs/hosting_after_purchase.md H1).
 *
 * POST { thread_id }
 *
 * @version 0.1.0
 */
function widget_escalate_logic(array $input): LogicResult {
	try {
		return SupportApi::ok('widget_escalate', SupportWidget::escalate((int)($input['thread_id'] ?? 0)));
	} catch (SupportRefusal $e) {
		return SupportApi::fail('widget_escalate', $e);
	}
}

function widget_escalate_logic_descriptor(): array {
	return array(
		'description'      => 'Hand a support conversation from the public pages to a person.',
		'mutates'          => true,
		'requires_setting' => 'support_desk_widget_enabled',
		'input'            => array(
			'thread_id' => array('type' => 'int', 'required' => true, 'label' => 'Conversation'),
		),
		'auth'             => array('min_user_permission' => 0, 'requires_browser_session' => true),
	);
}
