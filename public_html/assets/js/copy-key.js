/**
 * copy-key.js - open a backup's key for a copy made from backups, in this
 * browser (specs/site_copy.md WP10; views/copy-key.php).
 *
 * The backup's key is sealed to the owner's recovery key in a libsodium sealed
 * box, which WebCrypto cannot open whole. It can do the one step that needs
 * the recovery key: X25519 of the recovery private key with the box's
 * ephemeral public key. That 32-byte value opens this box and nothing else;
 * the copy's agent finishes the opening. So the page imports the pasted key,
 * checks its public half against the fingerprint the backup is sealed to,
 * works out that value, and posts it with the public half. The pasted key is
 * cleared from the box and never put in the form.
 *
 * @version 1.0.0
 */
(function () {
	'use strict';
	var c = window.copyKeyCeremony;
	var subtle = window.crypto && window.crypto.subtle;
	var keyInput = document.getElementById('copy_key_secret');
	var button = document.getElementById('copy_key_open');
	var status = document.getElementById('copy_key_status');
	if (!c || !keyInput || !button || !status) return;
	var form = document.querySelector('input[name="shared"]');
	form = form ? form.form : null;

	// DER prefix that makes a raw 32-byte X25519 secret PKCS#8 (OID 1.3.101.110).
	var PKCS8_PREFIX = [0x30, 0x2e, 0x02, 0x01, 0x00, 0x30, 0x05, 0x06, 0x03, 0x2b, 0x65, 0x6e, 0x04, 0x22, 0x04, 0x20];

	function b64decode(s) {
		var bin = atob(String(s).replace(/\s+/g, ''));
		var out = new Uint8Array(bin.length);
		for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
		return out;
	}
	function b64encode(bytes) {
		var bin = '';
		bytes = new Uint8Array(bytes);
		for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
		return btoa(bin);
	}
	function b64urlDecode(s) {
		s = String(s).replace(/-/g, '+').replace(/_/g, '/');
		while (s.length % 4) s += '=';
		return b64decode(s);
	}
	function hex(bytes) {
		return Array.prototype.map.call(new Uint8Array(bytes), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
	}
	function say(message, ok) {
		status.textContent = message;
		status.className = (ok ? 'text-success' : 'text-danger') + ' small mt-2';
	}

	async function work(pasted) {
		if (!subtle) {
			throw new Error('This browser has no WebCrypto. Use a current Chrome, Firefox or Safari.');
		}
		var priv;
		try { priv = b64decode(pasted); } catch (e) { throw new Error('That is not a recovery key: it is one line of base64.'); }
		if (priv.length !== 32) {
			throw new Error('That is not a recovery key: expected 32 bytes, got ' + priv.length + '. Paste the private key exactly as you saved it.');
		}
		var pkcs8 = new Uint8Array(48);
		pkcs8.set(PKCS8_PREFIX, 0);
		pkcs8.set(priv, 16);
		priv.fill(0);
		var key;
		try {
			// Extractable only so its public half can be read back below.
			key = await subtle.importKey('pkcs8', pkcs8, { name: 'X25519' }, true, ['deriveBits']);
		} catch (e) {
			throw new Error('This browser cannot do X25519. Use a current Chrome, Firefox or Safari.');
		} finally {
			pkcs8.fill(0);
		}
		var jwk = await subtle.exportKey('jwk', key);
		var pub = b64urlDecode(jwk.x);
		jwk.d = '';
		var fp = hex(await subtle.digest('SHA-256', pub));
		if (fp !== c.fingerprint) {
			throw new Error('That recovery key has fingerprint ' + fp.slice(0, 16) + ', and this backup is sealed to ' +
				c.fingerprint.slice(0, 16) + '. Check you copied the right entry.');
		}
		var eph = await subtle.importKey('raw', b64decode(c.ephemeralPublic), { name: 'X25519' }, false, []);
		var shared = await subtle.deriveBits({ name: 'X25519', public: eph }, key, 256);
		return { shared: b64encode(shared), pub: b64encode(pub) };
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
