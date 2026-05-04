<?php
/**
 * Page Title Component
 *
 * Page title with optional subtitle and breadcrumb navigation.
 * Pure HTML5, no framework dependencies.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$title           = $component_config['title'] ?? '';
$subtitle        = $component_config['subtitle'] ?? '';
$show_breadcrumbs = $component_config['show_breadcrumbs'] ?? false;
$breadcrumbs     = $component_config['breadcrumbs'] ?? [];
$bg_color        = $component_config['background_color'] ?? '#f8f9fa';
$text_color      = $component_config['text_color'] ?? '#212529';
$alignment       = $component_config['alignment'] ?? 'left';

$allowed_alignments = ['left', 'center', 'right'];
if (!in_array($alignment, $allowed_alignments)) {
	$alignment = 'left';
}

$section_style = 'background-color: ' . htmlspecialchars($bg_color) . '; color: ' . htmlspecialchars($text_color) . '; padding: 1.5rem 1rem;';
?>
<section style="<?php echo $section_style; ?>">
	<div style="max-width: 1100px; margin: 0 auto; text-align: <?php echo htmlspecialchars($alignment); ?>;">
		<?php if ($show_breadcrumbs && !empty($breadcrumbs)): ?>
			<nav aria-label="Breadcrumb" style="margin-bottom: 0.75rem;">
				<ol style="display: flex; flex-wrap: wrap; gap: 0.25rem 0.5rem; list-style: none; margin: 0; padding: 0; font-size: 0.875rem; color: #6c757d;">
					<?php
					$total = count($breadcrumbs);
					$i = 0;
					foreach ($breadcrumbs as $crumb):
						$i++;
						$is_last = ($i === $total);
					?>
						<li<?php if ($is_last): ?> aria-current="page"<?php endif; ?>>
							<?php if (!$is_last && !empty($crumb['link'])): ?>
								<a href="<?php echo htmlspecialchars($crumb['link']); ?>" style="color: inherit;"><?php echo htmlspecialchars($crumb['text']); ?></a>
								<span aria-hidden="true" style="margin-left: 0.5rem;">/</span>
							<?php else: ?>
								<?php echo htmlspecialchars($crumb['text']); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			</nav>
		<?php endif; ?>

		<?php if ($title): ?>
			<h1 style="margin: 0 0 0.25rem 0;"><?php echo htmlspecialchars($title); ?></h1>
		<?php endif; ?>

		<?php if ($subtitle): ?>
			<p style="margin: 0; color: #6c757d; font-size: 1.05rem;"><?php echo htmlspecialchars($subtitle); ?></p>
		<?php endif; ?>
	</div>
</section>
