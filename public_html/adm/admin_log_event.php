<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/event_logs_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(9);

	$usr_user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL, 1, 'You must provide a user to edit.');
	$log_type = LibraryFunctions::fetch_variable('log_type', NULL, 1, 'You must provide a log_type.');
	if($log_type != EventLog::SURVEY_COMPLETED && $log_type != EventLog::WEB_LINK_ADDED_1 && $log_type != EventLog::WEB_LINK_ADDED_2 ) {
		require_once(__DIR__ . '/../includes/Exceptions/ValidationException.php');
		throw new ValidationException('Bad log type: ' . $log_type);
	}

if ($_POST){
	$confirm = LibraryFunctions::fetch_variable('confirm', NULL, 1, 'You must confirm the action.');

	if ($confirm) {
		$log = new EventLog(NULL);
		try {
			$log->set('evl_event', $log_type);
			$log->set('evl_usr_user_id', $usr_user_id);
			$log->save();

		} catch (TTClassException $e) {
			error_log($e->getMessage());
		}
	}

	//NOW REDIRECT
	$session = SessionControl::get_instance();
	$returnurl = $session->get_return();
	header("Location: $returnurl");
	exit();

}
else{

	$user = new User($usr_user_id, TRUE);

	$page = new AdminPage();
	$page->admin_header(10);

	echo '<h1>Event Log</h1>';
	$formwriter = $page->getFormWriter('form1');
	$formwriter->begin_form('form', 'POST', '/admin/admin_log_event');

	echo '<fieldset><h4>Confirm</h4>';
	echo '<div class="fields full">';
	echo '<p>You are logging the following event ('.EventLog::$event_descriptions[$log_type].') for user: '.$user->display_name() . '.</p>';

	$formwriter->hiddeninput('confirm', '', ['value' => 1]);
	$formwriter->hiddeninput('usr_user_id', '', ['value' => $usr_user_id]);
	$formwriter->hiddeninput('log_type', '', ['value' => $log_type]);

	$formwriter->submitbutton('btn_submit', 'Submit');

	echo '</div>';
	echo '</fieldset>';
	$formwriter->end_form();
	$page->admin_footer();
}
?>
