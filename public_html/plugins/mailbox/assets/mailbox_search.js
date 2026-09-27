/**
 * MailboxSearch - search over end-to-end (Fortress) mail, on this device
 * (specs/client_custody_mail.md § R5).
 *
 * The server cannot read Fortress mail, so this browser keeps its own index:
 * built once, sealed in its own storage, kept up to date with new mail. This
 * file is the page's half. It holds the mail vault (through JoinerySealed),
 * pages the person's sealed search texts from mailbox/search_entries, opens
 * each one and hands the text to the worker (mailbox_search_worker.js), which
 * builds, seals, saves and searches the index.
 *
 *   hits(q)    catch up with new mail, then search: resolves {packed, count,
 *              status} for thread_list's device_hits, {error, status} when the
 *              index could not be used, or null when the mail vault is shut.
 *   status()   {indexed, total, building, failed}
 *   rebuild()  start this browser's index again
 *   remove()   delete this browser's index
 *   selfCheck() runs all of it in this browser against a throwaway key and a
 *              stand-in server; resolves {ok, checks}. Nothing real is touched.
 *
 * The index is sealed under a search key held by the server sealed to the mail
 * vault (mailbox/search_key): the first browser to need one makes it. The
 * worker gets only the record key derived from it. A first build walks the
 * mail newest first in the background, 25 pages at a time, and only one tab
 * builds at once; a search meanwhile answers over what is indexed. Locking the
 * vault ends the worker; the saved index stays sealed.
 *
 * @version 1.2 - a status notice comes only from a save or a build that ends or fails, never from
 *   a build that did not start (another tab holds it), so the reader's refresh-on-notice cannot
 *   loop; a failed build is not retried until a reload, an unlock or Rebuild; a search refreshes
 *   what this tab knows of the index before deciding to build (review of 2026-09-27, B1, B3)
 * @version 1.1 - one instance per set of dependencies (create()), so selfCheck() runs the real
 *   worker, storage and crypto against a stand-in server and key
 * @version 1.0
 */
