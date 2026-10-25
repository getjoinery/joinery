<?php
/**
 * API action: support_desk/desk_poll — the state of one answer.
 *
 * POST { site, thread_id, token, message_id } → {status, state, partial_text
 * and activity while running | message when finished | error when failed}.
 *
 * @version 0.1.0
 */
function desk_poll_logic(array $input): LogicResult {
	if ($limited = SupportApi::limit('desk_poll')) {
		return $limited;
	}
	try {
		$thread = SupportApi::thread($input);
		return SupportApi::ok('desk_poll', SupportIntake::poll($thread, (int)($input['message_id'] ?? 0)), false);
	} catch (SupportRefusal $e) {
		return SupportApi::fail('desk_poll', $e);
	}
}

function desk_poll_logic_descriptor(): array {
	return array(
		'auth' => array('min_user_permission' => 0),
		'description'      => 'Poll one support answer: partial text while it is written, the answer when done.',
		'requires_session' => false,
		'input'            => SupportApi::siteInput() + array(
			'message_id' => array('type' => 'int', 'required' => true, 'label' => 'Answer'),
		),
	);
}
