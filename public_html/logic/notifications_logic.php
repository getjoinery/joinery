<?php
/**
 * Notifications list logic
 *
 * @version 1.0
 */

function notifications_logic($get_vars, $post_vars) {
	$page_vars = array();
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('data/notifications_class.php'));

	$session = SessionControl::get_instance();
	if (!$session->is_logged_in()) {
		return LogicResult::redirect('/login');
	}

	$numperpage = 20;
	$page_offset = isset($get_vars['offset']) ? (int)$get_vars['offset'] : 0;

	$criteria = array('user_id' => $session->get_user_id(), 'deleted' => false);
	$notifications = new MultiNotification(
		$criteria,
		array('ntf_create_time' => 'DESC'),
		$numperpage,
		$page_offset
	);
	$numrecords = $notifications->count_all();
	$notifications->load();

	$page_vars['notifications'] = $notifications;
	$page_vars['title'] = 'Notifications';
	$page_vars['numrecords'] = $numrecords;
	$page_vars['pager'] = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage));

	return LogicResult::render($page_vars);
}
