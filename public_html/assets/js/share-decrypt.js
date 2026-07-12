/**
 * share-decrypt.js — the anonymous encrypted-file share page (/s/{token}).
 *
 * The link's URL fragment carries the raw file key (base64url), which the browser
 * never sends to the server (fragments are client-only). This script reads it,
 * decrypts the server-served metadata blob to recover the real name/mime, and —
 * on demand — fetches the ciphertext, decrypts it with DriveCrypto, and hands the
 * plaintext to the visitor as a download. The server only ever holds ciphertext.
 *
 * Depends on VaultCrypto + DriveCrypto. window.SHARE_ENC carries the ciphertext
 * download URL, the encrypted metadata blob, and the ciphertext size.
 */
(function () {
	'use strict';

	function $(id) { return document.getElementById(id); }
	function human(n) {
		n = Number(n) || 0;
		if (n < 1024) return n + ' B';
		var u = ['KB', 'MB', 'GB', 'TB'], i = -1;
		do { n /= 1024; i++; } while (n >= 1024 && i < u.length - 1);
		return n.toFixed(n < 10 ? 1 : 0) + ' ' + u[i];
	}
	function fail(msg) {
		var e = $('shareEncError');
		if (e) { e.textContent = msg; e.hidden = false; }
		var s = $('shareEncStatus'); if (s) s.hidden = true;
	}

	// base64url (fragment) -> raw bytes.
	function b64urlToBytes(s) {
		s = String(s).replace(/-/g, '+').replace(/_/g, '/');
		while (s.length % 4) s += '=';
		var bin = atob(s), out = new Uint8Array(bin.length);
		for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
		return out;
	}

	async function init() {
		var CFG = window.SHARE_ENC || {};
		var DC = window.DriveCrypto;
		if (!DC) { fail('Encryption is unavailable in this browser.'); return; }
		var supported = await DC.isSupported();
		if (!supported) { fail('This browser cannot decrypt the file (needs modern WebCrypto).'); return; }

		var frag = (window.location.hash || '').replace(/^#/, '');
		// Accept "#<key>" or "#k=<key>".
		var m = frag.match(/(?:^|[#&])k=([^&]+)/);
		var keyStr = m ? m[1] : frag;
		if (!keyStr) { fail('This link is missing its decryption key.'); return; }

		var fkKey, meta;
		try {
			var fkBytes = b64urlToBytes(keyStr);
			fkKey = await DC.importFileKey(fkBytes);
			meta = CFG.metadata ? await DC.decryptMetadata(CFG.metadata, fkKey) : {};
		} catch (e) {
			fail('The decryption key in this link is not valid for this file.');
			return;
		}

		if (meta.name && $('shareEncName')) $('shareEncName').textContent = meta.name;
		if ($('shareEncMeta')) {
			$('shareEncMeta').textContent = human(meta.size != null ? meta.size : CFG.size)
				+ ' · ' + (meta.mime || 'file');
		}

		var btn = $('shareEncDownload');
		if (btn) {
			btn.disabled = false;
			btn.onclick = function () { doDownload(CFG, fkKey, meta, btn); };
		}
	}

	async function doDownload(CFG, fkKey, meta, btn) {
		var DC = window.DriveCrypto;
		btn.disabled = true;
		var status = $('shareEncStatus');
		if (status) { status.hidden = false; status.textContent = 'Decrypting…'; }
		try {
			var resp = await fetch(CFG.downloadUrl);
			if (!resp.ok) throw new Error('Could not fetch the file.');
			var buf = await resp.arrayBuffer();
			var plain = await DC.decryptContent(buf, fkKey, meta.cid);
			var blob = new Blob([plain], { type: meta.mime || 'application/octet-stream' });
			var url = URL.createObjectURL(blob);
			var a = document.createElement('a');
			a.href = url; a.download = meta.name || 'download';
			document.body.appendChild(a); a.click(); a.remove();
			setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
			if (status) status.textContent = 'Decrypted in your browser.';
		} catch (e) {
			fail(e.message || 'Decryption failed.');
		} finally {
			btn.disabled = false;
		}
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
