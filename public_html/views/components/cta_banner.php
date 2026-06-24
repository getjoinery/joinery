<?php
/**
 * CTA Banner Component
 *
 * Full-width call-to-action banner. Pure HTML5, no framework dependencies. Renders
 * inside the `.jy-ui` kit; styling lives in the `.jy-cta-banner` section in
 * joinery-styles.css. Background and text colour arrive as --jy-cta-* custom
 * properties (server-computed).
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$heading         = $component_config['heading'] ?? '';
$subheading      = $component_config['subheading'] ?? '';
$cta_text        = $component_config['cta_text'] ?? 'Get Started';
$cta_link        = $component_config['cta_link'] ?? '';
$background_type = $component_config['background_type'] ?? 'gradient';
$bg_color        = $component_config['background_color'] ?? '#007bff';
$grad_start      = $component_config['gradient_start'] ?? '#667eea';
$grad_end        = $component_config['gradient_end'] ?? '#764ba2';
$bg_image        = $component_config['background_image'] ?? '';
$text_color      = $component_config['text_color'] ?? '#ffffff';

$show_secondary  = $component_config['secondary_cta']['show'] ?? false;
$secondary_text  = $component_config['secondary_cta']['text'] ?? '';
$secondary_link  = $component_config['secondary_cta']['link'] ?? '';

// Background value + optional image modifier
$image_mod = '';
switch ($background_type) {
	case 'color':
		$bg_value = htmlspecialchars($bg_color);
		break;
	case 'image':
		$bg_value = 'url(' . htmlspecialchars($bg_image) . ')';
		$image_mod = ' has-image';
		break;
	default:
		$bg_value = 'linear-gradient(135deg, ' . htmlspecialchars($grad_start) . ' 0%, ' . htmlspecialchars($grad_end) . ' 100%)';
}

$vars = '--jy-cta-bg: ' . $bg_value . '; --jy-cta-text: ' . htmlspecialchars($text_color) . ';';
?>
<section class="jy-ui jy-cta-banner<?php echo $image_mod; ?>" style="<?php echo $vars; ?>">
	<div class="jy-cta-banner-inner">
		<?php if ($heading): ?>
			<h2><?php echo htmlspecialchars($heading); ?></h2>
		<?php endif; ?>

		<?php if ($subheading): ?>
			<p class="jy-cta-banner-sub"><?php echo nl2br(htmlspecialchars($subheading)); ?></p>
		<?php endif; ?>

		<?php if ($cta_text && $cta_link): ?>
			<div class="jy-cta-banner-actions">
				<a href="<?php echo htmlspecialchars($cta_link); ?>" class="jy-cta-banner-btn"><?php echo htmlspecialchars($cta_text); ?></a>
				<?php if ($show_secondary && $secondary_text && $secondary_link): ?>
					<a href="<?php echo htmlspecialchars($secondary_link); ?>" class="jy-cta-banner-btn is-secondary"><?php echo htmlspecialchars($secondary_text); ?></a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</section>
