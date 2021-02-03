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
	
	$phone_numbers_verified = new MultiPhoneNumber(
		array('user_id' => $session->get_user_id(), 'deleted' => FALSE, 'verified' => TRUE));
	//$numphoneverified = $phone_numbers_verified->count_all();		
	
	$phone_numbers = new MultiPhoneNumber(
		array('user_id' => $session->get_user_id(), 'deleted' => FALSE));
	$phone_numbers->load();		
	//$numphonerecords = $phone_numbers->count_all();	

	$addresses = new MultiAddress(
		array('user_id' => $session->get_user_id(), 'deleted' => FALSE));
	$addresses->load();
	//$numaddressrecords = $addresses->count_all();	

	//$addresses_verified = new MultiAddress(
	//	array('user_id' => $session->get_user_id(), 'deleted' => FALSE, 'verified' => TRUE));
	//$numaddressverified = $addresses_verified->count_all();	
	
	
	//MESSAGES
	$messages = new MultiMessage(
	array('user_id_recipient' => $user->key), //SEARCH CRITERIA
	array('message_id'=>'DESC'),  // SORT, SORT DIRECTION
	10, //NUMBER PER PAGE
	NULL //OFFSET
	);
	$messages->load();	

	

?>
