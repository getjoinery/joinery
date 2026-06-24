<?php
/**
 * Text Block Component
 *
 * Heading with rich text content. Pure HTML5, no framework dependencies. Renders
 * inside the `.jy-ui` kit; styling lives in the `.jy-textblock` section in
 * joinery-styles.css. Background, text colour and alignment arrive as --jy-tb-*
 * custom properties (server-computed).
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

// Per-instance values as custom properties (server-computed inline)
$section_vars = '';
if ($background_color) { $section_vars .= '--jy-tb-bg: ' . htmlspecialchars($background_color) . ';'; }
if ($text_color)       { $section_vars .= ' --jy-tb-text: ' . htmlspecialchars($text_color) . ';'; }
$inner_vars = '--jy-tb-align: ' . htmlspecialchars($alignment) . ';';
?>
<section class="jy-ui jy-textblock-section"<?php if ($section_vars): ?> style="<?php echo $section_vars; ?>"<?php endif; ?>>
	<div class="jy-textblock" style="<?php echo $inner_vars; ?>">
		<?php if ($heading): ?>
			<<?php echo $heading_level; ?> class="jy-textblock-heading"><?php echo htmlspecialchars($heading); ?></<?php echo $heading_level; ?>>
		<?php endif; ?>
		<?php if ($content): ?>
			<div><?php echo $content; ?></div>
		<?php endif; ?>
	</div>
</section>
