/**
 * The search worker: keeps this browser's sealed search index over the
 * person's end-to-end (Fortress) mail (specs/client_custody_mail.md § R5).
 *
 * The page (mailbox_search.js) holds the mail vault. It opens each message's
 * search text and posts the text here; this worker splits it into words,
 * builds and merges the index (MailboxSearchCore), seals every record and
 * keeps them in IndexedDB, and answers searches. Nothing here can open mail:
 * the only key it holds is the record key the page derived from the search key
 * (non-extractable), which ends with the worker when the vault locks.
 *
 * Storage: database jy_mailsearch_{user id}, one object store `records`:
 *   head          {format, fp} in the clear: a different key or format clears
 *                 the database (a rebuild, never an error)
 *   meta          {seq, doc_count, catchup, backfill, backfill_done,
 *                 build_started, total}
 *   ids           message id per doc
 *   tail          the words of messages not merged into the shards yet
 *   shard:00..ff  the shards
 * Each sealed record is gzip, then AES-256-GCM under the record key with AD
 * mailsearch:{user id}:{record}:{format}. One add writes every record it
 * changed in one transaction, so what is saved is always one consistent index.
 *
 * Two tabs share one database: every operation runs under a Web Lock
 * (exclusive to write, shared to search) and first reloads the index if the
 * other tab saved since (meta.seq, a random stamp per save). Cursors only move forward, so a tab behind
 * another never takes the index back.
 *
 * @version 1.1 - an add that changes nothing saves nothing (review of 2026-09-27, B2)
 * @version 1.0
 */
'use strict';

var C = null;            // MailboxSearchCore, loaded by init
var key = null;          // the record key (AES-GCM, non-extractable)
var userId = 0;
var fp = '';
var db = null;
var index = null;
var meta = null;
var cache = new Map();   // shard number -> opened bytes, least recently used first
var CACHE_MAX = 32;
var MERGE_AT = 2000;     // messages in the tail before an add merges it into the shards
var enc = new TextEncoder();

self.onmessage = function (e) {
	var m = e.data || {};
	var run = chain.then(function () { return handle(m); });
	chain = run.catch(function () {});
	run.then(function (result) {
		self.postMessage({ id: m.id, ok: true, result: result });
	}, function (err) {
		self.postMessage({ id: m.id, ok: false, error: String((err && err.message) || err), storage: !!(err && err.storage) });
	});
};
var chain = Promise.resolve();

function handle(m) {
	switch (m.op) {
		case 'init': return init(m);
		case 'add': return locked('exclusive', function () { return add(m.entries || [], m.cursor || {}); });
		case 'search': return locked('shared', function () { return search(String(m.q || '')); });
		case 'forget': return locked('exclusive', forget);
		default: return Promise.reject(new Error('Unknown search operation.'));
	}
}

function lockName() { return 'jy_mailsearch_' + userId; }

function withLock(mode, fn) {
	if (!self.navigator || !navigator.locks) return Promise.resolve().then(fn);
	return navigator.locks.request(lockName(), { mode: mode }, fn);
}

// Under the lock, with the index as saved (another tab may have saved since).
function locked(mode, fn) {
	return withLock(mode, function () { return freshen().then(fn); });
}

// ---- storage -------------------------------------------------------------------

function storageError(e) {
	var err = new Error((e && e.message) || 'This browser would not keep the search index.');
	err.storage = true;
	return err;
}

function req(r) {
	return new Promise(function (resolve, reject) {
		r.onsuccess = function () { resolve(r.result); };
		r.onerror = function () { reject(storageError(r.error)); };
	});
}

function openDb() {
	return new Promise(function (resolve, reject) {
		var r;
		try { r = indexedDB.open('jy_mailsearch_' + userId, 1); } catch (e) { reject(storageError(e)); return; }
		r.onupgradeneeded = function () { r.result.createObjectStore('records'); };
		r.onsuccess = function () { resolve(r.result); };
		r.onerror = function () { reject(storageError(r.error)); };
		r.onblocked = function () { reject(storageError(new Error('The search index is busy in another tab.'))); };
	});
}

function getRecord(name) {
	return req(db.transaction('records', 'readonly').objectStore('records').get(name));
}

function writeRecords(records, clear) {
	return new Promise(function (resolve, reject) {
		var tx;
		try { tx = db.transaction('records', 'readwrite'); } catch (e) { reject(storageError(e)); return; }
		var store = tx.objectStore('records');
		if (clear) store.clear();
		records.forEach(function (value, name) { store.put(value, name); });
		tx.oncomplete = function () { resolve(); };
		tx.onerror = tx.onabort = function () { reject(storageError(tx.error)); };
	});
}

async function gzip(bytes) {
	var s = new Blob([bytes]).stream().pipeThrough(new CompressionStream('gzip'));
	return new Uint8Array(await new Response(s).arrayBuffer());
}

async function gunzip(bytes) {
	var s = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('gzip'));
	return new Uint8Array(await new Response(s).arrayBuffer());
}

function ad(name) { return enc.encode('mailsearch:' + userId + ':' + name + ':' + C.FORMAT); }

async function seal(name, bytes) {
	var iv = crypto.getRandomValues(new Uint8Array(12));
	var ct = await crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv, additionalData: ad(name) }, key, await gzip(bytes));
	return { iv: iv, ct: ct };
}

async function unseal(name, rec) {
	if (!rec) return null;
	var pt = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: rec.iv, additionalData: ad(name) }, key, rec.ct);
	return gunzip(new Uint8Array(pt));
}

function shardName(k) { return 'shard:' + (k < 16 ? '0' : '') + k.toString(16); }

