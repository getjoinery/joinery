<?php
/**
 * Feature Grid Component
 *
 * Grid of icon + title + description items.
 * Pure HTML5, no framework dependencies. Uses CSS Grid for layout.
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

$uid        = 'fg-' . htmlspecialchars($component_slug ?? uniqid());
$text_align = ($style === 'centered') ? 'center' : 'left';
$cols_mobile = min(2, $columns);

$icon_wrap_style = '';
if ($icon_style === 'circle') {
	$icon_wrap_style = 'display: inline-flex; width: 64px; height: 64px; border-radius: 50%; align-items: center; justify-content: center; background-color: ' . htmlspecialchars($icon_color) . '20;';
} elseif ($icon_style === 'square') {
	$icon_wrap_style = 'display: inline-flex; width: 64px; height: 64px; border-radius: 8px; align-items: center; justify-content: center; background-color: ' . htmlspecialchars($icon_color) . '20;';
}
?>
<style>
.<?php echo $uid; ?> {
	padding: 3rem 1rem;
	<?php if ($bg_color): ?>background-color: <?php echo htmlspecialchars($bg_color); ?>;<?php endif; ?>
}
.<?php echo $uid; ?>-inner {
	max-width: 1100px;
	margin: 0 auto;
}
.<?php echo $uid; ?>-header {
	text-align: center;
	margin-bottom: 2.5rem;
}
.<?php echo $uid; ?>-header h2 {
	margin: 0 0 0.5rem 0;
}
.<?php echo $uid; ?>-header p {
	margin: 0;
	color: #666;
}
.<?php echo $uid; ?>-grid {
	display: grid;
	grid-template-columns: repeat(<?php echo $columns; ?>, 1fr);
	gap: 2rem;
}
.<?php echo $uid; ?>-item {
	text-align: <?php echo $text_align; ?>;
	position: relative;
}
.<?php echo $uid; ?>-icon {
	margin-bottom: 0.75rem;
	<?php if ($text_align === 'center'): ?>display: flex; justify-content: center;<?php endif; ?>
}
.<?php echo $uid; ?>-icon i {
	font-size: 2rem;
	color: <?php echo htmlspecialchars($icon_color); ?>;
}
.<?php echo $uid; ?>-item h3 {
	margin: 0 0 0.5rem 0;
	font-size: 1.1rem;
}
.<?php echo $uid; ?>-item p {
	margin: 0;
	color: #555;
	font-size: 0.95rem;
	line-height: 1.6;
}
.<?php echo $uid; ?>-item a.item-link {
	position: absolute;
	inset: 0;
}
@media (max-width: 768px) {
	.<?php echo $uid; ?>-grid {
		grid-template-columns: repeat(<?php echo $cols_mobile; ?>, 1fr);
		gap: 1.5rem;
	}
}
@media (max-width: 480px) {
	.<?php echo $uid; ?>-grid {
		grid-template-columns: 1fr;
	}
}
</style>
<section class="<?php echo $uid; ?>">
	<div class="<?php echo $uid; ?>-inner">
		<?php if ($heading || $subheading): ?>
			<div class="<?php echo $uid; ?>-header">
				<?php if ($heading): ?>
					<h2><?php echo htmlspecialchars($heading); ?></h2>
				<?php endif; ?>
				<?php if ($subheading): ?>
					<p><?php echo nl2br(htmlspecialchars($subheading)); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="<?php echo $uid; ?>-grid">
			<?php foreach ($features as $feature): ?>
				<div class="<?php echo $uid; ?>-item">
					<?php if (!empty($feature['icon'])): ?>
						<div class="<?php echo $uid; ?>-icon">
							<?php if ($icon_wrap_style): ?>
								<div style="<?php echo $icon_wrap_style; ?>">
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
