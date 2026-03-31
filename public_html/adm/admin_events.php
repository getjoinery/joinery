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

	$headers = array("Start Date", "Event", "Status", "Registration");
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
		'sortoptions'=>array("Event ID"=>"event_id", "Event Name"=>"name", 'Start Date'=>'start_time', 'End Date'=>'end_time'),
		'filteroptions'=>array("Active & Upcoming"=>"active", "Future Events"=>"future", "All Events"=>"all", "Series"=>"series"),
		'altlinks' => $altlinks,
		'title' => 'Events',
		'search_on' => TRUE
	);
	$page->tableheader($headers, $table_options, $pager);

	foreach ($events as $event){
		$registrants = new MultiEventRegistrant(
			array('event_id'=>$event->key, 'expired' => false)
		);
		$numregistrants = $registrants->count_all();

		$waiting_lists = new MultiWaitingList(
			array('event_id'=>$event->key)
		);
		$numwaitinglists = $waiting_lists->count_all();

		$rowvalues = array();

		// Start Date
		$start_date = $event->get('evt_start_time')
			? LibraryFunctions::convert_time($event->get('evt_start_time'), 'UTC', $session->get_timezone(), 'D, M j, Y')
			: '—';
		$rowvalues[] = $start_date;

		// Event name + Repeating badge
		$event_name_display = '<a href="/admin/admin_event?evt_event_id='.$event->key.'"><strong>'.$event->get('evt_name'). '</strong></a>';
		if ($event->is_recurring_parent()) {
			$event_name_display .= ' <span class="badge bg-info ms-1">Repeating</span>';
		}
		$rowvalues[] = $event_name_display;

		// Status — visibility + cancelled badges
		$status_parts = [];
		if ($event->get('evt_delete_time')) {
			$status_parts[] = '<span class="badge bg-dark">Deleted</span>';
		} else if ($event->get('evt_visibility') == 0) {
			$status_parts[] = '<span class="badge bg-secondary">Private</span>';
		} else if ($event->get('evt_visibility') == 1) {
			$status_parts[] = '<a href="' . $event->get_url() . '"><span class="badge bg-success">Public</span></a>';
		} else {
			$status_parts[] = '<a href="' . $event->get_url() . '"><span class="badge bg-warning text-dark">Unlisted</span></a>';
		}
		if ($event->get('evt_status') == Event::STATUS_CANCELED) {
			$status_parts[] = '<span class="badge bg-danger">Cancelled</span>';
		}
		$rowvalues[] = implode(' ', $status_parts);

		// Registration — open/closed + counts + capacity + waiting list
		$reg_parts = [];
		if ($event->get('evt_is_accepting_signups')) {
			$reg_parts[] = '<span class="text-success fw-semibold">Open</span>';
		} else {
			$reg_parts[] = '<span class="text-muted">Closed</span>';
		}
		if ($numregistrants > 0 || $event->get('evt_max_signups')) {
			$count_str = $numregistrants;
			if ($event->get('evt_max_signups')) {
				$count_str .= '/' . $event->get('evt_max_signups');
			}
			$reg_parts[] = '<a href="/admin/admin_event?evt_event_id='.$event->key.'">' . $count_str . ' registered</a>';
		}
		if ($numwaitinglists > 0) {
			$reg_parts[] = $numwaitinglists . ' waiting';
		}
		$rowvalues[] = implode(' · ', $reg_parts);

		$page->disprow($rowvalues);
	}

	$page->endtable($pager);
	$page->admin_footer();

?>
