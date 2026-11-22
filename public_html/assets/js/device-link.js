/**
 * Device-link approval (views/profile/devices_link.php).
 *
 * The user arrives here from a code shown on a computer they are setting up.
 * The page's job is to tell them what is asking, and then — if they say yes —
 * do the one thing that can only happen in a browser: unwrap their
 * encrypted-folder key and seal it to that specific device.
 *
 * The key never crosses the wire in the open. The vault is unlocked here
 * through the core ceremony (JoinerySealed.session), the session seals its
 * secret to the device's public key without handing the bytes to this page,
 * and the server stores a blob it has no way to read.
 *
 * Every other client-custody vault the user has set up has its own checkbox
 * (vault_scope_{scope}); each chosen one is unlocked and sealed the same way,
 * one unlock per vault, and travels in sealed_vault_keys.
 *
 * @version 1.6 - a vault is sealed only to a device key the typed code was made from (keyIsTheCodes); a key that
 *   does not match hands nothing over
 * @version 1.5 - its settings are the page's #device-link-config JSON block
 * @version 1.4 - the server names the device kind (phones included); a signed-in app's
 *   handover says to return to the app
 * @version 1.3 - a checkbox, an unlock and a sealed key per vault beyond Drive
 * @version 1.2 - the unlock is the core ceremony; no dialog of its own
 * @version 1.1
 * @changelog 1.1 - The approval takes the FormWriter validator's submitHandler
 *   instead of adding a second submit listener, which ran it twice per click.
 */
