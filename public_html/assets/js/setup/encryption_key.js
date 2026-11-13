/*
 * The encryption key step: the passkey route, the bypass-phrase route, and the shown-once recovery codes (includes/setup_steps/encryption_key.php and vault_shown_once.php).
 * Loaded by views/setup.php for this step.
 *
 * @version 1.0
 */
// The shown-once panel: the codes and the gate on Continue.
window.setupVaultShowResult = function (result, hideElementId) {
	var codes = (result.recovery_codes || []).join('\n');
	document.getElementById('setup-vault-codes').textContent = codes;
	var hide = document.getElementById(hideElementId);
	if (hide) { hide.classList.add('d-none'); }
	document.getElementById('setup-vault-result').classList.remove('d-none');

	function download(name, blob) {
		var a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = name;
		a.click();
		URL.revokeObjectURL(a.href);
	}

	document.getElementById('setup-vault-copy').addEventListener('click', function () {
		navigator.clipboard.writeText(codes);
	});
	document.getElementById('setup-vault-codes-download').addEventListener('click', function () {
		download('vault-recovery-codes.txt', new Blob([codes], { type: 'text/plain' }));
	});

	// Continue stays inert until the codes are acknowledged — this screen is
	// the only time they exist.
	var saved = document.getElementById('setup-vault-saved');
	var cont = document.getElementById('setup-vault-continue');
	cont.style.pointerEvents = 'none';
	cont.style.opacity = '0.5';
	saved.addEventListener('change', function () {
		var on = saved.checked;
		cont.style.pointerEvents = on ? '' : 'none';
		cont.style.opacity = on ? '' : '0.5';
		cont.setAttribute('aria-disabled', on ? 'false' : 'true');
	});
};

document.addEventListener('DOMContentLoaded', function () {
	var open = document.getElementById('setup-vault-phrase-open');
	var panel = document.getElementById('setup-vault-phrase');
	var one = document.getElementById('setup-vault-phrase-1');
	var two = document.getElementById('setup-vault-phrase-2');
	var ack = document.getElementById('setup-vault-phrase-ack');
	var create = document.getElementById('setup-vault-phrase-create');
	var hint = document.getElementById('setup-vault-phrase-hint');
	if (!open || !panel) { return; }

	function openPanel() {
		panel.classList.remove('d-none');
		open.classList.add('d-none');
		one.focus();
	}
	open.addEventListener('click', openPanel);
	// Back from the step-up ceremony: the user already chose the phrase route.
	if (/[?&]phrase=1(&|$)/.test(window.location.search)) { openPanel(); }

	function sync() {
		create.disabled = !(ack.checked && one.value.length >= (parseInt(create.getAttribute('data-min-chars'), 10) || 0) && one.value === two.value);
	}
	[one, two].forEach(function (el) { el.addEventListener('input', sync); });
	ack.addEventListener('change', sync);

	create.addEventListener('click', async function () {
		hint.textContent = '';
		create.disabled = true;
		try {
			// The phrase and the codes stay in this browser: only the account
			// halves of their keys are sent (specs/one_vault_experience.md § R6, R7).
			var made = await VaultKeyring.setupVault({ acknowledged: true, passphrase: one.value });
			var result = made.result;
			// Shared second-factor step-up handling: a 2xx render carrying the
			// flag redirects to the ceremony, then back to this step.
			if (result && result.second_factor_required) {
				window.location = '/profile/verify-stepup?return=' + encodeURIComponent('/setup?step=encryption_key');
				return;
			}
			one.value = two.value = '';
			if (window.JoinerySealed) { JoinerySealed.adopt(VaultKeyring.ROOT, made.rootSession); }
			// The whole step collapses, not just the phrase panel — the
			// "add a passkey elsewhere" route above it is moot now.
			window.setupVaultShowResult({ recovery_codes: made.codes }, 'setup-vault-phrase-branch');
		} catch (e) {
			hint.textContent = e.message || 'The key could not be created.';
			sync();
		}
	});
});

document.addEventListener('DOMContentLoaded', function () {
	var ack = document.getElementById('setup-vault-ack');
	var create = document.getElementById('setup-vault-create');
	var hint = document.getElementById('setup-vault-hint');
	if (!ack || !create) { return; }

	ack.addEventListener('change', function () { create.disabled = !ack.checked; });

	create.addEventListener('click', async function () {
		hint.textContent = '';
		create.disabled = true;
		try {
			// One touch makes the account key and the root vault together,
			// with codes made here (specs/one_vault_experience.md § R4, R6).
			var made = await VaultKeyring.setupVault({ acknowledged: true });
			if (window.JoinerySealed) { JoinerySealed.adopt(VaultKeyring.ROOT, made.rootSession); }
			window.setupVaultShowResult({ recovery_codes: made.codes }, 'setup-vault-start');
		} catch (e) {
			// Every refusal this page cannot talk the user out of reveals the
			// fallback: a mandatory step must never become a dead end. PRF
			// support is only provable by trying, so this is where an
			// authenticator that cannot derive a key finally announces itself.
			var blocked = document.getElementById('setup-vault-blocked');
			if (e.data && e.data.requires_password) {
				hint.textContent = 'Your account needs a password before a key can be created — set one on your security page first.';
				if (blocked) { blocked.classList.remove('d-none'); }
			} else if (e.data && e.data.prf_unsupported) {
				hint.textContent = 'This passkey cannot hold an encryption key — that is a limit of the device or security key, not a setting. '
					+ 'A passkey from a phone, laptop or password manager can, and there is another way if none of yours can.';
				var retry = document.getElementById('setup-vault-retry');
				if (retry) { retry.classList.remove('d-none'); }
				if (blocked) { blocked.classList.remove('d-none'); }
			} else {
				hint.textContent = e.message || 'The key could not be created.';
			}
			create.disabled = !ack.checked;
		}
	});
});
