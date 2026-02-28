<?php
/**
 * Hero Static Component
 *
 * Single hero section with heading, subheading, background, and CTA.
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

$heading = $component_config['heading'] ?? '';
$subheading = $component_config['subheading'] ?? '';
$background_image = $component_config['background_image'] ?? '';
$background_color = $component_config['background_color'] ?? '#f8f9fa';
$text_color = $component_config['text_color'] ?? '#212529';
$alignment = $component_config['alignment'] ?? 'center';
$height = $component_config['height'] ?? 'medium';
$cta_text = $component_config['cta_text'] ?? '';
$cta_link = $component_config['cta_link'] ?? '';
$cta_style = $component_config['cta_style'] ?? 'primary';

// Height classes
$height_classes = [
	'small' => 'py-5',
	'medium' => 'py-5',
	'large' => 'py-5',
	'fullscreen' => 'min-vh-100 d-flex align-items-center'
];
$height_class = $height_classes[$height] ?? $height_classes['medium'];

// Alignment class
$align_class = 'text-' . $alignment;

// Build background style
$bg_style = '';
if ($background_image) {
	$bg_style = "background-image: url(" . htmlspecialchars($background_image) . "); background-size: cover; background-position: center;";
} elseif ($background_color) {
	$bg_style = "background-color: " . htmlspecialchars($background_color) . ";";
}

if ($text_color) {
	$bg_style .= " color: " . htmlspecialchars($text_color) . ";";
}
?>

<section class="hero-static <?php echo $height_class; ?>" style="<?php echo $bg_style; ?>">
	<div class="container">
		<div class="row justify-content-center">
			<div class="col-lg-8 <?php echo $align_class; ?>">
				<?php if ($heading): ?>
					<h1 class="display-4 mb-3"><?php echo htmlspecialchars($heading); ?></h1>
				<?php endif; ?>

				<?php if ($subheading): ?>
					<p class="lead mb-4"><?php echo nl2br(htmlspecialchars($subheading)); ?></p>
				<?php endif; ?>

				<?php if ($cta_text && $cta_link): ?>
					<a href="<?php echo htmlspecialchars($cta_link); ?>" class="btn btn-<?php echo htmlspecialchars($cta_style); ?> btn-lg">
						<?php echo htmlspecialchars($cta_text); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>
