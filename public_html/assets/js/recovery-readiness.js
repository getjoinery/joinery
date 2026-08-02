/**
 * Recovery Readiness — in-page verify tools.
 *
 * Ceremony: the operator pastes a recovery private key; the challenge the
 * server sealed to the configured public key is opened right here with
 * WebCrypto (X25519 + HKDF-SHA256 + AES-256-GCM, the BackupRecoveryKey
 * browser-challenge layout). The key never leaves the page: no fetch, no form
 * field, cleared the moment it is used. Only the recovered proof string —
 * public by construction — goes into the form.
 *
 * Client vault checks: for client-custody vaults the recovery code must never
 * reach the server, so the dry run happens here too — derive the KEK, attempt
 * the unwrap against the user's own wrapping rows, report pass/fail. Nothing
 * is consumed and nothing secret is transmitted.
 *
 * @version 1.0.0
 */
window.recoveryReadiness = (function () {
	'use strict';

	var subtle = window.crypto && window.crypto.subtle;

	// DER prefix that turns a raw 32-byte X25519 secret into the PKCS#8 form
	// WebCrypto insists on for private keys (OID 1.3.101.110).
	var PKCS8_PREFIX = new Uint8Array([
		0x30, 0x2e, 0x02, 0x01, 0x00, 0x30, 0x05, 0x06,
		0x03, 0x2b, 0x65, 0x6e, 0x04, 0x22, 0x04, 0x20
	]);

	function b64decode(s) {
		var bin = atob(String(s).replace(/\s+/g, ''));
		var out = new Uint8Array(bin.length);
		for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
		return out;
	}

	function concat() {
		var parts = Array.prototype.slice.call(arguments);
		var total = parts.reduce(function (n, p) { return n + p.length; }, 0);
		var out = new Uint8Array(total), at = 0;
		parts.forEach(function (p) { out.set(p, at); at += p.length; });
		return out;
	}

	function utf8(s) { return new TextEncoder().encode(s); }

	function zero(bytes) { if (bytes && bytes.fill) bytes.fill(0); }

	/**
	 * Open a sealed challenge with a pasted private key.
	 * Layout: base64( ephemeralPub[32] || iv[12] || ciphertext+tag ).
	 * @returns {Promise<string>} the recovered proof string
	 */
	async function openChallenge(challengeB64, privateKeyB64, publicKeyB64, infoPrefix) {
		if (!subtle) {
			throw new Error('This browser has no WebCrypto — use the command shown below instead.');
		}

		var priv;
		try {
			priv = b64decode(privateKeyB64);
		} catch (e) {
			throw new Error('That is not a recovery key — it should be one line of base64.');
		}
		if (priv.length !== 32) {
			throw new Error('That is not a recovery key: expected 32 bytes, got ' + priv.length +
				'. Paste the private key exactly as your password manager holds it.');
		}

		var blob = b64decode(challengeB64);
		var ephPub = blob.slice(0, 32);
		var iv = blob.slice(32, 44);
		var ct = blob.slice(44);
		var recipientPub = b64decode(publicKeyB64);

		var pkcs8 = concat(PKCS8_PREFIX, priv);
		zero(priv);

		var privKey;
		try {
			privKey = await subtle.importKey('pkcs8', pkcs8, { name: 'X25519' }, false, ['deriveBits']);
		} catch (e) {
			throw new Error('This browser cannot do X25519 in JavaScript — use the command shown below instead.');
		} finally {
			zero(pkcs8);
		}

		var ephKey = await subtle.importKey('raw', ephPub, { name: 'X25519' }, false, []);
		var shared = await subtle.deriveBits({ name: 'X25519', public: ephKey }, privKey, 256);

		var hkdfKey = await subtle.importKey('raw', shared, 'HKDF', false, ['deriveBits']);
		zero(new Uint8Array(shared));

		var info = concat(utf8(infoPrefix || 'joinery-backup-recovery-possession:'), ephPub, recipientPub);
		var bits = await subtle.deriveBits(
			{ name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(), info: info }, hkdfKey, 256);

		var aesKey = await subtle.importKey('raw', bits, { name: 'AES-GCM' }, false, ['decrypt']);
		zero(new Uint8Array(bits));

		var plain;
		try {
			plain = await subtle.decrypt({ name: 'AES-GCM', iv: iv }, aesKey, ct);
		} catch (e) {
			throw new Error('That key does not open this challenge. It is not the private half of the ' +
				'public key saved here — check you copied the right entry.');
		}
		return new TextDecoder().decode(plain);
	}

	function attachCeremony(c) {
		var keyInput = document.getElementById(c.keyInputId);
		var button = document.getElementById(c.buttonId);
		var status = document.getElementById(c.statusId);
		var proofInput = document.getElementById(c.proofId);
		if (!keyInput || !button || !status || !proofInput) return;

		function say(message, ok) {
			status.textContent = message;
			status.className = ok ? 'text-success small mt-2' : 'text-danger small mt-2';
		}

		button.addEventListener('click', function () {
			var pasted = keyInput.value.trim();
			if (!pasted) { say('Paste your recovery key first.', false); return; }

			button.disabled = true;
			say('Checking in this browser…', true);
			openChallenge(c.challenge, pasted, c.publicKey, c.infoPrefix).then(function (proof) {
				keyInput.value = ''; // the key is done with — do not keep it around
				proofInput.value = proof;
				say('Your key opened the challenge — recording…', true);
				// One button: the recovered proof (public by construction) is
				// submitted immediately; there is no decision between the two
				// steps. requestSubmit, not submit(), so form listeners run.
				var form = proofInput.form;
				if (form && form.requestSubmit) { form.requestSubmit(); }
				else if (form) { form.submit(); }
			}).catch(function (err) {
				say(err.message || String(err), false);
				button.disabled = false;
			});
		});
	}

	/**
	 * Client-custody vault code dry runs. Configs are emitted by the page as
	 * window.rrClientConfigs = { scope: { wrappings: [{wrapped, salt, ad}] } }.
	 * Requires window.VaultCrypto (vault-crypto.js) for the KEK/unwrap
	 * primitives — the same code the real recovery flow runs.
	 */
	function attachClientChecks() {
		var buttons = document.querySelectorAll('[data-rr-client-check]');
		Array.prototype.forEach.call(buttons, function (button) {
			var codeInput = document.getElementById(button.getAttribute('data-rr-code'));
			var status = document.getElementById(button.getAttribute('data-rr-status'));
			var scope = button.getAttribute('data-rr-scope');
			if (!codeInput || !status) return;

			function say(message, ok) {
				status.textContent = message;
				status.className = ok ? 'text-success small mt-2' : 'text-danger small mt-2';
			}

			button.addEventListener('click', async function () {
				var code = codeInput.value.trim();
				if (!code) { say('Enter a recovery code first.', false); return; }

				var cfg = (window.rrClientConfigs || {})[scope];
				if (!cfg || !window.VaultCrypto) {
					say('The vault crypto scripts did not load — reload the page and try again.', false);
					return;
				}

				button.disabled = true;
				say('Checking…', true);
				var passed = false;
				for (var i = 0; i < cfg.wrappings.length; i++) {
					var w = cfg.wrappings[i];
					try {
						var kek = await window.VaultCrypto.kekFromRecoveryCode(code, w.salt);
						await window.VaultCrypto.unwrapSecretKey(w.wrapped, kek, w.ad);
						passed = true;
						break;
					} catch (e) { /* wrong code for this row — try the next */ }
				}
				codeInput.value = '';

				// Report only pass/fail to the ledger; the code and any derived
				// material stay in this page.
				var wrap = document.querySelector('div[data-rr-client-form="' + scope + '"]');
				var form = wrap ? wrap.querySelector('form') : null;
				if (form) {
					form.querySelector('[name="passed"]').value = passed ? '1' : '0';
					say(passed ? 'That code works. Recording…' : 'That code does not open this vault. Recording…', passed);
					// requestSubmit, not submit(): submit() skips every submit
					// listener (FormWriter validation/CSRF hooks included).
					if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
				} else {
					say(passed ? 'That code works.' : 'That code does not open this vault.', passed);
					button.disabled = false;
				}
			});
		});
	}

	/**
	 * Inline step-up: when the server would demand a second-factor confirmation
	 * for a verify action, run the passkey ceremony right here and then let the
	 * original submit proceed — one click, no redirect that loses the POST.
	 *
	 * Security is unchanged: the ceremony calls the same passkey_stepup_options
	 * / passkey_stepup_verify endpoints and stamps the same server-side marker
	 * the logic gate checks. If anything here fails (no WebAuthn, dismissed
	 * prompt), the form submits anyway and the server redirect flow takes over.
	 */
	function attachStepUp(cfg) {
		if (!cfg || !cfg.needed || !cfg.passkey) return;
		if (!window.joineryApi || !window.JoineryPasskeys) return;

		var VERIFY_ACTIONS = ['verify_item', 'record_client_dry_run'];
		Array.prototype.forEach.call(document.querySelectorAll('form'), function (form) {
			var action = form.querySelector('input[name="action"]');
			if (!action || VERIFY_ACTIONS.indexOf(action.value) === -1) return;

			form.addEventListener('submit', function (ev) {
				// Idempotent: after one successful ceremony (any form), or after
				// this form already went around once, do not intercept again.
				if (!cfg.needed || form.dataset.rrStepped === '1') return;
				ev.preventDefault();

				joineryApi.post('passkey_stepup_options', {}).then(function (opt) {
					if (!opt || !opt.options) throw new Error('Could not start confirmation.');
					return JoineryPasskeys.authenticate(opt.options);
				}).then(function (credential) {
					return joineryApi.post('passkey_stepup_verify', { credential: credential });
				}).then(function (res) {
					if (res && res.success === false) throw new Error(res.message || 'Confirmation failed.');
					cfg.needed = false; // marker is stamped server-side for the whole session
					form.dataset.rrStepped = '1';
					if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
				}).catch(function () {
					// Fall back to the server's redirect flow rather than dead-ending.
					form.dataset.rrStepped = '1';
					if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
				});
			});
		});
	}

	return { openChallenge: openChallenge, attachCeremony: attachCeremony, attachClientChecks: attachClientChecks, attachStepUp: attachStepUp };
})();
