/**
 * Device-link approval (views/profile/devices_link.php).
 *
 * The user arrives here from a code shown on a computer they are setting up.
 * The page's job is to tell them what is asking, and then — if they say yes —
 * do the one thing that can only happen in a browser: unwrap their
 * encrypted-folder key and seal it to that specific device.
 *
 * The key never crosses the wire in the open. VaultKeyring unwraps it here from
 * a wrapping only the user's own unlocker opens, VaultCrypto seals it to the
 * device's public key, and the server stores a blob it has no way to read.
 *
 * @version 1.0
 */
(function () {
	'use strict';

	var CFG = window.DEVICE_LINK_CFG || {};
	var api = window.joineryApi;
	var VK = window.VaultKeyring;
	var SCOPE = 'drive';

	var $ = function (id) { return document.getElementById(id); };

	var resolved = null;      // the device details for the code currently entered
	var vaultResolve = null;  // pending unlock promise handlers
	var vaultReject = null;

	function alertBox(message, kind) {
		var box = $('dlkAlert');
		if (!box) { return; }
		box.className = 'jy-alert jy-alert-' + (kind || 'info');
		box.textContent = message;
		box.hidden = !message;
	}

	function vaultError(message) {
		var box = $('dlkVaultError');
		if (!box) { return; }
		box.textContent = message || '';
		box.hidden = !message;
	}

	function codeField() { return document.querySelector('[name="code"]'); }
	function vaultCheckbox() { return document.querySelector('[name="enable_vault"]'); }

	// ---- showing what is asking ---------------------------------------------

	var PLATFORM_LABELS = { macos: 'Mac', windows: 'Windows PC', linux: 'Linux computer' };

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
			$('dlkPlatform').textContent = PLATFORM_LABELS[info.platform] || info.platform || '';
			$('dlkIp').textContent = info.request_ip || 'unknown address';
			$('dlkDetails').hidden = false;
			alertBox('');

			// A device that never offered a public key cannot be handed the vault
			// key, so do not offer to.
			var cb = vaultCheckbox();
			if (cb && !info.supports_vault) {
				cb.checked = false;
				cb.disabled = true;
			} else if (cb) {
				cb.disabled = false;
			}
		} catch (e) {
			alertBox(e.message || 'That code could not be checked.', 'danger');
		}
	}

	// ---- the vault handoff ---------------------------------------------------

	function openVaultDialog() {
		return new Promise(function (resolve, reject) {
			vaultResolve = resolve;
			vaultReject = reject;
			vaultError('');
			$('dlkVaultDialog').showModal();
		});
	}

	function closeVaultDialog(session) {
		var dlg = $('dlkVaultDialog');
		if (dlg.open) { dlg.close(); }
		var resolveFn = vaultResolve, rejectFn = vaultReject;
		vaultResolve = null; vaultReject = null;
		if (session) { if (resolveFn) { resolveFn(session); } }
		else if (rejectFn) { rejectFn(new Error('Unlock cancelled.')); }
	}

	async function unlock(method) {
		vaultError('');
		try {
			var session;
			if (method === 'passkey') {
				var d = await VK.derivePasskeyKek(SCOPE);
				session = await VK.unlockWithPasskey(SCOPE, d.kek, d.credentialId);
			} else {
				var field = document.querySelector('[name="dlk_passphrase"]');
				session = await VK.unlockWithPassphrase(SCOPE, field ? (field.value || '') : '');
				if (field) { field.value = ''; }
			}
			closeVaultDialog(session);
		} catch (e) {
			vaultError(e.message || 'Unlock failed.');
		}
	}

	/**
	 * The vault secret key, sealed to this device. VaultKeyring hands back a
	 * session that can seal to an arbitrary public key but deliberately will not
	 * expose the raw secret — so the sealing happens inside the session, which
	 * is exactly the boundary we want.
	 */
	async function sealVaultKeyFor(devicePublicKey) {
		var session = await openVaultDialog();
		try {
			return await session.sealSecretKeyTo(devicePublicKey);
		} finally {
			session.lock();
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

		try {
			var body = { code: code };
			if (wantVault) {
				if (!resolved || !resolved.device_pubkey) {
					await resolveCode();
				}
				if (!resolved || !resolved.device_pubkey) {
					alertBox('This device cannot receive your encrypted folders.', 'danger');
					return;
				}
				body.enable_vault = true;
				body.sealed_vault_key = await sealVaultKeyFor(resolved.device_pubkey);
			}

			var res = await api.post('drive_device_link_approve', body);
			alertBox(res.device_name + ' is linked. It will start syncing in a few seconds — you can close this page.', 'success');
			document.querySelectorAll('#dlkAlert ~ form button, [name="code"], [name="enable_vault"]').forEach(function (el) {
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
				window.location = '/verify-stepup?return=' + encodeURIComponent(back);
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

	function init() {
		var field = codeField();
		if (field) {
			field.addEventListener('change', resolveCode);
			field.addEventListener('blur', resolveCode);
		}
		var form = field ? field.closest('form') : null;
		if (form) { form.addEventListener('submit', approve); }
		if ($('dlkDeny')) { $('dlkDeny').onclick = deny; }
		if ($('dlkUnlockPasskey')) { $('dlkUnlockPasskey').onclick = function () { unlock('passkey'); }; }
		if ($('dlkUnlockPp')) { $('dlkUnlockPp').onclick = function () { unlock('passphrase'); }; }
		var dlg = $('dlkVaultDialog');
		if (dlg) {
			dlg.addEventListener('cancel', function () { closeVaultDialog(null); });
			dlg.querySelectorAll('[data-dlk-close]').forEach(function (b) {
				b.onclick = function () { closeVaultDialog(null); };
			});
		}

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
