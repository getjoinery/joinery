/**
 * JoinerySealed - the browser half of every sealed row, whichever custody it
 * is under (docs/sealed_vault.md § Client-custody scopes).
 *
 * A model's API representation arrives in one of three shapes, and a page
 * hands it to open() without asking which:
 *   - plain (no `sealed_scope`, no `content_locked`): returned as is;
 *   - server custody, window closed (`content_locked: true`, or a 423): the
 *     vault lock ceremony runs, then the caller's refetch() answers;
 *   - client custody (`sealed_scope` set): the scope's session opens
 *     `sealed_dek` and every `v1.edge.` field decrypts under
 *     AD = `sealed_ad_prefix + key + ':' + field`.
 *
 * Writing a client-custody row is two posts to the consumer's own save action
 * (save()): the row without its sealed fields, then - with the id the first
 * reply names - the ciphertext seal() made. The server stores that through
 * SystemBase::acceptBrowserSealed().
 *
 * Sessions are held HERE, one per scope per tab, and never handed out as key
 * bytes: session(scope) returns the keyring closure, which can open and seal
 * but not reveal. lock(scope) drops the session and this module's cache of
 * opened values, calls every onLock(scope) handler and dispatches
 * `joinery:vault-scope-locked` (detail.scope). What a consumer wrote into its
 * own DOM and caches is the consumer's to wipe, in its onLock handler.
 *
 * Every open scope locks together: after `vault_client_autolock_minutes` of no
 * keyboard or pointer activity (the site's value, on the joinery-vault meta as
 * data-client-idle-minutes; a person's own choice for this browser overrides
 * it, see idleMinutes()), when the page is hidden for good (pagehide), and
 * when a back/forward-cache restore brings the page back (pageshow with
 * `persisted`) — a restored page must never show plaintext with a live key;
 * it reopens through the resume halves instead, like a reload.
 * `joinery:vault-scope-unlocked` (detail.scope) announces a session opening;
 * detail.resumed is true when it reopened after a reload rather than by a
 * ceremony.
 *
 * A reload of the same tab reopens what was open (specs/client_custody_mail.md
 * § R4a). When a scope opens, its secret is wrapped under a key derived from
 * two random halves: the tab keeps one in sessionStorage beside the wrapped
 * secret, the server keeps the other in this sign-in's session
 * (vault_client_resume, keyed by a random id per tab). Neither half opens
 * anything alone. At load, `ready` settles once every such scope has reopened
 * or been given up. A reload, or moving to another page in the tab, only drops
 * the in-memory key (pagehide); a real lock — Lock now, the idle lock, a
 * consumer's lock() — also drops both halves, so the next page asks again.
 * Closing the tab loses the tab's half; signing out loses the server's. The
 * idle clock survives reloads: a tab idle past its limit does not reopen.
 *
 * Framing lives here: `v1.edgeseal.{scope}.` on a sealed DEK and `v1.edge.` on
 * a field. vault-crypto.js stays raw.
 *
 * Depends on VaultCrypto, VaultKeyring and joineryApi (at call time).
 *
 * Rotating a scope's key (resealScope) moves every DEK sealed to it onto a new
 * keypair: the registered models' rows through vault_client_reseal_rows /
 * vault_row_reseal, and every other key through the consumers' onReseal hooks.
 *
 * @version 1.7 - the one vault: content vaults open through the root; adopt(); openAllThroughRoot()
 * @version 1.6 - want(scope, label): a page names the vaults it reads, for the lock chip
 * @version 1.5 - an open scope survives a reload of the same tab (resume halves, `ready`);
 *   pagehide drops only the in-memory key
 * @version 1.4 - seals to a pending rotation's key and names the key it used; the
 *   rotation checks it may begin before collecting unlockers; hooks skip and
 *   report keys neither key opens
 * @version 1.3 - onReseal() and resealScope(): a client-custody key rotation
 * @version 1.2 - sessions come only from the core ceremony
 * @version 1.1 - idle lock, pagehide/pageshow lock, the per-browser override
 * @version 1.0
 */
