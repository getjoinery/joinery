<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/mailing_lists_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/mailing_list_registrants_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(10);
	$session->set_return();
	
	$search_criteria = array('deleted' => false);

	$items = new MultiUser(
		$search_criteria,
		);		
	$items->load();

	

	foreach ($items as $item){
		
		if($item->get('usr_contact_preferences') == 1){
			if(!MailingListRegistrant::CheckIfExists($user->key, 1)){
				$mlr = new MailingListRegistrant(NULL);
				$mlr->set('mlr_usr_user_id', $this->key);
				$mlr->set('mlr_mlt_mailing_list_id', 1);
				$mlr->set('mlr_change_time', $this->get('usr_contact_preference_last_changed'));
				$mlr->set('mlr_delete_time', NULL);
				$mlr->prepare();
				$mlr->save();	
				echo 'Subscribe '. $item->key. '<br>';				
			}
		}
		else{
			echo 'Unsubscribe '. $item->key. '<br>';
		}
		
		
	}


?>


