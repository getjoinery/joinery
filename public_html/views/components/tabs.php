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

		<script>
		(function() {
			var container = document.getElementById('<?php echo $uid; ?>');
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
