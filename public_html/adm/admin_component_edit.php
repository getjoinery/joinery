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
require_once(PathHelper::getIncludePath('data/content_versions_class.php'));

$session = SessionControl::get_instance();
$session->check_permission(5); // Admin level
$session->set_return();
$settings = Globalvars::get_instance();

// Load or create component instance
// CRITICAL: Check edit_primary_key_value (form submission) first, fallback to GET
if (isset($_POST['edit_primary_key_value'])) {
	$content = new PageContent($_POST['edit_primary_key_value'], TRUE);
} elseif (isset($_GET['pac_page_content_id']) && $_GET['pac_page_content_id']) {
	$content = new PageContent($_GET['pac_page_content_id'], TRUE);
} else {
	$content = new PageContent(NULL);
	// Pre-fill page_id if passed from admin_page
	if (isset($_GET['pag_page_id']) && $_GET['pag_page_id']) {
		$content->set('pac_pag_page_id', intval($_GET['pag_page_id']));
	}
}

// Check if loading a previous version
$loading_version = false;
$version_notice = '';
if (isset($_GET['cnv_content_version_id']) && $_GET['cnv_content_version_id'] && $content->key) {
	$loading_version = true;
	$content_version = new ContentVersion($_GET['cnv_content_version_id'], TRUE);

	// Parse the versioned config JSON
	$versioned_content = $content_version->get('cnv_content');
	$versioned_config = json_decode($versioned_content, true);
	if ($versioned_config !== null) {
		// Valid JSON - restore as component config
		$content->set_config($versioned_config);
	}

	$version_notice = 'Viewing version from ' .
		LibraryFunctions::convert_time($content_version->get('cnv_create_time'), 'UTC', $session->get_timezone()) .
		'. Save to restore this version.';
}

