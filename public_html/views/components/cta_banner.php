<?php
/**
 * CTA Banner Component
 *
 * Full-width call-to-action banner.
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
$cta_text = $component_config['cta_text'] ?? 'Get Started';
$cta_link = $component_config['cta_link'] ?? '';
$background_type = $component_config['background_type'] ?? 'gradient';
$background_color = $component_config['background_color'] ?? '#007bff';
$gradient_start = $component_config['gradient_start'] ?? '#667eea';
$gradient_end = $component_config['gradient_end'] ?? '#764ba2';
$background_image = $component_config['background_image'] ?? '';
$text_color = $component_config['text_color'] ?? '#ffffff';

// Secondary CTA
$show_secondary = $component_config['secondary_cta']['show'] ?? false;
$secondary_text = $component_config['secondary_cta']['text'] ?? '';
$secondary_link = $component_config['secondary_cta']['link'] ?? '';

// Build background style
$bg_style = '';
switch ($background_type) {
	case 'color':
		$bg_style = "background-color: " . htmlspecialchars($background_color) . ";";
		break;
	case 'gradient':
		$bg_style = "background: linear-gradient(135deg, " . htmlspecialchars($gradient_start) . " 0%, " . htmlspecialchars($gradient_end) . " 100%);";
		break;
	case 'image':
		$bg_style = "background-image: url(" . htmlspecialchars($background_image) . "); background-size: cover; background-position: center;";
		break;
}

$bg_style .= " color: " . htmlspecialchars($text_color) . ";";
?>

<section class="cta-banner py-5" style="<?php echo $bg_style; ?>">
	<div class="jy-container">
		<div class="row justify-content-center">
			<div class="col-lg-10 text-center">
				<?php if ($heading): ?>
					<h2 class="display-5 mb-3"><?php echo htmlspecialchars($heading); ?></h2>
				<?php endif; ?>

				<?php if ($subheading): ?>
					<p class="lead mb-4" style="opacity: 0.9;"><?php echo nl2br(htmlspecialchars($subheading)); ?></p>
				<?php endif; ?>

				<div class="cta-buttons">
					<?php if ($cta_text && $cta_link): ?>
						<a href="<?php echo htmlspecialchars($cta_link); ?>" class="btn btn-light btn-lg px-4 me-2">
							<?php echo htmlspecialchars($cta_text); ?>
						</a>
					<?php endif; ?>

					<?php if ($show_secondary && $secondary_text && $secondary_link): ?>
						<a href="<?php echo htmlspecialchars($secondary_link); ?>" class="btn btn-outline-light btn-lg px-4">
							<?php echo htmlspecialchars($secondary_text); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
</section>
