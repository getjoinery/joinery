/*
 * The sign-in security step: the shown-once backup codes, and adding a passkey (includes/setup_steps/signin_security.php).
 * Loaded by views/setup.php for this step.
 *
 * @version 1.0
 */
// The shown-once backup codes, and the gate on Continue.
(function () {
	var codesEl = document.getElementById('setup-totp-codes');
	if (!codesEl) { return; }
	var codes = codesEl.textContent;
	var saved = document.getElementById('setup-codes-saved');
	var cont = document.getElementById('setup-codes-continue');
	cont.style.pointerEvents = 'none'; cont.style.opacity = '0.5';
	saved.addEventListener('change', function () {
		var on = saved.checked;
		cont.style.pointerEvents = on ? '' : 'none';
		cont.style.opacity = on ? '' : '0.5';
		cont.setAttribute('aria-disabled', on ? 'false' : 'true');
	});
	document.getElementById('setup-codes-copy').addEventListener('click', function () {
		navigator.clipboard.writeText(codes);
	});
	document.getElementById('setup-codes-download').addEventListener('click', function () {
		var a = document.createElement('a');
		a.href = URL.createObjectURL(new Blob([codes], { type: 'text/plain' }));
		a.download = 'backup-codes.txt';
		a.click();
		URL.revokeObjectURL(a.href);
	});
})();

document.addEventListener('DOMContentLoaded', function () {
	var btn = document.getElementById('setup-pk-add');
	var hint = document.getElementById('setup-pk-hint');
	if (!btn) { return; }
	if (!window.JoineryPasskeys || !JoineryPasskeys.isSupported()) {
		btn.disabled = true;
		var pwRow = document.getElementById('setup-pk-password-row');
		if (pwRow) { pwRow.style.display = 'none'; }
		hint.textContent = window.isSecureContext
			? 'This browser does not support passkeys — use the authenticator app below.'
			: 'Passkeys only work on the secure version of the site — finish the "Secure connection" step first, or use the authenticator app below.';
		return;
	}
	btn.addEventListener('click', async function () {
		hint.textContent = '';
		btn.disabled = true;
		try {
			var body = {};
			var pw = document.getElementById('setup-pk-password');
			if (pw) { body.current_password = pw.value; }
			var opts = await joineryApi.post('passkey_register_options', body);
			if (opts.second_factor_required) {
				// Confirm with the second factor this account has (its
				// authenticator app, before any passkey), then come back here.
				hint.textContent = 'Taking you to confirm with your second factor…';
				window.location = '/profile/verify-stepup?return=' + encodeURIComponent('/setup?step=signin_security');
				return;
			}
			var credential = await JoineryPasskeys.register(opts.options);
			// If a vault already exists, the creation just derived its key and
			// the verify can write the wrapping when an unlocker the vault
			// already has confirms it — the same activation the security page
			// runs. Best-effort: backing out leaves the passkey enrolled and
			// the security page can finish it.
			var unlocker = null;
			if (window.JoineryVaultLock && window.JoineryVaultLock.collectUnlocker) {
				try {
					unlocker = await JoineryVaultLock.collectUnlocker('to let the new passkey open your vault');
				} catch (e) { unlocker = null; }
			}
			var res = await joineryApi.post('passkey_register_verify', {
				credential: credential,
				label: 'Added during setup',
				unlocker: unlocker
			});
			window.location = '/setup?step=signin_security';
		} catch (e) {
			hint.textContent = e.message || 'The passkey could not be added.';
			btn.disabled = false;
		}
	});
});
