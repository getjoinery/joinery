<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/phone_number_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/messages_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_sessions_class.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/stripe-php/init.php');

	$session = SessionControl::get_instance();
	
	//CHECK FOR AN ACTIVATION CODE AND ACTIVATE
	if($_GET['act_code']){
		if($user_id = $session->get_user_id()){
			$activated_user = Activation::ActivateUser($_GET['act_code'], $user_id);
		}
		else{
			$activated_user = Activation::ActivateUser($_GET['act_code']);
		}
	}

	
	$session->check_permission(0);
	$session->set_return();
	
	
	$user = new User($session->get_user_id(), TRUE);		
	
	$phone_numbers = new MultiPhoneNumber(
		array('user_id' => $session->get_user_id(), 'deleted' => FALSE));
	$phone_numbers->load();	
	/*
	$num_phone_numbers = $phone_numbers->count_all();
	if($num_phone_numbers){
		$phone_number = $phone_numbers->get(0);	
	}
	else{
		$phone_number = new PhoneNumber(NULL);
	}
	*/

	$addresses = new MultiAddress(
		array('user_id' => $session->get_user_id(), 'deleted' => FALSE));
	$addresses->load();
	/*
	$num_addresses = $addresses->count_all();
	if($num_addresses){
		$address = $addresses->get(0);	
	}
	else{
		$address = new Address(NULL);
	}
	*/
	
	
	//MESSAGES
	$messages = new MultiMessage(
	array('user_id_recipient' => $user->key), //SEARCH CRITERIA
	array('message_id'=>'DESC'),  // SORT, SORT DIRECTION
	10, //NUMBER PER PAGE
	NULL //OFFSET
	);
	$messages->load();	
	
	
	$settings = Globalvars::get_instance();
	if($settings->get_setting('events_active')){
		//REMOVE USER FROM ANY EVENTS THAT ARE EXPIRED
		$event_registrants = new MultiEventRegistrant(array('user_id' => $user->key), NULL);
		$event_registrants->load();
		foreach($event_registrants as $event_registrant){
			if($event_registrant->get('evr_expires_time') && $event_registrant->get('evr_expires_time') < date("Y-m-d H:i:s")){
				$event_registrant->remove();
				//REFRESH THE PAGE
				LibraryFunctions::Redirect($_SERVER['REQUEST_URI']); 
			}
		}			
	}

	

?>
