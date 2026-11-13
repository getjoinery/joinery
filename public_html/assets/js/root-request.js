/*
 * The root request panel (AdminPage::root_request_panel()): shows a queued
 * root request's state and transcript as it runs, polling the read-only
 * root_request_status action every two seconds until it is done or failed.
 * Each .jy-rootreq box carries its request id (data-request) and, for a
 * package that may not verify, where to go on exit 3 (data-unverified-url).
 *
 * @version 1.0
 */
(function () {
	'use strict';
	function watch(box) {
	var id = box.getAttribute('data-request');
	var unverifiedUrl = box.getAttribute('data-unverified-url') || '';
	// Same-site only, never an open redirect (the server checks it too).
	if (unverifiedUrl.charAt(0) !== '/' || unverifiedUrl.charAt(1) === '/' || /[\x00-\x20\x7f\\]/.test(unverifiedUrl)) { unverifiedUrl = ''; }
	var stateEl = box.querySelector('.jy-rootreq__state');
	var noteEl  = box.querySelector('.jy-rootreq__note');
	var logEl   = box.querySelector('.jy-rootreq__log');
	var meta    = document.querySelector('meta[name="joinery-api-csrf"]');
	var words   = { queued: 'Queued', running: 'Running', done: 'Done', failed: 'Failed' };

	function tick() {
		fetch('/api/v1/action/root_request_status', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-Joinery-Csrf': meta ? meta.content : '' },
			body: JSON.stringify({ id: id })
		}).then(function (r) { return r.json(); }).then(function (d) {
			var data = (d && d.data) ? d.data : d;
			if (!data || !data.state) { setTimeout(tick, 5000); return; }
			box.setAttribute('data-state', data.state);
			stateEl.textContent = words[data.state] || data.state;
			if (data.transcript && data.transcript !== logEl.textContent) {
				logEl.textContent = data.transcript;
				logEl.scrollTop = logEl.scrollHeight;
			}
			if (data.state === 'failed' && data.exit_code === 3 && unverifiedUrl) {
				// Not ours: the warning page decides what happens next.
				noteEl.textContent = 'the package did not verify — opening the warning';
				window.location = unverifiedUrl;
				return;
			}
			if (data.state === 'queued' && data.actor !== 'present') {
				noteEl.textContent = 'waiting for this machine’s root actor';
			} else if (data.state === 'failed' && data.abandoned) {
				noteEl.textContent = 'interrupted — the run carrying it out was killed; submit it again';
			} else if (data.state === 'failed') {
				noteEl.textContent = 'exit ' + (data.exit_code === null ? '?' : data.exit_code);
			} else {
				noteEl.textContent = '';
			}
			if (!data.finished) { setTimeout(tick, 2000); }
		}).catch(function () { setTimeout(tick, 5000); });
	}
	tick();
	}
	function start() {
		document.querySelectorAll('.jy-rootreq[data-request]').forEach(watch);
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
