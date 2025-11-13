<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_contact_types_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$page_vars = process_logic(admin_contact_types_logic($_GET, $_POST));

$session = $page_vars['session'];
$contact_types = $page_vars['contact_types'];
$numrecords = $page_vars['numrecords'];
$numperpage = $page_vars['numperpage'];

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'contact-types',
	'breadcrumbs' => array(
		'Emails'=>'/admin/admin_emails',
		'Contact Types' => '',
	),
	'session' => $session,
)
);

$headers = array("Contact Type",  "Description", "Deleted");
$altlinks = array('New Contact Type' => '/admin/admin_contact_type_edit');
$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
$table_options = array(
	'altlinks' => $altlinks,
	'title' => 'Contact Types',
);
$page->tableheader($headers, $table_options, $pager);

foreach ($contact_types as $contact_type){

	$rowvalues = array();
	array_push($rowvalues, '<a href="/admin/admin_contact_type?ctt_contact_type_id='.$contact_type->key.'">'.$contact_type->get('ctt_name').'</a>');
	array_push($rowvalues, $contact_type->get('ctt_description'));

	$status = 'Active';
	if($contact_type->get('ctt_delete_time')) {
		$status = 'Deleted';
	}
	array_push($rowvalues, $status);

	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
?>
