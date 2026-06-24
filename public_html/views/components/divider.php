<?php
/**
 * Divider Component
 *
 * Horizontal divider line. Pure HTML5, no framework dependencies. Renders inside
 * the `.jy-ui` kit; styling lives in the `.jy-divider` section in joinery-styles.css.
 * Line style, width and color arrive as --jy-divider-* custom properties.
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
$centered = ($width !== 'full') ? ' is-centered' : '';

$vars = '--jy-divider-style: ' . htmlspecialchars($line_style)
	. '; --jy-divider-color: ' . htmlspecialchars($color)
	. '; --jy-divider-width: ' . $width_value . ';';
?>
<div class="jy-ui jy-divider<?php echo $centered; ?>" style="<?php echo $vars; ?>">
	<hr>
</div>
