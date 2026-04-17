<?php
/**
 * Text with Image Component
 *
 * Text content alongside an image, side by side. Pure HTML5, no framework dependencies.
 * Uses flexbox for layout with responsive stacking on mobile.
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

// Image flex-basis based on size
$image_basis_map = [
	'small' => '33.333%',
	'medium' => '50%',
	'large' => '66.666%',
];
$image_basis = $image_basis_map[$image_size] ?? '50%';

// Flex direction based on layout
$flex_direction = ($layout === 'image_left') ? 'row-reverse' : 'row';

// Section style
$section_style = '';
if ($background_color) {
	$section_style = 'background-color: ' . htmlspecialchars($background_color) . ';';
}

// Unique class for scoped styles
$uid = 'twi-' . htmlspecialchars($component_slug);
?>
<style>
.<?php echo $uid; ?>-wrap {
	max-width: 1100px;
	margin: 0 auto;
	padding: 3rem 1rem;
	display: flex;
	flex-direction: <?php echo $flex_direction; ?>;
	gap: 2rem;
	align-items: center;
}
.<?php echo $uid; ?>-text {
	flex: 1 1 0%;
	min-width: 0;
}
.<?php echo $uid; ?>-image {
	flex: 0 0 <?php echo $image_basis; ?>;
	min-width: 0;
}
.<?php echo $uid; ?>-image img {
	width: 100%;
	height: auto;
	display: block;
	object-fit: cover;
	border-radius: 4px;
}
.<?php echo $uid; ?>-cta {
	display: inline-block;
	margin-top: 1rem;
	padding: 0.6rem 1.5rem;
	background-color: #333;
	color: #fff;
	text-decoration: none;
	border-radius: 4px;
}
.<?php echo $uid; ?>-cta:hover {
	opacity: 0.85;
}
@media (max-width: 768px) {
	.<?php echo $uid; ?>-wrap {
		flex-direction: column;
	}
	.<?php echo $uid; ?>-image {
		flex: 0 0 100%;
	}
}
</style>
<section<?php if ($section_style): ?> style="<?php echo $section_style; ?>"<?php endif; ?>>
	<div class="<?php echo $uid; ?>-wrap">
		<div class="<?php echo $uid; ?>-text">
			<?php if ($heading): ?>
				<h2 style="margin: 0 0 1rem 0;"><?php echo htmlspecialchars($heading); ?></h2>
			<?php endif; ?>
			<?php if ($content): ?>
				<div><?php echo $content; ?></div>
			<?php endif; ?>
			<?php if ($show_cta && $cta_text && $cta_url): ?>
				<a href="<?php echo htmlspecialchars($cta_url); ?>" class="<?php echo $uid; ?>-cta"><?php echo htmlspecialchars($cta_text); ?></a>
			<?php endif; ?>
		</div>
		<?php if ($image_url): ?>
			<div class="<?php echo $uid; ?>-image">
				<img src="<?php echo htmlspecialchars($image_url); ?>" alt="<?php echo htmlspecialchars($image_alt); ?>" loading="lazy">
			</div>
		<?php endif; ?>
	</div>
</section>
