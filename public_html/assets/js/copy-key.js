/**
 * copy-key.js - open a backup's key for a copy made from backups, in this
 * browser (specs/site_copy.md WP10; views/copy-key.php).
 *
 * The backup's key is sealed to the owner's recovery key. The page does the
 * one step that needs the recovery key and posts only the value that opens
 * this box and nothing else; the copy's agent finishes the opening. That
 * value is the X-Wing box's dek-scope AES key (VaultCrypto.halfOpenKeyBox);
 * a box sealed to an old X25519 recovery key is refused. The page checks the
 * pasted key's public half against the fingerprint the box is sealed to and
 * posts the value with that public half. The pasted key is cleared from the
 * box and never put in the form.
 *
 * Also the encrypted data root's unlock from its management node's page
 * (server_manager node_detail_tabs/overview_encryption.php): the same value,
 * for the machine's break-glass box.
 *
 * window.copyKeyCeremony is { version, ciphertext, fingerprint };
 * assets/js/vault-crypto.js loads before this file.
 *
 * @version 2.1 - header: a v1 box is refused (v1_key_retirement WP2)
 * @version 2.0 - X-Wing (specs/post_quantum_sealing.md WP2): a v2 box posts its dek-scope key; the
 *   arithmetic is VaultCrypto's
 * @version 1.0.1
 */
(function () {
	'use strict';
	var c = window.copyKeyCeremony;
	var keyInput = document.getElementById('copy_key_secret');
	var button = document.getElementById('copy_key_open');
	var status = document.getElementById('copy_key_status');
	if (!c || !keyInput || !button || !status) return;
	var form = document.querySelector('input[name="shared"]');
	form = form ? form.form : null;

	function say(message, ok) {
		status.textContent = message;
		status.className = (ok ? 'text-success' : 'text-danger') + ' small mt-2';
	}

	async function work(pasted) {
		if (!window.VaultCrypto) {
			throw new Error('The page\'s encryption script did not load. Reload the page and try again.');
		}
		return window.VaultCrypto.halfOpenKeyBox(c, pasted, c.fingerprint);
	}

	button.addEventListener('click', function () {
		var pasted = keyInput.value.trim();
		if (!pasted) { say('Paste your recovery key first.', false); return; }
		button.disabled = true;
		say('Working in this browser…', true);
		work(pasted).then(function (r) {
			keyInput.value = '';
			form.elements['shared'].value = r.shared;
			form.elements['public_key'].value = r.pub;
			say('Unlocked in this browser. Sending to this machine…', true);
			if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
		}).catch(function (err) {
			say(err.message || String(err), false);
			button.disabled = false;
		});
	});
})();
