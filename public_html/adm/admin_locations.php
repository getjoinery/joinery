<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_locations_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$page_vars = process_logic(admin_locations_logic($_GET, $_POST));

$session = $page_vars['session'];
$locations = $page_vars['locations'];
$numrecords = $page_vars['numrecords'];
$numperpage = $page_vars['numperpage'];

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'locations',
	'page_title' => 'Locations',
	'readable_title' => 'Locations',
	'breadcrumbs' => array(
		'Events'=>'/admin/admin_events',
		'Locations' => '',
	),
	'session' => $session,
)
);

$headers = array("Location",  "Description", "Deleted");
$altlinks = array('New Location'=>'/admin/admin_location_edit');
$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
$table_options = array(
	'altlinks' => $altlinks,
	'title' => 'Locations',
);
$page->tableheader($headers, $table_options, $pager);

foreach ($locations as $location){

	$rowvalues = array();
	array_push($rowvalues, '<a href="/admin/admin_location?loc_location_id='.$location->key.'">'.$location->get('loc_name').'</a>');
	array_push($rowvalues, $location->get('loc_short_description'));

	$status = 'Active';
	if($location->get('loc_delete_time')) {
		$status = 'Deleted';
	}
	array_push($rowvalues, $status);

	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
?>
