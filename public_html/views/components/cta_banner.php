<?php
/**
 * CTA Banner Component
 *
 * Full-width call-to-action banner. Pure HTML5, no framework dependencies.
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

switch ($background_type) {
	case 'color':
		$bg_style = 'background-color: ' . htmlspecialchars($bg_color) . ';';
		break;
	case 'image':
		$bg_style = 'background-image: url(' . htmlspecialchars($bg_image) . '); background-size: cover; background-position: center;';
		break;
	default:
		$bg_style = 'background: linear-gradient(135deg, ' . htmlspecialchars($grad_start) . ' 0%, ' . htmlspecialchars($grad_end) . ' 100%);';
}

$section_style = $bg_style . ' color: ' . htmlspecialchars($text_color) . '; padding: 4rem 1.5rem; text-align: center;';
?>
<section style="<?php echo $section_style; ?>">
	<div style="max-width: 720px; margin: 0 auto;">
		<?php if ($heading): ?>
			<h2 style="margin: 0 0 1rem 0; font-size: 2rem; line-height: 1.2;"><?php echo htmlspecialchars($heading); ?></h2>
		<?php endif; ?>

		<?php if ($subheading): ?>
			<p style="margin: 0 0 2rem 0; opacity: 0.9; font-size: 1.1rem; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($subheading)); ?></p>
		<?php endif; ?>

		<?php if ($cta_text && $cta_link): ?>
			<div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">
				<a href="<?php echo htmlspecialchars($cta_link); ?>"
				   style="display: inline-block; padding: 0.75rem 2rem; background: rgba(255,255,255,0.95); color: #111; text-decoration: none; border-radius: 4px; font-weight: 600;">
					<?php echo htmlspecialchars($cta_text); ?>
				</a>

				<?php if ($show_secondary && $secondary_text && $secondary_link): ?>
					<a href="<?php echo htmlspecialchars($secondary_link); ?>"
					   style="display: inline-block; padding: 0.75rem 2rem; border: 2px solid rgba(255,255,255,0.7); color: inherit; text-decoration: none; border-radius: 4px; font-weight: 600;">
						<?php echo htmlspecialchars($secondary_text); ?>
					</a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</section>
