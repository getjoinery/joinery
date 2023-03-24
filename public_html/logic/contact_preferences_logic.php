<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Globalvars.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/mailing_lists_class.php');
	
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

	$search_criteria = array('deleted' => false, 'active' => true);
	$mailing_lists = new MultiMailingList(
		$search_criteria,
		array('name'=>'ASC'));	
	$mailing_lists->load();

	$announce_message = NULL;
	if($_REQUEST){
		
		if (isset($_REQUEST['zone'])) {
			if ($_REQUEST['action'] == 'ocu') {
				// One click unsubscribe
				//IF WE DON'T HAVE A CONTACT TYPE, ASSUME IT'S AN UNSUBSCRIBE FROM NEWSLETTERS
				if($_REQUEST['mailing_list_id']){
					$mailing_list_id = $_REQUEST['mailing_list_id'];
					$mailing_list = new MailingList($mailing_list_id, TRUE);
				}
				else{
					throw new SystemDisplayableError('You must pass a mailing list to unsubscribe.');
					exit;
				}

				$mailing_list->remove_registrant($user->key);
				
				$announce_message = 'You have been unsubscribed from ' . $mailing_list->get('mlt_name') . ' emails.  If you unsubscribed by mistake, you can choose "Subscribe" below and press the "Submit" button.';


			} 
			else if ($_REQUEST['zone'] == 'optional') {

				//HANDLE THE USERS'S MAILING LISTS
				$messages = array();
				$thismessage = array();
				foreach ($mailing_lists as $mailing_list){
					if(empty($_POST['new_list_subscribes'])){
						$new_list_subscribes = array();
					}
					else{
						$new_list_subscribes = $_POST['new_list_subscribes'];
					}
					
					//IF IT IS A CHOICE AND SELECTED
					if(in_array($mailing_list->key, $_POST['new_list_subscribes'])){

						if($mailing_list->is_user_in_list($user->key)){
							//IF USER IS ALREADY SUBSCRIBED
							$thismessage['message_type'] = 'warn';
							$thismessage['message_title'] = 'Notice';
							$thismessage['message'] = 'You are already SUBSCRIBED to the following lists: ' . $mailing_list->get('mlt_name');
							$messages[] = $thismessage;
						}
						else{
							//IF USER IS NOT SUBSCRIBED
							$status = $mailing_list->add_registrant($user->key);
							if($status){
								$thismessage['message_type'] = 'success';
								$thismessage['message_title'] = 'Success';
								$thismessage['message'] = 'You are SUBSCRIBED to the following lists: ' . $mailing_list->get('mlt_name');
								$messages[] = $thismessage;
							}
							else{
								$thismessage['message_type'] = 'error';
								$thismessage['message_title'] = 'Error';
								$thismessage['message'] = 'There was an error adding you to the following lists: ' . $mailing_list->get('mlt_name');
								$messages[] = $thismessage;
							}
						}
					}
					else{
						//IF IT IS A CHOICE AND NOT SELECTED
						if($mailing_list->is_user_in_list($user->key)){
							//IF USER IS SUBSCRIBED
							$status = $mailing_list->remove_registrant($user->key);
							if($status){
								$thismessage['message_type'] = 'success';
								$thismessage['message_title'] = 'Success';
								$thismessage['message'] = 'You are UNSUBSCRIBED to the following lists: ' . $mailing_list->get('mlt_name');
								$messages[] = $thismessage;
							}
							else{
								$thismessage['message_type'] = 'error';
								$thismessage['message_title'] = 'Error';
								$thismessage['message'] = 'There was an error removing you from the following lists: ' . $mailing_list->get('mlt_name');
								$messages[] = $thismessage;
							}
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
	
	$tab_menus = array(
		'Edit Account' => '/profile/account_edit',
		'Change Password' => '/profile/password_edit',
		'Edit Address' => '/profile/address_edit',
		'Edit Phone Number' => '/profile/phone_numbers_edit',
		'Change Contact Preferences' => '/profile/contact_preferences',
	);
	
	$_REQUEST['menu_item'] = 'Change Contact Preferences';
?>
