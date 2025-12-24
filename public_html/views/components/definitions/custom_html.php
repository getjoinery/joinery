<?php
/**
 * Custom HTML Component Definition
 *
 * @see /specs/page_component_system.md
 */
return [
	'type_key' => 'custom_html',
	'title' => 'Custom HTML',
	'description' => 'Raw HTML for advanced users',
	'category' => 'custom',
	'icon' => 'bx bx-code-alt',
	'template_file' => 'custom_html.php',
	'config_schema' => [
		'fields' => [
			['name' => 'html', 'label' => 'HTML Code', 'type' => 'textarea', 'help' => 'Enter custom HTML. Be careful with scripts and styles.'],
			['name' => 'container', 'label' => 'Wrap in Container', 'type' => 'checkboxinput', 'help' => 'Wraps content in a standard container for consistent width'],
			['name' => 'admin_note', 'label' => 'Admin Notes', 'type' => 'textarea', 'help' => 'Notes for administrators (not displayed on site)'],
		]
	],
	'logic_function' => null,
	'requires_plugin' => null,
];
