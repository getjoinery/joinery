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
 *   unlocker  --KEK-->  vault X-Wing secret (a 32-byte seed)  --seals-->  a data key (DEK)
 *                                                                        --encrypts--> content
 * An unlocker's KEK (from a passkey's WebAuthn PRF output, a recovery code, or
 * a passphrase) wraps the vault secret (AES-256-GCM). The secret opens the
 * sealed DEK (an X-Wing seal, below). The DEK, held as a NON-EXTRACTABLE
 * CryptoKey, encrypts each content blob. Adding or changing an unlocker only
 * re-wraps the secret - the DEK and the content are untouched.
 *
 * Blob formats (all base64 of raw bytes):
 *   wrapped secret     : base64( IV[12] || AES-GCM-ciphertext )   (AD = caller's string)
 *   sealed DEK (v2)    : base64( ct[1120] || IV[12] || AES-GCM-ciphertext )
 *                        (X-Wing to the 1216-byte public key; AES key =
 *                        HKDF(ss, salt empty, 'joinery-seal:v2:' || scope), scope 'dek')
 *   content blob       : base64( IV[12] || AES-GCM-ciphertext )
 * The v2 seal is the one every runtime writes (specs/post_quantum_sealing.md R2;
 * includes/SealedBox.php sealV2 makes the same bytes). A v1 seal (X25519 ECIES:
 * ephPub[32] || IV || ct, AES key HKDF(shared, 'sealed-vault:dek' || ephPub ||
 * recipientPub), the secret a 48-byte PKCS#8) is only ever OPENED, so a vault
 * still on its old key can be rotated: sealing to a 32-byte key is refused.
 * Which one a blob is follows from the recipient public key handed in beside
 * it (1216 bytes: v2; 32: v1), never from guessing at the blob.
 *
 * Crypto primitives are WebCrypto (AES-256-GCM, HKDF, SHA-256; X25519 only to
 * open a v1 seal) plus two vendored, hash-pinned bundles, the platform's
 * sanctioned deviation from vanilla-JS-only, each loaded on demand:
 * X-Wing (assets/vendor/noble/, WebCrypto has no ML-KEM), loaded with its
 * Subresource Integrity hash; and Argon2id WASM (assets/vendor/argon2/) used
 * ONLY for the low-entropy passphrase-fallback KDF. Recovery codes carry
 * >=128 bits of entropy, so their KEK is a fast SHA-256, never Argon2id.
 *
 * Every content blob and sealed DEK here is raw: the `v1.edge.` /
 * `v2.edgeseal.{scope}.` framing a sealed row carries belongs to the row layer
 * (joinery-sealed.js, SystemBase), and the server's SealedBox::sealEdge /
 * aeadEncryptGcm produce these same bytes. selfCheck() proves that against the
 * shared vectors in tests/vault/fixtures/xwing_vector.json and edge_vector.json.
 *
 * @version 2.0 - X-Wing (specs/post_quantum_sealing.md WP2): a vault key is a 32-byte seed and a
 *   1216-byte public key; seals are v2, a 32-byte key is refused; v1 opens only; scoped seals for the
 *   operator challenges (openChallenge, which opens only the four challenge labels, never 'dek') and
 *   halfOpenKeyBox() for the boxes an agent finishes; the bundle's URL carries its hash
 * @version 1.3 - macFromSecret(), publicKeyFromSecret(), verifyEd25519(), ed25519Supported(): the relay pin (client_custody_mail.md § R10)
 * @version 1.2 - the one vault's derivations: codeKeks(), passphraseKeks(), scopeKek(), hkdf()
 * @version 1.1 - encrypt()/decrypt() take an optional AD; selfCheck() opens the
 *   shared edge-format vector; a passphrase KDF that never settles rejects
 *   after a minute instead of hanging the unlock
 * @version 1.0
 */
window.VaultCrypto = (function () {
	'use strict';

	var subtle = window.crypto && window.crypto.subtle;
	var ARGON2_URL = '/assets/vendor/argon2/argon2-bundled.min.js';

	// The vendored X-Wing (assets/vendor/noble/README.md). The integrity hash is
	// the pin: a file that differs from it is not run. tests/vault/xwing_browser_gate.sh
	// fails when this, the README and the file disagree.
	// The ?v= is the file's hash too (its first 12 hex), so a rebuilt file is a
	// new URL and no browser holds a cached old copy the new hash refuses.
	var XWING_URL = '/assets/vendor/noble/noble-post-quantum-xwing.min.js?v=c755cc88401a';
	var XWING_SRI = 'sha256-x1XMiEAaLtqbGfzHH2xNZRE2VgxKEQiv9/2g61Qxu5g=';

	// Sizes and labels of the v2 seal (SealedBox.php: XWing::*, SealedBox::V2_INFO).
	var XWING_PUBLIC_BYTES = 1216;
	var XWING_CIPHERTEXT_BYTES = 1120;
	var XWING_SEED_BYTES = 32;
	var V1_PUBLIC_BYTES = 32;
	var V2_INFO = 'joinery-seal:v2:';
	var SCOPE_DEK = 'dek';
	var V1_KEY_REFUSAL = 'That key is an old X25519 key, which nothing seals to any more. Its holder rotates it to a new key first.';

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
		var material = concat(b64decode(saltB64), utf8(normalizeCode(code)));
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

	async function argon2Raw(passphrase, saltB64, kdfParams) {
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
		return new Uint8Array(result.hash);
	}

	async function kekFromPassphrase(passphrase, saltB64, kdfParams) {
		return importAesKek(await argon2Raw(passphrase, saltB64, kdfParams));
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

	// The X-Wing KEM, loaded once on first use, with its integrity hash, so a
	// changed file fails to load instead of running. A test engine (node)
	// puts the same file on window.JoineryPQ before loading this one.
	var _xwingLoading = null;
	function loadXWing() {
		if (window.JoineryPQ && window.JoineryPQ.xwing) return Promise.resolve(window.JoineryPQ.xwing);
		if (_xwingLoading) return _xwingLoading;
		if (typeof document === 'undefined' || !document.head) {
			return Promise.reject(new Error('The X-Wing module is not loaded.'));
		}
		_xwingLoading = new Promise(function (resolve, reject) {
			var failed = function (message) { _xwingLoading = null; reject(new Error(message)); };
			var s = document.createElement('script');
			s.src = XWING_URL;
			s.integrity = XWING_SRI;
			s.onload = function () {
				if (window.JoineryPQ && window.JoineryPQ.xwing) resolve(window.JoineryPQ.xwing);
				else failed('The X-Wing module failed to initialise.');
			};
			s.onerror = function () {
				failed('Could not load the X-Wing module this page needs to encrypt. Reload the page; if it keeps happening, report it.');
			};
			document.head.appendChild(s);
		});
		return _xwingLoading;
	}

	function importAesKek(rawKeyBytes) {
		return subtle.importKey('raw', rawKeyBytes, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']);
	}

	// ---- the one vault's derivations (specs/one_vault_experience.md) ----------
	// Mirrored in PHP by includes/VaultUnlockerKdf.php (the same names), which
	// the tests use to build exactly what this sends. Every one is salted by the
	// ROOT vault's salt. What leaves the browser is only ever an account half,
	// as raw bytes the caller base64url-encodes; the code and the phrase never do.

	var RECOVERY_ACCOUNT_INFO = 'joinery-vault:recovery:account:v1';
	var PASSPHRASE_ACCOUNT_INFO = 'joinery-vault:passphrase:account:v1';
	var PASSPHRASE_ROOT_INFO = 'joinery-vault:passphrase:root:v1';
	var SCOPE_INFO_PREFIX = 'joinery-vault:scope:v1:';

	function b64urlEncode(bytes) {
		return b64encode(bytes).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}

	// Crockford read-side leniency, as VaultUnlockerKdf::normalizeCode applies it.
	function normalizeCode(code) {
		return String(code).toUpperCase()
			.replace(/O/g, '0').replace(/[IL]/g, '1')
			.replace(/[^A-Z0-9]/g, '');
	}

	// HKDF-SHA256 over raw bytes: 32 bytes out.
	async function hkdf(ikmBytes, saltBytes, info) {
		var base = await subtle.importKey('raw', ikmBytes, 'HKDF', false, ['deriveBits']);
		var bits = await subtle.deriveBits({ name: 'HKDF', hash: 'SHA-256', salt: saltBytes || new Uint8Array(0),
			info: utf8(info) }, base, 256);
		return new Uint8Array(bits);
	}

	// HMAC-SHA256 of messageBytes under HKDF-SHA256(secretBytes, empty salt,
	// info): a MAC only the holder of the secret can make or check. A vault
	// session's pin MAC (info 'sealed-vault:pin', specs/client_custody_mail.md
	// § R10) is this over its own secret.
	async function macFromSecret(secretBytes, info, messageBytes) {
		var raw = await hkdf(secretBytes, null, info);
		try {
			var key = await subtle.importKey('raw', raw, { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
			return new Uint8Array(await subtle.sign('HMAC', key, messageBytes));
		} finally { raw.fill(0); }
	}

	// The public key (standard base64, as generateVaultKeypair gives it) of a
	// vault secret: worked out from the secret itself, so a caller can know its
	// own public key without taking anyone's word. A 32-byte secret is an X-Wing
	// seed; a 48-byte one is a v1 X25519 PKCS#8 (a vault not yet rotated).
	async function publicKeyFromSecret(secretBytes) {
		if (secretBytes.length === XWING_SEED_BYTES) {
			return b64encode(await xwingPublicKey(secretBytes));
		}
		var key = await subtle.importKey('pkcs8', secretBytes, { name: 'X25519' }, true, ['deriveBits']);
		var jwk = await subtle.exportKey('jwk', key);
		return b64encode(b64urlDecode(jwk.x));
	}

	// The 1216-byte X-Wing public key of a 32-byte seed.
	async function xwingPublicKey(seedBytes) {
		var xw = await loadXWing();
		var kp = xw.keygen(seedBytes);
		if (kp.secretKey && kp.secretKey !== seedBytes) kp.secretKey.fill(0);
		return new Uint8Array(kp.publicKey);
	}

	// Ed25519 verify: a detached signature (standard base64) over messageBytes
	// by a raw public key (standard base64). False on a bad signature, a
	// malformed key, or a browser without Ed25519 (see ed25519Supported).
	async function verifyEd25519(publicKeyB64, messageBytes, signatureB64) {
		try {
			var key = await subtle.importKey('raw', b64decode(publicKeyB64), { name: 'Ed25519' }, false, ['verify']);
			return await subtle.verify({ name: 'Ed25519' }, key, b64decode(signatureB64), messageBytes);
		} catch (e) { return false; }
	}

	// Whether this browser's WebCrypto has Ed25519 at all, so a caller can
	// tell "cannot check here" from "the check failed".
	async function ed25519Supported() {
		try {
			await subtle.importKey('raw', new Uint8Array(32), { name: 'Ed25519' }, false, ['verify']);
			return true;
		} catch (e) {
			return !(e && (e.name === 'NotSupportedError' || /not supported|unrecognized/i.test(String(e.message))));
		}
	}

	// A recovery code's two halves: { account: raw bytes (posted, base64url),
	// root: AES key (opens the code's twin on the root vault, here) }.
	async function codeKeks(code, rootSaltB64) {
		var normalized = utf8(normalizeCode(code));
		return {
			account: await hkdf(normalized, b64decode(rootSaltB64), RECOVERY_ACCOUNT_INFO),
			root: await kekFromRecoveryCode(code, rootSaltB64),
		};
	}

	// The passphrase's two halves from ONE Argon2id run: { account: raw bytes
	// (posted), root: AES key (kept) }.
	async function passphraseKeks(passphrase, rootSaltB64, kdfParams) {
		var h = await argon2Raw(passphrase, rootSaltB64, kdfParams);
		try {
			return {
				account: await hkdf(h, null, PASSPHRASE_ACCOUNT_INFO),
				root: await importAesKek(await hkdf(h, null, PASSPHRASE_ROOT_INFO)),
			};
		} finally {
			h.fill(0);
		}
	}

	// A content vault's key from the root vault's secret: each scope its own.
	async function scopeKek(rootSecretBytes, scope) {
		var raw = await hkdf(rootSecretBytes, null, SCOPE_INFO_PREFIX + scope);
		try { return await importAesKek(raw); } finally { raw.fill(0); }
	}

	function importRawKek(rawKeyBytes) { return importAesKek(rawKeyBytes); }

	// ---- the vault X-Wing keypair (generated in-browser) ----------------------

	// Returns { publicKeyB64, secretKeyBytes } - the 1216-byte X-Wing public key
	// (base64, stored cleartext in uev_public_key) and the 32-byte seed that is
	// the secret (wrapped under each unlocker's KEK, never stored unwrapped).
	async function generateVaultKeypair() {
		var seed = randomBytes(XWING_SEED_BYTES);
		var pub = await xwingPublicKey(seed);
		return { publicKeyB64: b64encode(pub), secretKeyBytes: seed };
	}

	// A v1 secret: the 48-byte X25519 PKCS#8 (or the raw 32-byte scalar a
	// pasted v1 recovery key is), as WebCrypto imports it.
	var X25519_PKCS8_PREFIX = [0x30, 0x2e, 0x02, 0x01, 0x00, 0x30, 0x05, 0x06, 0x03, 0x2b, 0x65, 0x6e, 0x04, 0x22, 0x04, 0x20];
	async function importV1PrivateKey(secretKeyBytes, extractable) {
		var pkcs8 = secretKeyBytes.length === 32 ? concat(new Uint8Array(X25519_PKCS8_PREFIX), secretKeyBytes) : secretKeyBytes;
		try {
			return await subtle.importKey('pkcs8', pkcs8, { name: 'X25519' }, !!extractable, ['deriveBits']);
		} catch (e) {
			throw new Error('This browser cannot do X25519, which opening something sealed to an old key needs. Use a current Chrome, Firefox or Safari.');
		} finally {
			if (pkcs8 !== secretKeyBytes) pkcs8.fill(0);
		}
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

	// ---- seal / open to a public key (v2: X-Wing) -------------------------------
	// The primitive for a row DEK, a Drive file key shared to another user, the
	// device handoff, and (with their own scope) the operator challenges.
	// base64( ct[1120] || IV[12] || AES-GCM ciphertext ), no AAD: a key seal binds
	// to its row through the row's own AEAD. The AES key is HKDF-SHA256 over the
	// X-Wing shared secret with info 'joinery-seal:v2:' || scope, so one scope's
	// box never opens as another's. X-Wing's combiner already binds the
	// ciphertext and the recipient key, so the info carries neither.

	// The 32-byte AES key of a v2 seal under `scope`, as raw bytes. Posted as
	// is only by halfOpenKeyBox, for scope 'dek'.
	function v2Key(sharedSecret, scope) {
		return hkdf(sharedSecret, null, V2_INFO + (scope || SCOPE_DEK));
	}

	async function gcmWithRawKey(rawKey, usage) {
		return subtle.importKey('raw', rawKey, { name: 'AES-GCM' }, false, [usage]);
	}

	// The v2 seal with its randomness supplied: the X-Wing encapsulation seed
	// (64 bytes) and the IV. Only test vectors pass them (sealV2With below);
	// sealToPublicKey draws both fresh.
	async function sealV2(dataBytes, recipientBytes, scope, eseed, iv) {
		var xw = await loadXWing();
		var enc = eseed ? xw.encapsulate(recipientBytes, eseed) : xw.encapsulate(recipientBytes);
		var raw = await v2Key(enc.sharedSecret, scope);
		var key = await gcmWithRawKey(raw, 'encrypt');
		raw.fill(0);
		enc.sharedSecret.fill(0);
		iv = iv || randomBytes(12);
		var ct = await subtle.encrypt({ name: 'AES-GCM', iv: iv }, key, dataBytes);
		return concat(new Uint8Array(enc.cipherText), iv, new Uint8Array(ct));
	}

	function splitV2(raw) {
		if (raw.length < XWING_CIPHERTEXT_BYTES + 12 + 16) throw new Error('That is not a sealed blob.');
		return {
			ct: raw.slice(0, XWING_CIPHERTEXT_BYTES),
			iv: raw.slice(XWING_CIPHERTEXT_BYTES, XWING_CIPHERTEXT_BYTES + 12),
			body: raw.slice(XWING_CIPHERTEXT_BYTES + 12),
		};
	}

	async function openV2(raw, seedBytes, scope) {
		if (seedBytes.length !== XWING_SEED_BYTES) throw new Error('This is sealed to a new key, and the secret given is not one.');
		var xw = await loadXWing();
		var p = splitV2(raw);
		var ss = new Uint8Array(xw.decapsulate(p.ct, seedBytes));
		var aes = await v2Key(ss, scope);
		ss.fill(0);
		var key = await gcmWithRawKey(aes, 'decrypt');
		aes.fill(0);
		return new Uint8Array(await subtle.decrypt({ name: 'AES-GCM', iv: p.iv }, key, p.body));
	}

	// Seal bytes to a 1216-byte X-Wing public key (standard base64), under
	// scope 'dek'. A 32-byte key is refused: nothing writes a v1 seal.
	async function sealToPublicKey(dataBytes, recipientPublicKeyB64) {
		var recipientBytes = b64decode(recipientPublicKeyB64);
		if (recipientBytes.length === V1_PUBLIC_BYTES) throw new Error(V1_KEY_REFUSAL);
		if (recipientBytes.length !== XWING_PUBLIC_BYTES) throw new Error('That is not a public key to seal to.');
		return b64encode(await sealV2(dataBytes, recipientBytes, SCOPE_DEK));
	}

	// Open a key seal (scope 'dek': a row DEK, a file key, a handed-over
	// secret) sealed to `recipientPublicKeyB64` with its secret. The key's
	// length names the version: 1216 bytes, a v2 seal opened with the 32-byte
	// seed; 32 bytes, a v1 seal opened with the PKCS#8 (or raw scalar) secret,
	// kept so an old key's rows can be rotated. A challenge never comes here
	// (openChallenge), so no caller can name a scope.
	async function openFromSecretKey(sealedBlob, secretKeyBytes, recipientPublicKeyB64) {
		var raw = b64decode(sealedBlob);
		var recipientBytes = b64decode(recipientPublicKeyB64);
		if (recipientBytes.length === XWING_PUBLIC_BYTES) return openV2(raw, secretKeyBytes, SCOPE_DEK);
		if (recipientBytes.length !== V1_PUBLIC_BYTES) throw new Error('That is not a public key.');
		return openV1(raw, secretKeyBytes, recipientBytes, 'sealed-vault:dek');
	}

	// The scopes an operator challenge is sealed under (BackupRecoveryKey::BROWSER_INFO,
	// ApprovalChallenge::SCOPES). openChallenge opens only these: never 'dek',
	// so a page handed some other box sealed to the same key (a backup's data
	// key) cannot be made to open it into a form field.
	var CHALLENGE_SCOPES = [
		'joinery-backup-recovery-possession:',
		'joinery-restore-approval:',
		'joinery-decommission-approval:',
		'joinery-copy-export-approval:',
	];

	// Open a v2 challenge sealed to a 1216-byte key with the 32-byte seed under
	// one of CHALLENGE_SCOPES. Resolves the plaintext bytes.
	async function openChallenge(sealedBlob, seedBytes, recipientPublicKeyB64, scope) {
		if (CHALLENGE_SCOPES.indexOf(scope) === -1) throw new Error('That is not a challenge this page opens.');
		if (b64decode(recipientPublicKeyB64).length !== XWING_PUBLIC_BYTES) throw new Error(V1_KEY_REFUSAL);
		return openV2(b64decode(sealedBlob), seedBytes, scope);
	}

	// The v1 browser seal: X25519 ECIES, HKDF info = label || ephPub || recipientPub.
	async function openV1(raw, secretKeyBytes, recipientBytes, label) {
		var ephPubBytes = raw.slice(0, 32), iv = raw.slice(32, 44), ct = raw.slice(44);
		var priv = await importV1PrivateKey(secretKeyBytes, false);
		var ephPub = await subtle.importKey('raw', ephPubBytes, { name: 'X25519' }, false, []);
		var shared = new Uint8Array(await subtle.deriveBits({ name: 'X25519', public: ephPub }, priv, 256));
		var hkdfKey = await subtle.importKey('raw', shared, { name: 'HKDF' }, false, ['deriveKey']);
		shared.fill(0);
		var key = await subtle.deriveKey(
			{ name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(0), info: concat(utf8(label), ephPubBytes, recipientBytes) },
			hkdfKey, { name: 'AES-GCM', length: 256 }, false, ['decrypt']
		);
		return new Uint8Array(await subtle.decrypt({ name: 'AES-GCM', iv: iv }, key, ct));
	}

	// ---- a box the agent finishes opening (a backup's data key, break-glass) ---
	// The page works out, from a pasted 32-byte recovery key, the one value that
	// opens this box and nothing else, checks the key is the one the box names
	// (by fingerprint), and hands back { shared, pub } (both base64) to post.
	//   v2 box { version: 2, ciphertext }: decapsulate in full and post the
	//      dek-scope AES key, never the X-Wing shared secret, which would also
	//      key any other scope's box with the same ciphertext (SealedBox::openV2WithKey).
	//   v1 box { version: 1, ephemeralPublic }: the X25519 of the key with the
	//      box's ephemeral key (a backup sealed to an old recovery key).
	async function halfOpenKeyBox(box, secretB64, fingerprint) {
		var secret;
		try { secret = b64decode(String(secretB64).replace(/\s+/g, '')); } catch (e) {
			throw new Error('That is not a recovery key: it is one line of base64.');
		}
		if (secret.length !== 32) {
			throw new Error('That is not a recovery key: expected 32 bytes, got ' + secret.length + '. Paste the private key exactly as you saved it.');
		}
		try {
			var version = Number(box && box.version);
			if (version !== 1 && version !== 2) throw new Error('This key box names no seal version this page knows.');
			var v2 = version === 2;
			var pub;
			var key = null;
			if (v2) {
				pub = await xwingPublicKey(secret);
			} else {
				// Extractable only so its public half can be read back.
				key = await importV1PrivateKey(secret, true);
				var jwk = await subtle.exportKey('jwk', key);
				pub = b64urlDecode(jwk.x);
				jwk.d = '';
			}
			var fp = toHex(new Uint8Array(await subtle.digest('SHA-256', pub)));
			if (fp !== fingerprint) {
				throw new Error('That recovery key has fingerprint ' + fp.slice(0, 16) + ', and this is sealed to ' +
					String(fingerprint).slice(0, 16) + '. Check you copied the right entry.');
			}
			var shared;
			if (v2) {
				var xw = await loadXWing();
				var ss = new Uint8Array(xw.decapsulate(b64decode(box.ciphertext), secret));
				shared = await v2Key(ss, SCOPE_DEK);
				ss.fill(0);
			} else {
				var eph = await subtle.importKey('raw', b64decode(box.ephemeralPublic), { name: 'X25519' }, false, []);
				shared = new Uint8Array(await subtle.deriveBits({ name: 'X25519', public: eph }, key, 256));
			}
			var out = { shared: b64encode(shared), pub: b64encode(pub) };
			shared.fill(0);
			return out;
		} finally {
			secret.fill(0);
		}
	}

	function toHex(bytes) {
		return Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
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

	// ---- self-check: the shared vectors --------------------------------------

	// The v2 seal vector of tests/vault/fixtures/xwing_vector.json (scope 'dek'),
	// which includes/SealedBox.php made and the PHP and node suites reproduce
	// byte for byte; the public key is derived from the seed here and checked
	// against its SHA-256. Test material only.
	var V2_VECTOR = {
		seedHex: '0012d8cedfeae75573e91b6f72a1f9a0fab3255c236bf68af9c4056aeaf44024',
		publicSha256: '5565acf02d22d42dc89e52b16bbc27e74a61b60e5046e82df6cc69205e61405c',
		plaintextHex: 'd9548abac8d5a8a47fb16c95a94f1db96c57d48e1f3a483d6575696a968d7768',
		sealedB64: 'z1aicfmL55Dfzx8CxbR27MzZf8JJoTOJKvYTlgR/B0vFQaYjnfcSbhdI/ooEGqW5Swt2GOHSS9gEuozOjtKQeUZBCSgjb8Ao4E275/NnGhs7qguJvJ0Tzcq5NbEB7SoSq9nlSF2/x42nV03Oh8hsjMCHLZjOS2ADVnLJzzHQB9xnbzawUoNJ4/3EhNeAHltkMR1ovEgMWLQa+HNNQhwmhs3Ngvx431fGHavV/bUqg5RByS9u5DMelei6NwYYKQIDWgOpOxGZLQksIZZv7TyWsHRa6ZTNRQdU8Ki2RLHpgHSy/Z2RID/g1aNaxjWP98N/+3SQG6LrZRHz2QZQr4P5i5LRA0m6eCOG0fnCyAJ9rNaG4pTtBKRI7rvr/cuYkpfgZZUZmLlEW0J8wxr3lpXqeJURvn8h1ZKs01CHXggw8BORCekhiwQW4R1sWsqAnAE823F8rvMEB2QVDeYsWIyq2PF01reIv9p+qMKSZYHI8onk1IP+QksgOGcdKCkniiGWoCvQ0A+Hy8qtV9mERDDqgcHcmeSncROF89plr+JWTNqQfihJes1h8WVBG3eOIRRvXUTiGQ12zP8ZfTLgDHcwx01E7d1Ys/mhRlOTglDI0OJPUiWbRLX+A9BAvCeSFl6REvKLLFgRCwzf2rVreY8krX6ivDvKm8YzB3vob2lzVpH0J2dghd4ABYBeURN9+iL2lak1d56Erd1vdTbEqHfza6B8oH5OwbOTnW4ib5Hey7KLaj7JwrfeG1rabfGmgT2zjY3AwzVxoMuAUmWfLB0nUSRbREQkjABOgP1LNHebYfa76TVeoosqu5lfsi6MZDoOrGS2g1U+aVps//ngNfArqfL6WGqT8idCVT1D3YWb9odVD8LMwpeQbsOnAEavEqXA4NmmekD5IIUHMGmH+rw0WgS1cBBBgFx/B3TI6WWNS1XYUdVgLlO03sumT0bfL5wZjMz7AeHbFANHH44r8fuYJ/MjHU+WBK+EoaWKD+hMVbx/je0Sb6JT3JCGKQ6JPAFfiQZh+VuM51hZf6qNTjLj5pYQecg0GIXzCBemwPccw8T4Eh0garUBn3mNSy9a1je6YYEkh5QdKnm6GVxJL/zYjXFoKOCdmnPcURs4QvFh3Z/yheI+wtis1x9u6vvR6yZ7lh1Dt/audQzgpURFT2JAZ5U6K/kGfy2GcNuo4BZ07vEvtLIHid0Lvw8uXAlaGLwrP+6v8YI/klTEg8Xi6UDW1vvF7ChQnPOlLdiTALQW1YrCaNRW3oZ4bDJ30JXVWIb397A3D+bG/EDwmHzEJMEebsFK6gqFsqqwaabtHF/5PfFl/g3EOeqL6q1VPrG7EgdyR9jpmW0eCHNpueFfi9ninr4nF/cPXQwNo1U4dlmJ58tonJEFC3OOpWRely31+RJ5+fdGoJ0Idflo7jQt895Ta6ushvqKMyWInPNJBv5MSpkRaNoqe4x7Bg1Dq95r+zb7MFtnerSnOoqlG3lEY2iELxwKvn2e52q6A3cVBS0AqfnEL0mJXRDf7IENRhwhvHrWVEq4/SWEfUMBb7213p8gy/Msxe2PtdhjiO/FSg==',
	};

	// The v1 edge vector of tests/vault/fixtures/edge_vector.json: a v1 seal is
	// still opened (a vault being rotated off its old key), and the field
	// format is unchanged. Test material only.
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

	async function refuses(promise) {
		try { await promise; return false; } catch (e) { return true; }
	}

	// Resolves true when this engine opens both vectors (the v2 seal, refusing
	// it under another scope; the v1 DEK and its field, the field refusing
	// another row's AD), a fresh v2 seal round-trips, and a 32-byte key is
	// refused for sealing; false otherwise.
	async function selfCheck() {
		try {
			var w = V2_VECTOR;
			var seed = fromHex(w.seedHex);
			var pub = await xwingPublicKey(seed);
			if (toHex(new Uint8Array(await subtle.digest('SHA-256', pub))) !== w.publicSha256) return false;
			if (!sameBytes(await openFromSecretKey(w.sealedB64, seed, b64encode(pub)), fromHex(w.plaintextHex))) return false;
			if (!await refuses(openChallenge(w.sealedB64, seed, b64encode(pub), 'joinery-restore-approval:'))) return false;
			if (!await refuses(openChallenge(w.sealedB64, seed, b64encode(pub), 'dek'))) return false;

			var v = EDGE_VECTOR;
			var pkcs8 = concat(new Uint8Array(X25519_PKCS8_PREFIX), fromHex(v.recipientSecretHex));
			var dek = await openFromSecretKey(v.sealedDekB64, pkcs8, v.recipientPublicB64);
			if (!sameBytes(dek, fromHex(v.dekHex))) return false;
			var dekKey = await importDek(dek);
			if (await decrypt(v.fieldBlobB64, dekKey, v.fieldAd) !== v.fieldPlaintext) return false;
			if (!await refuses(decrypt(v.fieldBlobB64, dekKey, v.fieldAd + 'x'))) return false;
			if (!await refuses(sealToPublicKey(randomBytes(32), v.recipientPublicB64))) return false;

			var pair = await generateVaultKeypair();
			if (pair.secretKeyBytes.length !== XWING_SEED_BYTES || b64decode(pair.publicKeyB64).length !== XWING_PUBLIC_BYTES) return false;
			if (await publicKeyFromSecret(pair.secretKeyBytes) !== pair.publicKeyB64) return false;
			var fresh = randomBytes(32);
			var reopened = await openFromSecretKey(await sealToPublicKey(fresh, pair.publicKeyB64), pair.secretKeyBytes, pair.publicKeyB64);
			return sameBytes(reopened, fresh);
		} catch (e) {
			return false;
		}
	}

	// ---- feature probe --------------------------------------------------------

	// Best-effort: can this browser do client-custody crypto? WebCrypto's
	// AES-GCM, HKDF and SHA-256, and the vendored X-Wing module loading and
	// making a key. A false result means it cannot (surface a clear message).
	async function isSupported() {
		if (!subtle || !window.crypto.getRandomValues) return false;
		try {
			await subtle.importKey('raw', new Uint8Array(32), 'HKDF', false, ['deriveBits']);
			var pub = await xwingPublicKey(randomBytes(XWING_SEED_BYTES));
			return pub.length === XWING_PUBLIC_BYTES;
		} catch (e) {
			return false;
		}
	}

	// Only the content crypto (AES-GCM through WebCrypto): what opening a public
	// share link needs, which involves no key seal and so no X-Wing module.
	async function contentSupported() {
		if (!subtle || !window.crypto.getRandomValues) return false;
		try {
			await subtle.importKey('raw', new Uint8Array(32), { name: 'AES-GCM' }, false, ['decrypt']);
			return true;
		} catch (e) {
			return false;
		}
	}

	// The v2 seal with fixed randomness, for reproducing a test vector
	// (tests/vault/xwing_vector.mjs). Nothing else calls it.
	async function sealV2With(dataBytes, recipientPublicKeyB64, scope, eseedBytes, ivBytes) {
		return b64encode(await sealV2(dataBytes, b64decode(recipientPublicKeyB64), scope, eseedBytes, ivBytes));
	}

	return {
		DEFAULT_KDF_PARAMS: DEFAULT_KDF_PARAMS,
		randomBytes: randomBytes,
		b64encode: b64encode,
		b64decode: b64decode,
		kekFromPrf: kekFromPrf,
		kekFromRecoveryCode: kekFromRecoveryCode,
		kekFromPassphrase: kekFromPassphrase,
		b64urlEncode: b64urlEncode,
		b64urlDecode: b64urlDecode,
		normalizeCode: normalizeCode,
		hkdf: hkdf,
		macFromSecret: macFromSecret,
		publicKeyFromSecret: publicKeyFromSecret,
		verifyEd25519: verifyEd25519,
		ed25519Supported: ed25519Supported,
		codeKeks: codeKeks,
		passphraseKeks: passphraseKeks,
		scopeKek: scopeKek,
		importRawKek: importRawKek,
		generateVaultKeypair: generateVaultKeypair,
		xwingPublicKey: xwingPublicKey,
		loadXWing: loadXWing,
		halfOpenKeyBox: halfOpenKeyBox,
		sealV2With: sealV2With,
		XWING_PUBLIC_BYTES: XWING_PUBLIC_BYTES,
		wrapSecretKey: wrapSecretKey,
		unwrapSecretKey: unwrapSecretKey,
		sealToPublicKey: sealToPublicKey,
		openFromSecretKey: openFromSecretKey,
		openChallenge: openChallenge,
		contentSupported: contentSupported,
		newDek: newDek,
		importDek: importDek,
		encrypt: encrypt,
		decrypt: decrypt,
		isSupported: isSupported,
		selfCheck: selfCheck,
	};
})();
