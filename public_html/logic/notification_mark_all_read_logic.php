<?php
/**
 * notification_mark_all_read — mark all the owner's unread notifications read.
 *
 * Mutating; session_write so the $_SESSION unread-count invalidation persists
 * (the web unread badge recomputes on the next page render).
 *
 * @version 1.0.0
 */

function notification_mark_all_read_logic(array $input): LogicResult {
	$session = SessionControl::get_instance();
	$user_id = $session->get_user_id();
	if (!$user_id) {
		return LogicResult::error('Sign in required.');
	}

	$dblink = DbConnector::get_instance()->get_db_link();
	$sql = "UPDATE ntf_notifications SET ntf_is_read = true, ntf_read_time = NOW()
			WHERE ntf_usr_user_id = ? AND ntf_is_read = false AND ntf_delete_time IS NULL";
	$q = $dblink->prepare($sql);
	$q->execute([$user_id]);
	$_SESSION['notification_unread_count'] = null;

	return LogicResult::render(['updated' => $q->rowCount()]);
}

function notification_mark_all_read_logic_descriptor(): array {
	return [
		'description' => 'Mark all the signed-in owner\'s unread notifications read.',
		'mutates'     => true,
		'auth'        => [
			'session_write' => true,
		],
		'input'       => [],
	];
}
?>
