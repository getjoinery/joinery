<?php
/**
 * push_config — what a page needs to turn push on in this browser: whether the
 * site can send, its public key, and whether this browser's subscription is
 * already registered to the signed-in member.
 *
 * @version 1.0.0
 */

function push_config_logic(array $input): LogicResult {
	$user_id = (int)SessionControl::get_instance()->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}

	$public_key = WebPushDriver::public_key();
	$endpoint = trim((string)($input['endpoint'] ?? ''));
	$registered = ($endpoint !== '' && PushSender::destination_for($user_id, $endpoint) !== null);

	return LogicResult::render(array(
		'available'  => $public_key !== null,
		'public_key' => $public_key,
		'registered' => $registered,
	));
}

function push_config_logic_descriptor(): array {
	return [
		'description' => "Whether this site can send browser push, its public key, and whether this browser's subscription is registered to you.",
		'mutates'     => false,
		'auth'        => [
			'min_user_permission' => 0,
			'capability'               => 'read',
			'requires_browser_session' => true,
		],
		'input'       => [
			'endpoint' => ['type' => 'string', 'required' => false, 'max_length' => 1024, 'label' => "This browser's push subscription endpoint"],
		],
	];
}
