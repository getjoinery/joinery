<?php
/**
 * push_subscribe — register this browser's push subscription to the signed-in
 * member. The browser is given a device cookie, so signing out on it retires
 * the subscription.
 *
 * @version 1.0.0
 */

function push_subscribe_logic(array $input): LogicResult {
	$user_id = (int)SessionControl::get_instance()->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}
	if (WebPushDriver::public_key() === null) {
		return LogicResult::error('This site cannot send push notifications yet.');
	}

	try {
		PushSender::register($user_id, PushDestination::KIND_WEB, array(
			'endpoint'   => (string)($input['endpoint'] ?? ''),
			'p256dh'     => (string)($input['p256dh'] ?? ''),
			'auth'       => (string)($input['auth'] ?? ''),
			'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
		), PushSender::issue_device_token());
	} catch (InvalidArgumentException $e) {
		return LogicResult::error($e->getMessage());
	}

	return LogicResult::render(array('registered' => true));
}

function push_subscribe_logic_descriptor(): array {
	return [
		'description' => "Turn on push notifications in this browser: register its push subscription to you.",
		'mutates'     => true,
		'auth'        => [
			'min_user_permission' => 0,
			'requires_browser_session' => true,
		],
		'input'       => [
			'endpoint' => ['type' => 'string', 'required' => true, 'max_length' => 1024, 'label' => 'Push subscription endpoint'],
			'p256dh'   => ['type' => 'string', 'required' => true, 'max_length' => 128, 'label' => "The subscription's p256dh key"],
			'auth'     => ['type' => 'string', 'required' => true, 'max_length' => 64, 'label' => "The subscription's auth secret"],
		],
	];
}
