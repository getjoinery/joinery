/**
 * VaultCrypto - the Sealed Vault's shared client-custody browser crypto module.
 *
 * Scope-agnostic on purpose: the password manager instantiates it with scope
 * 'passwords', Drive will reuse it unchanged with scope 'drive'. Nothing here
 * hardcodes a scope, a PRF context, or a consumer - the caller passes those in.
 *
 * The whole point is zero-knowledge: every key and every plaintext lives only
 * here, in the browser. The server stores and returns opaque blobs; it never
 * receives a KEK, a secret key, a DEK, or a plaintext. This module never
 * transmits anything - callers do the fetch()es, passing only ciphertext.
 *
 * Key hierarchy (mirrors the server-custody vault, but performed in-browser):
 *   unlocker  --KEK-->  vault X25519 secret key  --seals-->  a data key (DEK)
 *                                                            --encrypts--> content
 * An unlocker's KEK (from a passkey's WebAuthn PRF output, a recovery code, or
 * a passphrase) wraps the vault secret key (AES-256-GCM). The secret key opens
 * the sealed DEK (ECIES over X25519). The DEK, held as a NON-EXTRACTABLE
 * CryptoKey, encrypts each content blob. Adding or changing an unlocker only
 * re-wraps the secret key - the DEK and the content are untouched.
 *
 * Blob formats (all base64 of raw bytes):
 *   wrapped secret key : base64( IV[12] || AES-GCM-ciphertext )   (AD = caller's string)
 *   sealed DEK (ECIES) : base64( ephPub[32] || IV[12] || AES-GCM-ciphertext )
 *                        (AES key = HKDF(shared, 'sealed-vault:dek' || ephPub || recipientPub))
 *   content blob       : base64( IV[12] || AES-GCM-ciphertext )
 *
 * Crypto primitives are WebCrypto (AES-256-GCM, X25519, HKDF, SHA-256). The one
 * exception - the platform's sanctioned deviation from vanilla-JS-only - is the
 * vendored, hash-pinned Argon2id WASM (assets/vendor/argon2/argon2-bundled.min.js)
 * used ONLY for the low-entropy passphrase-fallback KDF. Recovery codes carry
 * >=128 bits of entropy, so their KEK is a fast SHA-256, never Argon2id.
 *
 * Every content blob and sealed DEK here is raw: the `v1.edge.` /
 * `v1.edgeseal.{scope}.` framing a sealed row carries belongs to the row layer
 * (joinery-sealed.js, SystemBase), and the server's SealedBox::sealEdge /
 * aeadEncryptGcm produce these same bytes. selfCheck() proves that against the
 * shared vector in tests/vault/fixtures/edge_vector.json.
 *
 * @version 1.1 - encrypt()/decrypt() take an optional AD; selfCheck() opens the
 *   shared edge-format vector; a passphrase KDF that never settles rejects
 *   after a minute instead of hanging the unlock
 * @version 1.0
 */
