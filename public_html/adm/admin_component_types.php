<?php
/**
 * Admin Component Types List
 *
 * List all component types in the system (the library of available components).
 * Superadmin only - component types are system-level definitions.
 *
 * @see /specs/page_component_system.md
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/components_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(10); // Superadmin only
$session->set_return();

// Handle delete action
if (isset($_POST['action']) && $_POST['action'] == 'delete') {
	$component = new Component($_POST['com_component_id'], TRUE);
	$component->soft_delete();
	header("Location: /admin/admin_component_types");
	exit();
}

// Get filter parameters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'active';
$searchterm = isset($_GET['searchterm']) ? trim($_GET['searchterm']) : '';

// Pagination
$numperpage = 25;
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

// Build search options
$search_options = array('deleted' => false);
if ($filter === 'active') {
	$search_options['active'] = true;
}

// Load components
$components = new MultiComponent(
	$search_options,
	array('com_category' => 'ASC', 'com_order' => 'ASC', 'com_title' => 'ASC'),
	$numperpage,
	$offset
);
$numrecords = $components->count_all();
$components->load();

// Get categories for display
$categories = Component::get_categories();

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'pages',
	'page_title' => 'Component Types',
	'readable_title' => 'Component Types',
	'breadcrumbs' => array(
		'Pages' => '/admin/admin_pages',
		'Component Types' => '',
	),
	'session' => $session,
));

// Table
$headers = array("Type Key", "Title", "Category", "Template", "Active", "Actions");
$altlinks = array('Add Component Type' => '/admin/admin_component_type_edit');
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage, 'offset' => $offset));
$table_options = array(
	'filteroptions' => array("Active" => "active", "All Types" => "all"),
	'altlinks' => $altlinks,
	'title' => 'Component Types',
	'search_on' => TRUE
);
$page->tableheader($headers, $table_options, $pager);

foreach ($components as $component) {
	$rowvalues = array();

	// Type Key
	$type_key = $component->get('com_type_key');
	array_push($rowvalues, '<code>' . htmlspecialchars($type_key ?: '(none)') . '</code>');

	// Title with icon
	$icon = $component->get('com_icon');
	$title = $component->get('com_title');
	$icon_html = $icon ? '<i class="' . htmlspecialchars($icon) . ' me-1"></i> ' : '';
	array_push($rowvalues, $icon_html . htmlspecialchars($title));

	// Category
	$category = $component->get('com_category');
	$category_label = isset($categories[$category]) ? $categories[$category] : $category;
	array_push($rowvalues, htmlspecialchars($category_label ?: '-'));

	// Template
	$template = $component->get('com_template_file');
	array_push($rowvalues, '<code>' . htmlspecialchars($template ?: '(none)') . '</code>');

	// Active status
	$is_active = $component->get('com_is_active');
	$status_badge = $is_active
		? '<span class="badge bg-success">Active</span>'
		: '<span class="badge bg-secondary">Inactive</span>';
	array_push($rowvalues, $status_badge);

	// Actions
	$edit_link = '<a href="/admin/admin_component_type_edit?com_component_id=' . $component->key . '" class="btn btn-sm btn-outline-primary me-1">Edit</a>';

	$delete_form = '<form method="POST" style="display:inline" onsubmit="return confirm(\'Are you sure you want to delete this component type?\');">';
	$delete_form .= '<input type="hidden" name="action" value="delete">';
	$delete_form .= '<input type="hidden" name="com_component_id" value="' . $component->key . '">';
	$delete_form .= '<button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>';
	$delete_form .= '</form>';

	array_push($rowvalues, $edit_link . $delete_form);

	$page->disprow($rowvalues);
}

$page->endtable($pager);

$page->admin_footer();
?>
