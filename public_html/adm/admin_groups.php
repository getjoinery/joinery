<?php

require_once(PathHelper::getIncludePath('adm/logic/admin_groups_logic.php'));
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));

$page_vars = process_logic(admin_groups_logic($_GET, $_POST));

$session = $page_vars['session'];
$groups = $page_vars['groups'];
$numrecords = $page_vars['numrecords'];
$numperpage = $page_vars['numperpage'];

$page = new AdminPage();
$page->admin_header(
array(
	'menu-id'=> 'groups',
	'page_title' => 'Groups',
	'readable_title' => 'Groups',
	'breadcrumbs' => array(
		'Users'=>'/admin/admin_users',
		'Groups' => '',
	),
	'session' => $session,
)
);

$headers = array("Group", "# Users", "Last Update", "Action");
$altlinks = array();
$altlinks += array('Add Group'=> '/admin/admin_group_edit');
$pager = new Pager(array('numrecords'=>$numrecords, 'numperpage'=> $numperpage));
$table_options = array(
	'altlinks' => $altlinks,
	'title' => 'Groups',
);
$page->tableheader($headers, $table_options, $pager);

foreach ($groups as $group){

	$rowvalues = array();

	array_push($rowvalues, "<a href='/admin/admin_group_members?grp_group_id=$group->key'>".$group->get('grp_name')."</a> ");

	$numusers = (string)$group->get_member_count();
	array_push($rowvalues, $numusers);

	array_push($rowvalues, LibraryFunctions::convert_time($group->get('grp_update_time'), "UTC", $session->get_timezone(), 'M j, Y'));

	$delform = AdminPage::action_button('Delete', '/admin/admin_group_permanent_delete', [
		'hidden'  => ['action' => 'remove', 'grp_group_id' => $group->key],
		'confirm' => 'Are you sure you want to delete this group?',
	]);

	array_push($rowvalues, $delform);

	$page->disprow($rowvalues);
}
$page->endtable($pager);

$page->admin_footer();
?>
