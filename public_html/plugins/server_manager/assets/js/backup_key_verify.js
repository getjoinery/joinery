/**
 * Backup key recovery — in-page possession check.
 *
 * The operator pastes their recovery private key (from wherever they actually
 * keep it — a password manager, typically) and this opens the challenge the
 * server sealed to the configured public key. That is the point: it tests the
 * copy they will still have after the machine is gone, not a file sitting on a
 * server that is about to be deleted.
 *
 * The key never leaves this page. There is no fetch, no form field, no storage:
 * it is read from an input that lives OUTSIDE the form, used in memory, and the
 * input is cleared immediately. Only the recovered proof string — which is
 * public, being derived from the public key's fingerprint — goes into the form
 * the operator then submits. The server re-checks that proof either way, so
 * nothing here is trusted.
 *
 * Crypto is WebCrypto only (X25519 + HKDF-SHA256 + AES-256-GCM), matching
 * BackupKeyCustody::browser_challenge(). Browsers without X25519 fall back to
 * the command-line instructions shown alongside.
 *
 * @version 1.0
 */
window.smBackupKeyVerify = (function () {
	'use strict';

	var subtle = window.crypto && window.crypto.subtle;

	// DER prefix that turns a raw 32-byte X25519 secret into the PKCS#8 form
	// WebCrypto insists on for private keys (OID 1.3.101.110).
	var PKCS8_PREFIX = new Uint8Array([
		0x30, 0x2e, 0x02, 0x01, 0x00, 0x30, 0x05, 0x06,
		0x03, 0x2b, 0x65, 0x6e, 0x04, 0x22, 0x04, 0x20
	]);

	var INFO_PREFIX = 'sm-escrow-possession:';

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

	/** Wipe a key buffer as soon as it has been used. */
	function zero(bytes) { if (bytes && bytes.fill) bytes.fill(0); }

	/**
	 * Open the challenge with a pasted private key.
	 * @returns {Promise<string>} the recovered proof string
	 */
	async function open(challengeB64, privateKeyB64, publicKeyB64) {
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

		var info = concat(utf8(INFO_PREFIX), ephPub, recipientPub);
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

	/**
	 * Wire the paste box to the proof field. Ids are passed in so the markup
	 * stays the panel's business.
	 */
	function attach(opts) {
		var keyInput = document.getElementById(opts.keyInputId);
		var button = document.getElementById(opts.buttonId);
		var status = document.getElementById(opts.statusId);
		var proofInput = document.querySelector('[name="escrow_proof"]');
		if (!keyInput || !button || !status || !proofInput) return;

		function say(message, ok) {
			status.textContent = message;
			status.className = ok ? 'text-success small mt-2' : 'text-danger small mt-2';
		}

		button.addEventListener('click', function () {
			var pasted = keyInput.value.trim();
			if (!pasted) { say('Paste your recovery key first.', false); return; }

			button.disabled = true;
			say('Opening…', true);
			open(opts.challenge, pasted, opts.publicKey).then(function (proof) {
				keyInput.value = '';            // the key is done with — do not keep it around
				proofInput.value = proof;
				say('Opened with your key. Click Verify to finish.', true);
				proofInput.focus();
			}).catch(function (err) {
				say(err.message || String(err), false);
			}).then(function () {
				button.disabled = false;
			});
		});
	}

	return { open: open, attach: attach };
})();
