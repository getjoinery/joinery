/**
 * protect_records.js - the send-protection record table rechecks itself
 * (plugins/mailbox/includes/protect_record_table.php,
 * specs/send_protection_one_press.md WP4).
 *
 * While [data-protect-records] has data-poll="1", it asks
 * mailbox/protect_record_list every 30 seconds (and on Check now), repaints
 * each row's status, and:
 *  - before signing is on, when signing_ready: enables the button inside
 *    [data-protect-turn-on], hides [data-protect-waiting] and the readiness
 *    cards in [data-protect-required], shows [data-protect-required-ready];
 *  - after, when strict_live: says both records are live.
 * Then it stops. It pauses while the tab is hidden. The press itself checks
 * everything again on the server.
 *
 * @version 1.2 - a row whose value to publish changed is redrawn by reloading, not left stale
 * @version 1.1 - a check that throws never stops the polling; a refusal stops it; a hidden tab does not start it
 * @version 1.0
 */
(function () {
	var box = document.querySelector('[data-protect-records]');
	if (!box || box.getAttribute('data-poll') !== '1' || !window.joineryApi) { return; }

	var domainId = parseInt(box.getAttribute('data-domain-id'), 10);
	var INTERVAL = 30000;
	var timer = null;
	var busy = false;
	var done = false;
	var checked = box.querySelector('[data-protect-checked]');
	var nowBtn = box.querySelector('[data-protect-check-now]');

	var CLASSES = {
		live: 'badge-subtle-success', pending: 'badge-subtle-primary', missing: 'badge-subtle-primary',
		differs: 'badge-subtle-warning', remove: 'badge-danger', waiting: 'badge-subtle-secondary'
	};

	function statusCell(cell, row) {
		cell.textContent = '';
		var badge = document.createElement('span');
		badge.className = 'badge ' + (CLASSES[row.status] || 'badge-subtle-secondary');
		badge.textContent = row.label;
		cell.appendChild(badge);
		(row.live || []).forEach(function (v) {
			var d = document.createElement('div');
			d.className = 'small text-muted mt-1';
			d.style.wordBreak = 'break-all';
			d.textContent = 'Live now: ' + v;
			cell.appendChild(d);
		});
	}

	/**
	 * Repaint each row's status. Returns false when the rows themselves
	 * changed (a value to publish was built from a live record that has since
	 * changed, or a row came or went): the value cell and its copy field are the
	 * server's to draw, so the page is reloaded instead.
	 */
	function paint(data) {
		var byKey = {};
		var count = 0;
		(data.groups || []).forEach(function (g) {
			(g.rows || []).forEach(function (r) { byKey[r.key] = r; count++; });
		});
		var trs = box.querySelectorAll('tr[data-record-key]');
		var same = trs.length === count;
		trs.forEach(function (tr) {
			var row = byKey[tr.getAttribute('data-record-key')];
			if (!row || row.value !== tr.getAttribute('data-record-value')) { same = false; }
		});
		if (!same) { return false; }
		trs.forEach(function (tr) {
			var row = byKey[tr.getAttribute('data-record-key')];
			var cell = tr.querySelector('[data-record-status]');
			if (cell) { statusCell(cell, row); }
		});
		return true;
	}

	/** Reload to redraw changed rows, unless the person is typing somewhere on the page. */
	function redraw() {
		var a = document.activeElement;
		if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) {
			say('A value to publish has changed. Reload the page to see it.');
			return;
		}
		window.location.reload();
	}

	function finish(message) {
		done = true;
		if (timer) { clearInterval(timer); timer = null; }
		if (nowBtn) { nowBtn.hidden = true; }
		if (checked) { checked.textContent = message; }
	}

	function say(message) {
		if (checked) { checked.textContent = message; }
	}

	function check() {
		if (busy || done) { return; }
		busy = true;
		if (nowBtn) { nowBtn.disabled = true; }
		window.joineryApi.post('mailbox/protect_record_list', { domain_id: domainId }).then(function (data) {
			if (!paint(data)) { redraw(); return; }
			var at = new Date().toLocaleTimeString();
			if (!data.protected && data.signing_ready) {
				document.querySelectorAll('[data-protect-turn-on] button').forEach(function (b) { b.disabled = false; });
				document.querySelectorAll('[data-protect-waiting], [data-protect-required]').forEach(function (el) { el.hidden = true; });
				document.querySelectorAll('[data-protect-required-ready]').forEach(function (el) { el.hidden = false; });
				finish('Checked at ' + at + '. The records your key needs are live: you can turn send protection on.');
			} else if (data.protected && data.strict_live) {
				finish('Checked at ' + at + '. Both records are live. Send protection is finished.');
			} else {
				say('Checked at ' + at + '. Checking again in 30 seconds.');
			}
		}).catch(function (err) {
			// Signed out, or no longer allowed: asking again changes nothing.
			if (err && (err.status === 401 || err.status === 403)) {
				finish('Stopped checking: ' + (err.message || 'not signed in') + '. Reload the page to check again.');
				return;
			}
			say('Could not check just now (' + ((err && err.message) || 'no answer') + '). Trying again in 30 seconds.');
		}).then(function () {
			busy = false;
			if (nowBtn) { nowBtn.disabled = false; }
		});
	}

	function start() {
		if (!timer && !done) { timer = setInterval(check, INTERVAL); }
	}
	function stop() {
		if (timer) { clearInterval(timer); timer = null; }
	}

	if (nowBtn) { nowBtn.addEventListener('click', check); }
	document.addEventListener('visibilitychange', function () {
		if (document.hidden) { stop(); } else { check(); start(); }
	});
	if (!document.hidden) { start(); }
})();
