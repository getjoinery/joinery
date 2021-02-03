<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/phone_number_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	
	$phn_phone_number_id = LibraryFunctions::fetch_variable('phn_phone_number_id', NULL, 0, '');

	if($_POST){

		if($phn_phone_number_id){
			$phone_number = new PhoneNumber($phn_phone_number_id, TRUE);
			$phone_number->authenticate_write($session);
		}
		else{
			$phone_number = NULL;
		}
		
		$phone_number = PhoneNumber::CreateFromForm($_POST, $session->get_user_id(), $phone_number, FALSE);
		
		//NOW REDIRECT
		LibraryFunctions::redirect('/profile/account_edit?m=phone_edited#phones');
		exit();
	}
	else{

		$phone_number = new PhoneNumber($phn_phone_number_id);
		
		if($phn_phone_number_id) {
			$phone_number->load();
		}
	}

?>
