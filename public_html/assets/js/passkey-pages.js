/*
 * The passkey buttons on the sign-in, two-step, password-reset and step-up
 * pages. Each button is known here by its id, with its two endpoints and its
 * fallback address fixed in this file, so markup can only say which button it
 * is, never what it calls (specs/strict_script_policy.md WP5).
 *
 * What a button may carry:
 *   data-jy-next="/path"      where to go when the server names no redirect
 *                             (a path on this site; anything else is ignored);
 *   data-email-field="name"   the input holding the email, when it is not "email";
 *   data-quiet-cancel         say nothing when the visitor cancels the prompt.
 *
 * Needs assets/js/passkeys.js, loaded before this file.
 *
 * @version 1.0
 */
(function () {
	'use strict';

	var FLOWS = {
		'passkey-signin-btn': {
			name: 'passkey_login', reveal: 'passkey-signin', email: true,
			next: '/profile', fail: 'Passkey sign-in was not completed.'
		},
		'pwreset-passkey-btn': {
			// Vault holders finish the second factor first; everyone else goes
			// straight to setting a new password.
			name: 'password_reset_passkey', email: true,
			next: '/password-reset-2', fail: 'Passkey reset was not completed.'
		},
		'pwreset-2fa-passkey-btn': {
			name: 'password_reset_2fa_passkey',
			next: '/password-reset-1', fail: 'Passkey confirmation was not completed.'
		},
		'login-2fa-passkey-btn': {
			name: 'login_2fa_passkey', trust: 'trust_device',
			next: '/profile', fail: 'Passkey verification was not completed.'
		}
	};

	function sameSite(to) {
		return typeof window.joinerySameSitePath === 'function' && window.joinerySameSitePath(to);
	}

	function wireFlow(btn, flow) {
		btn.addEventListener('click', async function () {
			btn.disabled = true;
			try {
				var body = {};
				if (flow.email) {
					var field = document.querySelector('input[name="' + (btn.getAttribute('data-email-field') || 'email').replace(/[^A-Za-z0-9_\-\[\]]/g, '') + '"]');
					body.email = field ? field.value.trim() : '';
				}
				if (flow.trust) {
					var trust = document.getElementById(flow.trust);
					if (trust && trust.checked) body.trust_device = 1;
				}
				var data = await JoineryPasskeys.runFlow(
					'/api/v1/action/' + flow.name + '_options',
					'/api/v1/action/' + flow.name + '_verify',
					flow.email || flow.trust ? body : undefined
				);
				var next = btn.getAttribute('data-jy-next');
				window.location.href = data.redirect || (next && sameSite(next) ? next : flow.next);
			} catch (e) {
				btn.disabled = false;
				if (btn.hasAttribute('data-quiet-cancel') && e && e.name === 'NotAllowedError') return;
				alert((e && e.message) || flow.fail);
			}
		});
	}

	// Step-up: confirm with a passkey, then go back to the action that asked.
	function wireStepUp(btn) {
		var errEl = document.getElementById('stepup-passkey-error');
		btn.addEventListener('click', async function () {
			if (errEl) errEl.textContent = '';
			btn.disabled = true;
			try {
				await JoineryPasskeys.stepUp();
				// The step-up stamped the shared marker server-side: go back.
				var back = btn.getAttribute('data-jy-next');
				window.location.href = back && sameSite(back) ? back : '/profile';
			} catch (e) {
				btn.disabled = false;
				// The error's name says which layer refused (browser, server or helper).
				if (errEl) {
					errEl.textContent = ((e && e.name && e.name !== 'Error') ? e.name + ': ' : '')
						+ ((e && e.message) || 'Could not confirm with your passkey.');
				}
			}
		});
	}

	function start() {
		if (!window.JoineryPasskeys) return;
		var step = document.getElementById('stepup-passkey-btn');
		if (step) wireStepUp(step);
		if (!JoineryPasskeys.isSupported()) return;
		Object.keys(FLOWS).forEach(function (id) {
			var btn = document.getElementById(id), flow = FLOWS[id];
			if (!btn) return;
			if (flow.reveal) {
				var wrap = document.getElementById(flow.reveal);
				if (!wrap) return;
				wrap.classList.remove('d-none');
			}
			wireFlow(btn, flow);
		});
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