function remember(k, bytes) {
	cache.delete(k);
	cache.set(k, bytes);
	while (cache.size > CACHE_MAX) cache.delete(cache.keys().next().value);
}

// Shards for MailboxSearchCore.Index: read through the cache; a put seals the
// shard into `pending`, which the add writes with everything else it changed.
var pending = null;
var store = {
	get: async function (k) {
		if (cache.has(k)) {
			var hit = cache.get(k);
			remember(k, hit);
			return hit;
		}
		var bytes = await unseal(shardName(k), await getRecord(shardName(k)));
		if (bytes) remember(k, bytes);
		return bytes;
	},
	put: async function (k, bytes) {
		remember(k, bytes);
		pending.set(shardName(k), await seal(shardName(k), bytes));
	}
};

// ---- the index -------------------------------------------------------------------

// A random stamp per save, not a counter: after one tab clears the index and
// saves again, a counter could land on the number another tab still holds.
function newSeq() {
	return Array.prototype.map.call(crypto.getRandomValues(new Uint8Array(8)),
		function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
}

function freshMeta() {
	return { seq: '', doc_count: 0, catchup: null, backfill: null, backfill_done: false, build_started: null, total: 0 };
}

async function readMeta() {
	var bytes = await unseal('meta', await getRecord('meta'));
	return bytes ? JSON.parse(new TextDecoder().decode(bytes)) : null;
}

async function loadAll() {
	var m = await readMeta();
	if (!m) {
		meta = freshMeta();
		index = new C.Index();
	} else {
		var ids = await unseal('ids', await getRecord('ids'));
		var tail = await unseal('tail', await getRecord('tail'));
		meta = m;
		index = new C.Index(ids ? C.decodeIds(ids) : [], tail ? C.shardToMap(tail) : new Map());
	}
	cache.clear();
}

// Another tab may have saved since this one last looked.
async function freshen() {
	if (!index) { await loadAll(); return; }
	var m = await readMeta();
	if (!m || m.seq !== meta.seq) await loadAll();
}

async function init(m) {
	if (!C) {
		importScripts(m.coreUrl);
		C = self.MailboxSearchCore;
	}
	key = m.key;
	fp = String(m.fp || '');
	userId = parseInt(m.userId, 10) || 0;
	if (!userId || !key) throw new Error('The search index needs its key.');
	db = await openDb();
	// Not locked(): the saved records may be sealed under another key, which is
	// exactly what this checks before anything tries to open them.
	return withLock('exclusive', async function () {
		var head = await getRecord('head');
		var wrong = !head || head.format !== C.FORMAT || head.fp !== fp;
		if (!wrong) {
			try { await loadAll(); } catch (e) { if (e && e.storage) throw e; wrong = true; }
		}
		if (wrong) {
			// Another key, another format, or records that will not open: this
			// browser's index is a cache of the mail, so it starts again.
			await writeRecords(new Map([['head', { format: C.FORMAT, fp: fp }]]), true);
			meta = freshMeta();
			index = new C.Index();
			cache.clear();
		}
		return publicMeta();
	});
}

function later(a, b) {   // is cursor a after cursor b?
	if (!b) return true;
	if (a.time !== b.time) return a.time > b.time;
	return a.id > b.id;
}

async function add(entries, cursor) {
	var changed = false;
	for (var i = 0; i < entries.length; i++) {
		var e = entries[i];
		if (e && e.id && index.add(Number(e.id), String(e.text || ''))) changed = true;
	}
	// Cursors only move forward: catch-up later, the first build earlier.
	if (cursor.catchup && later(cursor.catchup, meta.catchup)) { meta.catchup = cursor.catchup; changed = true; }
	if (cursor.backfill && (!meta.backfill || later(meta.backfill, cursor.backfill))) { meta.backfill = cursor.backfill; changed = true; }
	if (cursor.backfill_done && !meta.backfill_done) { meta.backfill_done = true; changed = true; }
	if (cursor.build_started && !meta.build_started) { meta.build_started = String(cursor.build_started); changed = true; }
	if (typeof cursor.total === 'number' && cursor.total !== meta.total) { meta.total = cursor.total; changed = true; }
	// A catch-up that found only what this index already holds (the overlap
	// re-reads the last ten minutes on every search) writes nothing: a save
	// rewrites every id, and makes every other tab reload.
	if (!changed) return publicMeta();

	pending = new Map();
	try {
		if (index.tailDocs >= MERGE_AT) await index.merge(store);
		meta.doc_count = index.ids.length;
		meta.seq = newSeq();
		pending.set('ids', await seal('ids', C.encodeIds(index.ids)));
		pending.set('tail', await seal('tail', C.mapToShard(index.tail)));
		pending.set('meta', await seal('meta', enc.encode(JSON.stringify(meta))));
		await writeRecords(pending, false);
	} catch (err) {
		// What is in memory is ahead of what is saved: read the saved index
		// back next time rather than build on a state that was never kept.
		index = null;
		cache.clear();
		throw err;
	} finally {
		pending = null;
	}
	return publicMeta();
}

async function search(q) {
	var ids = await index.search(store, q);
	return { packed: C.packIds(ids), count: ids.length, meta: publicMeta() };
}

async function forget() {
	await writeRecords(new Map([['head', { format: C.FORMAT, fp: fp }]]), true);
	meta = freshMeta();
	index = new C.Index();
	cache.clear();
	return publicMeta();
}

function publicMeta() {
	return {
		doc_count: meta.doc_count, catchup: meta.catchup, backfill: meta.backfill,
		backfill_done: !!meta.backfill_done, build_started: meta.build_started, total: meta.total || 0,
		seq: meta.seq || ''
	};
}
