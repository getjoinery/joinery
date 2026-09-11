<?php

function admin_events_logic(array $input): LogicResult {
	require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
	require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
	require_once(PathHelper::getIncludePath('data/users_class.php'));
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/events_class.php'));
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_registrants_class.php'));
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_sessions_class.php'));
	require_once(PathHelper::getIncludePath('plugins/event_manager/data/event_waiting_lists_class.php'));

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$searches = array();
	$numperpage = 30;
	$offset = LibraryFunctions::fetch_variable('offset', 0, 0, '', $input);
	$sort = LibraryFunctions::fetch_variable('sort', 'end_time', 0, '', $input);
	$sdirection = LibraryFunctions::fetch_variable('sdirection', 'ASC', 0, '', $input);
	$searchterm = LibraryFunctions::fetch_variable('searchterm', '', 0, '', $input);
	$filter = LibraryFunctions::fetch_variable('filter', 'active', 0, '', $input);

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

	if($filter == 'active'){
		$breadcrumb_array = array('Events'=>'Active & Upcoming');
		$searches['recurring_or_future'] = true;
	}
	else if($filter == 'all'){
		$breadcrumb_array = array('Events'=>'All Events');
		$searches['exclude_past_materialized'] = true;
	}
	else if($filter == 'series'){
		$breadcrumb_array = array('Events'=>'/plugins/event_manager/admin/admin_events', 'Recurring Series'=>'');
		$searches['only_recurring_parents'] = true;
	}
	else{
		$breadcrumb_array = array('Events'=>'/plugins/event_manager/admin/admin_events', 'Future Events'=>'');
		$searches['past'] = FALSE;
		$searches['status'] = 1;
	}

	$events = new MultiEvent(
		$searches,
		array($sort=>$sdirection),
		$numperpage,
		$offset);
	$events->load();
	$numrecords = $events->count_all();

	$page_vars = array(
		'session' => $session,
		'events' => $events,
		'numrecords' => $numrecords,
		'numperpage' => $numperpage,
		'offset' => $offset,
		'sort' => $sort,
		'sdirection' => $sdirection,
		'filter' => $filter,
		'breadcrumb_array' => $breadcrumb_array
	);

	return LogicResult::render($page_vars);
}
?>
