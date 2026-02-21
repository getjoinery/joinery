<?php
/**
 * Custom HTML Component
 *
 * Raw HTML for advanced users. Essential escape hatch for freeform content
 * in component-based pages.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 *   $container_class - CSS class for container (from layout system)
 *   $container_style - Inline style for container (from layout system)
 *   $max_height_style - Inline style for max height (from layout system)
 *
 * @see /specs/page_component_system.md
 */

$html = $component_config['html'] ?? '';

if (empty($html)) {
	return;
}
?>
<section class="custom-html py-4">
	<div class="container">
		<?php echo $html; // Note: HTML is not escaped - intentionally allows raw HTML ?>
	</div>
</section>
