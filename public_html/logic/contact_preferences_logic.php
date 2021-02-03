<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');

	$session = SessionControl::get_instance();
	
	if($_REQUEST['hash']){
		$user = new User($_REQUEST['user'], TRUE);
		
		if(!$_REQUEST['hash'] == $user->get('usr_authhash')){
			echo "Users don't match.  You cannot edit someone else's info.";
			exit;	
		}
	}
	else{
		$session->check_permission(0);
		$user = new User($session->get_user_id(), TRUE);
	}



	$email_type_to_name = array(
		User::NEWSLETTER => 'Newsletter',
	);
	
	/*
	$email_type_to_name = array(
		User::NEWSLETTER => 'Community tips',
		User::EMAIL_OFFERS => 'Special offers',
		User::EMAIL_UPDATES => 'Announcements',
		User::EMAIL_USER_FEEDBACK => 'Feedback Inquries',
	);
	*/

	$announce_message = NULL;
	if($_REQUEST){
		/*
		$options_to_values = array(
			'newsletter' => User::NEWSLETTER,
			'offers' => User::EMAIL_OFFERS,
			'updates' => User::EMAIL_UPDATES,
			'user_feedback' => User::EMAIL_USER_FEEDBACK,
		);
		*/
		$options_to_values = array(
			'newsletter' => User::NEWSLETTER,
		);
		
		
		if (isset($_REQUEST['zone'])) {
			if ($_REQUEST['zone'] == 'ocu') {
				// One click unsubscribe
				
				$user->unsubscribe_from_mailing_list();

				$announce_message = 'You have been unsubscribed from ' . $email_type_to_name[$options_to_values[$type_to_remove]] . ' emails.  If you unsubscribed by mistake, you can choose "Subscribe" below and press the "Submit" button.';
				/*
				if (isset($_REQUEST['type'])) {
					$type_to_remove = $_REQUEST['type'];
					if (array_key_exists($type_to_remove, $options_to_values)) {

						$user->set('usr_contact_preferences', $user->get('usr_contact_preferences') & (~$options_to_values[$type_to_remove]));
						$user->save();
						$announce_message = 'You have been unsubscribed from ' . $email_type_to_name[$options_to_values[$type_to_remove]] . ' emails.';
					}
				}
				*/
			} else if ($_REQUEST['zone'] == 'optional') {
				// Handle changes to the optional zone
				$new_prefs = 0;

				foreach($options_to_values as $option => $value) {
					if (isset($option) && $_POST[$option] == '1') {
						$new_prefs |= $value;
					}
				}

				
				if($new_prefs){
					$user->resubscribe_to_mailing_list();
					$announce_message = 'Your contact preferences have been updated.  You are now SUBSCRIBED.';
				}
				else{
					$user->unsubscribe_from_mailing_list();
					$announce_message = 'Your contact preferences have been updated. You are now UNSUBSCRIBED.';
				}
				
			}
		}
	}
?>
