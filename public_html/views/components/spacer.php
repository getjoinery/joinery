<?php
/**
 * Spacer Component
 *
 * Vertical spacing between components. Pure HTML5, no framework dependencies.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$height = $component_config['height'] ?? 'md';

$height_map = [
	'sm' => '1rem',
	'md' => '2rem',
	'lg' => '4rem',
	'xl' => '6rem',
];

$height_value = $height_map[$height] ?? $height_map['md'];
?>
<div style="height: <?php echo $height_value; ?>;" aria-hidden="true"></div>
