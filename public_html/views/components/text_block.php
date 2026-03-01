<?php
/**
 * Text Block Component
 *
 * Heading with rich text content. Pure HTML5, no framework dependencies.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$heading = $component_config['heading'] ?? '';
$heading_level = $component_config['heading_level'] ?? 'h2';
$content = $component_config['content'] ?? '';
$alignment = $component_config['alignment'] ?? 'left';
$background_color = $component_config['background_color'] ?? '';
$text_color = $component_config['text_color'] ?? '';

// Validate heading level
$allowed_levels = ['h2', 'h3', 'h4'];
if (!in_array($heading_level, $allowed_levels)) {
	$heading_level = 'h2';
}

// Build section style
$section_style = '';
if ($background_color) {
	$section_style .= 'background-color: ' . htmlspecialchars($background_color) . ';';
}
if ($text_color) {
	$section_style .= ' color: ' . htmlspecialchars($text_color) . ';';
}

// Build content style
$content_style = 'max-width: 1100px; margin: 0 auto; padding: 3rem 1rem;';
$content_style .= ' text-align: ' . htmlspecialchars($alignment) . ';';
?>
<section<?php if ($section_style): ?> style="<?php echo $section_style; ?>"<?php endif; ?>>
	<div style="<?php echo $content_style; ?>">
		<?php if ($heading): ?>
			<<?php echo $heading_level; ?> style="margin: 0 0 1rem 0;"><?php echo htmlspecialchars($heading); ?></<?php echo $heading_level; ?>>
		<?php endif; ?>
		<?php if ($content): ?>
			<div><?php echo $content; ?></div>
		<?php endif; ?>
	</div>
</section>
