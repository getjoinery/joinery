<?php
/**
 * Page Title Component
 *
 * Page title with optional subtitle and breadcrumb navigation.
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

$title = $component_config['title'] ?? '';
$subtitle = $component_config['subtitle'] ?? '';
$show_breadcrumbs = $component_config['show_breadcrumbs'] ?? false;
$breadcrumbs = $component_config['breadcrumbs'] ?? [];
$background_color = $component_config['background_color'] ?? '#f8f9fa';
$text_color = $component_config['text_color'] ?? '#212529';
$alignment = $component_config['alignment'] ?? 'left';

// Alignment class
$align_class = '';
if ($alignment === 'center') {
	$align_class = 'text-center';
} elseif ($alignment === 'right') {
	$align_class = 'text-end';
}

$bg_style = "background-color: " . htmlspecialchars($background_color) . "; color: " . htmlspecialchars($text_color) . ";";
?>

<section class="page-title-section py-4" style="<?php echo $bg_style; ?>">
	<div class="container">
		<?php if ($show_breadcrumbs && !empty($breadcrumbs)): ?>
			<nav aria-label="breadcrumb" class="mb-2">
				<ol class="breadcrumb mb-0">
					<?php
					$total = count($breadcrumbs);
					$i = 0;
					foreach ($breadcrumbs as $crumb):
						$i++;
						$is_last = ($i === $total);
					?>
						<li class="breadcrumb-item<?php echo $is_last ? ' active' : ''; ?>" <?php echo $is_last ? 'aria-current="page"' : ''; ?>>
							<?php if (!$is_last && !empty($crumb['link'])): ?>
								<a href="<?php echo htmlspecialchars($crumb['link']); ?>"><?php echo htmlspecialchars($crumb['text']); ?></a>
							<?php else: ?>
								<?php echo htmlspecialchars($crumb['text']); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			</nav>
		<?php endif; ?>

		<div class="<?php echo $align_class; ?>">
			<?php if ($title): ?>
				<h1 class="mb-1"><?php echo htmlspecialchars($title); ?></h1>
			<?php endif; ?>

			<?php if ($subtitle): ?>
				<p class="lead mb-0 text-muted"><?php echo htmlspecialchars($subtitle); ?></p>
			<?php endif; ?>
		</div>
	</div>
</section>