window.VaultCrypto = (function () {
	'use strict';

	var subtle = window.crypto && window.crypto.subtle;
	var ARGON2_URL = '/assets/vendor/argon2/argon2-bundled.min.js';

	// Argon2id parameters (RFC 9106 second recommendation / Bitwarden default).
	// Stored per-user in uev_kdf_params so raising them later re-derives and
	// re-wraps at the next unlock, leaving content untouched.
	var DEFAULT_KDF_PARAMS = { alg: 'argon2id', mem: 65536, time: 3, parallelism: 4, hashLen: 32 };

	// ---- byte / base64 helpers ------------------------------------------------

	function randomBytes(n) {
		var b = new Uint8Array(n);
		window.crypto.getRandomValues(b);
		return b;
	}

	function b64encode(bytes) {
		var arr = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
		var binary = '';
		for (var i = 0; i < arr.length; i++) binary += String.fromCharCode(arr[i]);
		return btoa(binary);
	}

	function b64decode(str) {
		var binary = atob(String(str));
		var bytes = new Uint8Array(binary.length);
		for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
		return bytes;
	}

	// base64url (for a passkey PRF output, which JoineryPasskeys hands back b64url)
	function b64urlDecode(b64url) {
		var base64 = String(b64url).replace(/-/g, '+').replace(/_/g, '/');
		while (base64.length % 4) base64 += '=';
		return b64decode(base64);
	}

	function utf8(str) { return new TextEncoder().encode(str); }
	function fromUtf8(bytes) { return new TextDecoder().decode(bytes); }

	function concat() {
		var total = 0, i;
		for (i = 0; i < arguments.length; i++) total += arguments[i].length;
		var out = new Uint8Array(total), off = 0;
		for (i = 0; i < arguments.length; i++) { out.set(arguments[i], off); off += arguments[i].length; }
		return out;
	}

	// ---- KEK derivation (one per unlocker type) -------------------------------

	// A passkey's WebAuthn PRF output (32 bytes, browser-derived, never sent to
	// the server) is used directly as the KEK. The distinct per-scope PRF
	// context guarantees one scope's KEK can never unwrap another's secret key.
	async function kekFromPrf(prfOutputB64url) {
		var raw = b64urlDecode(prfOutputB64url);
		return importAesKek(raw);
	}

	// Recovery codes have >=128 bits of entropy, so a fast keyed hash is enough
	// - a slow KDF buys nothing against a random 128-bit input. Mirrors the
	// server-custody kekFromRecoveryCode (crypto_generichash) in intent.
	async function kekFromRecoveryCode(code, saltB64) {
		// Crockford base32 leniency: the codes are drawn from the Crockford
		// alphabet (no I/L/O/U), so map a user's ambiguous entry back before
		// hashing. Then uppercase and strip separators.
		var normalized = String(code).toUpperCase()
			.replace(/O/g, '0').replace(/[IL]/g, '1')
			.replace(/[^A-Z0-9]/g, '');
		var material = concat(b64decode(saltB64), utf8(normalized));
		var digest = await subtle.digest('SHA-256', material);
		return importAesKek(new Uint8Array(digest));
	}

	// The low-entropy passphrase fallback: memory-hard Argon2id via the vendored
	// WASM. The strongest defense for an offline brute-force of the passphrase
	// against a stolen wrapped key.
	// The WASM module aborts without settling its promise when it cannot run
	// (a Content-Security-Policy that forbids compiling WebAssembly, a browser
	// out of memory), which would leave an unlock spinning forever. Argon2id at
	// these parameters takes seconds; a minute means it is not coming back.
	var ARGON2_TIMEOUT_MS = 60000;

	async function kekFromPassphrase(passphrase, saltB64, kdfParams) {
		var params = kdfParams || DEFAULT_KDF_PARAMS;
		await loadArgon2();
		var timer;
		var gaveUp = new Promise(function (resolve, reject) {
			timer = setTimeout(function () {
				reject(new Error('This browser could not check the passphrase (the Argon2 module did not run).'));
			}, ARGON2_TIMEOUT_MS);
		});
		var result = await Promise.race([gaveUp, window.argon2.hash({
			pass: passphrase,
			salt: b64decode(saltB64),
			time: params.time,
			mem: params.mem,
			parallelism: params.parallelism,
			hashLen: params.hashLen || 32,
			type: window.argon2.ArgonType.Argon2id,
		})]).finally(function () { clearTimeout(timer); });
		return importAesKek(result.hash);
	}

	var _argon2Loading = null;
	function loadArgon2() {
		if (window.argon2 && window.argon2.hash) return Promise.resolve();
		if (_argon2Loading) return _argon2Loading;
		_argon2Loading = new Promise(function (resolve, reject) {
			var s = document.createElement('script');
			s.src = ARGON2_URL;
			s.onload = function () {
				if (window.argon2 && window.argon2.hash) resolve();
				else reject(new Error('Argon2 module failed to initialise.'));
			};
			s.onerror = function () { reject(new Error('Could not load the Argon2 module.')); };
			document.head.appendChild(s);
		});
		return _argon2Loading;
	}

	function importAesKek(rawKeyBytes) {
		return subtle.importKey('raw', rawKeyBytes, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
	}

	// ---- the vault X25519 keypair (generated in-browser) ----------------------

	// Returns { publicKeyB64, secretKeyBytes } - the raw 32-byte public key
	// (base64, stored cleartext in uev_public_key) and the PKCS8-encoded secret
	// key bytes (wrapped under each unlocker's KEK, never stored unwrapped).
	async function generateVaultKeypair() {
		var pair = await subtle.generateKey({ name: 'X25519' }, true, ['deriveBits']);
		var pub = new Uint8Array(await subtle.exportKey('raw', pair.publicKey));
		var sec = new Uint8Array(await subtle.exportKey('pkcs8', pair.privateKey));
		return { publicKeyB64: b64encode(pub), secretKeyBytes: sec };
	}

	function importVaultPrivateKey(secretKeyBytes) {
		return subtle.importKey('pkcs8', secretKeyBytes, { name: 'X25519' }, false, ['deriveBits']);
	}
	function importVaultPublicKey(publicKeyB64) {
		return subtle.importKey('raw', b64decode(publicKeyB64), { name: 'X25519' }, false, []);
	}

	// ---- wrap / unwrap the vault secret key under a KEK (the uew blob) ---------

	// $ad is the caller's row-binding string (e.g. "vault:passwords:passkey:42").
	// Binding it means a wrapping's ciphertext can't be spliced onto another row
	// and still open. base64( IV[12] || ciphertext ).
	async function wrapSecretKey(secretKeyBytes, kek, ad) {
		var iv = randomBytes(12);
		var ct = await subtle.encrypt({ name: 'AES-GCM', iv: iv, additionalData: utf8(ad || '') }, kek, secretKeyBytes);
		return b64encode(concat(iv, new Uint8Array(ct)));
	}

	async function unwrapSecretKey(blob, kek, ad) {
		var raw = b64decode(blob);
		var iv = raw.slice(0, 12), ct = raw.slice(12);
		var pt = await subtle.decrypt({ name: 'AES-GCM', iv: iv, additionalData: utf8(ad || '') }, kek, ct);
		return new Uint8Array(pt);
	}

	// ---- seal / open a DEK to the vault keypair (ECIES over X25519) ------------
	// This is the same primitive Drive uses to seal a file key to another user's
	// public key (multi-user sharing). base64( ephPub[32] || IV[12] || ciphertext ).
	// The AES key is HKDF(shared, info = 'sealed-vault:dek' || ephPub ||
	// recipientPub) - binding both public keys into the KDF, as HPKE (RFC 9180)
	// and libsodium's sealed box do, so a shared secret only ever keys THIS
	// (ephemeral, recipient) pair. Opening therefore needs the recipient's own
	// public key alongside the secret key.

	async function ecdhAesKey(privateKey, publicKey, kdfInfo, usages) {
		var shared = await subtle.deriveBits({ name: 'X25519', public: publicKey }, privateKey, 256);
		var hkdfKey = await subtle.importKey('raw', shared, { name: 'HKDF' }, false, ['deriveKey']);
		return subtle.deriveKey(
			{ name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(0), info: kdfInfo },
			hkdfKey, { name: 'AES-GCM', length: 256 }, false, usages
		);
	}

	function sealKdfInfo(ephPubBytes, recipientPubBytes) {
		return concat(utf8('sealed-vault:dek'), ephPubBytes, recipientPubBytes);
	}

	async function sealToPublicKey(dataBytes, recipientPublicKeyB64) {
		var recipientBytes = b64decode(recipientPublicKeyB64);
		var recipient = await subtle.importKey('raw', recipientBytes, { name: 'X25519' }, false, []);
		var eph = await subtle.generateKey({ name: 'X25519' }, true, ['deriveBits']);
		var ephPub = new Uint8Array(await subtle.exportKey('raw', eph.publicKey));
		var key = await ecdhAesKey(eph.privateKey, recipient, sealKdfInfo(ephPub, recipientBytes), ['encrypt']);
		var iv = randomBytes(12);
		var ct = await subtle.encrypt({ name: 'AES-GCM', iv: iv }, key, dataBytes);
		return b64encode(concat(ephPub, iv, new Uint8Array(ct)));
	}

	async function openFromSecretKey(sealedBlob, secretKeyBytes, recipientPublicKeyB64) {
		var raw = b64decode(sealedBlob);
		var ephPubBytes = raw.slice(0, 32), iv = raw.slice(32, 44), ct = raw.slice(44);
		var recipientBytes = b64decode(recipientPublicKeyB64);
		var priv = await importVaultPrivateKey(secretKeyBytes);
		var ephPub = await subtle.importKey('raw', ephPubBytes, { name: 'X25519' }, false, []);
		var key = await ecdhAesKey(priv, ephPub, sealKdfInfo(new Uint8Array(ephPubBytes), recipientBytes), ['decrypt']);
		var pt = await subtle.decrypt({ name: 'AES-GCM', iv: iv }, key, ct);
		return new Uint8Array(pt);
	}

	// ---- the data key (DEK) + content encryption ------------------------------

	// A fresh random 256-bit DEK. Returns { dekBytes, dekKey }: dekBytes to seal
	// to the public key (then discard), dekKey the non-extractable CryptoKey for
	// content. Callers should null out dekBytes as soon as it is sealed.
	async function newDek() {
		var dekBytes = randomBytes(32);
		var dekKey = await importDek(dekBytes);
		return { dekBytes: dekBytes, dekKey: dekKey };
	}

	function importDek(dekBytes) {
		return subtle.importKey('raw', dekBytes, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
	}

	// The encrypt()->blob / blob->decrypt() contract the spec names. Each blob is
	// self-describing: base64( IV[12] || ciphertext ). Content is an opaque
	// string (the consumer JSON-encodes its own record). `ad` (optional, bytes or
	// a string) binds the blob to its row - a sealed model field passes
	// `{prefix}:{id}:{field}` so a ciphertext spliced onto another row fails.
	function gcmParams(iv, ad) {
		var params = { name: 'AES-GCM', iv: iv };
		if (ad !== undefined && ad !== null) params.additionalData = typeof ad === 'string' ? utf8(ad) : ad;
		return params;
	}

	async function encrypt(plaintextString, dekKey, ad) {
		var iv = randomBytes(12);
		var ct = await subtle.encrypt(gcmParams(iv, ad), dekKey, utf8(plaintextString));
		return b64encode(concat(iv, new Uint8Array(ct)));
	}

	async function decrypt(blob, dekKey, ad) {
		var raw = b64decode(blob);
		var iv = raw.slice(0, 12), ct = raw.slice(12);
		var pt = await subtle.decrypt(gcmParams(iv, ad), dekKey, ct);
		return fromUtf8(new Uint8Array(pt));
	}

	// ---- self-check: the shared edge-format vector ----------------------------

	// The same vector tests/vault/fixtures/edge_vector.json holds and the PHP
	// suite reproduces byte for byte. Opening it here proves this engine and the
	// server agree on the sealed-DEK and field formats. Test material only.
	var EDGE_VECTOR = {
		recipientSecretHex: 'b8e67311ce5fc36fbea57ba23b44b7bd96f3a05386aaa808754e46a470cd4b64',
		recipientPublicB64: 'YO6P+WWilqSUxSipdAY4nfvIGw9u4mFN3hBKas4ZBVY=',
		dekHex: 'a915e4d16fe838501138950a7aa00d509abd1416d68c069425cf44e0fab44ea3',
		sealedDekB64: 'oYPrrXh6/HP7O/wBfmEjulnEr/TB/73xZKOGT1Bib1xN+CQ1a6AGgnOrKRin5mqZICXeL60kdnhYxr/h5FYNKC6F+d1tyW8/49UZifhpB2n13vjmXLhWtVyUd58=',
		fieldAd: 'acn:42:acn_body',
		fieldPlaintext: 'Edge vector: the same bytes on both sides. ✓',
		fieldBlobB64: 'xPz/lc6huSNav/Tp2wox1T2zaBUxtkEVSUojPW+KZjNNwy4RtEMvEDIil5nXGNSR+LCPdKwkVsFTCBMNJ7rWIzGrFZWOrPe0sYM=',
	};

	function fromHex(h) {
		var out = new Uint8Array(h.length / 2);
		for (var i = 0; i < out.length; i++) out[i] = parseInt(h.substr(i * 2, 2), 16);
		return out;
	}
	function sameBytes(a, b) {
		if (a.length !== b.length) return false;
		for (var i = 0; i < a.length; i++) if (a[i] !== b[i]) return false;
		return true;
	}

	// Resolves true when this engine opens the vector (DEK and field, the field
	// refusing another row's AD) and a fresh seal round-trips; false otherwise.
	async function selfCheck() {
		try {
			var v = EDGE_VECTOR;
			var pkcs8 = concat(fromHex('302e020100300506032b656e04220420'), fromHex(v.recipientSecretHex));
			var dek = await openFromSecretKey(v.sealedDekB64, pkcs8, v.recipientPublicB64);
			if (!sameBytes(dek, fromHex(v.dekHex))) return false;
			var dekKey = await importDek(dek);
			if (await decrypt(v.fieldBlobB64, dekKey, v.fieldAd) !== v.fieldPlaintext) return false;
			var refused = false;
			try { await decrypt(v.fieldBlobB64, dekKey, v.fieldAd + 'x'); } catch (e) { refused = true; }
			if (!refused) return false;
			var pair = await generateVaultKeypair();
			var fresh = randomBytes(32);
			var reopened = await openFromSecretKey(await sealToPublicKey(fresh, pair.publicKeyB64), pair.secretKeyBytes, pair.publicKeyB64);
			return sameBytes(reopened, fresh);
		} catch (e) {
			return false;
		}
	}

	// ---- feature probe --------------------------------------------------------

	// Best-effort: does this browser have the WebCrypto primitives the module
	// needs? X25519 in WebCrypto is the newest of them; a false result means the
	// client can't do client-custody crypto here (surface a clear message).
	async function isSupported() {
		if (!subtle || !window.crypto.getRandomValues) return false;
		try {
			await subtle.generateKey({ name: 'X25519' }, true, ['deriveBits']);
			return true;
		} catch (e) {
			return false;
		}
	}

	return {
		DEFAULT_KDF_PARAMS: DEFAULT_KDF_PARAMS,
		randomBytes: randomBytes,
		b64encode: b64encode,
		b64decode: b64decode,
		kekFromPrf: kekFromPrf,
		kekFromRecoveryCode: kekFromRecoveryCode,
		kekFromPassphrase: kekFromPassphrase,
		generateVaultKeypair: generateVaultKeypair,
		wrapSecretKey: wrapSecretKey,
		unwrapSecretKey: unwrapSecretKey,
		sealToPublicKey: sealToPublicKey,
		openFromSecretKey: openFromSecretKey,
		newDek: newDek,
		importDek: importDek,
		encrypt: encrypt,
		decrypt: decrypt,
		isSupported: isSupported,
		selfCheck: selfCheck,
	};
})();
