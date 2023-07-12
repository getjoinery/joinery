<?php
	require_once( __DIR__ . '/../includes/Globalvars.php');	
	require_once( __DIR__ . '/../includes/ErrorHandler.php');
	require_once( __DIR__ . '/../includes/LibraryFunctions.php');
	require_once( __DIR__ . '/../includes/SessionControl.php');
	require_once( __DIR__ . '/../includes/StripeHelper.php');

	require_once( __DIR__ . '/../data/users_class.php');
	require_once( __DIR__ . '/../data/events_class.php');
	require_once( __DIR__ . '/../data/event_registrants_class.php');
	require_once( __DIR__ . '/../data/event_sessions_class.php');
	
	require_once( __DIR__ . '/../data/event_logs_class.php');
	
	$stripe_helper = new StripeHelper();
	
	$event_log = new EventLog(NULL);
	$event_log->set('evl_event', 'event_registrant_maintenance');
	$event_log->set('evl_usr_user_id', User::USER_SYSTEM);
	$event_log->save();
	$event_log->load();


	$session = SessionControl::get_instance();
	$settings = Globalvars::get_instance();
	

	
	//PERFORM MAINTENANCE ON THE EVENTS AND ORDERS	
	$event_registrants = new MultiEventRegistrant(array('user_id' => $user->key), NULL);
	$event_registrants->load();
	foreach($event_registrants as $event_registrant){
		
		//NOTE: THESE WERE REMOVED BECUASE THE USER SHOULD GET WHAT HE/SHE BOUGHT ORIGINALLY
		
		//REMOVE THE USER FROM ANY EVENTS ATTACHED TO BUNDLES WHERE THE BUNDLE NO LONGER CONTAINS THAT EVENT
		/*
		if($event_registrant->get('evr_grp_group_id')){
			$group = new Group($event_registrant->get('evr_grp_group_id'), TRUE);
			if(!$group->is_member_in_group($event_registrant->get('evr_evt_event_id'))){
				$event_registrant->remove();
			}
		}
		*/

		//ADD THE USER TO ANY EVENTS ATTACHED TO BUNDLES RECENTLY
		/*
		if($event_registrant->get('evr_grp_group_id')){
			$group = new Group($event_registrant->get('evr_grp_group_id'), TRUE);
			$event = new Event($event_registrant->get('evr_evt_event_id'), TRUE);
			$group_members = $group->get_member_list();
			foreach ($group_members as $group_member){
				//ADD THE USER TO THE EVENT
				$event_registrant = $event->add_registrant($user->key, NULL, $event_registrant->get('evr_grp_group_id'), NULL);
				
				//TODO: THE RECORDING CONSENT BOX
											
			}
		}
		*/
		
		//SET EXPIRED USER ANY EVENTS WHERE THE SUBSCRIPTION IS NO LONGER ACTIVE
		if($event_registrant->get('evr_odi_order_item_id')){
			$order_item = new OrderItem($event_registrant->get('evr_odi_order_item_id'), TRUE);
			if($order_item->get('odi_is_subscription')){
				$result = $stripe_helper->update_subscription_in_order_item($order_item);
			}
		}
	
	}

	$event_log->set('evl_was_success', 1);
	$event_log->set('evl_note', '');
	$event_log->save();		

?>
