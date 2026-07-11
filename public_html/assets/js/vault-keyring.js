/**
 * VaultKeyring - the Sealed Vault's shared client-custody enrollment, unlock,
 * and recovery ceremony, orchestrating VaultCrypto against the core
 * vault_client_* server actions.
 *
 * Scope-parameterized: the password manager drives it with scope 'passwords',
 * Drive will drive it with scope 'drive'. Nothing here knows about passwords or
 * files - it manages the vault IDENTITY (the keypair and its unlockers). What a
 * consumer seals with the unlocked secret key (a store DEK, a per-file key) is
 * the consumer's concern; this module just hands back an unlocked session.
 *
 * Zero-knowledge is preserved end to end: KEKs are derived here and used here;
 * the secret key is unwrapped here; only opaque wrapped blobs cross to the
 * server. A passkey's PRF output is read locally and never posted.
 *
 * Depends on VaultCrypto (assets/js/vault-crypto.js), JoineryPasskeys
 * (assets/js/passkeys.js), and joineryApi (assets/js/joinery-api.js).
 *
 * @version 1.0
 */
window.VaultKeyring = (function () {
	'use strict';

	var CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
	var RECOVERY_CODE_CHARS = 26;   // ~130 bits at 5 bits/char
	var DEFAULT_RECOVERY_COUNT = 10;

	// The row-binding AD for a client-custody wrapping. Stable and reconstructable
	// at unlock from scope + unlocker (never the row id), so wrapping happens
	// before the row exists - no two-phase insert.
	function adFor(scope, type, credentialId) {
		if (type === 'passkey') return 'vault:' + scope + ':passkey:' + credentialId;
		return 'vault:' + scope + ':' + type;
	}

	function generateRecoveryCode() {
		var bytes = VaultCrypto.randomBytes(RECOVERY_CODE_CHARS);
		var out = '';
		for (var i = 0; i < RECOVERY_CODE_CHARS; i++) {
			out += CROCKFORD[bytes[i] & 31];
			if (i % 4 === 3 && i !== RECOVERY_CODE_CHARS - 1) out += '-';
		}
		return out;
	}

	function api(action, body) {
		return joineryApi.post(action, body || {});
	}

	// An unlocked session: the vault secret key held in a closure, plus the ops a
	// consumer needs. lock() zeroes the key bytes. No plaintext lives here; the
	// consumer holds its own DEK/plaintext and discards it on lock too.
	function makeSession(scope, secretKeyBytes, publicKeyB64) {
		var secret = secretKeyBytes;   // Uint8Array; nulled on lock()
		return {
			scope: scope,
			publicKey: publicKeyB64,
			locked: function () { return secret === null; },
			// open a blob sealed to this vault's public key (e.g. the store DEK)
			openSealed: function (blob) {
				if (secret === null) return Promise.reject(new Error('Vault is locked.'));
				return VaultCrypto.openFromSecretKey(blob, secret, publicKeyB64);
			},
			// seal bytes to a public key (this vault's own, or a share recipient's)
			sealTo: function (bytes, publicKeyB64Target) {
				return VaultCrypto.sealToPublicKey(bytes, publicKeyB64Target || publicKeyB64);
			},
			// re-wrap the secret key under a fresh KEK (adding an unlocker)
			wrapUnder: function (kek, type, credentialId) {
				if (secret === null) return Promise.reject(new Error('Vault is locked.'));
				return VaultCrypto.wrapSecretKey(secret, kek, adFor(scope, type, credentialId));
			},
			lock: function () {
				if (secret) { secret.fill(0); secret = null; }
			},
		};
	}

	// ---- passkey helpers ------------------------------------------------------

	// Run the PRF assertion for a scope; returns { kek, credentialId } with the
	// KEK derived LOCALLY from the PRF output (never posted).
	async function derivePasskeyKek(scope) {
		var opt = await api('vault_client_prf_options', { scope: scope });
		var derived = await JoineryPasskeys.derive(opt.options);
		if (!derived.prfOutput) {
			throw new Error('This passkey did not return a derived secret. It may not support PRF - use your passphrase or recovery key.');
		}
		var kek = await VaultCrypto.kekFromPrf(derived.prfOutput);
		return { kek: kek, credentialId: derived.response.rawId };
	}

	// ---- setup ----------------------------------------------------------------

	/**
	 * Create a brand-new client-custody vault for a scope.
	 * opts: {
	 *   passkey: { kek, credentialId } | null,   // from derivePasskeyKek()
	 *   passphrase: string | null,               // optional fallback (or primary if no passkey)
	 *   recoveryCount: int,                       // default 10
	 *   acknowledged: bool                        // permanent-loss acknowledgment
	 * }
	 * Returns { session, recoveryCodes:[...], publicKey }.
	 */
	async function setup(scope, opts) {
		opts = opts || {};
		if (!opts.passkey && !opts.passphrase) {
			throw new Error('Set up a passkey or a passphrase to unlock your vault.');
		}

		var pair = await VaultCrypto.generateVaultKeypair();
		var saltB64 = VaultCrypto.b64encode(VaultCrypto.randomBytes(16));
		var kdfParams = VaultCrypto.DEFAULT_KDF_PARAMS;
		var wrappings = [];

		if (opts.passkey) {
			var pkBlob = await VaultCrypto.wrapSecretKey(pair.secretKeyBytes, opts.passkey.kek, adFor(scope, 'passkey', opts.passkey.credentialId));
			wrappings.push({ unlocker_type: 'passkey', credential_id: opts.passkey.credentialId, wrapped_secret_key: pkBlob });
		}

		var count = opts.recoveryCount || DEFAULT_RECOVERY_COUNT;
		var recoveryCodes = [];
		for (var i = 0; i < count; i++) {
			var code = generateRecoveryCode();
			recoveryCodes.push(code);
			var rkek = await VaultCrypto.kekFromRecoveryCode(code, saltB64);
			var rblob = await VaultCrypto.wrapSecretKey(pair.secretKeyBytes, rkek, adFor(scope, 'recovery'));
			wrappings.push({ unlocker_type: 'recovery', wrapped_secret_key: rblob, salt: saltB64 });
		}

		if (opts.passphrase) {
			var ppkek = await VaultCrypto.kekFromPassphrase(opts.passphrase, saltB64, kdfParams);
			var ppblob = await VaultCrypto.wrapSecretKey(pair.secretKeyBytes, ppkek, adFor(scope, 'passphrase'));
			wrappings.push({ unlocker_type: 'passphrase', wrapped_secret_key: ppblob, salt: saltB64 });
		}

		await api('vault_client_setup', {
			scope: scope,
			public_key: pair.publicKeyB64,
			salt: saltB64,
			kdf_params: kdfParams,
			acknowledged: opts.acknowledged ? 1 : 0,
			wrappings: wrappings,
		});

		var session = makeSession(scope, pair.secretKeyBytes, pair.publicKeyB64);
		return { session: session, recoveryCodes: recoveryCodes, publicKey: pair.publicKeyB64 };
	}

	// ---- unlock ---------------------------------------------------------------

	async function status(scope) {
		return api('vault_client_status', { scope: scope });
	}

	// Unlock via passkey PRF. Needs a { kek, credentialId } from derivePasskeyKek.
	async function unlockWithPasskey(scope, kek, credentialId) {
		var st = await status(scope);
		if (!st.set_up) throw new Error('Your vault is not set up.');
		var wrap = st.wrappings.find(function (w) {
			return w.unlocker_type === 'passkey' && w.credential_id === credentialId;
		});
		if (!wrap) throw new Error('This passkey is not enrolled on your vault.');
		var secret = await VaultCrypto.unwrapSecretKey(wrap.wrapped_secret_key, kek, adFor(scope, 'passkey', credentialId));
		return makeSession(scope, secret, st.public_key);
	}

	// Unlock via the optional passphrase.
	async function unlockWithPassphrase(scope, passphrase) {
		var st = await status(scope);
		if (!st.set_up) throw new Error('Your vault is not set up.');
		var kek = await VaultCrypto.kekFromPassphrase(passphrase, st.salt, st.kdf_params);
		var ad = adFor(scope, 'passphrase');
		var pp = st.wrappings.filter(function (w) { return w.unlocker_type === 'passphrase'; });
		for (var i = 0; i < pp.length; i++) {
			try {
				var secret = await VaultCrypto.unwrapSecretKey(pp[i].wrapped_secret_key, kek, ad);
				return makeSession(scope, secret, st.public_key);
			} catch (e) { /* wrong passphrase for this row - try next */ }
		}
		throw new Error('Incorrect passphrase.');
	}

	// Unlock via a one-time recovery key. On success, marks it used server-side.
	async function unlockWithRecovery(scope, code) {
		var st = await status(scope);
		if (!st.set_up) throw new Error('Your vault is not set up.');
		var ad = adFor(scope, 'recovery');
		var candidates = st.wrappings.filter(function (w) { return w.unlocker_type === 'recovery' && !w.is_used; });
		// One KEK per distinct salt (they share uev_salt), then try each blob.
		var kekBySalt = {};
		for (var i = 0; i < candidates.length; i++) {
			var salt = candidates[i].salt || st.salt;
			if (!kekBySalt[salt]) kekBySalt[salt] = await VaultCrypto.kekFromRecoveryCode(code, salt);
			try {
				var secret = await VaultCrypto.unwrapSecretKey(candidates[i].wrapped_secret_key, kekBySalt[salt], ad);
				await api('vault_client_consume_recovery', { scope: scope, wrapping_id: candidates[i].id });
				return { session: makeSession(scope, secret, st.public_key), consumedWrappingId: candidates[i].id };
			} catch (e) { /* wrong code for this row - try next */ }
		}
		throw new Error('Invalid or already-used recovery key.');
	}

	return {
		DEFAULT_RECOVERY_COUNT: DEFAULT_RECOVERY_COUNT,
		adFor: adFor,
		isSupported: function () { return VaultCrypto.isSupported(); },
		status: status,
		derivePasskeyKek: derivePasskeyKek,
		setup: setup,
		unlockWithPasskey: unlockWithPasskey,
		unlockWithPassphrase: unlockWithPassphrase,
		unlockWithRecovery: unlockWithRecovery,
	};
})();
