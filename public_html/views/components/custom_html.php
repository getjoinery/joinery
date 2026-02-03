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
 *
 * @see /specs/page_component_system.md
 */

$html = $component_config['html'] ?? '';
$container = $component_config['container'] ?? true;

if (empty($html)) {
	return;
}

if ($container): ?>
<section class="custom-html py-4">
	<div class="container">
		<?php echo $html; // Note: HTML is not escaped - intentionally allows raw HTML ?>
	</div>
</section>
<?php else: ?>
<?php echo $html; // Note: HTML is not escaped - intentionally allows raw HTML ?>
<?php endif; ?>
