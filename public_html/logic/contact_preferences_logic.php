<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/contact_types_class.php');
	
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


	$announce_message = NULL;
	if($_REQUEST){
		
		if (isset($_REQUEST['zone'])) {
			if ($_REQUEST['zone'] == 'ocu') {
				// One click unsubscribe
				//IF WE DON'T HAVE A CONTACT TYPE, ASSUME IT'S AN UNSUBSCRIBE FROM NEWSLETTERS
				if($_REQUEST['contact_type_id']){
					$contact_type_id = $_REQUEST['contact_type_id'];
				}
				else{
					$contact_type_id = User::NEWSLETTER;
				}
				$user->unsubscribe_from_contact_type($contact_type);
				
				$announce_message = 'You have been unsubscribed from ' . ContactType::ToReadable($contact_type_id) . ' emails.  If you unsubscribed by mistake, you can choose "Subscribe" below and press the "Submit" button.';

			} 
			else if ($_REQUEST['zone'] == 'optional') {

				//GET THE OLD UNSUBSCRIBES
				$old_unsubscribes = $user->get_contact_type_unsubscribes();

				//COMPARE WITH THE NEW UNSUBSCRIBES
				if(empty($_REQUEST['unsubscribes_list'])){
					$new_unsubscribes = array();
				}
				else{
					$new_unsubscribes = $_REQUEST['unsubscribes_list'];
				}
				

				$change_to_subscribe = array_diff($old_unsubscribes, $new_unsubscribes);
				
				$change_to_unsubscribe = array_diff($new_unsubscribes, $old_unsubscribes);

				$announce_message = 'Your contact preferences have been updated. ';
				$subscribed_readable = array();
				foreach ($change_to_subscribe as $contact_type_id){
					$user->subscribe_to_contact_type($contact_type_id);
					$subscribed_readable[] = ContactType::ToReadable($contact_type_id);
				}
				if(!empty($subscribed_readable)){
					$announce_message = 'You are now SUBSCRIBED to the following content: '. implode(', ', $subscribed_readable) .'. ';
				}
				
				
				foreach ($change_to_unsubscribe as $contact_type_id){
					$user->unsubscribe_from_contact_type($contact_type_id);
				}
				
				//FOR THE READOUT, LETS SHOW WHAT IS ACTUALLY THE CASE INSTEAD OF WHAT CHANGED
				$unsubscribed_readable = array();
				foreach ($new_unsubscribes as $contact_type_id){
					$unsubscribed_readable[] = ContactType::ToReadable($contact_type_id);
				}
				
				
				if(!empty($unsubscribed_readable)){
					$announce_message = 'You are now UNSUBSCRIBED from the following content: '. implode(', ', $unsubscribed_readable) .'. ';
				}					
				
			}
		}
	}
	
	$tab_menus = array(
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
	);
	
	$_REQUEST['menu_item'] = 'Change Contact Preferences';
?>
