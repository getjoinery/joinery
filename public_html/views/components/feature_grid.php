<?php
/**
 * Feature Grid Component
 *
 * Grid of icon + title + description items. Pure HTML5, CSS Grid layout. Renders
 * inside the `.jy-ui` kit; structure lives in the `.jy-feature-grid` section in
 * joinery-styles.css. Per-instance column counts, colors and icon style arrive as
 * --jy-fg-* custom properties (server-computed).
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$heading      = $component_config['heading'] ?? '';
$subheading   = $component_config['subheading'] ?? '';
$columns      = max(1, min(6, (int)($component_config['columns'] ?? 3)));
$features     = $component_config['features'] ?? [];
$style        = $component_config['style'] ?? 'centered';
$icon_style   = $component_config['icon_style'] ?? 'plain';
$icon_color   = $component_config['icon_color'] ?? '#333333';
$bg_color     = $component_config['background_color'] ?? '';

$cols_mobile = min(2, $columns);
$align_mod   = ($style === 'centered') ? '' : ' is-left';

// Icon wrap shape modifier ('plain' = no wrapper)
$wrap_mod = '';
if ($icon_style === 'circle') { $wrap_mod = ' is-circle'; }
elseif ($icon_style === 'square') { $wrap_mod = ' is-square'; }
$has_wrap = ($wrap_mod !== '');

// Per-instance values as custom properties (server-computed inline)
$vars = '--jy-fg-cols: ' . $columns . '; --jy-fg-cols-mobile: ' . $cols_mobile
	. '; --jy-fg-icon: ' . htmlspecialchars($icon_color) . ';';
if ($bg_color)  { $vars .= ' --jy-fg-bg: ' . htmlspecialchars($bg_color) . ';'; }
if ($has_wrap)  { $vars .= ' --jy-fg-icon-bg: ' . htmlspecialchars($icon_color) . '20;'; }
?>
<section class="jy-ui jy-feature-grid<?php echo $align_mod; ?>" style="<?php echo $vars; ?>">
	<div class="jy-feature-grid-inner">
		<?php if ($heading || $subheading): ?>
			<div class="jy-feature-grid-header">
				<?php if ($heading): ?>
					<h2><?php echo htmlspecialchars($heading); ?></h2>
				<?php endif; ?>
				<?php if ($subheading): ?>
					<p><?php echo nl2br(htmlspecialchars($subheading)); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="jy-feature-grid-grid">
			<?php foreach ($features as $feature): ?>
				<div class="jy-feature-grid-item">
					<?php if (!empty($feature['icon'])): ?>
						<div class="jy-feature-grid-icon">
							<?php if ($has_wrap): ?>
								<div class="jy-feature-grid-iconwrap<?php echo $wrap_mod; ?>">
									<i class="<?php echo htmlspecialchars($feature['icon']); ?>"></i>
								</div>
							<?php else: ?>
								<i class="<?php echo htmlspecialchars($feature['icon']); ?>"></i>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<?php if (!empty($feature['title'])): ?>
						<h3><?php echo htmlspecialchars($feature['title']); ?></h3>
					<?php endif; ?>

					<?php if (!empty($feature['description'])): ?>
						<p><?php echo nl2br(htmlspecialchars($feature['description'])); ?></p>
					<?php endif; ?>

					<?php if (!empty($feature['link'])): ?>
						<a href="<?php echo htmlspecialchars($feature['link']); ?>" class="item-link" aria-label="<?php echo htmlspecialchars($feature['title'] ?? ''); ?>"></a>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
