<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/ErrorHandler.php');
	
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	PathHelper::requireOnce('data/users_class.php');
	PathHelper::requireOnce('data/event_logs_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(9);	
	
	$usr_user_id = LibraryFunctions::fetch_variable('usr_user_id', NULL, 1, 'You must provide a user to edit.');
	$log_type = LibraryFunctions::fetch_variable('log_type', NULL, 1, 'You must provide a log_type.');	
	if($log_type != EventLog::SURVEY_COMPLETED && $log_type != EventLog::WEB_LINK_ADDED_1 && $log_type != EventLog::WEB_LINK_ADDED_2 ) {
		$errortext = 'Bad log type: ' . $log_type;
		$errorhandler = new ErrorHandler();
		$errorhandler->handle_general_error($errortext);		
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
	$formwriter = LibraryFunctions::get_formwriter_object('form1', 'admin');
	echo $formwriter->begin_form("form", "post", "/admin/admin_log_event");

	echo '<fieldset><h4>Confirm</h4>';
		echo '<div class="fields full">';
		echo '<p>You are logging the following event ('.EventLog::$event_descriptions[$log_type].') for user: '.$user->display_name() . '.</p>';

		echo $formwriter->hiddeninput("confirm", 1);
		echo $formwriter->hiddeninput("usr_user_id", $usr_user_id);
		echo $formwriter->hiddeninput("log_type", $log_type);
		
		echo $formwriter->start_buttons();
		echo $formwriter->new_form_button('Submit');
		echo $formwriter->end_buttons();

		echo '</div>';
	echo '</fieldset>';
	echo $formwriter->end_form();
	$page->admin_footer();
}
?>
