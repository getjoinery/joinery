<?php
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/SessionControl.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'].'/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/address_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'].'/data/users_class.php');

	$session = SessionControl::get_instance();

	$numperpage = 30;
	$swaoffset = 0;
	$swasort = 'start_time';
	$swasdirection = 'ASC';
	$searchterm = LibraryFunctions::fetch_variable('searchterm', NULL, 0, '');
	$user_id = LibraryFunctions::fetch_variable('u', NULL, 0, '');
	
	$tab = array();
	$searches = array();
	$searches['deleted'] = FALSE;
	$searches['visibility'] = 1;
	if($_REQUEST['type'] == 'past'){
		$searches['past'] = TRUE;
		$tab['past'] = 'active';
	}
	else if($_REQUEST['type'] == 'selfpaced'){
		$searches['type'] = Event::TYPE_SELF_PACED_ONLINE;
		$searches['past'] = FALSE;
		$searches['status'] = 1;
		$tab['selfpaced'] = 'active';
	}
	else if($_REQUEST['type'] == 'retreats'){
		$searches['type'] = Event::TYPE_RETREAT;
		$searches['past'] = FALSE;
		$searches['status'] = 1;
		$tab['retreat'] = 'active';
	}
	else{
		$searches['type'] = Event::TYPE_LIVE_ONLINE;
		$searches['past'] = FALSE;
		$searches['status'] = 1;
		$swasdirection = 'DESC';
		$tab['liveonline'] = 'active';
	}
	

	$events = new MultiEvent(
		$searches,
		array($swasort=>$swasdirection),
		$numperpage,
		$swaoffset,
		'AND');
	$events->load();	
	$numeventsrecords = $events->count_all();	

  
?>

