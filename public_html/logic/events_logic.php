<?php

function events_logic($get_vars, $post_vars){
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/event_types_class.php');

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
	$tab_menus = array ('future' => 'Future Events');
	foreach ($event_types as $event_type){
		$tab_menus[$event_type->key] = $event_type->get('ety_name');
	}
	$tab_menus['past'] = 'Past Events';

	$page_vars['tab_menus'] = $tab_menus;
	return $page_vars;
}
?>

