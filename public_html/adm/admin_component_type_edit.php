<?php
/**
 * Admin Component Type Edit
 *
 * Edit a component type definition. Superadmin only.
 *
 * @see /specs/page_component_system.md
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('data/components_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(10); // Superadmin only
$session->set_return();

// Load or create component
if (isset($_REQUEST['com_component_id']) && $_REQUEST['com_component_id']) {
	$component = new Component($_REQUEST['com_component_id'], TRUE);
} else {
	$component = new Component(NULL);
}

// Process form submission
if ($_POST) {
	$editable_fields = array(
		'com_type_key',
		'com_title',
		'com_description',
		'com_category',
		'com_icon',
		'com_template_file',
		'com_logic_function',
		'com_requires_plugin',
		'com_order'
	);

	foreach ($editable_fields as $field) {
		if (isset($_POST[$field])) {
			$value = trim($_POST[$field]);
			// Sanitize type_key to be URL-safe
			if ($field === 'com_type_key') {
				$value = preg_replace('/[^a-z0-9_]/', '_', strtolower($value));
			}
			$component->set($field, $value ?: null);
		}
	}

	// Handle checkbox for active status
	$component->set('com_is_active', isset($_POST['com_is_active']) ? true : false);

	// Handle config schema (JSON)
	if (isset($_POST['com_config_schema'])) {
		$schema_json = trim($_POST['com_config_schema']);
		if ($schema_json) {
			// Validate JSON
			$parsed = json_decode($schema_json, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				$error_message = 'Invalid JSON in Config Schema: ' . json_last_error_msg();
			} else {
				$component->set('com_config_schema', $parsed);
			}
		} else {
			$component->set('com_config_schema', null);
		}
	}

	if (!isset($error_message)) {
		try {
			$component->prepare();
			$component->save();
			LibraryFunctions::redirect('/admin/admin_component_types');
			exit();
		} catch (Exception $e) {
			$error_message = $e->getMessage();
		}
	}
}

// Get categories for dropdown
$categories = Component::get_categories();

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'pages',
	'breadcrumbs' => array(
		'Pages' => '/admin/admin_pages',
		'Component Types' => '/admin/admin_component_types',
		($component->key ? 'Edit' : 'Add') => '',
	),
	'session' => $session,
));

$pageoptions['title'] = $component->key ? 'Edit Component Type' : 'Add Component Type';
$page->begin_box($pageoptions);

if (isset($error_message)) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error_message) . '</div>';
}

$formwriter = $page->getFormWriter('form1', [
	'model' => $component,
	'edit_primary_key_value' => $component->key
]);

$formwriter->begin_form();

// Hidden field for edit mode - ensures POST contains the primary key
if ($component->key) {
	$formwriter->hiddeninput('com_component_id', ['value' => $component->key]);
}

echo '<div class="row"><div class="col-md-8">';

$formwriter->textinput('com_type_key', 'Type Key', [
	'help' => 'Unique identifier (lowercase, underscores only). e.g., hero_static, feature_grid',
	'validation' => ['required' => true]
]);

$formwriter->textinput('com_title', 'Title', [
	'help' => 'Display name for the component type',
	'validation' => ['required' => true]
]);

$formwriter->textarea('com_description', 'Description', [
	'help' => 'Brief description of what this component does'
]);

$formwriter->dropinput('com_category', 'Category', [
	'options' => array_merge(['' => '-- Select Category --'], $categories)
]);

$formwriter->textinput('com_icon', 'Icon Class', [
	'help' => 'CSS class for icon (e.g., bx-image, fa-star)'
]);

$formwriter->textinput('com_template_file', 'Template File', [
	'help' => 'Filename only (e.g., hero_static.php). Located in views/components/'
]);

$formwriter->textinput('com_logic_function', 'Logic Function', [
	'help' => 'Optional. Function name for dynamic data (e.g., recent_posts_logic)'
]);

$formwriter->textinput('com_requires_plugin', 'Requires Plugin', [
	'help' => 'Optional. Plugin name required for this component'
]);

$formwriter->textinput('com_order', 'Display Order', [
	'help' => 'Order in component type lists'
]);

$formwriter->checkboxinput('com_is_active', 'Active', [
	'help' => 'Inactive component types cannot be used'
]);

echo '</div><div class="col-md-4">';

// Config Schema JSON editor
echo '<div class="mb-3">';
echo '<label class="form-label">Config Schema (JSON)</label>';

// Get current schema value
$current_schema = $component->get('com_config_schema');
if (is_array($current_schema)) {
	$schema_display = json_encode($current_schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} else {
	$schema_display = $current_schema ?: '';
}

echo '<textarea name="com_config_schema" class="form-control font-monospace" rows="20" style="font-size: 12px;">';
echo htmlspecialchars($schema_display);
echo '</textarea>';

echo '<div class="form-text">JSON schema defining form fields. See <a href="/specs/page_component_system.md" target="_blank">spec</a> for format.</div>';

// Quick reference
echo '<div class="mt-3 p-2 bg-light rounded" style="font-size: 11px;">';
echo '<strong>Schema Format:</strong><br>';
echo '<code>{"fields": [{"name": "...", "label": "...", "type": "textinput"}]}</code>';
echo '<br><br><strong>Field Types:</strong><br>';
$field_types = Component::get_field_types();
foreach ($field_types as $type => $label) {
	echo '<code>' . $type . '</code> ';
}
echo '</div>';

echo '</div>';

echo '</div></div>';

$formwriter->submitbutton('btn_submit', 'Save Component Type');
$formwriter->end_form();

$page->end_box();

$page->admin_footer();
?>
