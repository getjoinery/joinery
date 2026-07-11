<?php
/**
 * notification_mark_read — mark a single notification read (owner only).
 *
 * Mutating; session_write so the $_SESSION unread-count invalidation persists
 * past the API's early session-lock release (the web unread badge recomputes on
 * the next page render, PublicPageBase).
 *
 * @version 1.0.0
 */

function notification_mark_read_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('data/notifications_class.php'));

	$session = SessionControl::get_instance();
	$user_id = $session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}

	$ntf_id = isset($input['notification_id']) ? (int) $input['notification_id'] : 0;
	if (!$ntf_id) {
		return LogicResult::error('No notification ID');
	}

	try {
		$ntf = new Notification($ntf_id, TRUE);
	} catch (Exception $e) {
		return LogicResult::error('Notification not found');
	}

	if ($ntf->get('ntf_usr_user_id') != $user_id) {
		return LogicResult::error('Permission denied');
	}

	$ntf->set('ntf_is_read', true);
	$ntf->set('ntf_read_time', gmdate('Y-m-d H:i:s'));
	$ntf->save();
	$_SESSION['notification_unread_count'] = null;

	return LogicResult::render(['marked' => true]);
}

function notification_mark_read_logic_descriptor(): array {
	return [
		'description' => 'Mark a single notification read (owner only).',
		'mutates'     => true,
		'auth'        => [
			'session_write' => true,
		],
		'input'       => [
			'notification_id' => ['type' => 'int', 'required' => true, 'label' => 'Notification ID'],
		],
	];
}
?>
