<?php
/**
 * push_unsubscribe — turn push off in this browser: retire its subscription,
 * if it is registered to the signed-in member.
 *
 * @version 1.0.0
 */

function push_unsubscribe_logic(array $input): LogicResult {
	$user_id = (int)SessionControl::get_instance()->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}

	$destination = PushSender::destination_for($user_id, trim((string)($input['endpoint'] ?? '')));
	$removed = $destination ? PushSender::retire(array((int)$destination->key), 'turned off in this browser') : 0;

	return LogicResult::render(array('removed' => $removed));
}

function push_unsubscribe_logic_descriptor(): array {
	return [
		'description' => 'Turn off push notifications in this browser.',
		'mutates'     => true,
		'auth'        => [
			'requires_browser_session' => true,
		],
		'input'       => [
			'endpoint' => ['type' => 'string', 'required' => true, 'max_length' => 1024, 'label' => 'Push subscription endpoint'],
		],
	];
}
