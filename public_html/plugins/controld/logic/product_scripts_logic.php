<?php
/* THIS FILE CONTAINS ALL SCRIPTS THAT ARE RUN UPON A PRODUCT PURCHASE
SET THE FUNCTION NAME WHEN CREATING THE PRODUCT  
ALL FUNCTIONS END WITH PRODUCT_SCRIPT
ALL FUNCTIONS MUST TAKE USER/PRODUCT/ORDER/ORDER_ITEM/CART  
*/

function controld_subscription_product_script($user, $product, $order, $order_item, $cart){
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');

	require_once('../data/ctlddevices_class.php');
	require_once('../data/ctldprofiles_class.php');
	
	
	require_once('../includes/ControlDHelper.php');





    $cd = new ControlDHelper();
	
	$data = array('name'=>'Testprofile6');
		$profile_key = '665222jfk3qk';
	$device_id = 'qbe2yv8az8';
	$org_id = '2116jfkhk4';
	print_r($cd->listSchedules());
	
	//$result = $cd->createProfile($data);
	
	//$result = $cd->createSchedule($name, $enforcing, $time_start, $time_end, $time_zone, $weekdays);
	$weekdays = array (1, 0, 0, 0, 0, 0, 0);
	$result = $cd->createSchedule($org_id, $device_id, $profile_id, 1, '15:00', '17:00', 'America/New_York', $weekdays);
	exit;

	//prevent_deactivation_pin
	$success = $result['success'];
	$profile_key = $result['body']['profiles'][0]['PK'];


	$data = array(
		'prevent_deactivation_pin' => '1234'
		);
	print_r($cd->modifyDevice($device_id, $data));

exit;	
	
	
	
	print_r($user);
	exit;
/*
	$session = SessionControl::get_instance();
	$page_vars['session'] = $session;

	$numperpage = 30;
	$swaoffset = 0;
	$swasort = 'start_time';
	$swasdirection = 'ASC';
	$searchterm = $get_vars['searchterm'];
	$user_id = $get_vars['u'];
	
	$searches = array();
	$searches['deleted'] = FALSE;
	$searches['visibility'] = 1;
	$swasdirection = 'DESC';	
	
	$settings = Globalvars::get_instance();
	if($settings->get_setting('events_label')){
		$page_vars['events_label'] = $settings->get_setting('events_label');
	}
	else{
		$page_vars['events_label'] = 'Events';
	}
	
	//SEE IF WE ARE ON A TAB
	if(!isset($get_vars['type']) || $get_vars['type'] == 'future'){
		//ASSUME WE'RE JUST LISTING FUTURE EVENTS
		$searches['past'] = FALSE;
		$searches['status'] = Event::STATUS_ACTIVE;
	}	
	else if($get_vars['type'] == 'past'){
		$searches['past'] = TRUE;		
	}
	else{
		$searches['past'] = FALSE;
		$searches['status'] = Event::STATUS_ACTIVE;
		if($get_vars['type']){
			$searches['type'] = (int)$get_vars['type'];
		}
		
	}

	$events = new MultiEvent(
		$searches,
		array($swasort=>$swasdirection),
		$numperpage,
		$swaoffset,
		'AND');
	$events->load();	
	$page_vars['events'] = $events;
	$numeventsrecords = $events->count_all();
	$page_vars['numeventsrecords'] = $numeventsrecords;	
	
	
	//GET ALL OF THE TYPES
	$event_types = new MultiEventType();
	$event_types->load();	
	//BUILD THE TAB MENU
	$tab_menus = array ('future' => 'Future '.$page_vars['events_label']);
	foreach ($event_types as $event_type){
		$tab_menus[$event_type->key] = $event_type->get('ety_name');
	}
	$tab_menus['past'] = 'Past '.$page_vars['events_label'];

	$page_vars['tab_menus'] = $tab_menus;
	return $page_vars;
	*/
}
?>

