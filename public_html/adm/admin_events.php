<?php
	
	// ErrorHandler.php no longer needed - using new ErrorManager system
	PathHelper::requireOnce('includes/AdminPage.php');
	
	PathHelper::requireOnce('data/users_class.php');
	PathHelper::requireOnce('data/events_class.php');
	PathHelper::requireOnce('data/event_registrants_class.php');
	PathHelper::requireOnce('data/event_sessions_class.php');
	PathHelper::requireOnce('data/event_waiting_lists_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();	

	$searches = array();
	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'start_time', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '');

	//ONLY SHOW DELETED TO SUPER ADMINS
	if($_SESSION['permission'] < 10){
		$searches['deleted'] = false;
	}

	if($searchterm) {
		if(is_numeric($searchterm)) {
			$searches['event_id'] = $searchterm;
		}
		else {
			$searches['name_like'] = $searchterm;
		}
	}

	if($_REQUEST['filter'] == 'all'){
		$breadcrumb_array = array('Events'=>'All Events');
	}
	else{
		$breadcrumb_array = array('Events'=>'/admin/admin_events', 'Future Events'=>'');
		$searches['past'] = FALSE;
		$searches['status'] = 1;
	}

	/*
	if($user_id) {
		$searches['user_id'] = $user_id;
	}
	*/

	$events = new MultiEvent(
		$searches,
		array($sort=>$sdirection),
		$numperpage,
		$offset);
	$events->load();	
	$numrecords = $events->count_all();	

	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'events-list',
		'page_title' => 'Events',
		'readable_title' => 'Events',
		'breadcrumbs' => $breadcrumb_array,
		'session' => $session,
	)
	);	

	$headers = array("Start time", "Event",  "Published", "Registration", "Registrants", "Waiting List");
	$altlinks = array('New Event'=>'/admin/admin_event_edit');
	
	$pager = new Pager(
		array(
			'numrecords'=>$numrecords, 
			'numperpage'=> $numperpage, 
			'offset'=>$offset,
			'sort'=>$sort,  
			'sdirection'=>$sdirection, 
			'filter' => $filter
		)
	);	

	$table_options = array(
		'sortoptions'=>array("Event ID"=>"event_id", "Event Name"=>"name", 'Start Time'=>'start_time'),
		'filteroptions'=>array("Future Events"=>"future", "All Events"=>"all"),
		'altlinks' => $altlinks,
		'title' => 'Events',
		'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($events as $event){
		$searches = array();
		$searches['event_id'] = $event->key;			
		
		$registrants = new MultiEventRegistrant(
			array('event_id'=>$event->key, 'expired' => false)
		);
		$numregistrants = $registrants->count_all();

		$waiting_lists = new MultiWaitingList(
			array('event_id'=>$event->key)
		);
		$numwaitinglists = $waiting_lists->count_all();
		//$user = new User($events->get('evt_usr_user_id'),TRUE);

		$rowvalues = array();

		array_push($rowvalues, LibraryFunctions::convert_time($event->get('evt_start_time_local'), $session->get_timezone(), $session->get_timezone(), 'M j, Y'));
		 
		array_push($rowvalues, '<a href="/admin/admin_event?evt_event_id='.$event->key.'"><strong>'.$event->get('evt_name'). '</strong></a>');

		if($event->get('evt_delete_time')){
			array_push($rowvalues, '<b>Deleted</b>');
		}
		else if($event->get('evt_visibility') == 0) {
			array_push($rowvalues, '<b>Private</b>');
		} 
		else if($event->get('evt_visibility') == 1){
			array_push($rowvalues, '<a href="' . $event->get_url() . '">Public</a>');
		}
		else{
			array_push($rowvalues, '<a href="' . $event->get_url() . '">Unlisted</a>');
		}			
		
		array_push($rowvalues, $event->get('evt_is_accepting_signups') ? 'Open' : 'Closed');
		array_push($rowvalues, '<a href="/admin/admin_event?evt_event_id='.$event->key.'">'.$numregistrants.' registered</a>');
		array_push($rowvalues, '<a href="/admin/admin_event?evt_event_id='.$event->key.'">'.$numwaitinglists.' on waiting list</a>');

		$page->disprow($rowvalues);
	}

	$page->endtable($pager);
	$page->admin_footer();

?>
