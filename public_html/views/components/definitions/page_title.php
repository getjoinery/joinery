<?php
/**
 * Page Title Component Definition
 *
 * @see /specs/page_component_system.md
 */
return [
	'type_key' => 'page_title',
	'title' => 'Page Title',
	'description' => 'Page title with optional subtitle and breadcrumb navigation',
	'category' => 'layout',
	'icon' => 'bx bx-heading',
	'template_file' => 'page_title.php',
	'config_schema' => [
		'fields' => [
			['name' => 'title', 'label' => 'Title', 'type' => 'textinput'],
			['name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'textarea'],
			['name' => 'alignment', 'label' => 'Text Alignment', 'type' => 'dropinput', 'options' => ['left' => 'Left', 'center' => 'Center', 'right' => 'Right']],
			['name' => 'show_breadcrumbs', 'label' => 'Show Breadcrumbs', 'type' => 'checkboxinput'],
			[
				'name' => 'breadcrumbs',
				'label' => 'Breadcrumb Items',
				'type' => 'repeater',
				'fields' => [
					['name' => 'text', 'label' => 'Text', 'type' => 'textinput'],
					['name' => 'link', 'label' => 'Link', 'type' => 'textinput'],
				]
			],
			['name' => 'background_color', 'label' => 'Background Color', 'type' => 'textinput', 'help' => 'Hex color, e.g., #f8f9fa'],
			['name' => 'text_color', 'label' => 'Text Color', 'type' => 'textinput', 'help' => 'Hex color, e.g., #212529'],
		]
	],
	'logic_function' => null,
	'requires_plugin' => null,
];
