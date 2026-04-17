<?php
/**
 * Accordion Component
 *
 * Collapsible FAQ-style content sections using native HTML5 <details>/<summary>.
 * Pure HTML5, no framework dependencies.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$heading = $component_config['heading'] ?? '';
$subheading = $component_config['subheading'] ?? '';
$items = $component_config['items'] ?? [];
$allow_multiple = !empty($component_config['allow_multiple']);
$style = $component_config['style'] ?? 'default';

$uid = 'acc-' . htmlspecialchars($component_slug);

// When allow_multiple is false, the name attribute on <details> creates an exclusive group
$group_name = $allow_multiple ? '' : $uid;

$is_bordered = ($style === 'default');
?>
<style>
.<?php echo $uid; ?> {
	max-width: 1100px;
	margin: 0 auto;
	padding: 3rem 1rem;
}
.<?php echo $uid; ?> details {
	<?php if ($is_bordered): ?>
	border: 1px solid #dee2e6;
	border-radius: 4px;
	margin-bottom: 0.5rem;
	<?php else: ?>
	border-bottom: 1px solid #dee2e6;
	<?php endif; ?>
}
.<?php echo $uid; ?> summary {
	padding: 1rem;
	font-weight: 600;
	cursor: pointer;
	list-style: none;
	display: flex;
	justify-content: space-between;
	align-items: center;
}
.<?php echo $uid; ?> summary::-webkit-details-marker {
	display: none;
}
.<?php echo $uid; ?> summary::after {
	content: '+';
	font-size: 1.25rem;
	font-weight: 400;
	transition: transform 0.2s;
}
.<?php echo $uid; ?> details[open] > summary::after {
	content: '\2212';
}
.<?php echo $uid; ?> .acc-content {
	padding: 0 1rem 1rem 1rem;
}
</style>
<section class="<?php echo $uid; ?>">
	<?php if ($heading || $subheading): ?>
		<div style="text-align: center; margin-bottom: 2rem;">
			<?php if ($heading): ?>
				<h2 style="margin: 0 0 0.5rem 0;"><?php echo htmlspecialchars($heading); ?></h2>
			<?php endif; ?>
			<?php if ($subheading): ?>
				<p style="margin: 0; color: #6c757d;"><?php echo nl2br(htmlspecialchars($subheading)); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php foreach ($items as $i => $item):
		$title = $item['title'] ?? '';
		$item_content = $item['content'] ?? '';
		$is_open = !empty($item['is_open']);
	?>
		<details<?php if ($is_open): ?> open<?php endif; ?><?php if ($group_name): ?> name="<?php echo $group_name; ?>"<?php endif; ?>>
			<summary><?php echo htmlspecialchars($title); ?></summary>
			<div class="acc-content"><?php echo $item_content; ?></div>
		</details>
	<?php endforeach; ?>
</section>
