/**
 * Server Manager dashboard board — collapsible host groups and filtering.
 *
 * Host groups are <details> elements, so opening and closing needs no script;
 * this adds what <details> cannot do:
 *   - remember each group's open/closed choice in this browser (a choice
 *     overrides the server's default of closed past six nodes)
 *   - Expand all / Collapse all
 *   - a find box that hides non-matching nodes and groups, opening the groups
 *     that still have matches
 *   - the same row filter for the join-request table (any input with
 *     data-filter-rows)
 *
 * @version 1.2 - a link to #host-N (or loading with it) opens that host group and scrolls to it
 * @version 1.1 - Actions menus on the host header bars (open on click, close on outside click or Escape)
 * @version 1.0
 */
(function () {
	'use strict';

	var KEY = 'svm_groups_open';
	var saved = {};
	try { saved = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { saved = {}; }
	function persist() { try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) { /* private window: fine */ } }

	var groups = Array.prototype.slice.call(document.querySelectorAll('.svm-group[data-group]'));
	var filtering = false;

	groups.forEach(function (g) {
		var id = g.getAttribute('data-group');
		if (Object.prototype.hasOwnProperty.call(saved, id)) { g.open = !!saved[id]; }
		g.addEventListener('toggle', function () {
			if (filtering) return; // a find opens groups; that is not the operator's choice
			saved[id] = g.open;
			persist();
		});
	});

	function setAll(open) {
		groups.forEach(function (g) { g.open = open; });
	}
	var exp = document.getElementById('svm-expand-all');
	var col = document.getElementById('svm-collapse-all');
	if (exp) exp.addEventListener('click', function () { setAll(true); });
	if (col) col.addEventListener('click', function () { setAll(false); });

	// Node find
	var box = document.getElementById('svm-node-filter');
	var counter = document.getElementById('svm-node-count');
	var none = document.getElementById('svm-no-match');
	var openBeforeFind = null;
	if (box) {
		box.addEventListener('input', function () {
			var q = box.value.trim().toLowerCase();
			var total = 0, shown = 0, groupsShown = 0;
			if (q && !filtering) {
				openBeforeFind = groups.map(function (g) { return g.open; });
			}
			filtering = q !== '';
			groups.forEach(function (g) {
				var hits = 0;
				g.querySelectorAll('.svm-node').forEach(function (n) {
					total++;
					var hit = !q || (n.getAttribute('data-filter-text') || '').indexOf(q) !== -1;
					n.hidden = !hit;
					if (hit) { hits++; shown++; }
				});
				g.hidden = q !== '' && hits === 0;
				if (!g.hidden) groupsShown++;
				if (q && hits) g.open = true;
			});
			if (!q && openBeforeFind) {
				groups.forEach(function (g, i) { g.open = openBeforeFind[i]; });
				openBeforeFind = null;
			}
			if (counter) counter.textContent = q ? (shown + ' of ' + total + ' nodes') : (total + ' nodes');
			if (none) none.hidden = !(q && groupsShown === 0);
		});
	}

	// Row filters (join requests)
	document.querySelectorAll('input[data-filter-rows]').forEach(function (inp) {
		inp.addEventListener('input', function () {
			var q = inp.value.trim().toLowerCase();
			document.querySelectorAll(inp.getAttribute('data-filter-rows')).forEach(function (r) {
				r.hidden = q !== '' && (r.getAttribute('data-filter-text') || '').indexOf(q) === -1;
			});
		});
	});

	// Actions menus. The button sits inside a <summary>, so a click must not
	// also toggle the group.
	function closeMenus(except) {
		document.querySelectorAll('.svm-menu-list').forEach(function (l) {
			if (l === except) return;
			l.hidden = true;
			var b = l.parentNode.querySelector('.svm-menu-btn');
			if (b) b.setAttribute('aria-expanded', 'false');
		});
	}
	document.querySelectorAll('.svm-menu-btn').forEach(function (btn) {
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			e.stopPropagation();
			var list = btn.parentNode.querySelector('.svm-menu-list');
			var opening = list.hidden;
			closeMenus(list);
			list.hidden = !opening;
			btn.setAttribute('aria-expanded', opening ? 'true' : 'false');
		});
	});
	document.addEventListener('click', function () { closeMenus(null); });
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenus(null); });

	// #host-N links (the provisions list): open the group, scroll to it.
	function showHost(hash) {
		var g = hash && /^#host-\d+$/.test(hash) ? document.querySelector(hash) : null;
		if (!g) return;
		g.open = true;
		g.scrollIntoView({ behavior: 'smooth', block: 'center' });
	}
	document.querySelectorAll('a.svm-host-jump').forEach(function (a) {
		a.addEventListener('click', function (e) { e.preventDefault(); showHost(a.getAttribute('href')); });
	});
	showHost(location.hash);
})();
