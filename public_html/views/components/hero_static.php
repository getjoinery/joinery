<?php
/**
 * Hero Static Component
 *
 * Single hero section with heading, subheading, background, and CTA.
 * Pure HTML5, no framework dependencies.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$heading    = $component_config['heading'] ?? '';
$subheading = $component_config['subheading'] ?? '';
$bg_image   = $component_config['background_image'] ?? '';
$bg_color   = $component_config['background_color'] ?? '#f8f9fa';
$text_color = $component_config['text_color'] ?? '#212529';
$alignment  = $component_config['alignment'] ?? 'center';
$height     = $component_config['height'] ?? 'medium';
$cta_text   = $component_config['cta_text'] ?? '';
$cta_link   = $component_config['cta_link'] ?? '';
$cta_style  = $component_config['cta_style'] ?? 'primary';

$padding_map = ['small' => '3rem 1.5rem', 'medium' => '5rem 1.5rem', 'large' => '8rem 1.5rem'];
$padding = $padding_map[$height] ?? $padding_map['medium'];

if ($height === 'fullscreen') {
	$section_style = 'min-height: 100vh; display: flex; align-items: center; padding: ' . $padding . ';';
} else {
	$section_style = 'padding: ' . $padding . ';';
}

if ($bg_image) {
	$section_style .= ' background-image: url(' . htmlspecialchars($bg_image) . '); background-size: cover; background-position: center;';
} else {
	$section_style .= ' background-color: ' . htmlspecialchars($bg_color) . ';';
}
$section_style .= ' color: ' . htmlspecialchars($text_color) . ';';

$btn_primary   = 'display: inline-block; padding: 0.75rem 2rem; background-color: ' . htmlspecialchars($text_color) . '; color: ' . htmlspecialchars($bg_color) . '; text-decoration: none; border-radius: 4px; font-weight: 600;';
$btn_secondary = 'display: inline-block; padding: 0.75rem 2rem; border: 2px solid currentColor; color: inherit; text-decoration: none; border-radius: 4px; font-weight: 600;';
$btn_inline    = ($cta_style === 'secondary') ? $btn_secondary : $btn_primary;
?>
<section style="<?php echo $section_style; ?>">
	<div style="max-width: 720px; margin: 0 auto; text-align: <?php echo htmlspecialchars($alignment); ?>; width: 100%;">
		<?php if ($heading): ?>
			<h1 style="margin: 0 0 1rem 0; font-size: 2.5rem; line-height: 1.2;"><?php echo htmlspecialchars($heading); ?></h1>
		<?php endif; ?>

		<?php if ($subheading): ?>
			<p style="margin: 0 0 2rem 0; font-size: 1.2rem; opacity: 0.85; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($subheading)); ?></p>
		<?php endif; ?>

		<?php if ($cta_text && $cta_link): ?>
			<a href="<?php echo htmlspecialchars($cta_link); ?>" style="<?php echo $btn_inline; ?>"><?php echo htmlspecialchars($cta_text); ?></a>
		<?php endif; ?>
	</div>
</section>
