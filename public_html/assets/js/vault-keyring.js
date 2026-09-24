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
 * ensureUnlocked(scope, opts) is the one ceremony every consumer uses: it
 * reads the scope's status and runs setup (with the recovery codes and their
 * proof), unlock, or nothing, as steps inside one JoineryModal, and resolves an
 * unlocked session. JoinerySealed calls it and holds the session; a page never
 * builds its own setup or unlock dialog.
 *
 * Depends on VaultCrypto (assets/js/vault-crypto.js), JoineryPasskeys
 * (assets/js/passkeys.js), joineryApi (assets/js/joinery-api.js), and for
 * ensureUnlocked() JoineryModal (assets/js/base.js).
 *
 * @version 1.2 - a close during the recovery-codes step re-opens it (setup is already
 *   committed, so the ceremony never rejects after it); has_second_factor note at setup
 * @version 1.1 - ensureUnlocked(): the setup / recovery / unlock ceremony in core
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
			// Seal the vault's own SECRET key to another public key — the device
			// handoff. This is the one operation that lets the vault identity
			// itself leave this browser, so it stays inside the session closure
			// rather than exposing the raw bytes to the caller: a consumer can
			// hand the key to a device it names, and cannot read it.
			//
			// The recipient (a sync client with its private half in the OS
			// keychain) opens the result and can then unwrap file keys exactly as
			// the browser does.
			sealSecretKeyTo: function (targetPublicKeyB64) {
				if (secret === null) return Promise.reject(new Error('Vault is locked.'));
				if (!targetPublicKeyB64) return Promise.reject(new Error('No recipient key to seal to.'));
				return VaultCrypto.sealToPublicKey(secret, targetPublicKeyB64);
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

	// ---- the ceremony ---------------------------------------------------------
	// Setup, the recovery codes and unlock are steps rendered into ONE
	// JoineryModal content element: the modal is a singleton and cannot nest.

	var MIN_PASSPHRASE = 10;

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text != null) e.textContent = text;
		return e;
	}
	function button(label, style, onClick) {
		var b = el('button', 'btn btn-' + (style || 'secondary'), label);
		b.type = 'button';
		b.addEventListener('click', onClick);
		return b;
	}
	function input(type, placeholder, autocomplete) {
		var i = el('input', 'form-control');
		i.type = type;
		i.placeholder = placeholder || '';
		if (autocomplete) i.autocomplete = autocomplete;
		return i;
	}
	function onEnter(inp, fn) {
		inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); fn(); } });
	}

	// A passkey that cannot derive a key says so in WebAuthn's words; say it in ours.
	function friendly(e, usingPasskey) {
		var msg = (e && e.message) || String(e || '');
		if (usingPasskey && /PRF|derived secret|PRF-capable/i.test(msg)) {
			return 'This device\'s passkey can\'t derive an encryption key. Use a passkey that supports it, or a passphrase or recovery code instead.';
		}
		if (usingPasskey && /NotAllowedError|not allowed|timed out|cancel/i.test(msg)) {
			return 'The passkey prompt was cancelled or timed out. Try again.';
		}
		return msg || 'Something went wrong. Try again.';
	}

	function normalizeCode(s) {
		return String(s).toUpperCase().replace(/O/g, '0').replace(/[IL]/g, '1').replace(/[^A-Z0-9]/g, '');
	}

	/**
	 * Resolve an unlocked session for `scope`, running whatever the scope needs:
	 * setup (then the recovery codes, which must be proven saved), or unlock
	 * (passkey, passphrase, or a recovery code — only what the keyring has).
	 * Rejects 'Unlock cancelled.' when the person closes it.
	 * opts.reason reads in the prompt: "to open this file".
	 */
	async function ensureUnlocked(scope, opts) {
		opts = opts || {};
		if (!window.JoineryModal) throw new Error('Unlocking is unavailable on this page.');
		if (!(await VaultCrypto.isSupported())) {
			await JoineryModal.alertAsync('This browser cannot open encrypted content: it needs modern WebCrypto. A current Chrome, Edge, Firefox or Safari can.');
			throw new Error('This browser cannot open encrypted content.');
		}
		var st = await status(scope);
		var label = st.label || 'vault';

		return new Promise(function (resolve, reject) {
			var root = el('div', 'jy-vault-ceremony');
			var settled = false;
			var handle = JoineryModal.open(root, { buttons: [{ label: 'Cancel', style: 'secondary' }] });
			var dialog = handle.dialog;
			var actions = dialog.querySelector('.dialog-actions');
			// While codes are on screen they are the only copy: no Esc, no Cancel.
			// A browser may still close the dialog (a second Escape can skip the
			// cancel event), and by then setup has been committed on the server:
			// the vault exists and this is its only session. So a close in that
			// step re-opens the dialog with the codes still in it, and nothing
			// after setup ever rejects.
			var holdOpen = false;
			var recoverySession = null;
			var onCancel = function (e) { if (holdOpen) e.preventDefault(); };
			dialog.addEventListener('cancel', onCancel);
			function cleanup() {
				dialog.removeEventListener('cancel', onCancel);
				if (actions) actions.hidden = false;
			}
			function onClose() {
				if (settled) { cleanup(); return; }
				if (holdOpen && recoverySession) {
					try {
						dialog.showModal();
						dialog.addEventListener('close', onClose, { once: true });
						return;
					} catch (e) {
						settled = true;
						cleanup();
						recoverySession.label = label;
						recoverySession.recoveryUnsaved = true;
						resolve(recoverySession);
						setTimeout(function () {
							JoineryModal.alert('Your ' + label + ' is set up, but its recovery codes were closed before you saved them. '
								+ 'Keep the passkey or passphrase you set it up with: without them it cannot be opened.');
						}, 0);
						return;
					}
				}
				cleanup();
				settled = true;
				reject(new Error('Unlock cancelled.'));
			}
			dialog.addEventListener('close', onClose, { once: true });

			function finish(session) {
				session.label = label;
				settled = true;
				dialog.close();
				resolve(session);
			}
			function frame(title) {
				root.innerHTML = '';
				root.appendChild(el('h3', 'jy-vault-ceremony-title', title));
				if (opts.reason) root.appendChild(el('p', 'jy-vault-ceremony-reason', 'You need it ' + opts.reason + '.'));
				var err = el('p', 'jy-vault-ceremony-error');
				err.hidden = true;
				err.setAttribute('role', 'alert');
				return function showError(msg) { err.textContent = msg || ''; err.hidden = !msg; if (!err.parentNode) root.appendChild(err); };
			}

			// ---- setup ----
			function renderSetup() {
				var showError = frame('Set up your ' + label);
				if (!st.passkeys_enabled) {
					// vault_client_setup refuses every setup while passkeys are off,
					// passphrase-only included, so offer nothing that would fail.
					root.appendChild(el('p', null, 'Setting up your ' + label + ' needs passkeys, and passkeys are turned off on this site. A site administrator can turn them on in the site settings.'));
					var cancelBtn = actions && actions.querySelector('button');
					if (cancelBtn) cancelBtn.textContent = 'Close';
					return;
				}
				root.appendChild(el('p', null, 'Your ' + label + ' is encrypted in this browser before it is stored, so only you can open it. '
					+ 'If you lose every way in (your passkey, your passphrase and your recovery codes) what it holds is gone for good. Nobody can recover it for you.'));
				if (st.has_second_factor === false) {
					// The re-enrollment gate holds a factorless vault holder at the
					// security page; say so now rather than after the fact.
					root.appendChild(el('p', 'jy-vault-ceremony-note', 'A vault needs a second way to sign in to your account: a passkey or an authenticator app. '
						+ 'You have neither yet, so once this is set up the site asks you to add one before you carry on.'));
				}
				var ackRow = el('label', 'jy-vault-ceremony-check');
				var ack = el('input');
				ack.type = 'checkbox';
				ackRow.appendChild(ack);
				ackRow.appendChild(document.createTextNode(' I understand'));
				root.appendChild(ackRow);

				var ppWrap = el('div', 'jy-vault-ceremony-fields');
				ppWrap.hidden = true;
				var pp = input('password', 'Passphrase (at least ' + MIN_PASSPHRASE + ' characters)', 'new-password');
				var pp2 = input('password', 'Type the passphrase again', 'new-password');
				ppWrap.appendChild(pp);
				ppWrap.appendChild(pp2);

				var passkeyBtn = button('Set up with a passkey', 'primary', function () { run(true); });
				var ppBtn = button('Set up with a passphrase only', 'secondary', function () { run(false); });
				ppBtn.hidden = true;
				var toggle = button('Add a passphrase', 'link', function () {
					ppWrap.hidden = false; ppBtn.hidden = false; toggle.hidden = true; pp.focus();
				});
				var row = el('div', 'jy-vault-ceremony-actions');
				row.appendChild(passkeyBtn);
				row.appendChild(toggle);
				root.appendChild(row);
				root.appendChild(ppWrap);
				var row2 = el('div', 'jy-vault-ceremony-actions');
				row2.appendChild(ppBtn);
				root.appendChild(row2);

				async function run(usePasskey) {
					showError('');
					if (!ack.checked) { showError('Tick "I understand" to continue.'); return; }
					var phrase = pp.value || '';
					if (phrase !== '' || pp2.value !== '' || !usePasskey) {
						if (phrase.length < MIN_PASSPHRASE) { showError('Use a passphrase of at least ' + MIN_PASSPHRASE + ' characters.'); return; }
						if (phrase !== pp2.value) { showError('The passphrases don\'t match.'); return; }
					}
					passkeyBtn.disabled = ppBtn.disabled = true;
					try {
						var setupOpts = { acknowledged: true, passphrase: phrase || null };
						if (usePasskey) setupOpts.passkey = await derivePasskeyKek(scope);
						var res = await setup(scope, setupOpts);
						pp.value = pp2.value = '';
						renderRecovery(res.session, res.recoveryCodes);
					} catch (e) {
						showError(friendly(e, usePasskey));
						passkeyBtn.disabled = ppBtn.disabled = false;
					}
				}
			}

			// ---- the recovery codes, shown once, proven saved ----
			function renderRecovery(session, codes) {
				holdOpen = true;
				recoverySession = session;
				if (actions) actions.hidden = true;
				var showError = frame('Save your recovery codes');
				root.appendChild(el('p', null, 'Each code opens your ' + label + ' once if you lose your passkey and passphrase. '
					+ 'This is the only time they are shown. Download them, or copy them somewhere safe and type the last one below.'));
				var list = el('ol', 'jy-vault-ceremony-codes');
				codes.forEach(function (c) { list.appendChild(el('li', null, c)); });
				root.appendChild(list);
				var proven = false;
				var done = button('Done', 'primary', function () {
					if (!proven) return;
					list.innerHTML = '';
					codes = null;
					holdOpen = false;
					finish(session);
				});
				done.disabled = true;
				var dl = button('Download codes', 'secondary', function () {
					var text = 'Recovery codes for your ' + label + ' (' + location.host + ')\n'
						+ 'Keep these somewhere safe and private. Each one opens the vault once if you lose your passkey and passphrase.\n\n'
						+ codes.join('\n') + '\n';
					var a = document.createElement('a');
					a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
					a.download = 'recovery-codes-' + scope + '.txt';
					document.body.appendChild(a); a.click(); a.remove();
					setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
					proven = true; done.disabled = false;
				});
				var proofLabel = el('label', 'jy-vault-ceremony-label', 'Type the last code to confirm you saved them');
				var proof = input('text', codes[codes.length - 1].replace(/[A-Z0-9]/g, '•'), 'off');
				proof.spellcheck = false;
				proof.addEventListener('input', function () {
					proven = proven || (normalizeCode(proof.value) !== '' && normalizeCode(proof.value) === normalizeCode(codes[codes.length - 1]));
					done.disabled = !proven;
				});
				onEnter(proof, function () { done.click(); });
				root.appendChild(proofLabel);
				root.appendChild(proof);
				var row = el('div', 'jy-vault-ceremony-actions');
				row.appendChild(dl);
				row.appendChild(done);
				root.appendChild(row);
				showError('');
			}

			// ---- unlock ----
			function renderUnlock() {
				var showError = frame('Unlock your ' + label);
				var canPasskey = st.passkeys_enabled && st.passkey_wrapping_count > 0;
				var busy = false;
				async function attempt(fn, usingPasskey) {
					if (busy) return;
					busy = true;
					showError('');
					try { finish(await fn()); }
					catch (e) { showError(friendly(e, usingPasskey)); }
					finally { busy = false; }
				}
				if (canPasskey) {
					var row = el('div', 'jy-vault-ceremony-actions');
					row.appendChild(button('Unlock with a passkey', 'primary', function () {
						attempt(async function () {
							var d = await derivePasskeyKek(scope);
							return unlockWithPasskey(scope, d.kek, d.credentialId);
						}, true);
					}));
					root.appendChild(row);
				}
				if (st.has_passphrase) {
					var pp = input('password', 'Passphrase', 'current-password');
					var go = function () {
						attempt(async function () {
							var s = await unlockWithPassphrase(scope, pp.value || '');
							pp.value = '';
							return s;
						}, false);
					};
					onEnter(pp, go);
					var ppRow = el('div', 'jy-vault-ceremony-fields');
					ppRow.appendChild(pp);
					ppRow.appendChild(button('Unlock with passphrase', canPasskey ? 'secondary' : 'primary', go));
					root.appendChild(ppRow);
				}
				var recWrap = el('div', 'jy-vault-ceremony-fields');
				var rec = input('text', 'Recovery code', 'off');
				rec.spellcheck = false;
				var goRec = function () {
					attempt(async function () {
						var r = await unlockWithRecovery(scope, rec.value || '');
						rec.value = '';
						return r.session;
					}, false);
				};
				onEnter(rec, goRec);
				recWrap.appendChild(rec);
				recWrap.appendChild(button('Unlock with recovery code', 'secondary', goRec));
				if (canPasskey || st.has_passphrase) {
					recWrap.hidden = true;
					var show = button('Use a recovery code', 'link', function () { recWrap.hidden = false; show.hidden = true; rec.focus(); });
					root.appendChild(show);
				}
				root.appendChild(recWrap);
				showError('');
			}

			if (st.set_up) renderUnlock(); else renderSetup();
		});
	}

	return {
		DEFAULT_RECOVERY_COUNT: DEFAULT_RECOVERY_COUNT,
		ensureUnlocked: ensureUnlocked,
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
