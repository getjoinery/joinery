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

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'user_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');
	
	
	$search_criteria = array('deleted' => false);

	$items = new MultiUser(
		$search_criteria,
		array($sort=>$sdirection),
		$numperpage,
		$offset);		
	$items->load();

	$mailing_list = new MailingList(1, TRUE);

	foreach ($items as $item){
		
		if($item->get('usr_contact_preferences') == 1){
			try{
				$mailing_list->add_registrant($item->key);
				echo 'Subscribe '. $item->display_name(). '<br>';
			}
			catch (MailingListException $e){
				echo 'Already subscribed '. $item->display_name(). '<br>';
			}
			
		}
		else{
			echo 'Unsubscribe '. $item->display_name(). '<br>';
		}
		
		
	}


?>


