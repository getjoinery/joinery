/*
 * /admin/server_manager: a node row opens its page when clicked anywhere but
 * a control, and nodes with API credentials refresh their status once on load
 * (silent on failure: the stored status stays).
 *
 * @version 1.0
 */
// Auto-refresh status for nodes with API credentials. Fires once on page load
// in parallel, bypassing the agent/job pipeline. Silent on failure — the
// pre-rendered dot (from last stored status) stays as the fallback.
(function() {
	var rows = document.querySelectorAll('.node-row[data-api-refreshable="1"]');
	if (!rows.length) return;

	var colors = ['secondary','success','warning','danger','info','primary'];

	// A host's dot follows its nodes: red if any is red, amber if any is amber, green only if all are green.
	function refreshHostDot(row) {
		var group = row.closest('.svm-group');
		var hostDot = group && group.querySelector('.svm-host-dot');
		if (!hostDot) return;
		var seen = [];
		group.querySelectorAll('.svm-node:not([data-removed]) .svm-dot').forEach(function(d) {
			colors.forEach(function(c) { if (d.classList.contains('svm-dot-' + c)) seen.push(c); });
		});
		var color = seen.indexOf('danger') !== -1 ? 'danger'
			: (seen.indexOf('warning') !== -1 ? 'warning'
			: (seen.length && seen.every(function(c) { return c === 'success'; }) ? 'success' : 'secondary'));
		colors.forEach(function(c) { hostDot.classList.remove('svm-dot-' + c); });
		hostDot.classList.add('svm-dot-' + color);
	}

	rows.forEach(function(row) {
		var nodeId = row.getAttribute('data-node-id');
		var dot = row.querySelector('.js-status-badge');
		var versionSpan = row.querySelector('.js-version-indicator');
		var lastCheckSpan = row.querySelector('.js-last-check');
		if (dot) dot.style.opacity = '0.4';

		smApiPost('refresh_node_status', { node_id: nodeId })
			.then(function(j) {
				if (dot) dot.style.opacity = '';
				if (!j.ok) return;

				if (dot && j.status_color) {
					colors.forEach(function(c) { dot.classList.remove('svm-dot-' + c); });
					dot.classList.add('svm-dot-' + j.status_color);
				}

				// Only update the version badge when the response includes definitive
				// version data. HTTP-only checks omit version_cmp entirely; API checks
				// without a joinery_version return null. In both cases, preserve the
				// server-rendered badge rather than clearing it.
				if (versionSpan && 'version_cmp' in j && j.version_cmp !== null) {
					versionSpan.innerHTML = '';
					if (j.version_cmp === -1) {
						versionSpan.innerHTML = '<span class="badge badge-warning" title="Management node is at ' +
							smEsc(j.cp_version || '') + '">upgrade available</span>';
					} else if (j.version_cmp === 1) {
						versionSpan.innerHTML = '<span class="badge badge-danger" title="Management node is at ' +
							smEsc(j.cp_version || '') + '">ahead of management node</span>';
					}
				}

				if (lastCheckSpan && j.last_check) {
					lastCheckSpan.textContent = '(' + j.last_check + ')';
				}
				refreshHostDot(row);
			})
			.catch(function() {
				if (dot) dot.style.opacity = '';
			});
	});
})();

// A node row opens its page, unless the click was on a control inside it.
document.addEventListener('click', function (e) {
	var row = e.target.closest ? e.target.closest('.node-row[data-href]') : null;
	if (!row || e.target.closest('form,button,input,a')) return;
	if (typeof joinerySameSitePath === 'function' && !joinerySameSitePath(row.dataset.href)) return;
	window.location = row.dataset.href;
});