window.JoinerySealed = (function () {
	'use strict';

	var EDGE_SEAL = 'v1.edgeseal.';
	var EDGE_FIELD = 'v1.edge.';

	var sessions = {};      // scope -> keyring session (closure)
	var pending = {};       // scope -> Promise<session> while a ceremony runs
	var opened = {};        // scope -> { field ciphertext -> plaintext }
	var lockHandlers = {};  // scope -> [fn]
	var wanted = {};        // scope -> label: the browser-held vaults this page reads

	// A page declares the client scopes it reads, so the lock chip can say one
	// of them is shut and offer to open it, rather than read "unlocked" while
	// the page still shows sealed content.
	function want(scope, label) {
		if (!scope || wanted[scope]) return;
		wanted[scope] = label || scope;
		document.dispatchEvent(new CustomEvent('joinery:vault-scope-wanted', { detail: { scope: scope } }));
	}
	function wantedScopes() { return Object.keys(wanted); }

	// ---- the idle lock ---------------------------------------------------------

	var IDLE_KEY = 'jy_vault_client_autolock';
	var IDLE_CHOICES = [5, 15, 30, 60];
	var idleTimer = null;
	var lastActivity = 0;

	function siteIdleMinutes() {
		var meta = document.querySelector('meta[name="joinery-vault"]');
		var n = meta ? parseInt(meta.getAttribute('data-client-idle-minutes'), 10) : NaN;
		return n > 0 ? n : 15;
	}

	// This browser's choice (the password manager's select offers IDLE_CHOICES)
	// wins over the site's; a stored value that is not a sane number of minutes
	// is ignored rather than trusted.
	function idleMinutes() {
		try {
			var n = parseInt(localStorage.getItem(IDLE_KEY), 10);
			if (n > 0 && n <= 1440) return n;
		} catch (e) { /* storage blocked: the site's value */ }
		return siteIdleMinutes();
	}

	// null clears this browser's choice, back to the site's value.
	function setIdleMinutes(minutes) {
		try {
			if (minutes === null) localStorage.removeItem(IDLE_KEY);
			else if (parseInt(minutes, 10) > 0) localStorage.setItem(IDLE_KEY, String(parseInt(minutes, 10)));
		} catch (e) { /* storage blocked: this tab keeps the site's value */ }
		resetIdle();
	}

	function resetIdle() {
		if (idleTimer) { clearTimeout(idleTimer); idleTimer = null; }
		if (!openScopes().length) return;
		idleTimer = setTimeout(lockAll, idleMinutes() * 60000);
	}

	// Activity defers the lock. pointermove fires constantly, so it re-arms at
	// most once a second; the lock is minutes away, so nothing is lost.
	function activity() {
		var now = Date.now();
		if (now - lastActivity < 1000) return;
		lastActivity = now;
		if (idleTimer) resetIdle();
		noteActivity(now);
	}
	['keydown', 'pointerdown', 'pointermove'].forEach(function (evt) {
		document.addEventListener(evt, activity, { passive: true, capture: true });
	});
	// Leaving the page (a reload, a link, closing the tab) drops the key from
	// memory; the resume halves stay, so the next page in this tab reopens.
	window.addEventListener('pagehide', function () { lockAll({ keepResume: true }); });
	window.addEventListener('pageshow', function (e) {
		if (!e.persisted) return;
		lockAll({ keepResume: true });
		ready = resumeAll();
	});

	// ---- surviving a reload (the resume halves) ---------------------------------

	var RESUME_PREFIX = 'jy_vault_resume:';
	var TAB_KEY = 'jy_vault_tab';
	var ACTIVITY_KEY = 'jy_vault_activity';
	var RESUME_ACTION = 'vault_client_resume';

	function ssGet(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
	function ssSet(k, v) { try { sessionStorage.setItem(k, v); return true; } catch (e) { return false; } }
	function ssDel(k) { try { sessionStorage.removeItem(k); } catch (e) { /* storage blocked */ } }

	// The idle clock, carried across reloads. Written at most every 15 seconds.
	var activityWritten = 0;
	function noteActivity(now) {
		if (now - activityWritten < 15000) return;
		activityWritten = now;
		ssSet(ACTIVITY_KEY, String(now));
	}
	function idleExpired() {
		var last = parseInt(ssGet(ACTIVITY_KEY), 10);
		return last > 0 && (Date.now() - last) > idleMinutes() * 60000;
	}

	// This tab's id for its server half, made once per tab.
	function tabId() {
		var id = ssGet(TAB_KEY);
		if (id && /^[0-9a-f]{32}$/.test(id)) return id;
		var bytes = crypto.getRandomValues(new Uint8Array(16));
		id = Array.prototype.map.call(bytes, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
		ssSet(TAB_KEY, id);
		return id;
	}

	// The wrapping key: HKDF over both halves, bound to the scope.
	async function resumeKey(serverHalf, tabHalf, scope) {
		var ikm = new Uint8Array(serverHalf.length + tabHalf.length);
		ikm.set(serverHalf, 0);
		ikm.set(tabHalf, serverHalf.length);
		var base = await crypto.subtle.importKey('raw', ikm, 'HKDF', false, ['deriveKey']);
		ikm.fill(0);
		return crypto.subtle.deriveKey(
			{ name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(0),
				info: new TextEncoder().encode('joinery-vault-resume:v1:' + scope) },
			base, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
	}

	// Keep what a reload needs to reopen `scope`. Best effort: a failure only
	// means the next page asks for the passkey, as before.
	async function persistResume(scope, s) {
		if (!window.joineryApi || typeof s.wrapUnder !== 'function') return;
		var serverHalf = crypto.getRandomValues(new Uint8Array(32));
		var tabHalf = crypto.getRandomValues(new Uint8Array(32));
		try {
			var key = await resumeKey(serverHalf, tabHalf, scope);
			var wrapped = await s.wrapUnder(key, 'resume');
			var tab = tabId();
			await joineryApi.post(RESUME_ACTION, { op: 'put', scope: scope, tab: tab,
				share: VaultCrypto.b64encode(serverHalf) });
			if (!sessions[scope] || sessions[scope] !== s) return;   // locked meanwhile
			ssSet(RESUME_PREFIX + scope, JSON.stringify({ v: 1, wrapped: wrapped,
				tab_half: VaultCrypto.b64encode(tabHalf), public_key: s.publicKey, label: s.label || '' }));
			noteActivity(Date.now());
		} catch (e) {
			/* the next page asks, as without this */
		} finally {
			serverHalf.fill(0);
			tabHalf.fill(0);
		}
	}

	// Forget both halves of `scope` (a real lock).
	function dropResume(scope) {
		var had = ssGet(RESUME_PREFIX + scope) !== null;
		ssDel(RESUME_PREFIX + scope);
		if (had && window.joineryApi) {
			joineryApi.post(RESUME_ACTION, { op: 'drop', scope: scope, tab: tabId() }).catch(function () {});
		}
	}

	async function resumeOne(scope) {
		var rec = null;
		try { rec = JSON.parse(ssGet(RESUME_PREFIX + scope) || 'null'); } catch (e) { rec = null; }
		if (!rec || rec.v !== 1 || isOpen(scope)) return;
		if (idleExpired()) { dropResume(scope); return; }
		var tabHalf = null, serverHalf = null;
		try {
			var got = await joineryApi.post(RESUME_ACTION, { op: 'get', scope: scope, tab: tabId() });
			if (!got || !got.share) { ssDel(RESUME_PREFIX + scope); return; }
			// A rotation retired the key this secret belongs to.
			if (rec.public_key !== got.public_key && rec.public_key !== got.pending_public_key) {
				dropResume(scope);
				return;
			}
			serverHalf = VaultCrypto.b64decode(got.share);
			tabHalf = VaultCrypto.b64decode(rec.tab_half);
			var key = await resumeKey(serverHalf, tabHalf, scope);
			var secret = await VaultCrypto.unwrapSecretKey(rec.wrapped, key, VaultKeyring.adFor(scope, 'resume'));
			if (isOpen(scope)) { secret.fill(0); return; }   // a ceremony won the race
			var s = VaultKeyring.sessionFrom(scope, secret, rec.public_key);
			s.label = rec.label || s.label;
			sessions[scope] = s;
			resetIdle();
			document.dispatchEvent(new CustomEvent('joinery:vault-scope-unlocked',
				{ detail: { scope: scope, resumed: true } }));
		} catch (e) {
			dropResume(scope);   // unusable halves: ask next time
		} finally {
			if (serverHalf) serverHalf.fill(0);
			if (tabHalf) tabHalf.fill(0);
		}
	}

	// Reopen every scope this tab had open. Resolves when each has reopened or
	// been given up; never rejects.
	function resumeAll() {
		var scopes = [];
		try {
			for (var i = 0; i < sessionStorage.length; i++) {
				var k = sessionStorage.key(i);
				if (k && k.indexOf(RESUME_PREFIX) === 0) scopes.push(k.slice(RESUME_PREFIX.length));
			}
		} catch (e) { return Promise.resolve(); }
		if (!scopes.length) return Promise.resolve();
		return new Promise(function (resolve) {
			var go = function () {
				if (!window.joineryApi || !window.VaultKeyring || !window.VaultCrypto) { resolve(); return; }
				Promise.all(scopes.map(function (sc) { return resumeOne(sc).catch(function () {}); }))
					.then(function () { resolve(); });
			};
			// The API and keyring scripts load beside this one; start once they are in.
			if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go);
			else go();
		});
	}
	var ready = resumeAll();

	function isEdgeField(v) { return typeof v === 'string' && v.indexOf(EDGE_FIELD) === 0; }

	// The scope and raw blob of a `v1.edgeseal.{scope}.` key, or null.
	function parseSealedDek(sealed) {
		if (typeof sealed !== 'string' || sealed.indexOf(EDGE_SEAL) !== 0) return null;
		var rest = sealed.slice(EDGE_SEAL.length);
		var dot = rest.indexOf('.');
		if (dot < 1) return null;
		var scope = rest.slice(0, dot);
		if (!/^[a-z0-9_]{1,32}$/.test(scope)) return null;
		return { scope: scope, blob: rest.slice(dot + 1) };
	}

	function rowId(row) { return row.key != null ? row.key : row.id; }

	// ---- sessions ------------------------------------------------------------

	function isOpen(scope) {
		var s = sessions[scope];
		return !!(s && !s.locked());
	}

	function openScopes() {
		return Object.keys(sessions).filter(isOpen);
	}

	// ---- the one vault (specs/one_vault_experience.md) -------------------------
	// The root vault is held here like any scope, and every content vault opens
	// through it: one touch (the lock chip's ceremony) opens the root, and the
	// root opens the rest without asking again. A content vault made before the
	// root opens once by its own ceremony and is given its root wrapping then.

	var ROOT = 'root';

	function rootSession() { return isOpen(ROOT) ? sessions[ROOT] : null; }

	// Hold a session a ceremony elsewhere opened (the lock chip's unlock), as
	// session() holds one it opened itself.
	function adopt(scope, s) {
		if (!s || s.locked()) return;
		if (isOpen(scope)) { if (sessions[scope] !== s) s.lock(); return; }
		sessions[scope] = s;
		resetIdle();
		persistResume(scope, s);
		document.dispatchEvent(new CustomEvent('joinery:vault-scope-unlocked', { detail: { scope: scope } }));
	}

	// The root, running the one unlock when it is shut.
	async function needRoot(opts) {
		if (rootSession()) return rootSession();
		if (!window.JoineryVaultLock) throw new Error('Unlock your vault to open this.');
		var ok = await JoineryVaultLock.unlock(opts && opts.reason ? { reason: opts.reason } : {});
		if (!ok) throw new Error('Unlock cancelled.');
		if (!rootSession()) throw new Error('Your vault opened, but not its end-to-end part. Unlock it with the passkey you set it up with, or a recovery code.');
		return rootSession();
	}

	// A content vault through the root, or its own ceremony for one made before
	// the root existed (then given its root wrapping, so it is the last time).
	async function openContent(scope, opts) {
		if (!VaultKeyring.contentSession) return VaultKeyring.ensureUnlocked(scope, opts || {});
		var root = await needRoot(opts);
		var s = await VaultKeyring.contentSession(root, scope);
		if (s) return s;
		s = await VaultKeyring.ensureUnlocked(scope, opts || {});
		if (s && !s.locked() && rootSession()) {
			VaultKeyring.addRootWrapping(rootSession(), scope, s).catch(function () { /* next open asks once more */ });
		}
		return s;
	}

	// An unlocked session for `scope`, running the one unlock when needed.
	// Concurrent callers share one ceremony.
	function session(scope, opts) {
		if (isOpen(scope)) return Promise.resolve(sessions[scope]);
		if (pending[scope]) return pending[scope];
		if (!window.VaultKeyring || !VaultKeyring.ensureUnlocked) {
			return Promise.reject(new Error('This page cannot unlock your ' + scope + ' vault.'));
		}
		// A reload's resume first: no ceremony for a scope this tab already opened.
		pending[scope] = ready.then(function () {
			if (isOpen(scope)) return { resumed: sessions[scope] };
			return scope === ROOT ? needRoot(opts) : openContent(scope, opts);
		}).then(function (s) {
			if (s && s.resumed) return s.resumed;
			if (!s || s.locked()) throw new Error('Unlock did not complete.');
			if (isOpen(scope)) return sessions[scope];
			adopt(scope, s);
			return s;
		});
		var clear = function () { delete pending[scope]; };
		pending[scope].then(clear, clear);
		return pending[scope];
	}

	/**
	 * With the root just opened: open every content vault this page wants, and
	 * every one the person holds that opens through the root, so the chip reads
	 * open. contentStatus is vault_status's `content` map. Best effort.
	 */
	async function openAllThroughRoot(contentStatus) {
		var root = rootSession();
		if (!root) return;
		var scopes = Object.keys(contentStatus || {});
		for (var i = 0; i < scopes.length; i++) {
			var scope = scopes[i];
			if (isOpen(scope)) continue;
			try {
				var s = await VaultKeyring.openThroughRoot(root, scope, contentStatus[scope]);
				if (s) adopt(scope, s);
			} catch (e) { /* that one stays shut; its own open says why */ }
		}
	}

	// What to call an open scope: the registry label the ceremony read.
	function labelFor(scope) {
		var s = sessions[scope];
		return (s && s.label) || wanted[scope] || scope;
	}

	function onLock(scope, fn) {
		(lockHandlers[scope] = lockHandlers[scope] || []).push(fn);
	}

	// opts.keepResume (pagehide only): drop the key from memory but keep the
	// resume halves, so the next page in this tab reopens. Every other lock is
	// a real one and forgets them.
	function lock(scope, opts) {
		var s = sessions[scope];
		delete sessions[scope];
		delete opened[scope];
		if (!(opts && opts.keepResume)) dropResume(scope);
		if (!s) return;
		s.lock();
		resetIdle();
		(lockHandlers[scope] || []).forEach(function (fn) {
			try { fn(scope); } catch (e) { /* one consumer's wipe must not stop another's */ }
		});
		document.dispatchEvent(new CustomEvent('joinery:vault-scope-locked', { detail: { scope: scope } }));
	}

	function lockAll(opts) {
		// The idle timer calls this with its own argument; only an object is options.
		var o = (opts && typeof opts === 'object') ? opts : null;
		Object.keys(sessions).forEach(function (scope) { lock(scope, o); });
	}

	// ---- opening -------------------------------------------------------------

	// Decrypt every v1.edge. field of `row` with an unlocked session. `cache`
	// (a ciphertext -> plaintext map, or null) spares a second open of the same
	// value; a field's ciphertext is unique (fresh IV), so it is a safe key.
	async function openWithSession(row, s, cache) {
		var parsed = parseSealedDek(row.sealed_dek);
		if (!parsed) throw new Error('This row carries no browser-sealed key.');
		var out = Object.assign({}, row);
		var dekKey = null;
		var keys = Object.keys(row);
		for (var i = 0; i < keys.length; i++) {
			var field = keys[i], value = row[field];
			if (!isEdgeField(value)) continue;
			if (cache && Object.prototype.hasOwnProperty.call(cache, value)) { out[field] = cache[value]; continue; }
			if (!dekKey) {
				var dekBytes = await s.openSealed(parsed.blob);
				dekKey = await VaultCrypto.importDek(dekBytes);
				dekBytes.fill(0);
			}
			var plain = await VaultCrypto.decrypt(value.slice(EDGE_FIELD.length), dekKey,
				String(row.sealed_ad_prefix) + rowId(row) + ':' + field);
			out[field] = plain;
			if (cache) cache[value] = plain;
		}
		return out;
	}

	/**
	 * Open a model's API row, whichever rung it is on. `refetch` repeats the
	 * read that produced it (needed for the server-custody locked case only).
	 * opts.reason reads in an unlock prompt ("to open this note").
	 */
	async function open(row, refetch, opts) {
		if (!row) return row;
		if (row.content_locked) {
			if (!window.JoineryVaultLock) throw new Error('Unlock your vault to open this.');
			var ok = await JoineryVaultLock.unlock();
			if (!ok) throw new Error('Unlock cancelled.');
			return refetch ? refetch() : row;
		}
		if (!row.sealed_scope) return row;
		var scope = row.sealed_scope;
		var s = await session(scope, opts);
		var cache = opened[scope] = opened[scope] || {};
		return openWithSession(row, s, cache);
	}

	// ---- sealing -------------------------------------------------------------

	// The key new material seals to: the pending key while the scope's key is
	// being rotated (the commit makes it current), else the key in use. Asked
	// of the server each time, so a tab opened before a rotation began does not
	// seal to the key it will retire. Sealing needs no unlock; a scope not set up
	// yet runs setup through the session ceremony first.
	async function publicKeyFor(scope) {
		var st = await VaultKeyring.status(scope);
		if (st && st.set_up) return st.pending_public_key || st.public_key;
		return (await session(scope)).publicKey;
	}

	async function sealWith(publicKeyB64, scope, id, adPrefix, values) {
		var d = await VaultCrypto.newDek();
		try {
			var sealedDek = EDGE_SEAL + scope + '.' + await VaultCrypto.sealToPublicKey(d.dekBytes, publicKeyB64);
			var fields = {};
			var names = Object.keys(values || {});
			for (var i = 0; i < names.length; i++) {
				var v = values[names[i]];
				// Empty stays bare, as the server stores it: '' and null are not secrets.
				if (v === null || v === undefined) { fields[names[i]] = null; continue; }
				if (v === '') { fields[names[i]] = ''; continue; }
				fields[names[i]] = EDGE_FIELD + await VaultCrypto.encrypt(String(v), d.dekKey, String(adPrefix) + id + ':' + names[i]);
			}
			return { sealed_dek: sealedDek, fields: fields, public_key: publicKeyB64 };
		} finally {
			d.dekBytes.fill(0);
		}
	}

	/**
	 * Seal `values` for row `id` of a model whose AD prefix is `adPrefix`.
	 * Resolves { sealed_dek, fields, public_key }: public_key names the key it
	 * sealed to, which the server stamps the row's key generation from.
	 */
	async function seal(scope, id, adPrefix, values) {
		return sealWith(await publicKeyFor(scope), scope, id, adPrefix, values);
	}

	/**
	 * The two-step browser write, as one call. Posts `values` minus the sealed
	 * fields to `action`, reads `id` and `sealed_ad_prefix` from the reply,
	 * seals the rest, and posts { id, sealed_dek, fields, public_key } to the
	 * same action — public_key names the key it sealed to; pass it on to
	 * acceptBrowserSealed(). opts: { scope, sealedFields: [names] }. Resolves
	 * the second reply.
	 */
	async function save(action, values, opts) {
		opts = opts || {};
		if (!opts.scope) throw new Error('JoinerySealed.save needs opts.scope.');
		var sealedNames = opts.sealedFields || [];
		var plain = {}, secret = {};
		Object.keys(values || {}).forEach(function (k) {
			(sealedNames.indexOf(k) >= 0 ? secret : plain)[k] = values[k];
		});
		var first = await joineryApi.post(action, plain);
		var id = first && (first.id != null ? first.id : first.key);
		if (id == null || !first || first.sealed_ad_prefix == null) {
			throw new Error(action + ' must reply with id and sealed_ad_prefix.');
		}
		var sealed = await seal(opts.scope, id, first.sealed_ad_prefix, secret);
		return joineryApi.post(action, { id: id, sealed_dek: sealed.sealed_dek, fields: sealed.fields, public_key: sealed.public_key });
	}

	// ---- key rotation --------------------------------------------------------

	var resealHooks = {};   // scope -> [fn(ctx)]

	/**
	 * Register how a consumer re-seals keys it keeps OUTSIDE sealed models when
	 * `scope`'s key rotates. fn(ctx) gets { oldSession, newSession, newPublicKey,
	 * progress(text), skip(description) }: open each key with ctx.oldSession,
	 * seal it to ctx.newPublicKey, store it. It must be safe to run twice: a
	 * rotation that stopped runs every hook again, and a key already moved opens
	 * with ctx.newSession instead (leave it). A key neither opens was unreadable
	 * before the rotation too: leave it and report it with ctx.skip().
	 */
	function onReseal(scope, fn) {
		(resealHooks[scope] = resealHooks[scope] || []).push(fn);
	}

	function stripEdgeSeal(sealed) {
		var parsed = parseSealedDek(sealed);
		if (!parsed) throw new Error('A sealed key is not a browser-sealed key.');
		return parsed.blob;
	}

	/**
	 * Rotate `scope`'s keypair, or finish a rotation that stopped. Needs the old
	 * key (the ceremony unlocks it) and, to finish, the new one (its own
	 * passphrase, passkey or new recovery code). Moves every sealed DEK of the
	 * registered models and runs every onReseal hook, then commits. Resolves
	 * { key_generation }; rejects 'Rotation cancelled.' when stopped before
	 * anything was written. opts.progress(text) reports as it goes.
	 */
	async function resealScope(scope, opts) {
		opts = opts || {};
		var report = opts.progress || function () {};
		var st = await VaultKeyring.status(scope);
		if (!st.set_up) throw new Error('This vault is not set up.');
		var oldSession = await session(scope, { reason: 'to rotate its key' });
		var newSession, newPublicKey;

		if (st.pending_key_generation == null) {
			// Refusals (a step-up due, a consumer unable to re-seal) come before the
			// passkey taps and the passphrase, not after them.
			await joineryApi.post('vault_client_rotate_begin', { scope: scope, dry_run: 1 });
			var pair = await VaultCrypto.generateVaultKeypair();
			var plan = await VaultKeyring.rotationPlan(scope, st, pair.secretKeyBytes);
			report('Saving the new key…');
			await joineryApi.post('vault_client_rotate_begin', { scope: scope, public_key: pair.publicKeyB64, wrappings: plan.wrappings });
			await VaultKeyring.showRecoveryCodes(scope, st.label || 'vault', plan.recoveryCodes,
				'These open the new key of your ' + (st.label || 'vault') + ' once each, if you lose your passkey and passphrase. '
				+ 'Your old codes stop working when the rotation finishes. This is the only time they are shown.');
			newSession = VaultKeyring.sessionFrom(scope, pair.secretKeyBytes, pair.publicKeyB64);
			newPublicKey = pair.publicKeyB64;
		} else {
			newSession = await VaultKeyring.ensureUnlocked(scope, { pending: true, reason: 'to finish rotating its key' });
			newPublicKey = st.pending_public_key;
		}

		try {
			// Rows of the registered models: the server lists only rows still on
			// the old key, so a walk that stopped picks up where it was.
			var moved = 0, cursor = { model: '', after_id: 0 };
			for (;;) {
				var page = await joineryApi.post('vault_client_reseal_rows', { scope: scope, model: cursor.model, after_id: cursor.after_id, limit: 100 });
				if (page.rows && page.rows.length) {
					var out = [];
					for (var i = 0; i < page.rows.length; i++) {
						var r = page.rows[i];
						var dek = await oldSession.openSealed(stripEdgeSeal(r.sealed_dek));
						out.push({ model: r.model, id: r.id, sealed_dek: EDGE_SEAL + scope + '.' + await oldSession.sealTo(dek, newPublicKey) });
						dek.fill(0);
					}
					await joineryApi.post('vault_row_reseal', { rows: out });
					moved += out.length;
					report('Re-sealed ' + moved + ' of ' + (moved + Math.max(0, page.remaining - out.length)) + '…');
				}
				if (!page.next) break;
				cursor = page.next;
			}
			// Keys kept outside those models. A key neither key opens was unreadable
			// before the rotation too; a hook skips it and says so, rather than
			// holding the rotation back for something it cannot change.
			var skipped = [];
			var hooks = resealHooks[scope] || [];
			for (var h = 0; h < hooks.length; h++) {
				await hooks[h]({ oldSession: oldSession, newSession: newSession, newPublicKey: newPublicKey, progress: report,
					skip: function (what) { skipped.push(what); } });
			}
			report('Finishing…');
			var result = await joineryApi.post('vault_client_rotate_commit', { scope: scope });
			result.skipped = skipped;
			return result;
		} finally {
			newSession.lock();
			lock(scope);
		}
	}

	// ---- self-check ----------------------------------------------------------

	// Seal a row to a fresh keypair and open it through the same path open()
	// takes, refusing a splice onto another row. Touches no session or cache.
	async function selfCheck() {
		try {
			var pair = await VaultCrypto.generateVaultKeypair();
			var fake = {
				locked: function () { return false; },
				openSealed: function (blob) { return VaultCrypto.openFromSecretKey(blob, pair.secretKeyBytes, pair.publicKeyB64); },
			};
			var sealed = await sealWith(pair.publicKeyB64, 'selfcheck', 7, 'sc:', { sc_body: 'hello', sc_empty: '' });
			var row = { key: 7, sealed_scope: 'selfcheck', sealed_ad_prefix: 'sc:', sealed_dek: sealed.sealed_dek,
				sc_body: sealed.fields.sc_body, sc_empty: sealed.fields.sc_empty, sc_plain: 'x' };
			var out = await openWithSession(row, fake, null);
			if (out.sc_body !== 'hello' || out.sc_empty !== '' || out.sc_plain !== 'x') return false;
			var spliced = Object.assign({}, row, { key: 8 });
			try { await openWithSession(spliced, fake, null); return false; } catch (e) { /* refused, as it must be */ }
			return true;
		} catch (e) {
			return false;
		}
	}

	return {
		open: open,
		seal: seal,
		save: save,
		session: session,
		isOpen: isOpen,
		openScopes: openScopes,
		labelFor: labelFor,
		idleMinutes: idleMinutes,
		setIdleMinutes: setIdleMinutes,
		onReseal: onReseal,
		resealScope: resealScope,
		onLock: onLock,
		lock: lock,
		lockAll: lockAll,
		want: want,
		wantedScopes: wantedScopes,
		adopt: adopt,
		rootSession: rootSession,
		openAllThroughRoot: openAllThroughRoot,
		selfCheck: selfCheck,
		// Settles once a reload's resume has reopened (or given up on) every
		// scope this tab had open. Read it before deciding a scope is shut.
		get ready() { return ready; },
	};
})();
