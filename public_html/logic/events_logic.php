<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/event_types_class.php');

	$session = SessionControl::get_instance();

	$numperpage = 30;
	$swaoffset = 0;
	$swasort = 'start_time';
	$swasdirection = 'ASC';
	$searchterm = LibraryFunctions::fetch_variable('searchterm', NULL, 0, '');
	$user_id = LibraryFunctions::fetch_variable('u', NULL, 0, '');
	
	$searches = array();
	$searches['deleted'] = FALSE;
	$searches['visibility'] = 1;
	$swasdirection = 'DESC';	
	
	//SEE IF WE ARE ON A TAB
	if(!isset($_REQUEST['type']) || $_REQUEST['type'] == 'future'){
		//ASSUME WE'RE JUST LISTING FUTURE EVENTS
		$searches['past'] = FALSE;
		$searches['status'] = Event::STATUS_ACTIVE;
	}	
	else if($_REQUEST['type'] == 'past'){
		$searches['past'] = TRUE;		
	}
	else{
		$searches['past'] = FALSE;
		if(is_int($searches['type'])){
			$searches['type'] = $_REQUEST['type'];
		}
		else{
			$searches['past'] = FALSE;
		}
		$searches['status'] = Event::STATUS_ACTIVE;
	}

	$events = new MultiEvent(
		$searches,
		array($swasort=>$swasdirection),
		$numperpage,
		$swaoffset,
		'AND');
	$events->load();	
	$numeventsrecords = $events->count_all();		
	
	
	//GET ALL OF THE TYPES
	$event_types = new MultiEventType();
	$event_types->load();	
	//BUILD THE TAB MENU
	$tab_menus = array ('future' => 'Future Events');
	foreach ($event_types as $event_type){
		$tab_menus[$event_type->key] = $event_type->get('ety_name');
	}
	$tab_menus['past'] = 'Past Events';

  
?>

