<?php
	require_once(__DIR__ . '/../includes/PathHelper.php');
	
	PathHelper::requireOnce('includes/Activation.php');
	PathHelper::requireOnce('includes/ErrorHandler.php');
	PathHelper::requireOnce('includes/AdminPage.php');
	PathHelper::requireOnce('includes/SessionControl.php');
	PathHelper::requireOnce('includes/DbConnector.php');
	PathHelper::requireOnce('data/events_class.php');
	PathHelper::requireOnce('data/event_registrants_class.php');
	PathHelper::requireOnce('data/event_waiting_lists_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(8);
	$session->set_return();

	$event = new Event($_REQUEST['evt_event_id'], TRUE);

	//REGISTRANTS

	$rsearch_criteria = array();
	$rsearch_criteria['event_id'] = $event->key;
	
	$event_registrants = new MultiEventRegistrant(
		$rsearch_criteria,
		);
	$numregistrants = $event_registrants->count_all();
	$event_registrants->load();
	

	//WAITING LIST

	$wsearch_criteria = array();
	$wsearch_criteria['event_id'] = $event->key;
	$waiting_lists = new MultiWaitingList(		
		$wsearch_criteria,);
	$numwaitinglist = $waiting_lists->count_all();
	$waiting_lists->load();

	
	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'events',
		'page_title' => 'Event',
		'readable_title' => 'Event',
		'breadcrumbs' => array(
			'Events'=>'/admin/admin_events', 
			$event->get('evt_name') => '/admin/admin_event?evt_event_id='.$event->key,
			'Registrants'=>'',
		),
		'session' => $session,
	)
	);	

	$settings = Globalvars::get_instance();
	$webDir = $settings->get_setting('webDir');



		$options['title'] = $event->get('evt_name');
		$options['altlinks'] = array();

			

	$pageoptions['title'] = "Emails of all registrants";
	$page->begin_box($pageoptions);
	$registrant_emails = '';
	foreach($event_registrants as $event_registrant){

		$registrant = new User($event_registrant->get('evr_usr_user_id'), TRUE);
		
		$registrant_emails .= $registrant->display_name() . ' &lt;'.$registrant->get('usr_email'). '&gt;, ';
	}
	echo '<p>'.$registrant_emails. '</p>';	
	$page->end_box();

	$pageoptions['title'] = "Emails of waiting list";
	$registrant_emails = '';
	foreach($waiting_lists as $waiting_list){

		$registrant = new User($waiting_list->get('ewl_usr_user_id'), TRUE);
		
		$registrant_emails .= $registrant->display_name() . ' &lt;'.$registrant->get('usr_email'). '&gt;, ';
	}
	$page->begin_box($pageoptions);
	echo '<p>'.$registrant_emails. '</p>';	
	$page->end_box();

	$page->admin_footer();

?>
