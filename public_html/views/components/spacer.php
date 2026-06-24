<?php
/**
 * Spacer Component
 *
 * Vertical spacing between components. Pure HTML5, no framework dependencies.
 * Renders inside the `.jy-ui` kit; the height presets live in the `.jy-spacer`
 * section in joinery-styles.css (selected via .is-{size} modifier).
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$height = $component_config['height'] ?? 'md';
$valid  = ['sm', 'md', 'lg', 'xl'];
$h      = in_array($height, $valid) ? $height : 'md';
$mod    = ($h === 'md') ? '' : ' is-' . $h;
?>
<div class="jy-ui jy-spacer<?php echo $mod; ?>" aria-hidden="true"></div>