(function () {
	'use strict';

	var CFG = (window.joineryPageData && joineryPageData('device-link-config')) || {};
	var api = window.joineryApi;
	var SCOPE = 'drive';

	var $ = function (id) { return document.getElementById(id); };

	var resolved = null;      // the device details for the code currently entered

	function alertBox(message, kind) {
		var box = $('dlkAlert');
		if (!box) { return; }
		box.className = 'jy-alert jy-alert-' + (kind || 'info');
		box.textContent = message;
		box.hidden = !message;
	}

	function codeField() { return document.querySelector('[name="code"]'); }
	function vaultCheckbox() { return document.querySelector('[name="enable_vault"]'); }
	// The other vaults' checkboxes: [{scope, box}].
	function scopeCheckboxes() {
		return Array.prototype.slice.call(document.querySelectorAll('input[type="checkbox"][name^="vault_scope_"]'))
			.map(function (box) { return { scope: box.name.slice('vault_scope_'.length), box: box }; });
	}

	// ---- showing what is asking ---------------------------------------------

	async function resolveCode() {
		var field = codeField();
		var code = field ? (field.value || '').trim() : '';
		resolved = null;
		$('dlkDetails').hidden = true;
		if (code.length < 8) { return; }

		try {
			var info = await api.post('drive_device_link_info', { code: code });
			resolved = info;
			$('dlkName').textContent = info.device_name || '';
			$('dlkPlatform').textContent = info.platform_label || info.platform || '';
			$('dlkIp').textContent = info.request_ip || 'unknown address';
			$('dlkDetails').hidden = false;
			alertBox('');

			// A device that never offered a public key cannot be handed any
			// vault key, so do not offer to.
			var boxes = scopeCheckboxes().map(function (s) { return s.box; });
			if (vaultCheckbox()) { boxes.push(vaultCheckbox()); }
			boxes.forEach(function (cb) {
				if (!info.supports_vault) { cb.checked = false; }
				cb.disabled = !info.supports_vault;
			});
		} catch (e) {
			alertBox(e.message || 'That code could not be checked.', 'danger');
		}
	}

	// ---- the device's key is the one the code names ----------------------------

	var CODE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	var CODE_KEY_PREFIX = 'joinery-device-link-code:v1:';

	// A typed code in canonical form, as the server folds it (DeviceLink::normalize_code).
	function normalizeCode(raw) {
		return String(raw || '').toUpperCase().replace(/[^A-Z0-9]/g, '')
			.replace(/O/g, '0').replace(/[IL]/g, '1').replace(/U/g, 'V');
	}

	function b64bytes(b64) {
		var bin = atob(String(b64)), out = new Uint8Array(bin.length);
		for (var i = 0; i < bin.length; i++) { out[i] = bin.charCodeAt(i); }
		return out;
	}

	// The code a public key and nonce give (DeviceLink::code_for_key): the first
	// 40 bits of SHA-256(prefix || nonce || key), 5 bits a character.
	async function codeForKey(pubkeyB64, nonceB64) {
		var prefix = new TextEncoder().encode(CODE_KEY_PREFIX), nonce = b64bytes(nonceB64), key = b64bytes(pubkeyB64);
		var msg = new Uint8Array(prefix.length + nonce.length + key.length);
		msg.set(prefix, 0); msg.set(nonce, prefix.length); msg.set(key, prefix.length + nonce.length);
		var digest = new Uint8Array(await crypto.subtle.digest('SHA-256', msg));
		var out = '', acc = 0, bits = 0;
		for (var i = 0; out.length < 8; i++) {
			acc = ((acc << 8) | digest[i]) & 0xffff;
			bits += 8;
			while (bits >= 5 && out.length < 8) {
				bits -= 5;
				out += CODE_ALPHABET[(acc >> bits) & 31];
			}
		}
		return out;
	}

	/**
	 * Whether the key this site named for the device is the one the code was
	 * made from. The code came to the person from the device itself; the key
	 * comes from this site's records. A vault's secret is sealed only to a key
	 * that passes, so a key changed in those records hands nothing over.
	 */
	async function keyIsTheCodes(code, info) {
		if (!info || !info.device_pubkey || !info.code_nonce) { return false; }
		try {
			return (await codeForKey(info.device_pubkey, info.code_nonce)) === normalizeCode(code);
		} catch (e) { return false; }
	}

	// ---- the vault handoff ---------------------------------------------------

	/**
	 * The vault secret key, sealed to this device. The session can seal to an
	 * arbitrary public key but deliberately will not expose the raw secret — so
	 * the sealing happens inside the session, which is exactly the boundary we
	 * want. A vault this page had to unlock for the handoff is locked again
	 * straight after it.
	 */
	async function sealVaultKeyFor(devicePublicKey, scope, reason) {
		var wasOpen = JoinerySealed.isOpen(scope);
		var session = await JoinerySealed.session(scope, { reason: reason });
		try {
			return await session.sealSecretKeyTo(devicePublicKey);
		} finally {
			if (!wasOpen) { JoinerySealed.lock(scope); }
		}
	}

	// ---- approve / deny ------------------------------------------------------

	async function approve(event) {
		if (event) { event.preventDefault(); }
		var field = codeField();
		var code = field ? (field.value || '').trim() : '';
		if (!code) { alertBox('Enter the code shown on the device.', 'danger'); return; }

		var cb = vaultCheckbox();
		var wantVault = !!(cb && cb.checked && !cb.disabled);
		var wantScopes = scopeCheckboxes().filter(function (s) { return s.box.checked && !s.box.disabled; });

		try {
			var body = { code: code };
			if (wantVault || wantScopes.length) {
				if (!resolved || !resolved.device_pubkey) {
					await resolveCode();
				}
				if (!resolved || !resolved.device_pubkey) {
					alertBox('This device cannot receive your vaults.', 'danger');
					return;
				}
				// Always asked again for the code being approved, then checked.
				await resolveCode();
				if (!resolved) { return; }   // resolveCode said why
				if (!(await keyIsTheCodes(code, resolved))) {
					alertBox('Nothing was handed over. The key this site holds for that device is not the one its code was made from, '
						+ 'so your vaults were not sent to it. Start again on the device; if this repeats, tell your site admin.', 'danger');
					return;
				}
			}
			if (wantVault) {
				body.enable_vault = true;
				body.sealed_vault_key = await sealVaultKeyFor(resolved.device_pubkey, SCOPE,
					'to give this device your encrypted folders');
			}
			// Each vault is its own keypair and its own unlock.
			if (wantScopes.length) {
				body.sealed_vault_keys = {};
				for (var i = 0; i < wantScopes.length; i++) {
					body.sealed_vault_keys[wantScopes[i].scope] = await sealVaultKeyFor(resolved.device_pubkey,
						wantScopes[i].scope, 'to give this device this vault');
				}
			}

			var res = await api.post('drive_device_link_approve', body);
			alertBox(resolved && resolved.bound
				? res.device_name + ' has its keys. Go back to the app — it picks them up in a few seconds.'
				: res.device_name + ' is linked. It will start syncing in a few seconds — you can close this page.', 'success');
			document.querySelectorAll('#dlkAlert ~ form button, [name="code"], [name="enable_vault"], [name^="vault_scope_"]').forEach(function (el) {
				el.disabled = true;
			});
		} catch (e) {
			// A step-up is an instruction, not a failure: the server is asking the
			// user to prove it is them before a machine is trusted. Send them
			// through the shared confirmation page (it handles passkeys and
			// authenticator codes alike) and bring them back here with the code
			// still in the URL, so they land where they left off.
			if (e && e.data && e.data.requires_stepup) {
				var back = '/profile/devices/link?code=' + encodeURIComponent(code);
				window.location = '/profile/verify-stepup?return=' + encodeURIComponent(back);
				return;
			}
			if (e && e.message === 'Unlock cancelled.') {
				alertBox('Linking cancelled — your vault stayed locked.', 'info');
				return;
			}
			alertBox((e && e.message) || 'The device could not be linked.', 'danger');
		}
	}

	async function deny() {
		var field = codeField();
		var code = field ? (field.value || '').trim() : '';
		if (!code) { alertBox('Enter the code shown on the device.', 'danger'); return; }
		try {
			await api.post('drive_device_link_deny', { code: code });
			alertBox('Refused. The device has been told no.', 'success');
		} catch (e) {
			alertBox((e && e.message) || 'That could not be refused.', 'danger');
		}
	}

	// ---- wiring --------------------------------------------------------------

	/**
	 * Hand this form's one submission to `handler`.
	 *
	 * A FormWriter form answers a valid submit by re-submitting NATIVELY, which
	 * fires `submit` a second time on purpose (so listeners see the validated
	 * submission). A plain listener therefore runs twice per click — and here
	 * that means two approvals: the first mints the credential, the second finds
	 * the code already claimed and tells the user their code was invalid, at the
	 * exact moment the device finished linking. Taking the validator's
	 * submitHandler is how an API-driven form gets one submission (same idiom as
	 * drive.js). The listener is the fallback for a form with no validator.
	 */
	function interceptSubmit(form, handler) {
		if (!form) { return; }
		var wrapped = function (e) {
			if (e && e.preventDefault) { e.preventDefault(); }
			handler(e || null);
		};
		var attach = function () {
			if (form.joineryValidator) {
				form.joineryValidator.submitHandler = function () { wrapped(null); };
			} else {
				form.addEventListener('submit', wrapped);
			}
		};
		// This script is deferred, so it runs before the FormWriter inline
		// scripts' DOMContentLoaded handlers create the validators — wait for
		// them, or the takeover lands on a form whose validator then
		// native-submits straight past it.
		if (form.joineryValidator || document.readyState === 'complete') { attach(); }
		else { document.addEventListener('DOMContentLoaded', attach); }
	}

	// For tests/vault/device_link_code.mjs: the check on its own.
	window.JoineryDeviceLink = { codeForKey: codeForKey, keyIsTheCodes: keyIsTheCodes, normalizeCode: normalizeCode };

	function init() {
		var field = codeField();
		if (field) {
			field.addEventListener('change', resolveCode);
			field.addEventListener('blur', resolveCode);
		}
		var form = field ? field.closest('form') : null;
		interceptSubmit(form, approve);
		if ($('dlkDeny')) { $('dlkDeny').onclick = deny; }

		// Arriving with ?code= in the URL is the normal path — resolve it at once
		// so the user sees what is asking without typing anything.
		if (CFG.code) { resolveCode(); }
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
