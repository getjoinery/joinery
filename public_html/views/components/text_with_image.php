<?php
/**
 * Text with Image Component
 *
 * Text content alongside an image, side by side, stacking on mobile. Pure HTML5,
 * no framework dependencies. Renders inside the `.jy-ui` kit; styling lives in the
 * `.jy-twi` feature section in joinery-styles.css. Per-instance image width and
 * background are passed as --jy-twi-* custom properties (server-computed).
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$heading = $component_config['heading'] ?? '';
$content = $component_config['content'] ?? '';
$image_url = $component_config['image_url'] ?? '';
$image_alt = $component_config['image_alt'] ?? '';
$layout = $component_config['layout'] ?? 'image_right';
$image_size = $component_config['image_size'] ?? 'medium';
$show_cta = !empty($component_config['show_cta']);
$cta_text = $component_config['cta_text'] ?? '';
$cta_url = $component_config['cta_url'] ?? '';
$background_color = $component_config['background_color'] ?? '';

// Image column width by size
$image_basis_map = [
	'small' => '33.333%',
	'medium' => '50%',
	'large' => '66.666%',
];
$image_basis = $image_basis_map[$image_size] ?? '50%';

// image_left reverses the flex row
$reverse_class = ($layout === 'image_left') ? ' is-reverse' : '';

// Per-instance values as custom properties (the sanctioned server-computed inline)
$section_vars = $background_color ? '--jy-twi-bg: ' . htmlspecialchars($background_color) . ';' : '';
$inner_vars   = '--jy-twi-img: ' . $image_basis . ';';
?>
<section class="jy-ui jy-twi-section"<?php if ($section_vars): ?> style="<?php echo $section_vars; ?>"<?php endif; ?>>
	<div class="jy-twi<?php echo $reverse_class; ?>" style="<?php echo $inner_vars; ?>">
		<div class="jy-twi-text">
			<?php if ($heading): ?>
				<h2><?php echo htmlspecialchars($heading); ?></h2>
			<?php endif; ?>
			<?php if ($content): ?>
				<div><?php echo $content; ?></div>
			<?php endif; ?>
			<?php if ($show_cta && $cta_text && $cta_url): ?>
				<a href="<?php echo htmlspecialchars($cta_url); ?>" class="btn btn-primary jy-twi-cta"><?php echo htmlspecialchars($cta_text); ?></a>
			<?php endif; ?>
		</div>
		<?php if ($image_url): ?>
			<div class="jy-twi-image">
				<img src="<?php echo htmlspecialchars($image_url); ?>" alt="<?php echo htmlspecialchars($image_alt); ?>" loading="lazy">
			</div>
		<?php endif; ?>
	</div>
</section>
