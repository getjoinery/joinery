<?php
/**
 * Tabs Component
 *
 * Tabbed content sections with accessible ARIA markup. Pure HTML5, no framework
 * dependencies; a small inline script handles tab switching. Renders inside the
 * `.jy-ui` kit; styling lives in the `.jy-tabs` feature section in joinery-styles.css.
 *
 * Available variables:
 *   $component_config - Configuration array from pac_config
 *   $component_data - Dynamic data (empty for static components)
 *   $component - PageContent object (the instance)
 *   $component_type_record - Component object (the type definition)
 *   $component_slug - The component's slug
 */

$heading = $component_config['heading'] ?? '';
$tabs = $component_config['tabs'] ?? [];
$tab_style = $component_config['tab_style'] ?? 'underline';
$alignment = $component_config['alignment'] ?? 'start';

$uid = 'tabs-' . htmlspecialchars($component_slug);
$mods = '';
if ($tab_style === 'pills') { $mods .= ' is-pills'; }
if ($alignment === 'center') { $mods .= ' is-center'; }
?>
<section class="jy-ui jy-tabs<?php echo $mods; ?>" id="<?php echo $uid; ?>">
	<?php if ($heading): ?>
		<h2 class="jy-tabs-heading"><?php echo htmlspecialchars($heading); ?></h2>
	<?php endif; ?>

	<?php if (!empty($tabs)): ?>
		<div class="jy-tabs-list" role="tablist">
			<?php foreach ($tabs as $i => $tab): ?>
				<button role="tab"
					id="tab-<?php echo $uid; ?>-<?php echo $i; ?>"
					aria-controls="panel-<?php echo $uid; ?>-<?php echo $i; ?>"
					aria-selected="<?php echo ($i === 0) ? 'true' : 'false'; ?>"
					tabindex="<?php echo ($i === 0) ? '0' : '-1'; ?>"
				><?php echo htmlspecialchars($tab['title'] ?? ''); ?></button>
			<?php endforeach; ?>
		</div>

		<?php foreach ($tabs as $i => $tab): ?>
			<div role="tabpanel"
				id="panel-<?php echo $uid; ?>-<?php echo $i; ?>"
				aria-labelledby="tab-<?php echo $uid; ?>-<?php echo $i; ?>"
				class="jy-tabs-panel<?php echo ($i === 0) ? ' active' : ''; ?>"
			><?php echo $tab['content'] ?? ''; ?></div>
		<?php endforeach; ?>

		<?php echo PageScript::src('assets/js/tabs-component.js'); ?>
	<?php endif; ?>
</section>
