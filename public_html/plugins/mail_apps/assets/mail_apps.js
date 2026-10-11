/**
 * Mail apps page (/profile/mail_apps): make an app password, show it once,
 * revoke one.
 *
 * Making one calls core's app_password_create, which asks the person to
 * confirm it is them first (requires_stepup): JoineryPasskeys.withStepUp()
 * runs the passkey confirmation, or sends them to /verify-stepup and back.
 *
 * @version 1.0.0
 */
(function () {
	'use strict';

	function $(sel) { return document.querySelector(sel); }

	function say(text, kind) {
		var box = $('[data-mail-apps-result]');
		if (!box) { return; }
		box.className = 'jy-alert jy-alert-' + (kind || 'info');
		box.textContent = text;
		box.hidden = false;
	}

	// Groups of four, so the password can be read aloud and typed.
	function grouped(password) {
		var parts = password.split('-');
		return parts.map(function (p, i) {
			return i === 0 ? p : p.replace(/(.{4})/g, '$1 ').trim();
		}).join(' - ');
	}

	async function make(event) {
		event.preventDefault();
		var input = document.querySelector('#mail-apps-add [name="device_name"]');
		var name = input ? input.value.trim() : '';
		if (!name) { say('Name the device first, such as "Laptop".', 'danger'); return; }
		var button = document.querySelector('#mail-apps-add [type="submit"]');
		if (button) { button.disabled = true; }
		try {
			var call = function () {
				return window.joineryApi.post('app_password_create', { purpose: 'mail', device_name: name });
			};
			var res = window.JoineryPasskeys
				? await window.JoineryPasskeys.withStepUp(call, '/profile/mail_apps')
				: await call();
			$('[data-mail-apps-device]').textContent = res.device_name;
			$('[data-mail-apps-secret]').textContent = grouped(res.password);
			$('[data-mail-apps-secret]').setAttribute('data-raw', res.password);
			$('[data-mail-apps-password]').hidden = false;
			say('App password made. Copy it into your mail program now.', 'success');
			if (input) { input.value = ''; }
		} catch (e) {
			say((e && e.message) || 'The app password could not be made.', 'danger');
		} finally {
			if (button) { button.disabled = false; }
		}
	}

	async function revoke(button) {
		var id = parseInt(button.getAttribute('data-mail-apps-revoke'), 10);
		var name = button.getAttribute('data-mail-apps-name') || 'this device';
		var ok = window.JoineryModal && JoineryModal.confirmAsync
			? await JoineryModal.confirmAsync('Revoke the app password for "' + name + '"? Its mail program stops getting mail at once.', { confirmLabel: 'Revoke' })
			: window.confirm('Revoke the app password for "' + name + '"?');
		if (!ok) { return; }
		button.disabled = true;
		try {
			await window.joineryApi.post('app_credential_revoke', { credential_id: id });
			window.location.reload();
		} catch (e) {
			button.disabled = false;
			say((e && e.message) || 'That app password could not be revoked.', 'danger');
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		var form = document.getElementById('mail-apps-add');
		if (form) { form.addEventListener('submit', make); }
		var copy = $('[data-mail-apps-copy]');
		if (copy) {
			copy.addEventListener('click', function () {
				var raw = $('[data-mail-apps-secret]').getAttribute('data-raw') || '';
				if (navigator.clipboard) {
					navigator.clipboard.writeText(raw).then(function () { copy.textContent = 'Copied'; });
				}
			});
		}
		document.querySelectorAll('[data-mail-apps-revoke]').forEach(function (b) {
			b.addEventListener('click', function () { revoke(b); });
		});
	});
})();
