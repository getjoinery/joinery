<?php
/**
 * Hero Static Component
 *
 * Single hero section with heading, subheading, background, and CTA. Pure HTML5,
 * no framework dependencies. Renders inside the `.jy-ui` kit; styling lives in the
 * `.jy-hero` section in joinery-styles.css. Background, colours and alignment
 * arrive as --jy-hero-* custom properties (server-computed).
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

// Height preset modifier ('medium' = default padding, no modifier)
$height_mod = '';
if ($height === 'small') { $height_mod = ' is-sm'; }
elseif ($height === 'large') { $height_mod = ' is-lg'; }
elseif ($height === 'fullscreen') { $height_mod = ' is-fullscreen'; }

// Background: image overlays a cover background, otherwise a flat colour
if ($bg_image) {
	$bg_value = 'url(' . htmlspecialchars($bg_image) . ')';
	$image_mod = ' has-image';
} else {
	$bg_value = htmlspecialchars($bg_color);
	$image_mod = '';
}

$btn_mod = ($cta_style === 'secondary') ? ' is-secondary' : '';

$vars = '--jy-hero-bg: ' . $bg_value
	. '; --jy-hero-text: ' . htmlspecialchars($text_color)
	. '; --jy-hero-btnfg: ' . htmlspecialchars($bg_color)
	. '; --jy-hero-align: ' . htmlspecialchars($alignment) . ';';
?>
<section class="jy-ui jy-hero<?php echo $height_mod . $image_mod; ?>" style="<?php echo $vars; ?>">
	<div class="jy-hero-inner">
		<?php if ($heading): ?>
			<h1><?php echo htmlspecialchars($heading); ?></h1>
		<?php endif; ?>

		<?php if ($subheading): ?>
			<p class="jy-hero-sub"><?php echo nl2br(htmlspecialchars($subheading)); ?></p>
		<?php endif; ?>

		<?php if ($cta_text && $cta_link): ?>
			<a href="<?php echo htmlspecialchars($cta_link); ?>" class="jy-hero-btn<?php echo $btn_mod; ?>"><?php echo htmlspecialchars($cta_text); ?></a>
		<?php endif; ?>
	</div>
</section>
