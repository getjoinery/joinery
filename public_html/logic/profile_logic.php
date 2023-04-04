<?php

function profile_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/Activation.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/phone_number_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/messages_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_sessions_class.php');
	
	$page_vars = array();
	
	//require_once($_SERVER['DOCUMENT_ROOT'].'/includes/stripe-php/init.php');
	$settings = Globalvars::get_instance(); 
	$page_vars['settings'] = $settings;
	$composer_dir = $settings->get_setting('composerAutoLoad');	
	require_once $composer_dir.'autoload.php';
	
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
	include($_SERVER['DOCUMENT_ROOT'] . '/utils/registrant_maintenance.php');
	

	$event_registrants = new MultiEventRegistrant(array('user_id' => $user->key), array('event_id'=> 'DESC'));
	$num_events = $event_registrants->count_all();
	$event_registrants->load();
	$page_vars['num_events'] = $num_events;
	$page_vars['event_registrants'] = $event_registrants;
	
	//COMPATIBILITY WITH OLD TEMPLATE
	
	$event_registrants_future = new MultiEventRegistrant(array('user_id' => $user->key, 'past' => false), array('event_id'=> 'DESC'));
	$num_future_events = $event_registrants_future->count_all();
	$event_registrants_future->load();
	$page_vars['event_registrants_future'] = $event_registrants_future;

	$event_registrants_past = new MultiEventRegistrant(array('user_id' => $user->key, 'past' => true), array('event_id'=> 'DESC'));
	$num_past_events = $event_registrants_future->count_all();
	$event_registrants_past->load();
	$page_vars['event_registrants_past'] = $event_registrants_past;
	
	//END COMPATIBILITY WITH OLD TEMPLATE

		
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
	array('user_id_recipient' => $user->key), //SEARCH CRITERIA
	array('message_id'=>'DESC'),  // SORT, SORT DIRECTION
	5, //NUMBER PER PAGE
	NULL //OFFSET
	);
	$messages->load();	
	$page_vars['messages'] = $messages;
	
	//SUBSCRIPTIONS
	$subscriptions = new MultiOrderItem(
	array('user_id' => $user->key, 'is_subscription' => true), //SEARCH CRITERIA
	array('order_item_id' => 'DESC'),  // SORT, SORT DIRECTION
	5, //NUMBER PER PAGE
	NULL //OFFSET
	);
	$subscriptions->load();	
	$page_vars['subscriptions'] = $subscriptions;
	
	
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
	
	return $page_vars;
}
	
?>
