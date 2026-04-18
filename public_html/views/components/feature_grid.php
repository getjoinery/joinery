<?php
/**
 * Feature Grid Component
 *
 * Grid of icon + title + description items.
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
$columns = $component_config['columns'] ?? 4;
$features = $component_config['features'] ?? [];
$style = $component_config['style'] ?? 'centered';
$icon_style = $component_config['icon_style'] ?? 'plain';
$icon_color = $component_config['icon_color'] ?? '#007bff';
$background_color = $component_config['background_color'] ?? '#ffffff';

// Column class based on count
$col_classes = [
	2 => 'col-md-6',
	3 => 'col-md-4',
	4 => 'col-md-6 col-lg-3',
	6 => 'col-md-4 col-lg-2'
];
$col_class = $col_classes[$columns] ?? 'col-md-6 col-lg-3';

// Text alignment based on style
$text_class = ($style === 'centered') ? 'text-center' : '';
?>

<section class="feature-grid py-5" style="background-color: <?php echo htmlspecialchars($background_color); ?>;">
	<div class="jy-container">
		<?php if ($heading || $subheading): ?>
			<div class="row mb-5">
				<div class="col-lg-8 mx-auto text-center">
					<?php if ($heading): ?>
						<h2 class="mb-3"><?php echo htmlspecialchars($heading); ?></h2>
					<?php endif; ?>
					<?php if ($subheading): ?>
						<p class="lead text-muted"><?php echo nl2br(htmlspecialchars($subheading)); ?></p>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>

		<div class="row g-4">
			<?php foreach ($features as $feature): ?>
				<div class="<?php echo $col_class; ?>">
					<?php if ($style === 'card'): ?>
						<div class="card h-100 shadow-sm">
							<div class="card-body <?php echo $text_class; ?>">
					<?php else: ?>
						<div class="<?php echo $text_class; ?>">
					<?php endif; ?>

						<?php
						$icon = $feature['icon'] ?? '';
						if ($icon):
							$icon_wrapper_style = '';
							if ($icon_style === 'circle') {
								$icon_wrapper_style = 'display: inline-flex; width: 64px; height: 64px; border-radius: 50%; align-items: center; justify-content: center; background-color: ' . htmlspecialchars($icon_color) . '20;';
							} elseif ($icon_style === 'square') {
								$icon_wrapper_style = 'display: inline-flex; width: 64px; height: 64px; border-radius: 8px; align-items: center; justify-content: center; background-color: ' . htmlspecialchars($icon_color) . '20;';
							}
						?>
							<div class="feature-icon mb-3" style="<?php echo $icon_wrapper_style; ?>">
								<i class="<?php echo htmlspecialchars($icon); ?>" style="font-size: 2rem; color: <?php echo htmlspecialchars($icon_color); ?>;"></i>
							</div>
						<?php endif; ?>

						<?php if (!empty($feature['title'])): ?>
							<h5 class="mb-2"><?php echo htmlspecialchars($feature['title']); ?></h5>
						<?php endif; ?>

						<?php if (!empty($feature['description'])): ?>
							<p class="text-muted mb-0"><?php echo nl2br(htmlspecialchars($feature['description'])); ?></p>
						<?php endif; ?>

						<?php if (!empty($feature['link'])): ?>
							<a href="<?php echo htmlspecialchars($feature['link']); ?>" class="stretched-link"></a>
						<?php endif; ?>

					<?php if ($style === 'card'): ?>
							</div>
						</div>
					<?php else: ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
