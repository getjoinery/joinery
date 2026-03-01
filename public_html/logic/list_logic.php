<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

require_once(PathHelper::getThemeFilePath('FormWriter.php', 'includes'));

function list_logic($get_vars, $post_vars, $mailing_list, $params){
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/mailing_lists_class.php'));

	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	
	if(!$settings->get_setting('mailing_lists_active')){
		header("HTTP/1.0 404 Not Found");
		echo 'This feature is turned off';
		exit();
	}

	
	if(!$mailing_list || !$mailing_list->get('mlt_is_active') || $mailing_list->get('mlt_visibility') == MailingList::VISIBILITY_PRIVATE){
		require_once(LibraryFunctions::display_404_page());				
	}
	

	$session = SessionControl::get_instance();
	$session->set_return();
	$page_vars['session'] = $session;

	
	if($_POST){
		
		if(!$session->get_user_id()){
			$formwriter = new FormWriter('form1');
			if(!$formwriter->honeypot_check($_POST)){
				LibraryFunctions::display_404_page();			
			}
			
			if(!$formwriter->antispam_question_check($_POST)){
				throw new SystemDisplayableError(
					'Please type the correct value into the anti-spam field.');			
			}		
			
			$captcha_success = $formwriter->captcha_check($_POST);
			if (!$captcha_success) {
				$errormsg = 'Sorry, '.strip_tags($_POST['usr_first_name']).' '.strip_tags($_POST['usr_last_name']).', you must click the CAPTCHA to submit the form.';
				throw new SystemDisplayableError($errormsg);	
			}	
		}
		
		
		//IF USER IS LOGGED IN, LOAD THEIR INFO...IF NOT SEE IF THERE IS EXISTING USER...IF NOT CREATE ONE
		if($session->get_user_id()){ 
			$user = new User($session->get_user_id(), TRUE);
		}
		else if(!$user = User::GetByEmail($_POST['usr_email'])){
			// Use email prefix as default name when name fields aren't provided (e.g. compact newsletter form)
			$email_prefix = explode('@', $_POST['usr_email'])[0];
			$data = array(
				'usr_first_name' => !empty($_POST['usr_first_name']) ? $_POST['usr_first_name'] : $email_prefix,
				'usr_last_name' => !empty($_POST['usr_last_name']) ? $_POST['usr_last_name'] : '(subscriber)',
				'usr_email' => $_POST['usr_email'],
				'usr_nickname' => $_POST['usr_nickname'] ?? '',
				'usr_timezone' => $_POST['usr_timezone'] ?? '',
				'password' => $_POST['usr_password'] ?? '',
				'send_emails' => false
			);
			$user = User::CreateNew($data);
		}
		
		$messages = [];
		if (isset($_POST['mlt_mailing_list_id_subscribe'])) {
			if ($mailing_list->is_user_in_list($user->key)) {
				$messages[] = [
					'message_type' => 'warn',
					'message_title' => 'Notice',
					'message' => 'You are already subscribed to ' . htmlspecialchars($mailing_list->get('mlt_name')),
				];
			} else {
				$status = $mailing_list->add_registrant($user->key);
				$messages[] = $status
					? ['message_type' => 'success', 'message_title' => 'Success', 'message' => 'You are now subscribed to ' . htmlspecialchars($mailing_list->get('mlt_name'))]
					: ['message_type' => 'error', 'message_title' => 'Error', 'message' => 'There was an error subscribing you to ' . htmlspecialchars($mailing_list->get('mlt_name'))];
			}
		} elseif (isset($_POST['mlt_mailing_list_id_unsubscribe'])) {
			if (!$mailing_list->is_user_in_list($user->key)) {
				$messages[] = [
					'message_type' => 'warn',
					'message_title' => 'Notice',
					'message' => 'You are not subscribed to ' . htmlspecialchars($mailing_list->get('mlt_name')),
				];
			} else {
				$status = $mailing_list->remove_registrant($user->key);
				$messages[] = $status
					? ['message_type' => 'success', 'message_title' => 'Success', 'message' => 'You have been unsubscribed from ' . htmlspecialchars($mailing_list->get('mlt_name'))]
					: ['message_type' => 'error', 'message_title' => 'Error', 'message' => 'There was an error unsubscribing you from ' . htmlspecialchars($mailing_list->get('mlt_name'))];
			}
		}
		$page_vars['messages'] = $messages;

	}
	
	
	$member_of_list = false;
	if($session->get_user_id()){
		$page_vars['member_of_list'] = $mailing_list->is_user_in_list($session->get_user_id());
	}	
	
	return LogicResult::render($page_vars);
}
?>