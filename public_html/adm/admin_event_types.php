<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_event_types_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));

$page_vars = process_logic(admin_event_types_logic($_GET, $_POST));

$session = $page_vars['session'];
$event_types = $page_vars['event_types'];

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

$headers = array('Event Type Name');
$altlinks = array();
if($session->get_permission() > 7){
	$altlinks['New Event Type'] = '/admin/admin_event_type_edit';
}
$box_vars = array(
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
