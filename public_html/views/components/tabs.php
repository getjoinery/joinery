<?php
/**
 * Tabs Component
 *
 * Tabbed content sections with accessible ARIA markup. Pure HTML5, no framework dependencies.
 * Uses a small inline script for tab switching.
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
$is_pills = ($tab_style === 'pills');
$justify = ($alignment === 'center') ? 'center' : 'flex-start';
?>
<style>
.<?php echo $uid; ?> {
	max-width: 1100px;
	margin: 0 auto;
	padding: 3rem 1rem;
}
.<?php echo $uid; ?>-list {
	display: flex;
	gap: 0;
	justify-content: <?php echo $justify; ?>;
	list-style: none;
	margin: 0 0 1.5rem 0;
	padding: 0;
	<?php if (!$is_pills): ?>
	border-bottom: 2px solid #dee2e6;
	<?php endif; ?>
}
.<?php echo $uid; ?>-list button {
	background: none;
	border: none;
	padding: 0.75rem 1.25rem;
	cursor: pointer;
	font-size: 1rem;
	color: #6c757d;
	position: relative;
	<?php if ($is_pills): ?>
	border-radius: 2rem;
	margin-right: 0.5rem;
	<?php else: ?>
	margin-bottom: -2px;
	<?php endif; ?>
}
.<?php echo $uid; ?>-list button:hover {
	color: #333;
}
.<?php echo $uid; ?>-list button[aria-selected="true"] {
	color: #333;
	font-weight: 600;
	<?php if ($is_pills): ?>
	background-color: #e9ecef;
	<?php else: ?>
	border-bottom: 2px solid #333;
	<?php endif; ?>
}
.<?php echo $uid; ?>-panel {
	display: none;
}
.<?php echo $uid; ?>-panel.active {
	display: block;
}
</style>
<section class="<?php echo $uid; ?>">
	<?php if ($heading): ?>
		<h2 style="margin: 0 0 1.5rem 0;"><?php echo htmlspecialchars($heading); ?></h2>
	<?php endif; ?>

	<?php if (!empty($tabs)): ?>
		<div class="<?php echo $uid; ?>-list" role="tablist">
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
				class="<?php echo $uid; ?>-panel<?php echo ($i === 0) ? ' active' : ''; ?>"
			><?php echo $tab['content'] ?? ''; ?></div>
		<?php endforeach; ?>

		<script>
		(function() {
			var container = document.querySelector('.<?php echo $uid; ?>');
			if (!container) return;
			var tablist = container.querySelector('[role="tablist"]');
			tablist.addEventListener('click', function(e) {
				var btn = e.target.closest('[role="tab"]');
				if (!btn) return;
				var tabs = tablist.querySelectorAll('[role="tab"]');
				var panels = container.querySelectorAll('[role="tabpanel"]');
				tabs.forEach(function(t) { t.setAttribute('aria-selected', 'false'); t.tabIndex = -1; });
				panels.forEach(function(p) { p.classList.remove('active'); });
				btn.setAttribute('aria-selected', 'true');
				btn.tabIndex = 0;
				var panel = document.getElementById(btn.getAttribute('aria-controls'));
				if (panel) panel.classList.add('active');
			});
			tablist.addEventListener('keydown', function(e) {
				var tabs = Array.from(tablist.querySelectorAll('[role="tab"]'));
				var idx = tabs.indexOf(e.target);
				if (idx < 0) return;
				var next = -1;
				if (e.key === 'ArrowRight') next = (idx + 1) % tabs.length;
				else if (e.key === 'ArrowLeft') next = (idx - 1 + tabs.length) % tabs.length;
				if (next >= 0) { e.preventDefault(); tabs[next].click(); tabs[next].focus(); }
			});
		})();
		</script>
	<?php endif; ?>
</section>
