<?php
/**
 * push_test — send a test notification to this browser, so a member can see
 * push works. Sent now, not at the end of the request, so the answer can say
 * what the push service said.
 *
 * @version 1.0.0
 */

function push_test_logic(array $input): LogicResult {
	$user_id = (int)SessionControl::get_instance()->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}

	$destination = PushSender::destination_for($user_id, trim((string)($input['endpoint'] ?? '')));
	if ($destination === null) {
		return LogicResult::error('Push is not turned on in this browser.');
	}

	$outcomes = PushSender::send(array($user_id), array(
		'title' => (string)Globalvars::get_instance()->get_setting('site_name'),
		'body'  => 'Push notifications work in this browser.',
		'link'  => '/profile/notification_preferences',
		'tag'   => 'push.test',
	), (int)$destination->key);
	$outcome = $outcomes[(int)$destination->key] ?? array('result' => PushDriver::RESULT_FAILED, 'reason' => 'nothing was sent');

	return LogicResult::render(array(
		'sent'   => $outcome['result'] === PushDriver::RESULT_SENT,
		'result' => $outcome['result'],
		'reason' => $outcome['reason'],
	));
}

function push_test_logic_descriptor(): array {
	return [
		'description' => 'Send a test push notification to this browser.',
		'mutates'     => true,
		'auth'        => [
			'requires_browser_session' => true,
		],
		'input'       => [
			'endpoint' => ['type' => 'string', 'required' => true, 'max_length' => 1024, 'label' => 'Push subscription endpoint'],
		],
	];
}
