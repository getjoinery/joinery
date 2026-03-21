<?php

function profile_logic($get_vars, $post_vars){
	require_once(__DIR__ . '/../includes/PathHelper.php');
	require_once(PathHelper::getIncludePath('includes/Activation.php'));
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));

	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('includes/SessionControl.php'));

	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/address_class.php'));
	require_once(PathHelper::getIncludePath('data/phone_number_class.php'));
	require_once(PathHelper::getIncludePath('data/files_class.php'));
	require_once(PathHelper::getIncludePath('data/messages_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
	require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));

	$page_vars = array();

	//require_once(PathHelper::getIncludePath('includes/stripe-php/init.php'));
	$settings = Globalvars::get_instance();
	$page_vars['settings'] = $settings;
	require_once(PathHelper::getComposerAutoloadPath());

	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;
	$session->check_permission(0);
	$session->set_return();

	//CHECK FOR AN ACTIVATION CODE AND ACTIVATE
	if($get_vars['act_code']){
		if($user_id = $session->get_user_id()){
			$activated_user = Activation::ActivateUser($get_vars['act_code'], $user_id);
		}
		else{
			$activated_user = Activation::ActivateUser($get_vars['act_code']);
		}
	}

	$user = new User($session->get_user_id(), TRUE);
	$page_vars['user'] = $user;
	include(PathHelper::getAbsolutePath('utils/registrant_maintenance.php'));

	$event_registrants = new MultiEventRegistrant(
		array(
		'user_id' => $user->key,
		'deleted' => false
		),
		array('evt_event_id'=> 'DESC')
	);
	$num_events = $event_registrants->count_all();
	$event_registrants->load();
	$page_vars['num_events'] = $num_events;
	$page_vars['event_registrants'] = $event_registrants;

	$page_vars['event_registrations'] =	array();
	foreach($event_registrants as $event_registrant){
		$event = new Event($event_registrant->get('evr_evt_event_id'), TRUE);
		if(!$event || $event->get('evt_delete_time')){
			continue;
		}
		$next_session = $event->get_next_session();

		$time = '';
		$tz = $event->get('evt_timezone');
		if($next_session){
			$time = '<b>Next session: ';

			if($event->get('evt_timezone') != $page_vars['session']->get_timezone()){
				$time .= $next_session->get_time_string($page_vars['session']->get_timezone());
			}
			else{
				$time .= $next_session->get_time_string($tz);
			}
			$time .= '</b>';
		}
		else if($event->get('evt_status') != 2 && $event->get('evt_status') != 3){

			if($event->get('evt_timezone') != $page_vars['session']->get_timezone()){
				$time .= $event->get_time_string($page_vars['session']->get_timezone());
			}
			else{
				$time .= $event->get_time_string($tz);
			}
		}

		$tevent = array();

		$tevent['event_time'] = $time;

		$tevent['calendar_links'] = array();
		if($event->get('evt_status') != 2 && $event->get('evt_status') != 3){
			$tevent['calendar_links'] = $event->get_add_to_calendar_links();
		}

		$tevent['event_name'] = $event->get('evt_name');
		$tevent['event_expires'] = '';
		 '';

		if($event->get('evt_session_display_type')==2){
			$tevent['event_link'] = '/profile/event_sessions_course?evt_event_id='.$event->key;
		}
		else{
			$tevent['event_link'] = '/profile/event_sessions?evt_event_id='.$event->key;
		}

		if($event_registrant->get('evr_expires_time') && $event_registrant->get('evr_expires_time') < date("Y-m-d H:i:s")){
			$tevent['event_status'] = 'Expired';
		}
		else{
			if($event->get('evt_status') == Event::STATUS_ACTIVE){
				if($event_registrant->get('evr_expires_time')){
					$tevent['event_status'] = 'Active';
					$tevent['event_expires'] = LibraryFunctions::convert_time($event_registrant->get('evr_expires_time'), 'UTC', $page_vars['session']->get_timezone());
				}
				else{
					$tevent['event_status'] = 'Active';
				}
			}
			else if($event->get('evt_status') == Event::STATUS_CANCELED){
				$tevent['event_status'] = 'Canceled';
			}
			else if($event->get('evt_status') == Event::STATUS_COMPLETED){
				$tevent['event_status'] = 'Completed';
			}
		}
		$page_vars['event_registrations'][] = $tevent;

	}

	$phone_numbers = new MultiPhoneNumber(
		array('user_id' => $session->get_user_id(), 'deleted' => FALSE));
	$num_phone_numbers = $phone_numbers->count_all();
	if($num_phone_numbers){
		$phone_numbers->load();
		$phone_number = $phone_numbers->get(0);

	}
	else{
		$phone_number = new PhoneNumber(NULL);
	}
	$page_vars['phone_number'] = $phone_number;

	//ORDERS
	$numperpage = 5;
	$conoffset = LibraryFunctions::fetch_variable('conoffset', 0, 0, '');
	$consort = LibraryFunctions::fetch_variable('consort', 'ord_order_id', 0, '');
	$consdirection = LibraryFunctions::fetch_variable('consdirection', 'DESC', 0, '');
	$search_criteria = NULL;

	$search_criteria = array();
	$search_criteria['user_id'] = $session->get_user_id();
	$search_criteria['deleted'] = false;

	$orders = new MultiOrder(
		$search_criteria,
		array($consort=>$consdirection),
		$numperpage,
		$conoffset);
	$numorders = $orders->count_all();
	$orders->load();
	$page_vars['numorders'] = $numorders;
	$page_vars['orders'] = $orders;

	$addresses = new MultiAddress(
		array('user_id' => $session->get_user_id(), 'deleted' => FALSE));

	$num_addresses = $addresses->count_all();
	if($num_addresses){
		$addresses->load();
		$address = $addresses->get(0);
	}
	else{
		$address = new Address(NULL);
	}
	$page_vars['num_addresses'] = $num_addresses;
	$page_vars['address'] = $address;

	//MESSAGES
	$messages = new MultiMessage(
	array('user_id_recipient' => $user->key, 'deleted' => false), //SEARCH CRITERIA
	array('message_id'=>'DESC'),  // SORT, SORT DIRECTION
	5, //NUMBER PER PAGE
	NULL //OFFSET
	);
	$messages->load();
	$page_vars['messages'] = $messages;

	//SUBSCRIPTIONS
	if($page_vars['settings']->get_setting('products_active') && $page_vars['settings']->get_setting('subscriptions_active')){
		$subscriptions = new MultiOrderItem(
		array('user_id' => $user->key, 'is_subscription' => true), //SEARCH CRITERIA
		array('order_item_id' => 'DESC'),  // SORT, SORT DIRECTION
		5, //NUMBER PER PAGE
		NULL //OFFSET
		);
		$subscriptions->load();
		$page_vars['subscriptions'] = $subscriptions;
	}
	else{
		$page_vars['subscriptions'] = NULL;
	}

	$user_subscribed_list = array();
	$search_criteria = array('deleted' => false, 'user_id' => $user->key);
	$user_lists = new MultiMailingListRegistrant(
		$search_criteria);
	$user_lists->load();

	foreach ($user_lists as $user_list){
		$mailing_list = new MailingList($user_list->get('mlr_mlt_mailing_list_id'), TRUE);
		$user_subscribed_list[] = $mailing_list->get('mlt_name');
	}

	$page_vars['user_subscribed_list'] = $user_subscribed_list;

	$page_vars['display_messages'] = $session->get_messages($_SERVER['REQUEST_URI']);

	// Pending surveys: find event registrations with incomplete surveys
	$pending_surveys = array();
	if ($session->get_user_id()) {
		require_once(PathHelper::getIncludePath('data/events_class.php'));
		$user_registrations = new MultiEventRegistrant(
			array('user_id' => $session->get_user_id(), 'deleted' => false),
			array('evr_create_time' => 'DESC')
		);
		$user_registrations->load();
		foreach ($user_registrations as $reg) {
			if ($reg->get('evr_survey_completed')) continue;
			$event = new Event($reg->get('evr_evt_event_id'), TRUE);
			$display = $event->get('evt_survey_display');
			if (!$event->get('evt_svy_survey_id')) continue;
			if ($display === 'optional_at_confirmation' || $display === 'after_event') {
				// For after_event, only show if event has ended
				if ($display === 'after_event') {
					$now_utc = gmdate('Y-m-d H:i:s');
					$end_time = $event->get('evt_end_time') ?: $event->get('evt_start_time');
					if ($end_time > $now_utc) continue;
				}
				$pending_surveys[] = array(
					'survey_id' => $event->get('evt_svy_survey_id'),
					'event_id' => $event->key,
					'event_name' => $event->get('evt_name'),
				);
			}
		}
	}
	$page_vars['pending_surveys'] = $pending_surveys;

	return LogicResult::render($page_vars);
}

?>
