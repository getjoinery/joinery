<?php
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/AdminPage-uikit3.php');
	
	require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/LibraryFunctions.php');

	require_once($_SERVER['DOCUMENT_ROOT'] . '/data/event_types_class.php');

	$session = SessionControl::get_instance();
	$session->check_permission(7);


	$page = new AdminPage();
	$page->admin_header(	
	array(
		'menu-id'=> 'events',
		'page_title' => 'Event Types',
		'readable_title' => 'Event Types',
		'breadcrumbs' => array(
			'Events'=>'/admin/admin_products', 
			'Event Types' => '',
		),
		'session' => $session,
	)
	);

		
	$event_types = new MultiEventType();
	$event_types->load();

	$headers = array('Event Type Name');
	$altlinks = array();
	if($_SESSION['permission'] > 7){
		$altlinks['New Event Type'] = '/admin/admin_event_type_edit';
	}
	$box_vars =	array(
		'altlinks' => $altlinks,
		'title' => 'Event Types'
	);
	$page->tableheader($headers, $box_vars);

	foreach($event_types as $event_type) {
		$rowvalues=array();
		array_push($rowvalues, $event_type->get('ety_name'));
		$page->disprow($rowvalues);
	}

	$page->endtable();
		

	$page->admin_footer();

?>
