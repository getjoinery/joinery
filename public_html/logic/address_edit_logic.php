<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SystemClass.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');
	
	$settings = Globalvars::get_instance();
	$site_template = $settings->get_setting('site_template');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/PublicPageTW.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/theme/'.$site_template.'/includes/FormWriterPublicTW.php');		

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/address_class.php');
	
	$session = SessionControl::get_instance();
	$session->check_permission(0);

	//$new_address = FALSE;

	if (isset($_REQUEST['usa_address_id'])) {
		$address_id = $_REQUEST['usa_address_id'];

		if ($address_id == FALSE) {
			throw new SystemInvalidFormError('The form is invalid.');
		}

		$address = new Address($address_id, TRUE);
		$address->authenticate_write($session);
	} 
	else {
		/*
		$new_address = TRUE;
		$address = new Address(NULL);

		$user_addresses = new MultiAddress(array('user_id' => $session->get_user_id(), 'deleted' => FALSE));

		if ($user_addresses->count_all()) {
			$address->set('usa_is_default', FALSE);
		} else {
			$address->set('usa_is_default', TRUE);
		}

		$address->set('usa_usr_user_id', $session->get_user_id());
		//$address->set('usa_is_bad', FALSE);
		*/
	}


	if($_POST){

		/*
		if (!$new_address && $session->get_permission() == 0) {
			if ($address->get('usa_address_is_verified')) {
				$errorhandler = new ErrorHandler();
				$errorhandler->handle_general_error(
					'Sorry, you cannot edit a verified address.');
			}

			if (!$address->get('usa_is_bad')) {
				$errorhandler = new ErrorHandler();
				$errorhandler->handle_general_error(
					'Sorry, you cannot edit a mappable and valid address.  If you need to enter a new address, you can <a href="/profile/users_addrs_deleted?a=' . LibraryFunctions::encode($address->key) . '">delete this one</a> and <a href="/profile/addresses_edit">add a new one</a>.');
			}
		}
		*/

		$address = Address::CreateAddressFromForm($_POST, $session->get_user_id(), $address);
		
		
		if(!$address){
			
			$msgtxt = 'Could not save the address: <br /><br /><strong>' . $e->getMessage() . '</strong>';
			$message = new DisplayMessage($msgtxt, '/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "addressbox", TRUE);
			$session->save_message($message);	

			LibraryFunctions::redirect('/profile/account_edit?#addresses');
			exit;
		}
	
		$msgtxt = 'Addresses have been edited.';
		$message = new DisplayMessage($msgtxt, '/\/profile\/account_edit.*/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "addressbox", TRUE);
		$session->save_message($message);	
		LibraryFunctions::redirect('/profile/account_edit?#addresses');
		exit;



	} 

	$tab_menus = array(
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
	);
	
	$_REQUEST['menu_item'] = 'Edit Address';

?>
