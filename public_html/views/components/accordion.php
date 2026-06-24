<?php
/**
 * Accordion Component
 *
 * Collapsible FAQ-style content sections using native HTML5 <details>/<summary>.
 * Pure HTML5, no framework dependencies. Renders inside the `.jy-ui` kit; styling
 * lives in the kit stylesheet (`.jy-accordion` feature section in joinery-styles.css).
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

// 'default' = bordered cards; anything else = flush (bottom-border only)
$flush_class = ($style === 'default') ? '' : ' is-flush';
?>
<section class="jy-ui jy-accordion<?php echo $flush_class; ?>">
	<?php if ($heading || $subheading): ?>
		<div class="jy-accordion-header">
			<?php if ($heading): ?>
				<h2><?php echo htmlspecialchars($heading); ?></h2>
			<?php endif; ?>
			<?php if ($subheading): ?>
				<p><?php echo nl2br(htmlspecialchars($subheading)); ?></p>
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
