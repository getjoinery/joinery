<?php
function events_logic($get_vars, $post_vars){
require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/event_types_class.php'));

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

	// Expand recurring events for future/active listings; plain query for past
	$use_recurring = (!isset($get_vars['type']) || $get_vars['type'] == 'future');
	if ($use_recurring) {
		$all_events = MultiEvent::getWithRepeatingEvents($searches, null, $numperpage);
	} else {
		$searches['exclude_recurring_parents'] = true;
		$events = new MultiEvent(
			$searches,
			array($swasort=>$swasdirection),
			$numperpage,
			$swaoffset,
			'AND');
		$events->load();
		$all_events = iterator_to_array($events);
	}

	$page_vars['events'] = $all_events;
	$numeventsrecords = count($all_events);
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
	return LogicResult::render($page_vars);
}
?>

