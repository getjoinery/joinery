/**
 * Email settings, "Your AI model": where the member's own AI model answers
 * (specs/fortress_mail_device_ai.md § R2).
 *
 * The member pastes the address their model's service gives them. The start
 * of it — the origin, https://host[:port] — goes to mailbox/device_ai_host and
 * is kept with the account; the mailbox page names exactly it in its CSP. The
 * rest of the address stays in this browser, in the same entry the AI panel's
 * "Your AI, your model" keeps the key and model in (mailbox_device_ai.js).
 *
 * Choosing where mail is sent needs a fresh second-factor confirmation:
 * MailboxDeviceAi.registerOrigin() (mailbox_device_ai.js, loaded first) runs
 * the passkey confirmation here and saves again, or goes to the confirmation
 * page and back without a passkey.
 *
 * Page contract: window.MAILBOX_DEVICE_AI = {origin, user_id, site_model};
 * the form #device-ai-host-form with its device_ai_address field; a
 * [data-device-ai-remove] button when an address is registered; a
 * [data-device-ai-use-site] button when the site's own model is offered —
 * one click registers its origin and keeps its path and model here.
 *
 * Vanilla JS. @version 1.2 - the site's own model with one click; registration shared
 * @version 1.1 - shows the whole address when this browser holds the rest
 */
(function () {
	'use strict';

	var cfg = window.MAILBOX_DEVICE_AI || {};
	var STORE = 'jy_device_ai:' + String(cfg.user_id || 0);
	var RETURN = '/profile/mailbox/settings#your-model';

	function note(text) {
		var n = document.querySelector('[data-device-ai-note]');
		if (!n) return;
		n.textContent = text;
		n.hidden = !text;
	}

	/** Split what was pasted into the origin (kept with the account) and the
	 *  rest (kept here). Throws with the reason when it is not an address. */
	function split(address) {
		var u;
		try { u = new URL(String(address || '').trim()); } catch (e) {
			throw new Error('That is not a web address. It starts with https://');
		}
		if (u.protocol !== 'https:' && u.protocol !== 'http:') {
			throw new Error('The address must start with https://');
		}
		if (u.username || u.password) {
			throw new Error('Leave any name or password out of the address; your key goes in the AI panel.');
		}
		if (u.search || u.hash) {
			throw new Error('Leave off anything after a ? or a #.');
		}
		return { origin: u.origin, path: u.pathname.replace(/\/+$/, '') };
	}

	/** Keep the rest of the address here, and the model when one is given. */
	function keepPath(origin, path, model) {
		try {
			var raw = window.localStorage.getItem(STORE);
			var old = raw ? JSON.parse(raw) : null;
			var entry = (old && old.origin === origin) ? old : { origin: origin, key: '', model: '' };
			entry.path = path;
			if (model) entry.model = model;
			window.localStorage.setItem(STORE, JSON.stringify(entry));
		} catch (e) { /* a browser that keeps nothing: the panel asks again */ }
	}

	function register(origin) {
		if (!window.MailboxDeviceAi || !window.MailboxDeviceAi.registerOrigin) {
			return Promise.reject(new Error('This page could not load what saving needs. Reload it and try again.'));
		}
		return window.MailboxDeviceAi.registerOrigin(origin, RETURN);
	}

	document.addEventListener('DOMContentLoaded', function () {
		var form = document.getElementById('device-ai-host-form');
		// The server knows only the start of the address. Show the whole of it
		// when this browser holds the rest, so saving it again unchanged keeps
		// the rest instead of clearing it.
		var field = form ? form.querySelector('[name="device_ai_address"]') : null;
		if (field && cfg.origin && field.value === cfg.origin) {
			try {
				var saved = JSON.parse(window.localStorage.getItem(STORE) || 'null');
				if (saved && saved.origin === cfg.origin && saved.path) field.value = cfg.origin + saved.path;
			} catch (e) { /* nothing kept here */ }
		}
		if (form) {
			form.addEventListener('submit', function (ev) {
				ev.preventDefault();
				var input = form.querySelector('[name="device_ai_address"]');
				var parts;
				try { parts = split(input ? input.value : ''); } catch (e) { note(e.message); return; }
				var btn = form.querySelector('[type="submit"]');
				if (btn) btn.disabled = true;
				note('Saving…');
				register(parts.origin).then(function (origin) {
					keepPath(origin, parts.path);
					window.location.href = RETURN;
					window.location.reload();
				}).catch(function (e) {
					note((e && e.message) || 'Could not save the address.');
				}).then(function () { if (btn) btn.disabled = false; });
			});
		}
		var use = document.querySelector('[data-device-ai-use-site]');
		if (use && cfg.site_model && cfg.site_model.origin) {
			use.addEventListener('click', function () {
				var site = cfg.site_model;
				if (field) field.value = site.origin + (site.path || '');
				use.disabled = true;
				note('Saving…');
				register(site.origin).then(function (origin) {
					keepPath(origin, site.path || '', site.model);
					window.location.href = RETURN;
					window.location.reload();
				}).catch(function (e) {
					note((e && e.message) || 'Could not save the address.');
					use.disabled = false;
				});
			});
		}
		var remove = document.querySelector('[data-device-ai-remove]');
		if (remove) {
			remove.addEventListener('click', function () {
				remove.disabled = true;
				window.joineryApi.post('mailbox/device_ai_host', { origin: '' }).then(function () {
					window.location.href = RETURN;
					window.location.reload();
				}).catch(function (e) {
					note((e && e.message) || 'Could not remove the address.');
					remove.disabled = false;
				});
			});
		}
	});

	window.MailboxDeviceAiSettings = { split: split };
})();
