/**
 * sandbox-frame.js - core's bridge between a sandboxed plugin's framed page
 * and the site (specs/sandboxed_php_plugins.md design 9).
 *
 * The frame is another site with no network (its response header), so this
 * is its one way out, and it understands two messages, from that frame's own
 * window only:
 *
 *   {type: 'sbx-call', id, handler, data}   call the plugin's handler; the answer
 *                                           comes back as {type: 'sbx-answer', id, ok, output, message}
 *   {type: 'sbx-height', height}            the frame's height in pixels (100 to 20000)
 *
 * Answers go to that frame's window only. Their target origin is '*', which a
 * sandboxed frame needs (it has no origin to name); that is safe only because
 * the outer page's frame-src keeps the frame on core's framed route.
 *
 * @version 1.0
 */
(function () {
	'use strict';
	var meta = document.querySelector('meta[name="joinery-api-csrf"]');
	var csrf = meta ? meta.content : '';
	var frames = document.querySelectorAll('iframe.jy-sandbox-frame');
	if (!frames.length) {
		return;
	}
	window.addEventListener('message', function (event) {
		var frame = null;
		for (var i = 0; i < frames.length; i++) {
			if (frames[i].contentWindow === event.source) {
				frame = frames[i];
			}
		}
		var msg = event.data;
		if (!frame || !msg || typeof msg !== 'object') {
			return;
		}
		if (msg.type === 'sbx-height') {
			var h = parseInt(msg.height, 10);
			if (h >= 100 && h <= 20000) {
				frame.style.height = h + 'px';
			}
			return;
		}
		if (msg.type !== 'sbx-call' || typeof msg.handler !== 'string') {
			return;
		}
		var reply = function (answer) {
			answer.type = 'sbx-answer';
			answer.id = msg.id;
			frame.contentWindow.postMessage(answer, '*');
		};
		fetch('/api/v1/action/sandbox_frame_call', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-Joinery-Csrf': csrf },
			body: JSON.stringify({
				plugin: frame.getAttribute('data-sbx-plugin'),
				kind: frame.getAttribute('data-sbx-kind'),
				page: frame.getAttribute('data-sbx-page'),
				handler: msg.handler,
				data: (msg.data && typeof msg.data === 'object') ? msg.data : {}
			})
		}).then(function (r) {
			return r.json();
		}).then(function (j) {
			var d = (j && j.data) || {};
			reply({ ok: !!d.ok, output: d.output === undefined ? null : d.output, message: d.message || (j && j.error) || '' });
		}).catch(function () {
			reply({ ok: false, output: null, message: 'The site did not answer.' });
		});
	});
})();
