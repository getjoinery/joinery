<?php

	require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('data/events_class.php'));
	require_once(PathHelper::getIncludePath('data/event_registrants_class.php'));
	require_once(PathHelper::getIncludePath('data/event_sessions_class.php'));
	require_once(PathHelper::getIncludePath('data/event_waiting_lists_class.php'));
	require_once(PathHelper::getIncludePath('adm/logic/admin_events_logic.php'));

	$page_vars = process_logic(admin_events_logic($_GET, $_POST));
	extract($page_vars);

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
