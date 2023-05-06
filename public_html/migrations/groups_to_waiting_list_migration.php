<?php

	function groups_to_waiting_list_migration(){
		require_once( __DIR__ . '/../includes/Globalvars.php');	
		require_once( __DIR__ . '/../includes/ErrorHandler.php');
		require_once( __DIR__ . '/../includes/LibraryFunctions.php');
		require_once( __DIR__ . '/../includes/SessionControl.php');

		require_once( __DIR__ . '/../data/users_class.php');
		require_once( __DIR__ . '/../data/events_class.php');
		require_once( __DIR__ . '/../data/event_registrants_class.php');
		require_once( __DIR__ . '/../data/event_waiting_lists_class.php');

		$settings = Globalvars::get_instance();
		
		require_once( __DIR__ . '/../data/event_logs_class.php');
		
		$event_log = new EventLog(NULL);
		$event_log->set('evl_event', 'groups_to_waiting_list');
		$event_log->set('evl_usr_user_id', User::USER_SYSTEM);
		$event_log->save();
		$event_log->load();


		$session = SessionControl::get_instance();
		$settings = Globalvars::get_instance();
		
		$events = new MultiEvent();
		$events->load();	
		
		$entries = 0;
		foreach($events as $event){
			$group = $event->get_waiting_list_group();
			$group_members = $group->get_member_list();
			
			foreach($group_members as $group_member){
				$waiting_list = new WaitingList(NULL);
				$waiting_list->set('ewl_usr_user_id', $group_member->get('grm_foreign_key_id'));
				$waiting_list->set('ewl_evt_event_id', $event->key);
				$result = WaitingList::CheckIfExists($waiting_list->get('ewl_usr_user_id'), $waiting_list->get('ewl_evt_event_id'));
				if(!$result){
					$entries++;
					$waiting_list->save();
				}			
			}
		}

		$event_log->set('evl_was_success', 1);
		$event_log->set('evl_note', '');
		$event_log->save();		
		
		echo "Groups to waiting list migration complete. ".$entries." entries added\n<br>";
		return true;
	}

?>
