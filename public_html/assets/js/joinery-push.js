/**
 * Browser push: turn notifications on or off in this browser, and send a test.
 *
 * Drives a panel marked [data-jy-push] (rendered by PushPanel::html()):
 *   [data-jy-push-status]   the sentence saying where things stand
 *   [data-jy-push-on]       "Turn on in this browser"
 *   [data-jy-push-off]      "Turn off in this browser"
 *   [data-jy-push-test]     "Send a test"
 *
 * Turning on registers the service worker (/service-worker.js), asks the
 * browser for permission (which must come from the click), subscribes with the
 * site's public key, and registers the subscription (push_subscribe). A
 * subscription made with an older key (the site minted a new pair) is replaced.
 *
 * Uses window.joineryApi for every call.
 *
 * @version 1.0.0
 */
(function () {
	'use strict';

	function b64urlToBytes(s) {
		var pad = '='.repeat((4 - s.length % 4) % 4);
		var raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/'));
		var out = new Uint8Array(raw.length);
		for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
		return out;
	}

	function sameKey(subscription, keyBytes) {
		var k = subscription.options && subscription.options.applicationServerKey;
		if (!k) return true; // the browser does not say; trust it
		var a = new Uint8Array(k);
		if (a.length !== keyBytes.length) return false;
		for (var i = 0; i < a.length; i++) if (a[i] !== keyBytes[i]) return false;
		return true;
	}

	function init(panel) {
		var statusEl = panel.querySelector('[data-jy-push-status]');
		var onBtn = panel.querySelector('[data-jy-push-on]');
		var offBtn = panel.querySelector('[data-jy-push-off]');
		var testBtn = panel.querySelector('[data-jy-push-test]');
		var config = null;
		var registration = null;

		function show(text, state) {
			statusEl.textContent = text;
			onBtn.hidden = state !== 'off';
			offBtn.hidden = state !== 'on';
			testBtn.hidden = state !== 'on';
		}

		function busy(on) {
			[onBtn, offBtn, testBtn].forEach(function (b) { b.disabled = on; });
		}

		function fail(err) {
			busy(false);
			statusEl.textContent = (err && err.message) || 'Something went wrong.';
		}

		if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
			show('This browser cannot receive push notifications. On an iPhone or iPad, add this site to your Home Screen first, then open it from there.', 'none');
			return;
		}

		function currentSubscription() {
			return navigator.serviceWorker.register('/service-worker.js').then(function (reg) {
				registration = reg;
				return reg.pushManager.getSubscription();
			});
		}

		function refresh() {
			busy(true);
			return currentSubscription().then(function (sub) {
				return window.joineryApi.post('push_config', { endpoint: sub ? sub.endpoint : '' }).then(function (cfg) {
					config = cfg;
					busy(false);
					if (!cfg.available) {
						show('This site cannot send push notifications yet.', 'none');
					} else if (Notification.permission === 'denied') {
						show('This browser blocks notifications from this site. Allow them in the browser\'s site settings, then reload this page.', 'none');
					} else if (sub && cfg.registered && sameKey(sub, b64urlToBytes(cfg.public_key))) {
						show('Push notifications are on in this browser.', 'on');
					} else {
						show('Push notifications are off in this browser.', 'off');
					}
				});
			}).catch(fail);
		}

		onBtn.addEventListener('click', function () {
			busy(true);
			var key = b64urlToBytes(config.public_key);
			Notification.requestPermission().then(function (permission) {
				if (permission !== 'granted') {
					throw new Error('Notifications were not allowed, so push stays off.');
				}
				return registration.pushManager.getSubscription();
			}).then(function (sub) {
				// A subscription bound to an older key cannot be used; replace it.
				if (sub && !sameKey(sub, key)) {
					return sub.unsubscribe().then(function () { return null; });
				}
				return sub;
			}).then(function (sub) {
				return sub || registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key })
					.catch(function (err) {
						throw new Error('This browser would not set up push notifications (' + err.message + ').');
					});
			}).then(function (sub) {
				var json = sub.toJSON();
				return window.joineryApi.post('push_subscribe', {
					endpoint: json.endpoint,
					p256dh: json.keys && json.keys.p256dh,
					auth: json.keys && json.keys.auth
				});
			}).then(refresh).catch(fail);
		});

		offBtn.addEventListener('click', function () {
			busy(true);
			registration.pushManager.getSubscription().then(function (sub) {
				if (!sub) return null;
				return window.joineryApi.post('push_unsubscribe', { endpoint: sub.endpoint }).then(function () {
					return sub.unsubscribe();
				});
			}).then(refresh).catch(fail);
		});

		testBtn.addEventListener('click', function () {
			busy(true);
			registration.pushManager.getSubscription().then(function (sub) {
				if (!sub) throw new Error('Push is not turned on in this browser.');
				return window.joineryApi.post('push_test', { endpoint: sub.endpoint });
			}).then(function (r) {
				busy(false);
				statusEl.textContent = r.sent
					? 'Test sent. It should appear within a few seconds.'
					: 'The test was not delivered: ' + r.reason;
			}).catch(fail);
		});

		refresh();
	}

	document.addEventListener('DOMContentLoaded', function () {
		var panels = document.querySelectorAll('[data-jy-push]');
		for (var i = 0; i < panels.length; i++) init(panels[i]);
	});
})();