window.MailboxSearch = (function () {
	'use strict';

	var SCOPE = 'mail';
	var EDGE_SEAL = 'v1.edgeseal.' + SCOPE + '.';
	var EDGE_FIELD = 'v1.edge.';

	function cfg() { return window.MAILBOX_READER || {}; }

	// What an instance reaches the outside world through. The page's instance
	// uses the real ones; selfCheck() hands in stand-ins.
	function realDeps() {
		return {
			post: function (action, body) { return window.joineryApi.post(action, body); },
			isOpen: function () { return !!(window.MailboxFortress && MailboxFortress.isOpen()); },
			session: function () { return JoinerySealed.session(SCOPE); },
			publicKey: async function () {
				var st = await VaultKeyring.status(SCOPE);
				return st.pending_public_key || st.public_key;
			},
			inflate: function (text) { return MailboxFortress.inflateSearchText(text); },
			workerUrl: cfg().searchWorkerUrl,
			coreUrl: cfg().searchCoreUrl,
			buildPages: 25,         // pages per save in a first build (5,000 messages)
			catchupBatch: 5000      // messages per save when catching up
		};
	}

	function create(deps) {
		var worker = null;
		var calls = new Map();
		var callSeq = 0;
		var starting = null;
		var userId = 0;
		var meta = null;
		var catching = null;
		var building = null;
		var failed = null;       // the last search's failure, cleared by the next one that works
		var buildError = null;   // a first build that failed: not retried until stop() (reload, lock, Rebuild)
		var listeners = [];
		// Bumped by stop(). A catch-up or build that started before it finds the
		// number changed and ends without touching what came after.
		var epoch = 0;

		function supported() {
			return !!(window.Worker && window.indexedDB && window.CompressionStream && window.DecompressionStream
				&& window.crypto && crypto.subtle && window.VaultCrypto && deps.workerUrl && deps.coreUrl);
		}

		function status() {
			return {
				indexed: meta ? meta.doc_count : 0,
				total: meta ? Math.max(meta.total || 0, meta.doc_count || 0) : 0,
				building: !!(meta && !meta.backfill_done),
				failed: failed || buildError
			};
		}

		// The reader refreshes its list on a notice, and a refresh searches again.
		// So a notice must mean progress (a save) or an end (the build finished or
		// failed for good): a notice that a search can provoke without progress
		// would loop.
		function onStatus(fn) { listeners.push(fn); }
		function notify() {
			var s = status();
			listeners.forEach(function (fn) { try { fn(s); } catch (e) { /* one listener must not stop the next */ } });
		}

		// ---- the worker ------------------------------------------------------------------

		function call(op, args) {
			if (!worker) return Promise.reject(new Error('Search on this device has stopped.'));
			return new Promise(function (resolve, reject) {
				var id = ++callSeq;
				calls.set(id, { resolve: resolve, reject: reject });
				worker.postMessage(Object.assign({ op: op, id: id }, args || {}));
			});
		}

		function onMessage(e) {
			var m = e.data || {};
			var c = calls.get(m.id);
			if (!c) return;
			calls.delete(m.id);
			if (m.ok) { c.resolve(m.result); return; }
			var err = new Error(m.error || 'Search on this device failed.');
			err.storage = !!m.storage;
			c.reject(err);
		}

		function stop() {
			epoch++;
			catching = null;
			building = null;
			if (worker) worker.terminate();
			worker = null;
			starting = null;
			calls.forEach(function (c) { c.reject(new Error('Your vault locked.')); });
			calls.clear();
			meta = null;
			buildError = null;
		}

		// The search key: fetched sealed, made and stored once if there is none,
		// opened with the mail vault; the worker gets the record key and a
		// fingerprint derived from it, never the key.
		async function searchKey() {
			var got = await deps.post('mailbox/search_key', { op: 'get' });
			if (!got.set_up) {
				var raw = crypto.getRandomValues(new Uint8Array(32));
				try {
					var pub = await deps.publicKey();
					var sealed = EDGE_SEAL + await VaultCrypto.sealToPublicKey(raw, pub);
					got = await deps.post('mailbox/search_key', { op: 'create', sealed_key: sealed, public_key: pub });
				} catch (e) {
					// Another browser made it first: use that one.
					got = await deps.post('mailbox/search_key', { op: 'get' });
					if (!got.set_up) throw e;
				} finally {
					raw.fill(0);
				}
			}
			var session = await deps.session();
			var bytes = await session.openSealed(String(got.sealed_key).slice(EDGE_SEAL.length));
			try {
				var base = await crypto.subtle.importKey('raw', bytes, 'HKDF', false, ['deriveKey', 'deriveBits']);
				var te = new TextEncoder();
				var recordKey = await crypto.subtle.deriveKey(
					{ name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(0), info: te.encode('mailbox-search:v1') },
					base, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
				var fpBits = new Uint8Array(await crypto.subtle.deriveBits(
					{ name: 'HKDF', hash: 'SHA-256', salt: new Uint8Array(0), info: te.encode('mailbox-search:fp') }, base, 128));
				var fp = Array.prototype.map.call(fpBits, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
				return { key: recordKey, fp: fp, userId: got.user_id };
			} finally {
				bytes.fill(0);
			}
		}

		function start() {
			if (starting) return starting;
			var at = epoch;
			var mine = (async function () {
				var k = await searchKey();
				if (at !== epoch) throw new Error('Search on this device has stopped.');
				userId = k.userId;
				worker = new Worker(deps.workerUrl);
				worker.onmessage = onMessage;
				worker.onerror = function () { failed = 'Search on this device stopped.'; stop(); notify(); };
				meta = await call('init', {
					coreUrl: new URL(deps.coreUrl, location.href).href,
					key: k.key, fp: k.fp, userId: k.userId
				});
				// Ask the browser not to clear the index to free space.
				if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(function () {});
				return meta;
			})();
			starting = mine;
			mine.catch(function () { if (starting === mine) stop(); });
			return mine;
		}

		// ---- reading the mail ----------------------------------------------------------

		// Open a page's search texts. A row that will not open is left out (and not
		// asked for again); a page where none opens stops the walk, so a vault that
		// changed under it (a rotation) is retried rather than indexed as empty.
		async function openEntries(entries) {
			if (!deps.isOpen()) throw new Error('Your vault locked.');
			var session = await deps.session();
			var tried = 0;
			var out = await Promise.all(entries.map(async function (e) {
				var s = e && e.sealed;
				if (!s || typeof s.iem_search_text !== 'string' || typeof s.sealed_dek !== 'string') return null;
				tried++;
				try {
					var dek = await session.openSealed(s.sealed_dek.slice(EDGE_SEAL.length));
					var rowKey;
					try { rowKey = await VaultCrypto.importDek(dek); } finally { dek.fill(0); }
					var text = await VaultCrypto.decrypt(s.iem_search_text.slice(EDGE_FIELD.length), rowKey,
						String(s.sealed_ad_prefix) + e.id + ':iem_search_text');
					return { id: e.id, text: await deps.inflate(text) };
				} catch (err) {
					return null;
				}
			}));
			var opened = out.filter(Boolean);
			if (tried > 0 && opened.length === 0) throw new Error('Mail could not be opened for search on this device.');
			return opened;
		}

		function page(args) { return deps.post('mailbox/search_entries', args); }

		// New mail since this browser last looked. The first page reaches back ten
		// minutes; the worker skips anything it already has.
		function catchUp() {
			if (catching) return catching;
			var at = epoch;
			var mine = (async function () {
				if (!meta || !meta.build_started) return;
				var cur = meta.catchup || { time: meta.build_started, id: 0 };
				var batch = [];
				var last = null;
				for (var first = true; ; first = false) {
					var p = await page({ order: 'new', time: cur.time, id: cur.id, overlap: first });
					batch = batch.concat(await openEntries(p.entries || []));
					if (p.last) last = p.last;
					if (at !== epoch) return;
					if (!p.next || batch.length >= deps.catchupBatch) {
						if (batch.length || last) meta = await call('add', { entries: batch, cursor: last ? { catchup: last } : {} });
						batch = [];
					}
					if (!p.next) break;
					cur = p.next;
				}
			})();
			catching = mine;
			var done = function () { if (catching === mine) catching = null; };
			mine.then(done, done);
			return mine;
		}

		// The first build: newest first, in the background, one tab at a time.
		function backfill() {
			if (building || buildError || !meta || meta.backfill_done) return building;
			var at = epoch;
			var run = async function () {
				var cur = meta.backfill;
				var pend = {};
				var batch = [];
				var withTotal = true;
				for (var n = 1; ; n++) {
					if (at !== epoch || !worker || !deps.isOpen()) return;
					var args = { order: 'old', with_total: withTotal };
					if (cur) { args.time = cur.time; args.id = cur.id; }
					var p = await page(args);
					if (withTotal) { pend.total = p.total; withTotal = false; }
					if (!meta.build_started && !pend.build_started) {
						// Mail written from here on is the catch-up's.
						pend.build_started = p.server_time;
						pend.catchup = { time: p.server_time, id: 0 };
					}
					batch = batch.concat(await openEntries(p.entries || []));
					if (at !== epoch) return;
					if (p.next) pend.backfill = p.next; else pend.backfill_done = true;
					// The first page saves at once, so the newest mail is searchable in seconds.
					if (!p.next || n === 1 || n % deps.buildPages === 0) {
						meta = await call('add', { entries: batch, cursor: pend });
						batch = [];
						pend = {};
						notify();
					}
					if (!p.next) return;
					cur = p.next;
				}
			};
			var mine = (window.navigator && navigator.locks)
				? navigator.locks.request('jy_mailsearch_build_' + userId, { ifAvailable: true }, function (lock) {
					return lock ? run() : null;
				})
				: run();
			building = mine;
			mine.then(function () {
				if (building === mine) building = null;
				// run() noticed each save itself, the last one included. A build
				// that did not start (another tab has it) changed nothing: no notice.
			}, function (e) {
				if (building === mine) building = null;
				if (at !== epoch) return;   // stopped on purpose: a lock or a rebuild
				buildError = e && e.storage ? 'storage' : ((e && e.message) || 'Search on this device stopped.');
				notify();
			});
			return mine;
		}

		// ---- what the reader calls ----------------------------------------------------

		async function hits(q) {
			if (!supported() || !deps.isOpen()) return null;
			try {
				await start();
				await catchUp();
				var r = await call('search', { q: String(q || '') });
				// The worker read the index as saved, so this tab now knows whether
				// another tab finished the build meanwhile, and from where to go on.
				meta = r.meta;
				failed = null;
				backfill();
				return { packed: r.packed, count: r.count, status: status() };
			} catch (e) {
				if (!deps.isOpen()) return null;   // locked mid-way: the reader shows the lock
				failed = e && e.storage ? 'storage' : ((e && e.message) || 'Search on this device stopped.');
				return { error: failed, status: status() };
			}
		}

		// Stop whatever is running first, so no build still going writes into the
		// fresh index with the old one's cursors.
		async function rebuild() {
			stop();
			await start();
			meta = await call('forget');
			failed = null;
			backfill();
			notify();
		}

		async function remove() {
			await start();
			await call('forget');
			stop();
			failed = null;
			notify();
		}

		return {
			supported: supported, hits: hits, status: status, onStatus: onStatus,
			rebuild: rebuild, remove: remove, stop: stop,
			seq: function () { return meta ? meta.seq : null; },
			// For selfCheck(): wait for a first build to finish.
			settled: function () { return Promise.resolve(building).then(function () { return catching; }); }
		};
	}

	// ---- the self-check ----------------------------------------------------------------

	/**
	 * Build, search, reload, catch up, rebuild and remove an index in this
	 * browser, with the real worker, IndexedDB and crypto, against a stand-in
	 * server holding sealed messages made here under a throwaway key. It uses its
	 * own database (a user id no account has) and deletes it after.
	 */
	async function selfCheck() {
		var checks = [];
		var note = function (label, ok, detail) { checks.push({ label: label, ok: !!ok, detail: detail }); };
		var fakeUser = 900000000 + Math.floor(Math.random() * 90000000);
		var te = new TextEncoder();
		var inst = null;
		try {
			var pair = await VaultCrypto.generateVaultKeypair();
			var session = {
				openSealed: function (blob) { return VaultCrypto.openFromSecretKey(blob, pair.secretKeyBytes, pair.publicKeyB64); }
			};
			var server = { key: null, rows: [], clock: 0, calls: [] };
			var stamp = function () {
				server.clock++;
				var s = String(server.clock);
				return '2026-01-01 00:00:' + ('00' + s).slice(-2) + '.' + ('000000' + s).slice(-6);
			};
			async function addMail(id, text) {
				var d = await VaultCrypto.newDek();
				var row = {
					id: id, time: stamp(),
					sealed: {
						key: id, sealed_scope: SCOPE, sealed_ad_prefix: 'mail:',
						sealed_dek: EDGE_SEAL + await VaultCrypto.sealToPublicKey(d.dekBytes, pair.publicKeyB64),
						iem_search_text: EDGE_FIELD + await VaultCrypto.encrypt(text, d.dekKey, 'mail:' + id + ':iem_search_text')
					}
				};
				d.dekBytes.fill(0);
				server.rows.push(row);
			}
			var cmp = function (a, b) { return a.time !== b.time ? (a.time < b.time ? -1 : 1) : a.id - b.id; };
			var post = async function (action, body) {
				server.calls.push(action + ':' + (body.order || body.op || ''));
				if (action === 'mailbox/search_key') {
					if (body.op === 'create') {
						if (server.key) throw new Error('Your search key is already set up.');
						server.key = body.sealed_key;
					}
					return { set_up: !!server.key, sealed_key: server.key, user_id: fakeUser };
				}
				if (action === 'mailbox/search_entries') {
					var limit = 2;
					var all = server.rows.slice().sort(cmp);
					var picked;
					if (body.order === 'old') {
						var before = body.time ? { time: body.time, id: body.id || Infinity } : null;
						picked = all.filter(function (r) { return !before || cmp(r, before) < 0; }).reverse();
					} else {
						var since = { time: body.time || '', id: body.id || 0 };
						picked = all.filter(function (r) { return body.overlap ? r.time > '' : cmp(r, since) > 0; });
					}
					var pageRows = picked.slice(0, limit);
					var more = picked.length > limit;
					var last = pageRows.length ? pageRows[pageRows.length - 1] : null;
					return {
						entries: pageRows.map(function (r) { return { id: r.id, sealed: r.sealed }; }),
						next: more && last ? { time: last.time, id: last.id } : null,
						last: last ? { time: last.time, id: last.id } : null,
						server_time: '2026-01-01 00:00:59.000000',
						total: body.with_total ? server.rows.length : undefined
					};
				}
				throw new Error('unexpected ' + action);
			};
			var deps = {
				post: post,
				isOpen: function () { return true; },
				session: function () { return Promise.resolve(session); },
				publicKey: function () { return Promise.resolve(pair.publicKeyB64); },
				inflate: function (t) { return Promise.resolve(t); },
				workerUrl: cfg().searchWorkerUrl, coreUrl: cfg().searchCoreUrl,
				buildPages: 2, catchupBatch: 5000
			};
			var unpack = function (packed) {
				var s = atob(packed || ''), ids = [], v = 0, mul = 1, prev = 0;
				for (var i = 0; i < s.length; i++) {
					var b = s.charCodeAt(i);
					v += (b & 127) * mul; mul *= 128;
					if (!(b & 128)) { prev += v; ids.push(prev); v = 0; mul = 1; }
				}
				return ids;
			};

			for (var i = 1; i <= 7; i++) await addMail(100 + i, 'Message ' + i + ' about ' + (i % 2 ? 'otters' : 'badgers') + (i === 3 ? ' and a quokka invoice' : ''));

			// Another tab holds the build: this one searches what there is and
			// says nothing until something changes.
			var release;
			var held = new Promise(function (res) { release = res; });
			var got = new Promise(function (res) {
				navigator.locks.request('jy_mailsearch_build_' + fakeUser, function () { res(); return held; });
			});
			await got;
			inst = create(deps);
			var notices = 0;
			inst.onStatus(function () { notices++; });
			var r = await inst.hits('otters');
			await new Promise(function (res) { setTimeout(res, 300); });
			note('a first search answers', r && typeof r.packed === 'string', r && r.error);
			note('while another tab builds, a search here gives no notice (the reader\'s refresh cannot loop)', notices === 0, notices + ' notices');
			release();
			await inst.hits('otters');
			await inst.settled();
			r = await inst.hits('otters');
			note('after the build, a word finds every message holding it', JSON.stringify(unpack(r.packed)) === '[101,103,105,107]', JSON.stringify(unpack(r.packed)));
			r = await inst.hits('QUOKKA otters');
			note('every word must be present, case aside', JSON.stringify(unpack(r.packed)) === '[103]', JSON.stringify(unpack(r.packed)));
			note('the build is done and counted', inst.status().indexed === 7 && !inst.status().building, JSON.stringify(inst.status()));
			note('the search key was made once and sealed to the vault', server.key && server.key.indexOf(EDGE_SEAL) === 0);
			var seqBefore = (await inst.hits('otters')).status && inst.seq();
			await inst.hits('badgers');
			note('a search with nothing new saves nothing', inst.seq() === seqBefore, seqBefore + ' / ' + inst.seq());

			inst.stop();
			await addMail(200, 'A new otters message');
			inst = create(deps);
			server.calls = [];
			r = await inst.hits('otters');
			note('a new instance opens the saved index without rebuilding', server.calls.indexOf('mailbox/search_entries:old') === -1, server.calls.join(' '));
			note('and catches up with new mail', JSON.stringify(unpack(r.packed)) === '[101,103,105,107,200]', JSON.stringify(unpack(r.packed)));

			// A second tab on the same index, both catching up at once.
			await addMail(201, 'Otters again');
			var tab2 = create(deps);
			var both = await Promise.all([inst.hits('otters'), tab2.hits('otters')]);
			var want = '[101,103,105,107,200,201]';
			note('two tabs catching up at once agree', JSON.stringify(unpack(both[0].packed)) === want
				&& JSON.stringify(unpack(both[1].packed)) === want,
				JSON.stringify(unpack(both[0].packed)) + ' / ' + JSON.stringify(unpack(both[1].packed)));
			await addMail(202, 'Otters a third time');
			r = await tab2.hits('otters');
			var r1 = await inst.hits('otters');
			note('and each sees what the other saved', JSON.stringify(unpack(r1.packed)) === '[101,103,105,107,200,201,202]'
				&& inst.status().indexed === 10, JSON.stringify(unpack(r1.packed)) + ' ' + inst.status().indexed);
			tab2.stop();

			var wrongKey = server.key;
			inst.stop();
			server.key = null;   // the search key replaced: the saved index must be dropped, not misread
			inst = create(deps);
			r = await inst.hits('otters');
			await inst.settled();
			r = await inst.hits('otters');
			note('a different search key clears the index and builds again', server.key !== wrongKey
				&& JSON.stringify(unpack(r.packed)) === '[101,103,105,107,200,201,202]', JSON.stringify(unpack(r.packed)));

			await inst.rebuild();
			await inst.settled();
			r = await inst.hits('badgers');
			note('rebuild starts over and finds the same mail', JSON.stringify(unpack(r.packed)) === '[102,104,106]', JSON.stringify(unpack(r.packed)));

			await inst.remove();
			inst = create(deps);
			server.calls = [];
			await inst.hits('badgers');
			await inst.settled();
			note('after remove, the next search here builds again', server.calls.indexOf('mailbox/search_entries:old') !== -1, server.calls.join(' '));
		} catch (e) {
			note('the self-check ran to the end', false, (e && e.message) || String(e));
		} finally {
			if (inst) inst.stop();
			try { indexedDB.deleteDatabase('jy_mailsearch_' + fakeUser); } catch (e) { /* best effort */ }
		}
		return { ok: checks.every(function (c) { return c.ok; }), checks: checks };
	}

	var main = create(realDeps());
	if (window.MailboxFortress) {
		MailboxFortress.onLock(function () { main.stop(); });
	}
	main.selfCheck = selfCheck;
	return main;
})();
