/*
 * A node's Overview tab (includes/node_detail_tabs/overview.php):
 *   - the Actions menu ([data-sm-menu-toggle]) opens and closes;
 *   - Remove Permanently ([data-sm-remove-node]) asks for the slug, and, for a
 *     machine whose host is gone, for the deleted machine's ID or address too,
 *     which is posted as confirm_machine;
 *   - the moved-site check, and the tab's other live parts.
 * Loaded deferred, once (each part is guarded).
 *
 * @version 1.1 - a refused Start the move can be pressed again
 * @version 1.0
 */
if (!window.__smNodeOverview) {
window.__smNodeOverview = true;

document.addEventListener('click', function (e) {
	var t = e.target.closest ? e.target : null;
	if (!t) return;
	var toggle = t.closest('[data-sm-menu-toggle]');
	if (toggle) {
		var m = toggle.nextElementSibling;
		if (m) m.style.display = m.style.display === 'block' ? 'none' : 'block';
		return;
	}
	var rm = t.closest('[data-sm-remove-node]');
	if (!rm || !window.JoineryModal) return;
	var f = document.getElementById('purge_node_form');
	if (!f) return;
	JoineryModal.confirmTyped(rm.getAttribute('data-message'), rm.getAttribute('data-typed'), function () {
		var attest = rm.getAttribute('data-attest') || '';
		if (!attest) { f.submit(); return; }
		JoineryModal.confirmTyped(rm.getAttribute('data-attest-message'), attest, function () {
			f.elements['confirm_machine'].value = attest;
			f.submit();
		}, { confirmLabel: 'I deleted it' });
	});
});

(function () {
	var box = document.getElementById('movedCheck');
	var label = document.getElementById('movedCheckLabel');
	var again = document.getElementById('movedCheckAgain');
	if (!box || !label || !again) return;
	var polls = 0;
	// The host's check takes seconds; poll while it runs, for about three minutes.
	function ask(force) {
		again.disabled = true;
		joineryApi.post('server_manager/moved_site_check', { node_id: parseInt(box.dataset.node, 10), force: force ? 1 : 0 })
			.then(function (data) {
				if (!data || !data.ok) {
					label.insertAdjacentHTML('beforeend', '<div class="text-warning"></div>');
					label.lastChild.textContent = (data && data.message) || 'The check could not run.';
					again.disabled = false;
					return;
				}
				label.innerHTML = data.html;
				if (data.checking && polls++ < 60) {
					setTimeout(function () { ask(false); }, 3000);
				} else {
					again.disabled = false;
				}
			})
			.catch(function (err) {
				label.insertAdjacentHTML('beforeend', '<div class="text-warning"></div>');
				label.lastChild.textContent = (err && err.message) || 'The check could not run.';
				again.disabled = false;
			});
	}
	again.addEventListener('click', function () { polls = 0; ask(true); });
	if (box.dataset.ask === '1' || box.dataset.checking === '1') { ask(false); }
})();

(function () {
	var list = document.getElementById('xferCheck');
	var start = document.getElementById('xferStart');
	var notice = document.getElementById('xferNotice');
	if (!list) return;
	var provision = parseInt(list.dataset.provision, 10);
	var marks = { pass: '✓', warning: '!', blocker: '✗' };
	var colours = { pass: 'text-success', warning: 'text-warning', blocker: 'text-danger' };
	function show(check) {
		list.textContent = '';
		(check.items || []).forEach(function (item) {
			if (item.result === 'pass') { return; }
			var li = document.createElement('li');
			li.className = colours[item.result] || '';
			li.textContent = marks[item.result] + ' ' + item.label + (item.detail ? ' — ' + item.detail : '') + (item.fix ? ' ' + item.fix : '');
			list.appendChild(li);
		});
		var summary = document.createElement('li');
		summary.className = check.blockers ? 'text-danger' : 'text-success';
		summary.textContent = check.blockers ? check.blockers + ' thing(s) to fix before it can start.'
			: 'Ready to hand over' + (check.warnings ? ' (' + check.warnings + ' warning(s) above).' : '.');
		list.insertBefore(summary, list.firstChild);
		if (start) { start.disabled = check.blockers > 0; }
	}
	function run(what) {
		notice.textContent = '';
		return joineryApi.post('server_manager/transfer_operator', { 'do': what, provision_id: provision });
	}
	function check() {
		list.innerHTML = '<li class="text-muted">Checking…</li>';
		run('check').then(function (data) { show(data.check); }).catch(function (err) {
			list.innerHTML = '';
			notice.className = 'small mt-2 mb-0 text-danger';
			notice.textContent = err && err.message ? err.message : 'The check could not run.';
		});
	}
	document.getElementById('xferRecheck').addEventListener('click', check);
	if (start) {
		var go = function () {
			start.disabled = true;
			run('start').then(function () { window.location.reload(); }).catch(function (err) {
				start.disabled = false;
				notice.className = 'small mt-2 mb-0 text-danger';
				notice.textContent = err && err.message ? err.message : 'It could not start.';
			});
		};
		start.addEventListener('click', function () {
			if (window.JoineryModal) { JoineryModal.confirm(start.dataset.confirm, go); return; }
			if (window.confirm(start.dataset.confirm)) go();
		});
	}
	check();
})();
}
