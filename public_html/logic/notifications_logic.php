<?php
/**
 * Notifications list logic
 *
 * @version 1.1 - sign-in through check_permission(0), so the navigation gates apply
 * @version 1.0
 */

function notifications_logic(array $input): LogicResult {
	$page_vars = array();
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/Pager.php'));
	require_once(PathHelper::getIncludePath('data/notifications_class.php'));

	$session = SessionControl::get_instance();
	// check_permission, not a bare is_logged_in(): it is what applies the
	// navigation gates (terms, a forced password change, the setup interrupt,
	// the second-factor gates), and it sends a signed-out visitor to /login
	// with this page kept as the return.
	$session->check_permission(0);

	$numperpage = 20;
	$page_offset = isset($input['offset']) ? (int)$input['offset'] : 0;

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
