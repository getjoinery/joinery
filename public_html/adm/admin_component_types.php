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

// Pagination
$numperpage = 25;
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;

// Only show active, non-deleted component types
$search_options = array('deleted' => false, 'active' => true);

// Load components
$components = new MultiComponent(
	$search_options,
	array('com_category' => 'ASC', 'com_title' => 'ASC'),
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
$headers = array("Title", "Category", "Theme");
$altlinks = array();
$pager = new Pager(array('numrecords' => $numrecords, 'numperpage' => $numperpage, 'offset' => $offset));
$table_options = array(
	'altlinks' => $altlinks,
	'title' => 'Component Types',
	'search_on' => TRUE
);
$page->tableheader($headers, $table_options, $pager);

foreach ($components as $component) {
	$rowvalues = array();

	// Title (linked to detail view)
	$title = $component->get('com_title');
	$title_link = '<a href="/admin/admin_component_type_edit?com_component_id=' . $component->key . '">' . htmlspecialchars($title) . '</a>';
	array_push($rowvalues, $title_link);

	// Category
	$category = $component->get('com_category');
	$category_label = isset($categories[$category]) ? $categories[$category] : $category;
	array_push($rowvalues, htmlspecialchars($category_label ?: '-'));

	// Theme/Compatibility (derived from template path and css_framework)
	$template = $component->get('com_template_file');
	$css_framework = $component->get('com_css_framework');

	if ($template && preg_match('#^theme/([^/]+)/#', $template, $matches)) {
		// Theme-specific component
		$theme_display = '<span class="badge bg-info">' . htmlspecialchars($matches[1]) . '</span>';
	} elseif ($css_framework) {
		// Framework-specific
		$theme_display = '<span class="badge bg-primary">' . htmlspecialchars(ucfirst($css_framework)) . '</span>';
	} else {
		// Plain HTML - works with all themes
		$theme_display = '<span class="badge bg-secondary">All</span>';
	}
	array_push($rowvalues, $theme_display);

	$page->disprow($rowvalues);
}

$page->endtable($pager);

$page->admin_footer();
?>
