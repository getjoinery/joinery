/**
 * vault-gate.js - unlock in place for an action that needs the vault open
 * (includes/VaultGate.php).
 *
 * [data-vault-unlock] runs JoineryVaultLock.unlock(); with no vault on the page
 * (vault-lock.js absent) it goes to /profile/security, where one is set up.
 * On 'joinery:vault-unlocked' every [data-vault-open] shows and every
 * [data-vault-locked] hides; 'joinery:vault-locked' swaps them back.
 *
 * @version 1.0
 */
(function () {
	if (window.JoineryVaultGate) { return; }
	window.JoineryVaultGate = true;

	function show(open) {
		document.querySelectorAll('[data-vault-open]').forEach(function (el) { el.hidden = !open; });
		document.querySelectorAll('[data-vault-locked]').forEach(function (el) { el.hidden = open; });
	}

	document.addEventListener('click', function (ev) {
		var btn = ev.target.closest ? ev.target.closest('[data-vault-unlock]') : null;
		if (!btn) { return; }
		ev.preventDefault();
		if (!window.JoineryVaultLock || !window.JoineryVaultLock.unlock) {
			window.location.href = '/profile/security';
			return;
		}
		btn.disabled = true;
		var reason = btn.getAttribute('data-vault-reason');
		window.JoineryVaultLock.unlock(reason ? { reason: reason } : {}).then(function (ok) {
			btn.disabled = false;
			if (ok) { show(true); }
		}, function () { btn.disabled = false; });
	});
	document.addEventListener('joinery:vault-unlocked', function () { show(true); });
	document.addEventListener('joinery:vault-locked', function () { show(false); });
})();
