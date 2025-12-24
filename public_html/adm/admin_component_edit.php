<?php
/**
 * Admin Component Edit
 *
 * Edit a component instance. Dynamically generates form fields
 * based on the component type's config schema.
 *
 * @see /specs/page_component_system.md
 */

require_once(PathHelper::getIncludePath('includes/AdminPage.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/FormWriterV2Base.php'));
require_once(PathHelper::getIncludePath('data/components_class.php'));
require_once(PathHelper::getIncludePath('data/page_contents_class.php'));
require_once(PathHelper::getIncludePath('data/pages_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(5); // Admin level
$session->set_return();
$settings = Globalvars::get_instance();

// Load or create component instance
if (isset($_REQUEST['pac_page_content_id']) && $_REQUEST['pac_page_content_id']) {
	$content = new PageContent($_REQUEST['pac_page_content_id'], TRUE);
} else {
	$content = new PageContent(NULL);
	// Pre-fill page_id if passed from admin_page
	if (isset($_GET['pag_page_id']) && $_GET['pag_page_id']) {
		$content->set('pac_pag_page_id', intval($_GET['pag_page_id']));
	}
}

// Process form submission
if ($_POST) {
	// Set basic fields
	$content->set('pac_title', trim($_POST['pac_title']));

	// Slug is optional for page-attached components, required for standalone
	$slug = trim($_POST['pac_location_name']);
	if ($slug) {
		// Sanitize provided slug
		$slug = $content->generate_slug($slug);
		$content->set('pac_location_name', $slug);
	} else {
		// No slug provided - leave empty (fine for page-attached components)
		$content->set('pac_location_name', null);
	}

	// Set component type
	$content->set('pac_com_component_id', $_POST['pac_com_component_id'] ?: null);

	// Set page assignment (optional)
	$content->set('pac_pag_page_id', $_POST['pac_pag_page_id'] ?: null);

	// Set order
	$content->set('pac_order', intval($_POST['pac_order']));

	// Set published status
	$content->set('pac_is_published', isset($_POST['pac_is_published']));

	// Set user if new
	if (!$content->key) {
		$content->set('pac_usr_user_id', $session->get_user_id());
	}

	// Build config from POST data based on component type schema
	$config = array();
	$component_type_id = $_POST['pac_com_component_id'];
	if ($component_type_id) {
		$component_type = new Component($component_type_id, TRUE);
		$schema_fields = $component_type->get_config_schema();

		foreach ($schema_fields as $field) {
			$field_name = $field['name'];
			$field_type = $field['type'] ?? 'textinput';

			if ($field_type === 'repeater') {
				// Handle repeater data
				if (isset($_POST[$field_name]) && is_array($_POST[$field_name])) {
					$config[$field_name] = FormWriterV2Base::process_repeater_data($_POST[$field_name]);
				} else {
					$config[$field_name] = array();
				}
			} elseif ($field_type === 'checkboxinput') {
				$config[$field_name] = isset($_POST[$field_name]) ? true : false;
			} else {
				$config[$field_name] = isset($_POST[$field_name]) ? $_POST[$field_name] : '';
			}
		}
	}

	$content->set_config($config);

	try {
		$content->prepare();
		$content->save();

		// Redirect based on context: back to page if attached, otherwise to components list
		$page_id = $content->get('pac_pag_page_id');
		if ($page_id) {
			LibraryFunctions::redirect('/admin/admin_page?pag_page_id=' . $page_id);
		} else {
			LibraryFunctions::redirect('/admin/admin_components');
		}
		exit();
	} catch (Exception $e) {
		$error_message = $e->getMessage();
	}
}

// Get component types for dropdown
$component_types = new MultiComponent(array('deleted' => false, 'active' => true), array('com_title' => 'ASC'));
$component_types->load();

// Get pages for dropdown
$pages = new MultiPage(array('deleted' => false), array('pag_title' => 'ASC'));
$pages->load();
$pages_dropdown = array('' => '-- Not assigned to a page --');
foreach ($pages as $pg) {
	$pages_dropdown[$pg->key] = $pg->get('pag_title');
}

$page = new AdminPage();
$page->admin_header(array(
	'menu-id' => 'pages',
	'breadcrumbs' => array(
		'Pages' => '/admin/admin_pages',
		'Components' => '/admin/admin_components',
		($content->key ? 'Edit' : 'Add') => '',
	),
	'session' => $session,
));

$pageoptions['title'] = $content->key ? 'Edit Component' : 'Add Component';
$page->begin_box($pageoptions);

if (isset($error_message)) {
	echo '<div class="alert alert-danger">' . htmlspecialchars($error_message) . '</div>';
}

$formwriter = $page->getFormWriter('form1', [
	'model' => $content,
	'edit_primary_key_value' => $content->key
]);

$formwriter->begin_form();

// Hidden field for edit mode - ensures POST contains the primary key
if ($content->key) {
	$formwriter->hiddeninput('pac_page_content_id', ['value' => $content->key]);
}

echo '<div class="row"><div class="col-md-8">';

// Component type selection
echo '<div class="mb-3">';
echo '<label class="form-label">Component Type</label>';
$type_change_url = '/admin/admin_component_edit?';
if ($content->key) {
	$type_change_url .= 'pac_page_content_id=' . $content->key . '&';
} elseif ($content->get('pac_pag_page_id')) {
	$type_change_url .= 'pag_page_id=' . $content->get('pac_pag_page_id') . '&';
}
echo '<select name="pac_com_component_id" id="pac_com_component_id" class="form-select" onchange="location.href=\'' . $type_change_url . 'component_type=\'+this.value">';
echo '<option value="">-- Select Component Type --</option>';

$current_type_id = $_GET['component_type'] ?? $content->get('pac_com_component_id');
foreach ($component_types as $type) {
	$selected = ($current_type_id == $type->key) ? ' selected' : '';
	echo '<option value="' . $type->key . '"' . $selected . '>' . htmlspecialchars($type->get('com_title'));
	$type_key = $type->get('com_type_key');
	if ($type_key) {
		echo ' (' . htmlspecialchars($type_key) . ')';
	}
	echo '</option>';
}
echo '</select>';
echo '<div class="form-text">Select the type of component to create</div>';
echo '</div>';

$formwriter->textinput('pac_title', 'Label', [
	'help' => 'Internal name for identifying this component'
]);

$formwriter->textinput('pac_location_name', 'Slug (optional)', [
	'help' => 'Only needed for explicit rendering via ComponentRenderer::render(\'slug\'). Leave empty for page-attached components.'
]);

// Dynamic config fields based on component type
if ($current_type_id) {
	$component_type = new Component($current_type_id, TRUE);
	$schema_fields = $component_type->get_config_schema();

	if (!empty($schema_fields)) {
		echo '<hr><h5>Component Configuration</h5>';
		echo '<p class="text-muted">Configure the content and settings for this component.</p>';

		$current_config = $content->get_config();

		foreach ($schema_fields as $field) {
			$field_name = $field['name'];
			$field_label = $field['label'] ?? $field_name;
			$field_type = $field['type'] ?? 'textinput';
			$field_help = $field['help'] ?? '';

			$field_options = [
				'value' => $current_config[$field_name] ?? '',
				'help' => $field_help,
				'model' => false,
				'validation' => false
			];

			// Handle different field types
			if ($field_type === 'repeater') {
				$field_options['fields'] = $field['fields'] ?? [];
				$field_options['add_label'] = '+ Add ' . $field_label;
				$formwriter->repeater($field_name, $field_label, $field_options);
			} elseif ($field_type === 'dropinput' && isset($field['options'])) {
				$field_options['options'] = $field['options'];
				$formwriter->dropinput($field_name, $field_label, $field_options);
			} elseif ($field_type === 'checkboxinput') {
				$formwriter->checkboxinput($field_name, $field_label, $field_options);
			} elseif ($field_type === 'textarea' || $field_type === 'textbox') {
				$formwriter->textarea($field_name, $field_label, $field_options);
			} elseif (method_exists($formwriter, $field_type)) {
				$formwriter->$field_type($field_name, $field_label, $field_options);
			} else {
				$formwriter->textinput($field_name, $field_label, $field_options);
			}
		}
	} else {
		echo '<div class="alert alert-info">This component type has no configurable fields.</div>';
	}
} else {
	echo '<div class="alert alert-warning">Select a component type to configure its settings.</div>';
}

echo '</div><div class="col-md-4">';

// Sidebar with publishing options
echo '<div class="card"><div class="card-body">';
echo '<h6 class="card-title">Publishing</h6>';

$formwriter->checkboxinput('pac_is_published', 'Published', [
	'help' => 'Only published components are rendered on the site'
]);

$formwriter->dropinput('pac_pag_page_id', 'Assign to Page', [
	'options' => $pages_dropdown,
	'help' => 'Optional. Auto-render on this page via get_filled_content()'
]);

$formwriter->textinput('pac_order', 'Display Order', [
	'help' => 'Order when multiple components on same page (lower = first)'
]);

echo '</div></div>';

// Preview info
if ($content->key && $content->get('pac_location_name')) {
	echo '<div class="card mt-3"><div class="card-body">';
	echo '<h6 class="card-title">Template Usage</h6>';
	echo '<code>echo ComponentRenderer::render(\'' . htmlspecialchars($content->get('pac_location_name')) . '\');</code>';
	echo '</div></div>';
}

echo '</div></div>';

echo '<hr>';
$formwriter->submitbutton('btn_submit', 'Save Component');
$formwriter->end_form();

$page->end_box();

// Include repeater JavaScript
echo '<script>
document.addEventListener("DOMContentLoaded", function() {
    // Add row
    document.querySelectorAll(".repeater-add").forEach(function(btn) {
        btn.addEventListener("click", function() {
            var repeater = this.closest(".repeater");
            var template = repeater.querySelector(".repeater-template");
            var items = repeater.querySelector(".repeater-items");
            var nextIndex = items.querySelectorAll(".repeater-row").length;

            // Clone template and replace __INDEX__ with actual index
            var clone = template.content.cloneNode(true);
            var html = clone.querySelector(".repeater-row").outerHTML.replace(/__INDEX__/g, nextIndex);

            items.insertAdjacentHTML("beforeend", html);
        });
    });

    // Remove row (delegated for dynamically added rows)
    document.addEventListener("click", function(e) {
        if (e.target.classList.contains("repeater-remove")) {
            e.target.closest(".repeater-row").remove();
        }
    });
});
</script>';

$page->admin_footer();
?>
