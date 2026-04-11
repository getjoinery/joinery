<?php
/**
 * Server Manager - Node List
 * URL: /admin/server_manager/nodes
 *
 * @version 1.0
 */
require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/Pager.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/managed_node_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(10);
$session->set_return();

$numperpage = 30;
$offset = LibraryFunctions::fetch_variable_local($_GET, 'offset', 0);
$sort = LibraryFunctions::fetch_variable_local($_GET, 'sort', 'mgn_name');
$sdirection = LibraryFunctions::fetch_variable_local($_GET, 'sdirection', 'ASC');

$search_criteria = [];
if ($session->get_permission() < 10) {
	$search_criteria['deleted'] = false;
}

$nodes = new MultiManagedNode($search_criteria, [$sort => $sdirection], $numperpage, $offset);
$numrecords = $nodes->count_all();
$nodes->load();

$page = new AdminPage();
$page->admin_header([
	'menu-id' => 'server-manager',
	'page_title' => 'Managed Nodes',
	'readable_title' => 'Managed Nodes',
	'breadcrumbs' => [
		'Server Manager' => '/admin/server_manager',
		'Nodes' => '',
	],
	'session' => $session,
]);

$headers = ['Name', 'Host', 'Container', 'Site URL', 'Version', 'Last Check', 'Status', 'Actions'];
$pager = new Pager(['numrecords' => $numrecords, 'numperpage' => $numperpage]);
$table_options = [
	'altlinks' => ['Add Node' => '/admin/server_manager/nodes_edit'],
	'title' => 'Managed Nodes',
	'sortoptions' => [
		'Name' => 'mgn_name',
		'Host' => 'mgn_host',
	],
];
$page->tableheader($headers, $table_options, $pager);

foreach ($nodes as $node) {
	$last_check = $node->get('mgn_last_status_check');
	$check_age = $last_check ? (time() - strtotime($last_check)) : PHP_INT_MAX;

	if ($node->get('mgn_delete_time')) {
		$status_badge = '<span class="badge bg-dark">Deleted</span>';
	} elseif (!$node->get('mgn_enabled')) {
		$status_badge = '<span class="badge bg-secondary">Disabled</span>';
	} elseif (!$last_check) {
		$status_badge = '<span class="badge bg-secondary">Never checked</span>';
	} elseif ($check_age < 300) {
		$status_badge = '<span class="badge bg-success">OK</span>';
	} elseif ($check_age < 1800) {
		$status_badge = '<span class="badge bg-warning">Stale</span>';
	} else {
		$status_badge = '<span class="badge bg-danger">Outdated</span>';
	}

	$rowvalues = [];
	array_push($rowvalues, '<a href="/admin/server_manager/nodes_edit?mgn_id=' . $node->key . '">' . htmlspecialchars($node->get('mgn_name')) . '</a>');
	array_push($rowvalues, htmlspecialchars($node->get('mgn_host')));
	array_push($rowvalues, htmlspecialchars($node->get('mgn_container_name') ?: '-'));
	array_push($rowvalues, $node->get('mgn_site_url') ? '<a href="' . htmlspecialchars($node->get('mgn_site_url')) . '" target="_blank">' . htmlspecialchars($node->get('mgn_site_url')) . '</a>' : '-');
	array_push($rowvalues, htmlspecialchars($node->get('mgn_joinery_version') ?: '-'));
	array_push($rowvalues, $last_check ? LibraryFunctions::convert_time($last_check, 'UTC', $session->get_timezone(), 'M j, g:i A') : '-');
	array_push($rowvalues, $status_badge);

	$actions = '<a href="/admin/server_manager/nodes_edit?mgn_id=' . $node->key . '" class="btn btn-sm btn-primary">Edit</a> '
			 . '<a href="/admin/server_manager/jobs?node_id=' . $node->key . '" class="btn btn-sm btn-outline-secondary">Jobs</a>';
	array_push($rowvalues, $actions);

	$page->disprow($rowvalues);
}

$page->endtable($pager);
$page->admin_footer();
?>
