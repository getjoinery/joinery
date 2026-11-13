/*
 * The tabs component (views/components/tabs.php): in every .jy-tabs on the
 * page, a click on a tab shows its panel, and the arrow keys move between tabs.
 *
 * @version 1.0
 */
(function () {
	function wire(container) {
		var tablist = container.querySelector('[role="tablist"]');
		if (!tablist || tablist.hasAttribute('data-jy-tabs-wired')) return;
		tablist.setAttribute('data-jy-tabs-wired', '');
		tablist.addEventListener('click', function (e) {
			var btn = e.target.closest('[role="tab"]');
			if (!btn) return;
			var tabs = tablist.querySelectorAll('[role="tab"]');
			var panels = container.querySelectorAll('[role="tabpanel"]');
			tabs.forEach(function (t) { t.setAttribute('aria-selected', 'false'); t.tabIndex = -1; });
			panels.forEach(function (p) { p.classList.remove('active'); });
			btn.setAttribute('aria-selected', 'true');
			btn.tabIndex = 0;
			var panel = document.getElementById(btn.getAttribute('aria-controls'));
			if (panel) panel.classList.add('active');
		});
		tablist.addEventListener('keydown', function (e) {
			var tabs = Array.from(tablist.querySelectorAll('[role="tab"]'));
			var idx = tabs.indexOf(e.target);
			if (idx < 0) return;
			var next = -1;
			if (e.key === 'ArrowRight') next = (idx + 1) % tabs.length;
			else if (e.key === 'ArrowLeft') next = (idx - 1 + tabs.length) % tabs.length;
			if (next >= 0) { e.preventDefault(); tabs[next].click(); tabs[next].focus(); }
		});
	}
	function start() { document.querySelectorAll('.jy-tabs').forEach(wire); }
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
