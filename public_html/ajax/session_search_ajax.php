<?php
	header('Content-Type: application/json');

	require_once( __DIR__ . '/../includes/Globalvars.php');
	require_once( __DIR__ . '/../includes/DbConnector.php');
	require_once( __DIR__ . '/../includes/SessionControl.php');
	require_once( __DIR__ . '/../data/events_class.php');
	require_once( __DIR__ . '/../data/event_sessions_class.php');



	$session = SessionControl::get_instance();
	$session->check_permission(5);
	$session->set_return();

	$numperpage = 50;
	$aoffset = LibraryFunctions::fetch_variable('aoffset', 0, 0, '');
	$asort = LibraryFunctions::fetch_variable('asort', 'name', 0, '');
	$asdirection = LibraryFunctions::fetch_variable('asdirection', 'ASC', 0, '');

	$searchterm = LibraryFunctions::fetch_variable('q', '', 0, '');

	$search_criteria = array();
	
	if ($searchterm != ''){
		$fsearch = trim(preg_replace('/\s+/', ' ', $searchterm));
		$fsearch = str_replace(' ', ' | ', $fsearch);

		$search_criteria['name_like'] = $fsearch;
		
		/*
		if(is_numeric($searchterm) && (int)$searchterm > 0 && (int)$searchterm < 2147483647) {
			$search_criteria['event_session_id'] = (int)$searchterm;
		}
		*/

	}

	$results = new MultiEvent(
		$search_criteria,
		array($asort=>$asdirection),
		$numperpage,
		$aoffset,
		'OR');
	//$numrecords = $results->count_all();
	$results->load();

	$events_done = array();
	$json = [];
	foreach ($results as $result) {
		if(!in_array($result->key, $events_done)){
			$events_done[] = $result->key;
			$ssearch_criteria = array();
			$ssearch_criteria['event_id'] = $result->key;
			$sessions = new MultiEventSessions(
				$ssearch_criteria,
				array('session_number_then_title'=>'ASC'),
				NULL,
				NULL
			);
			//$numrecords = $results->count_all();
			$sessions->load();
			 
			foreach ($sessions as $session) {
				$json[] = ['id'=>$session->key, 'text'=>$result->get('evt_name') . ' - ' . $session->get('evs_title')];
			}
		}
	}
	echo json_encode($json);
exit();

?>