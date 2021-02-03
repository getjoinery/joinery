<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/phone_number_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	$session->set_return();

	$m = LibraryFunctions::fetch_variable('m', NULL);
	$user = new User($session->get_user_id(), TRUE);	
	
	$phone_numbers = new MultiPhoneNumber(
		array('user_id' => $session->get_user_id(), 'deleted' => FALSE));
	$phone_numbers->load();		
	$numphonerecords = $phone_numbers->count_all();	

	$addresses = new MultiAddress(
		array('user_id' => $session->get_user_id(), 'deleted' => FALSE));
	$addresses->load();
	$numaddressrecords = $addresses->count_all();	

	$display_messages = $session->get_messages($_SERVER['REQUEST_URI']);	
?>