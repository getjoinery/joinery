<?php
/**
 * Custom HTML Component
 *
 * Raw HTML escape hatch. Outputs stored HTML directly with no wrapper.
 * Callers are responsible for including their own section/container markup
 * in the stored HTML.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$html = $component_config['html'] ?? '';

if (empty($html)) {
	return;
}

echo $html;
