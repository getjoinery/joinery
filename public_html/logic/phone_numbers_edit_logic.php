<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function phone_numbers_edit_logic($get_vars, $post_vars){
	PathHelper::requireOnce('includes/Activation.php');
	PathHelper::requireOnce('includes/ErrorHandler.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');
	PathHelper::requireOnce('includes/SessionControl.php');

	PathHelper::requireOnce('data/phone_number_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(0);
	
	$phn_phone_number_id = LibraryFunctions::fetch_variable('phn_phone_number_id', NULL, 0, '');

	if($post_vars){

		if($post_vars['phn_phone_number_id']){
			$phone_number = new PhoneNumber($post_vars['phn_phone_number_id'], TRUE);
			$phone_number->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		}
		else{
			$phone_number = NULL;
		}
		
		$phone_number = PhoneNumber::CreateFromForm($post_vars, $session->get_user_id(), $phone_number, FALSE);
		
		if($phone_number){
			$msgtxt = 'Addresses have been edited.';
			$message = new DisplayMessage($msgtxt, 'Success', '/\/profile\/phone_numbers_edit.*/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "phonebox", TRUE);
			$session->save_message($message);	
		}
	}


	$user = new User($session->get_user_id(), TRUE);
	$phone_numbers = new MultiPhoneNumber(
		array('user_id'=>$user->key),
		NULL,
		30,
		0);
	$numphone = $phone_numbers->count_all();
	$phone_numbers->load();
	if($numphone){
		$page_vars['phone_number'] = $phone_numbers->get(0);
	}
	else{
		$page_vars['phone_number'] = new PhoneNumber(NULL);
	}


	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);

	$page_vars['tab_menus'] = array(
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
	);

	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);
		

	return $page_vars;
}
?>
