<?php
/**
 * Divider Component
 *
 * Horizontal divider line. Pure HTML5, no framework dependencies.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$line_style = $component_config['style'] ?? 'solid';
$width = $component_config['width'] ?? 'full';
$color = $component_config['color'] ?? '#dee2e6';

$width_map = [
	'full' => '100%',
	'medium' => '50%',
	'short' => '25%',
];
$width_value = $width_map[$width] ?? '100%';

$hr_style = 'border: 0; border-top: 1px ' . htmlspecialchars($line_style) . ' ' . htmlspecialchars($color) . ';';
$hr_style .= ' width: ' . $width_value . ';';
if ($width !== 'full') {
	$hr_style .= ' margin-left: auto; margin-right: auto;';
}
?>
<div style="max-width: 1100px; margin: 0 auto; padding: 0 1rem;">
	<hr style="<?php echo $hr_style; ?>">
</div>