// Process form submission (skip if loading a version to prevent accidental overwrite)
if ($_POST && !$loading_version) {
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

if ($version_notice) {
	echo '<div class="alert alert-info">';
	echo '<i class="fas fa-history me-2"></i>';
	echo htmlspecialchars($version_notice);
	echo '</div>';
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

// Dynamic config fields based on component type
if ($current_type_id) {
	$component_type = new Component($current_type_id, TRUE);
	$schema_fields = $component_type->get_config_schema();

	if (!empty($schema_fields)) {
		echo '<hr><h5>Component Configuration</h5>';

		$current_config = $content->get_config();

		// Separate fields into regular and advanced
		$regular_fields = [];
		$advanced_fields = [];
		foreach ($schema_fields as $field) {
			if (!empty($field['advanced'])) {
				$advanced_fields[] = $field;
			} else {
				$regular_fields[] = $field;
			}
		}

		// Helper function to render a field
		$render_field = function($field, $formwriter, $current_config) {
			$field_name = $field['name'];
			$field_label = $field['label'] ?? $field_name;
			$field_type = $field['type'] ?? 'textinput';
			$field_help = $field['help'] ?? '';

			$field_default = $field['default'] ?? '';
			$field_options = [
				'value' => $current_config[$field_name] ?? $field_default,
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
			} elseif ($field_type === 'richtext') {
				$field_options['htmlmode'] = 'yes';
				$formwriter->textbox($field_name, $field_label, $field_options);
			} elseif ($field_type === 'imageselector') {
				// Pass through any imageselector-specific options from schema
				$imageselector_options = ['button_text', 'button_class', 'grid_columns',
					'thumbnail_width', 'preview_width', 'primary_color', 'border_radius',
					'ajax_endpoint', 'page_size', 'placeholder'];
				foreach ($imageselector_options as $opt) {
					if (isset($field[$opt])) {
						$field_options[$opt] = $field[$opt];
					}
				}
				$formwriter->imageselector($field_name, $field_label, $field_options);
			} elseif ($field_type === 'colorpicker') {
				// Pass through any colorpicker-specific options from schema
				$colorpicker_options = ['theme', 'max_swatches', 'custom_colors',
					'show_custom_picker', 'swatch_size', 'sort', 'initial_display'];
				foreach ($colorpicker_options as $opt) {
					if (isset($field[$opt])) {
						$field_options[$opt] = $field[$opt];
					}
				}
				$formwriter->colorpicker($field_name, $field_label, $field_options);
			} elseif (method_exists($formwriter, $field_type)) {
				$formwriter->$field_type($field_name, $field_label, $field_options);
			} else {
				$formwriter->textinput($field_name, $field_label, $field_options);
			}
		};

		// Render regular fields
		foreach ($regular_fields as $field) {
			$render_field($field, $formwriter, $current_config);
		}

		// Render advanced fields in collapsible section (includes slug + schema advanced fields)
		$advanced_count = count($advanced_fields) + 1; // +1 for slug
		$advanced_id = 'advanced_fields_' . uniqid();
		echo '<div class="advanced-fields-section mt-4">';
		echo '<a href="#" class="advanced-fields-toggle text-muted" data-target="' . $advanced_id . '">';
		echo '<i class="fas fa-cog me-1"></i>Show advanced fields (' . $advanced_count . ')';
		echo '</a>';
		echo '<div id="' . $advanced_id . '" class="advanced-fields-content" style="display:none;">';
		echo '<div class="mt-3 pt-3 border-top">';

		// Slug field (always advanced)
		$formwriter->textinput('pac_location_name', 'Slug (optional)', [
			'help' => 'Only needed for explicit rendering via ComponentRenderer::render(\'slug\'). Leave empty for page-attached components.'
		]);

		// Schema-defined advanced fields
		foreach ($advanced_fields as $field) {
			$render_field($field, $formwriter, $current_config);
		}

		echo '</div></div></div>';
	} else {
		// No component type selected yet, but still show slug in advanced section
		$advanced_id = 'advanced_fields_' . uniqid();
		echo '<div class="advanced-fields-section mt-4">';
		echo '<a href="#" class="advanced-fields-toggle text-muted" data-target="' . $advanced_id . '">';
		echo '<i class="fas fa-cog me-1"></i>Show advanced fields (1)';
		echo '</a>';
		echo '<div id="' . $advanced_id . '" class="advanced-fields-content" style="display:none;">';
		echo '<div class="mt-3 pt-3 border-top">';
		$formwriter->textinput('pac_location_name', 'Slug (optional)', [
			'help' => 'Only needed for explicit rendering via ComponentRenderer::render(\'slug\'). Leave empty for page-attached components.'
		]);
		echo '</div></div></div>';

		echo '<div class="alert alert-info mt-3">This component type has no configurable fields.</div>';
	}
} else {
	// No component type selected - show slug in advanced and prompt to select type
	$advanced_id = 'advanced_fields_' . uniqid();
	echo '<div class="advanced-fields-section mt-4">';
	echo '<a href="#" class="advanced-fields-toggle text-muted" data-target="' . $advanced_id . '">';
	echo '<i class="fas fa-cog me-1"></i>Show advanced fields (1)';
	echo '</a>';
	echo '<div id="' . $advanced_id . '" class="advanced-fields-content" style="display:none;">';
	echo '<div class="mt-3 pt-3 border-top">';
	$formwriter->textinput('pac_location_name', 'Slug (optional)', [
		'help' => 'Only needed for explicit rendering via ComponentRenderer::render(\'slug\'). Leave empty for page-attached components.'
	]);
	echo '</div></div></div>';

	echo '<div class="alert alert-warning mt-3">Select a component type to configure its settings.</div>';
}

// Page assignment options
echo '<div class="card mt-4"><div class="card-body">';
echo '<h6 class="card-title">Page Assignment</h6>';
echo '<div class="row">';
echo '<div class="col-md-6">';
$formwriter->dropinput('pac_pag_page_id', 'Assign to Page', [
	'options' => $pages_dropdown,
	'help' => 'Optional. Auto-render on this page via get_filled_content()'
]);
echo '</div><div class="col-md-6">';
$formwriter->textinput('pac_order', 'Display Order', [
	'help' => 'Order when multiple components on same page (lower = first)'
]);
echo '</div></div>';
echo '</div></div>';

echo '<hr>';
$formwriter->submitbutton('btn_submit', 'Save Component');
$formwriter->end_form();

echo '</div><div class="col-md-4">';

// Sidebar - Version history (only for existing components)
if ($content->key) {
	$content_versions = new MultiContentVersion(
		array('type' => ContentVersion::TYPE_PAGE_CONTENT, 'foreign_key_id' => $content->key),
		array('create_time' => 'DESC')
	);
	$content_versions->load();

	$version_options = $content_versions->get_dropdown_array($session, FALSE);

	if (count($version_options)) {
		echo '<div class="card"><div class="card-body">';
		echo '<h6 class="card-title">Version History</h6>';
		echo '<p class="text-muted small">Load a previous version to view or restore it.</p>';

		$version_form = $page->getFormWriter('form_load_version', [
			'method' => 'GET',
			'action' => '/admin/admin_component_edit'
		]);
		$version_form->begin_form();
		$version_form->hiddeninput('pac_page_content_id', '', ['value' => $content->key]);
		$version_form->dropinput('cnv_content_version_id', 'Version', [
			'options' => $version_options
		]);
		$version_form->submitbutton('btn_load', 'Load');
		$version_form->end_form();

		echo '</div></div>';
	}
}

// Preview info
if ($content->key && $content->get('pac_location_name')) {
	echo '<div class="card mt-3"><div class="card-body">';
	echo '<h6 class="card-title">Template Usage</h6>';
	echo '<code>echo ComponentRenderer::render(\'' . htmlspecialchars($content->get('pac_location_name')) . '\');</code>';
	echo '</div></div>';
}

echo '</div></div>';

$page->end_box();

// JavaScript for advanced fields toggle
?>
<script>
document.addEventListener('DOMContentLoaded', function() {
	// Main advanced fields toggle
	document.querySelectorAll('.advanced-fields-toggle').forEach(function(link) {
		link.addEventListener('click', function(e) {
			e.preventDefault();
			var targetId = this.getAttribute('data-target');
			var target = document.getElementById(targetId);
			if (target) {
				var isHidden = target.style.display === 'none';
				target.style.display = isHidden ? 'block' : 'none';
				var count = target.querySelectorAll('.mb-3, .form-group').length;
				if (isHidden) {
					this.innerHTML = '<i class="fas fa-cog me-1"></i>Hide advanced fields';
				} else {
					this.innerHTML = '<i class="fas fa-cog me-1"></i>Show advanced fields (' + count + ')';
				}
			}
		});
	});

	// Repeater advanced fields toggle (use event delegation for dynamic rows)
	document.addEventListener('click', function(e) {
		if (e.target.closest('.repeater-advanced-toggle')) {
			e.preventDefault();
			var link = e.target.closest('.repeater-advanced-toggle');
			var targetId = link.getAttribute('data-target');
			var target = document.getElementById(targetId);
			if (target) {
				var isHidden = target.style.display === 'none';
				target.style.display = isHidden ? 'block' : 'none';
				var count = target.querySelectorAll('.col-md, .col-md-3, .col-md-5').length;
				if (isHidden) {
					link.innerHTML = '<i class="fas fa-cog me-1"></i>Hide advanced';
				} else {
					link.innerHTML = '<i class="fas fa-cog me-1"></i>Advanced (' + count + ')';
				}
			}
		}
	});
});
</script>
<?php

$page->admin_footer();
?>
