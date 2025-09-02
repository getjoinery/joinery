<?php
require_once(__DIR__ . '/../includes/PathHelper.php');

function address_edit_logic($get_vars, $post_vars){
	PathHelper::requireOnce('includes/SessionControl.php');
	// ErrorHandler.php no longer needed - using new ErrorManager system
	PathHelper::requireOnce('includes/SystemBase.php');
	PathHelper::requireOnce('includes/LibraryFunctions.php');

	
	PathHelper::requireOnce('data/address_class.php');
	
	$session = SessionControl::get_instance();
	$session->check_permission(0);

	//$new_address = FALSE;


	if(!empty($post_vars)){
		$address_id = $_POST['usa_address_id'];
		$page_vars['usa_address_id'] = $address_id;

		if ($address_id) {
			$address = new Address($address_id, TRUE);
			$address->authenticate_write(array('current_user_id'=>$session->get_user_id(), 'current_user_permission'=>$session->get_permission()));
		}
		
		
		
		// Address editing restrictions removed - handled by new validation system

		$address = Address::CreateAddressFromForm($post_vars, $session->get_user_id(), $address);
		
		
		if(!$address){
			$msgtxt = 'Could not save the address: <br /><br /><strong>' . $e->getMessage() . '</strong>';
			$message = new DisplayMessage($msgtxt, 'Address error', '/\/profile\/address_edit.*/', DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "addressbox", TRUE);
			$session->save_message($message);	
		}
		else{
			$msgtxt = 'Addresses have been edited.'; 
			$message = new DisplayMessage($msgtxt, 'Success', '/\/profile\/address_edit.*/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "addressbox", TRUE);
			$session->save_message($message);	
		}



	} 
	
	$user = new User($session->get_user_id(), TRUE);
	$addresses = new MultiAddress(
		array('user_id'=>$user->key),
		NULL,
		30,
		0);
	$numaddressrecords = $addresses->count_all();
	$addresses->load();
	

	$page_vars['tab_menus'] = array(
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
	);

	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);
			
	if($numaddressrecords){
		$page_vars['address'] = $addresses->get(0);
	}
	else{
		$page_vars['address'] = new Address(NULL);
	}
	return $page_vars;
}
?>
