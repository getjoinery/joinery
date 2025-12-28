<?php
/**
 * Admin Component Type View
 *
 * View a component type definition (read-only). Superadmin only.
 * Component types are defined by JSON files and synced to the database.
 *
 * @see /specs/page_component_system.md
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('data/components_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(10); // Superadmin only
$session->set_return();

// Load component
if (!isset($_REQUEST['com_component_id']) || !$_REQUEST['com_component_id']) {
	LibraryFunctions::redirect('/admin/admin_component_types');
	exit();
}

$component = new Component($_REQUEST['com_component_id'], TRUE);
if (!$component->key) {
	LibraryFunctions::redirect('/admin/admin_component_types');
	exit();
}

// Get categories for display
$categories = Component::get_categories();

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'pages',
	'breadcrumbs' => array(
		'Pages' => '/admin/admin_pages',
		'Component Types' => '/admin/admin_component_types',
		'View' => '',
	),
	'session' => $session,
));

$title = $component->get('com_title') ?: $component->get('com_type_key');
$pageoptions['title'] = htmlspecialchars($title);
$page->begin_box($pageoptions);

// Helper function for displaying fields
function display_field($label, $value, $is_code = false) {
	if ($value === null || $value === '') {
		$display = '<span class="text-muted">—</span>';
	} elseif ($is_code) {
		$display = '<code>' . htmlspecialchars($value) . '</code>';
	} else {
		$display = htmlspecialchars($value);
	}
	echo '<div class="mb-3">';
	echo '<label class="form-label text-muted small mb-0">' . htmlspecialchars($label) . '</label>';
	echo '<div>' . $display . '</div>';
	echo '</div>';
}

echo '<div class="row"><div class="col-md-8">';

// Basic info
display_field('Type Key', $component->get('com_type_key'), true);
display_field('Title', $component->get('com_title'));
display_field('Description', $component->get('com_description'));

// Category
$category = $component->get('com_category');
$category_label = isset($categories[$category]) ? $categories[$category] : $category;
display_field('Category', $category_label);

echo '<hr>';

// Technical details
display_field('Template File', $component->get('com_template_file'), true);
display_field('Logic Function', $component->get('com_logic_function'), true);
display_field('Requires Plugin', $component->get('com_requires_plugin'), true);
display_field('CSS Framework', $component->get('com_css_framework'), true);

echo '</div><div class="col-md-4">';

// Config Schema
echo '<div class="mb-3">';
echo '<label class="form-label text-muted small mb-0">Config Schema</label>';

$current_schema = $component->get('com_config_schema');
if (is_array($current_schema) && !empty($current_schema)) {
	$schema_display = json_encode($current_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	echo '<pre class="bg-light p-2 rounded" style="font-size: 11px; max-height: 400px; overflow: auto; white-space: pre-wrap; word-wrap: break-word;">';
	echo htmlspecialchars($schema_display);
	echo '</pre>';
} else {
	echo '<div><span class="text-muted">—</span></div>';
}

echo '</div>';

echo '</div></div>';

// Back button
echo '<div class="mt-4">';
echo '<a href="/admin/admin_component_types" class="btn btn-outline-secondary">← Back to Component Types</a>';
echo '</div>';

$page->end_box();

$page->admin_footer();
?>
