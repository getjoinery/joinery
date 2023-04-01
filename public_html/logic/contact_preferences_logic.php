<?php

function contact_preferences_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/mailing_lists_class.php');
	
	$session = SessionControl::get_instance();
	
	if($get_vars['hash']){
		$user = new User($get_vars['user'], TRUE);
		
		if(!$get_vars['hash'] == $user->get('usr_authhash')){
			echo "Users don't match.  You cannot edit someone else's info.";
			exit;	
		}
	}
	else{
		$session->check_permission(0);
		$user = new User($session->get_user_id(), TRUE);
	}
	
	

	$search_criteria = array('deleted' => false, 'active' => true);
	$mailing_lists = new MultiMailingList(
		$search_criteria,
		array('name'=>'ASC'));	
	$mailing_lists->load();

	if (isset($get_vars['zone']) && $get_vars['action'] == 'ocu') {
		// One click unsubscribe
		//IF WE DON'T HAVE A CONTACT TYPE, ASSUME IT'S AN UNSUBSCRIBE FROM NEWSLETTERS
		if($get_vars['mailing_list_id']){
			$mailing_list_id = $get_vars['mailing_list_id'];
			$mailing_list = new MailingList($mailing_list_id, TRUE);
		}
		else{
			throw new SystemDisplayableError('You must pass a mailing list to unsubscribe.');
			exit;
		}

		$mailing_list->remove_registrant($user->key);
		
		$msgtxt = 'You have been unsubscribed from ' . $mailing_list->get('mlt_name') . ' emails.  If you unsubscribed by mistake, you can choose "Subscribe" below and press the "Submit" button.';
		$message = new DisplayMessage($msgtxt, 'Success', '/\/profile\/contact_preferences.*/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "contactbox", TRUE);
		$session->save_message($message);	
	}
	
	
	if($post_vars){
		
		if ($post_vars['zone'] == 'optional') {

			//HANDLE THE USERS'S MAILING LISTS

			foreach ($mailing_lists as $mailing_list){
				if(empty($post_vars['new_list_subscribes'])){
					$new_list_subscribes = array();
				}
				else{
					$new_list_subscribes = $post_vars['new_list_subscribes'];
				}
				
				//IF IT IS A CHOICE AND SELECTED
				if(in_array($mailing_list->key, $post_vars['new_list_subscribes'])){

					if($mailing_list->is_user_in_list($user->key)){
						//IF USER IS ALREADY SUBSCRIBED
						$msgtxt = 'You are already SUBSCRIBED to the following lists: ' . $mailing_list->get('mlt_name');
						$message = new DisplayMessage($msgtxt, 'Notice', '/\/profile\/contact_preferences.*/', DisplayMessage::MESSAGE_WARNING, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "contactbox", TRUE);
						$session->save_message($message);
					}
					else{
						//IF USER IS NOT SUBSCRIBED
						$status = $mailing_list->add_registrant($user->key);
						if($status){
							$msgtxt = 'You are SUBSCRIBED to the following lists: ' . $mailing_list->get('mlt_name');
							$message = new DisplayMessage($msgtxt, 'Success', '/\/profile\/contact_preferences.*/', DisplayMessage::MESSAGE_ANNOUNCEMENT, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "contactbox", TRUE);
							$session->save_message($message);
						}
						else{
							$msgtxt = 'There was an error adding you to the following lists: ' . $mailing_list->get('mlt_name');
							$message = new DisplayMessage($msgtxt, 'Error', '/\/profile\/contact_preferences.*/', DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "contactbox", TRUE);
							$session->save_message($message);
						}
					}
				}
				else{
					//IF IT IS A CHOICE AND NOT SELECTED
					if($mailing_list->is_user_in_list($user->key)){
						//IF USER IS SUBSCRIBED
						$status = $mailing_list->remove_registrant($user->key);
						if($status){
							$msgtxt =  'You are UNSUBSCRIBED from the following lists: ' . $mailing_list->get('mlt_name');
							$message = new DisplayMessage($msgtxt, 'Success', '/\/profile\/contact_preferences.*/', DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "contactbox", TRUE);
							$session->save_message($message);							
						}
						else{
							$msgtxt =  'There was an error removing you from the following lists: ' . $mailing_list->get('mlt_name');
							$message = new DisplayMessage($msgtxt, 'Error', '/\/profile\/contact_preferences.*/', DisplayMessage::MESSAGE_ERROR, DisplayMessage::MESSAGE_DISPLAY_IN_PAGE, "contactbox", TRUE);
							$session->save_message($message);
						}
					}	
				}				
			}

		}
	}


	$user_subscribed_list = array();
	$search_criteria = array('deleted' => false, 'user_id' => $user->key);
	$user_lists = new MultiMailingListRegistrant(
		$search_criteria);	
	$user_lists->load();
	foreach ($user_lists as $user_list){
		$user_subscribed_list[] = $user_list->get('mlr_mlt_mailing_list_id');
	}
	
	$page_vars['optionvals'] = $mailing_lists->get_dropdown_array();	
	//REMOVE ALL OF THE PRIVATE AND UNLISTED LISTS THE USER IS NOT SUBSCRIBED TO
	foreach($page_vars['optionvals'] as $key=>$value){
		$mailing_list = new MailingList($value, TRUE);
		if($mailing_list->get('mlt_visibility') == MailingList::VISIBILITY_PRIVATE || $mailing_list->get('mlt_visibility') == MailingList::VISIBILITY_PUBLIC_UNLISTED){
			if(!in_array($value, $user_subscribed_list)){
				unset($page_vars['optionvals'][$key]);
			}
		}
	}

	$page_vars['checkedvals'] = $user_subscribed_list;
	$page_vars['readonlyvals'] = array(); //DEFAULT
	$page_vars['disabledvals'] = array();

	

	
	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);
	
	$page_vars['tab_menus'] = array(
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
	);
	
	return $page_vars;
}
?>
