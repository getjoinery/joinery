<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/ErrorHandler.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/FormWriterMaster.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/SessionControl.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/users_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/events_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_registrants_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_sessions_class.php');
	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_waiting_lists_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();	

	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '');
	$sort = LibraryFunctions::fetch_variable('sort', 'event_id', 0, '');
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'DESC', 0, '');


	
	$searches = array();
	
	if($_REQUEST['filter'] == 'all'){
		$breadcrumb_array = array('Events'=>'All Events');
		$sort = 'event_id';
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
	
	
	if($searchterm) {
		if(is_numeric($searchterm)) {
			$searches['event_id'] = $searchterm;
		}
		else {
			$searches['name'] = $searchterm;
		}
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
	



	$headers = array("Event", "Start time", "Published", "Registration", "Registrants", "Waiting List", "Sessions");
	$altlinks = array('New Event'=>'/admin/admin_event_edit');
	$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));	
	$table_options = array(
		'sortoptions'=>array("Event ID"=>"event_id", "Event Name"=>"name"),
		'filteroptions'=>array("Future Events"=>"future", "All Events"=>"all"),
		'altlinks' => $altlinks,
		'title' => 'Events',
		//'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($events as $event){
		$searches = array();
		$searches['event_id'] = $event->key;
		$event_sessions = new MultiEventSessions(
			$searches);
		$numsessions = $event_sessions->count_all();			
		
		$registrants = new MultiEventRegistrant(
			array('event_id'=>$event->key)
		);
		$numregistrants = $registrants->count_all();

		$waiting_lists = new MultiWaitingList(
			array('event_id'=>$event->key)
		);
		$numwaitinglists = $waiting_lists->count_all();
		//$user = new User($events->get('evt_usr_user_id'),TRUE);

		$rowvalues = array();

		array_push($rowvalues, '<a href="/admin/admin_event?evt_event_id='.$event->key.'"><strong>'.$event->get('evt_name'). '</strong></a>');

/*
		$rowvalues[] = $bids->jobs_count(5, 5);
		$rowvalues[] = $bids->jobs_count(15, 50);
		$rowvalues[] = $bids->jobs_count(NULL, 0);
		$rowvalues[] = $bids->jobs_count(20, 50);

		// Figure out how many estimates were viewed!
		$viewed_job_items = 0;
		foreach($bids->all_job_items() as $job_item) {
			$viewed_job_items += $job_item->get('jbi_buyer_last_seen_time') ? 1 : 0;
		}
		$rowvalues[] = $viewed_job_items;
		*/

		//array_push($rowvalues, '<a href="/admin/admin_user?usr_user_id='.$event->get('evt_usr_user_id').'">user</a>');
		//array_push($rowvalues, $event->get_event_start_time());
		array_push($rowvalues, LibraryFunctions::convert_time($event->get('evt_start_time_local'), $session->get_timezone(), $session->get_timezone(), 'M j, Y'));
		 
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
			array_push($rowvalues, '<a href="' . $event->get_url() . '">Public but unlisted</a>');
		}			
		
		array_push($rowvalues, $event->get('evt_is_accepting_signups') ? 'Open' : 'Closed');
		array_push($rowvalues, '<a href="/admin/admin_event?evt_event_id='.$event->key.'">'.$numregistrants.' registered</a>');
		array_push($rowvalues, '<a href="/admin/admin_event?evt_event_id='.$event->key.'">'.$numwaitinglists.' on waiting list</a>');


		array_push($rowvalues, $numsessions . ' sessions');
		
		
		$page->disprow($rowvalues);
	}


	$page->endtable($pager);
	$page->admin_footer();


?>
