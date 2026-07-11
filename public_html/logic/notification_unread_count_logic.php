<?php
/**
 * notification_unread_count — the signed-in owner's unread notification count.
 *
 * Purely read-only: it does not refresh the $_SESSION cache (the mark actions'
 * invalidation already forces a recompute on the next page render).
 *
 * @version 1.0.0
 */

function notification_unread_count_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('data/notifications_class.php'));

	$session = SessionControl::get_instance();
	$user_id = $session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}

	$unread = Notification::get_unread_count($user_id);
	return LogicResult::render(['unread_count' => $unread]);
}

function notification_unread_count_logic_descriptor(): array {
	return [
		'description' => 'Unread notification count for the signed-in owner.',
		'mutates'     => false,
		'auth'        => [
			'capability' => 'read',
		],
		'input'       => [],
	];
}
?>
