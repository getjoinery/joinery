<?php
/**
 * Page Title Component
 *
 * Page title with optional subtitle and breadcrumb navigation. Pure HTML5, no
 * framework dependencies. Renders inside the `.jy-ui` kit; styling lives in the
 * `.jy-pagetitle` section in joinery-styles.css. Background, text colour and
 * alignment arrive as --jy-pt-* custom properties (server-computed).
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

$vars = '--jy-pt-bg: ' . htmlspecialchars($bg_color)
	. '; --jy-pt-text: ' . htmlspecialchars($text_color)
	. '; --jy-pt-align: ' . $alignment . ';';
?>
<section class="jy-ui jy-pagetitle" style="<?php echo $vars; ?>">
	<div class="jy-pagetitle-inner">
		<?php if ($show_breadcrumbs && !empty($breadcrumbs)): ?>
			<nav class="jy-pagetitle-crumbs" aria-label="Breadcrumb">
				<ol>
					<?php
					$total = count($breadcrumbs);
					$i = 0;
					foreach ($breadcrumbs as $crumb):
						$i++;
						$is_last = ($i === $total);
					?>
						<li<?php if ($is_last): ?> aria-current="page"<?php endif; ?>>
							<?php if (!$is_last && !empty($crumb['link'])): ?>
								<a href="<?php echo htmlspecialchars($crumb['link']); ?>"><?php echo htmlspecialchars($crumb['text']); ?></a>
								<span class="sep" aria-hidden="true">/</span>
							<?php else: ?>
								<?php echo htmlspecialchars($crumb['text']); ?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
			</nav>
		<?php endif; ?>

		<?php if ($title): ?>
			<h1><?php echo htmlspecialchars($title); ?></h1>
		<?php endif; ?>

		<?php if ($subtitle): ?>
			<p class="jy-pagetitle-subtitle"><?php echo htmlspecialchars($subtitle); ?></p>
		<?php endif; ?>
	</div>
</section>
