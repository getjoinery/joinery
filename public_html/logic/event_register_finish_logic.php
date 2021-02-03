<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');			
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');

	
	$session = SessionControl::get_instance();
	
	$skip_id_check = FALSE;
	if($user_id = $session->get_user_id()){ 
		if($_SESSION['permission'] > 7){
			//IF ADMIN JUST TAKE THE INFO AS IT COMES
			$skip_id_check = TRUE;
			if(!$user_id_reported = LibraryFunctions::fetch_variable('userid', NULL, False, '')){
				$user_id_reported = $user_id;
			}
			$user = new User($user_id_reported, TRUE);
		}
		else{
			$act_code = LibraryFunctions::fetch_variable('act_code', NULL, 0, '');
			if(Activation::checkTempCode($act_code, 2)){
				$user = Activation::ActivateUser($act_code);
				//Activation::deleteTempCode($act_code);
			}
			else{
				$user = new User($user_id, TRUE);
			}
		}
	}
	else{
		$user_id_reported = LibraryFunctions::fetch_variable('userid', NULL, TRUE, '');
		$act_code = LibraryFunctions::fetch_variable('act_code', NULL, 0, '');
		if(Activation::checkTempCode($act_code, 2)){
			$user = Activation::ActivateUser($act_code);
			if($user->key != $user_id_reported){
				//CANNOT GET HERE WITHOUT AN ACT CODE
				throw new SystemDisplayableError("Users do not match.  You cannot edit someone else's info.");
				exit();									
			}
			//Activation::deleteTempCode($act_code);
		}
		else{
			//CANNOT GET HERE WITHOUT AN ACT CODE
			throw new SystemDisplayableError("We need the activation code");
			exit();					
		}
		
	}
	
	//IF EVENT REGISTRANT IS AVAILABLE
	$evr_event_registrant_id = LibraryFunctions::fetch_variable('eventregistrantid', TRUE, 0, '');
	$event_registrant = new EventRegistrant($evr_event_registrant_id, TRUE);
	if(!$skip_id_check){
		if($user->key != $event_registrant->get('evr_usr_user_id')){
			//CANNOT GET HERE WITHOUT AN ACT CODE
			throw new SystemDisplayableError("Users do not match.  You cannot edit someone else's info.");
			exit();						
		}
	}
	
	$event = new Event($event_registrant->get('evr_evt_event_id'), TRUE);



if($_POST){
	
	if($_POST['usa_address1']){
		Address::CreateAddressFromForm($_POST, $user->key);
	}

	
	if($_POST['phn_phone_number']){
		PhoneNumber::CreateFromForm($_POST, $user->key, FALSE);
	}
	
	//FILL IN THE REGISTRANT INFO
	//$event_registrant->set('evr_recording_consent', TRUE);
	$event_registrant->set('evr_first_event', $_POST['evr_first_event']);
	$event_registrant->set('evr_other_events', $_POST['evr_other_events']);
	$event_registrant->set('evr_health_notes', $_POST['evr_health_notes']);
	$event_registrant->set('evr_extra_info_completed', TRUE);
	
	
	//$event_registrant->authenticate_write($session);
	$event_registrant->prepare();
	$event_registrant->save();
	
	//FILL IN THE USER INFO
	if($_POST['usr_nickname']){
		$user->set('usr_nickname', $_POST['usr_nickname']);
		$user->prepare();
		$user->save();
	}


	$msgtxt = 'Your information for this event has been updated.';
	$message = new DisplayMessage($msgtxt, '/\/profile\/profile.*/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, 'userbox', TRUE);
	$session->save_message($message);	
	//NOW REDIRECT
	if($_SESSION['permission']){
		header("Location: /profile");
		exit();
	}
	else{
		$message = '<h3>Thanks for submitting the extra info for this event.  We look forward to seeing you there!</h3>';	
	}

}

?>