<?php
/**
 * Feature Grid Component Definition
 *
 * @see /specs/page_component_system.md
 */
return [
	'type_key' => 'feature_grid',
	'title' => 'Feature Grid',
	'description' => 'Grid of icon + title + description items',
	'category' => 'features',
	'icon' => 'bx bx-grid-alt',
	'template_file' => 'feature_grid.php',
	'config_schema' => [
		'fields' => [
			['name' => 'heading', 'label' => 'Section Heading', 'type' => 'textinput'],
			['name' => 'subheading', 'label' => 'Section Subheading', 'type' => 'textarea'],
			['name' => 'columns', 'label' => 'Columns', 'type' => 'dropinput', 'options' => [2 => '2 Columns', 3 => '3 Columns', 4 => '4 Columns']],
			[
				'name' => 'features',
				'label' => 'Features',
				'type' => 'repeater',
				'fields' => [
					['name' => 'icon', 'label' => 'Icon', 'type' => 'textinput'],
					['name' => 'title', 'label' => 'Title', 'type' => 'textinput'],
					['name' => 'description', 'label' => 'Description', 'type' => 'textarea'],
					['name' => 'link', 'label' => 'Link (optional)', 'type' => 'textinput'],
				]
			],
			['name' => 'style', 'label' => 'Display Style', 'type' => 'dropinput', 'options' => ['centered' => 'Centered (icon above)', 'left' => 'Left Aligned (icon left)', 'card' => 'Cards with Shadow']],
			['name' => 'icon_style', 'label' => 'Icon Style', 'type' => 'dropinput', 'options' => ['plain' => 'Plain Icon', 'circle' => 'Circle Background', 'square' => 'Square Background']],
			['name' => 'icon_color', 'label' => 'Icon Color', 'type' => 'textinput', 'help' => 'Hex color, e.g., #007bff'],
			['name' => 'background_color', 'label' => 'Section Background', 'type' => 'textinput', 'help' => 'Hex color, e.g., #ffffff'],
		]
	],
	'logic_function' => null,
	'requires_plugin' => null,
];
