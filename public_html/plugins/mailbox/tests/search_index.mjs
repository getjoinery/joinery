/**
 * Exercises MailboxSearchCore (plugins/mailbox/assets/mailbox_search_core.js),
 * the pure half of a browser's search index over Fortress mail
 * (specs/client_custody_mail.md § R5), under Node.
 *
 * What is under guard:
 *   - the tokenizer: case, diacritics, punctuation, the 40-character rule, a
 *     query word that splits;
 *   - one word's docs: list and bitmap round trips, the smaller one chosen,
 *     extending a posting equals encoding the whole list;
 *   - a merged index is byte-for-byte a build from scratch, whatever the
 *     merge schedule, and every query answers what a brute-force scan does;
 *   - the id encodings, and the device_hits vector PHP decodes
 *     (fixtures/device_hits_vector.json, read by fortress_search_test.php);
 *   - the benchmark: 100,000 synthetic messages at the 32 KB search-text cap,
 *     saved (gzip) size at most 60 MB and a two-word query under 50 ms (best
 *     of three). The numbers are printed. SEARCH_BENCH_MESSAGES overrides the
 *     count.
 *
 * @version 1.0
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import { gzipSync } from 'node:zlib';

const here = dirname(fileURLToPath(import.meta.url));
const require = createRequire(import.meta.url);
const C = require(join(here, '..', 'assets', 'mailbox_search_core.js'));

let passed = 0, failed = 0;
function check(ok, label, detail) {
	if (ok) { passed++; console.log('  PASS: ' + label); }
	else { failed++; console.log('  FAIL: ' + label + (detail !== undefined ? ' (' + detail + ')' : '')); }
}
function section(t) { console.log('\n== ' + t + ' =='); }
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const vlen = (v) => { let n = 1; while (v >= 128) { v = Math.floor(v / 128); n++; } return n; };

// A deterministic generator, so a failure reproduces.
function rng(seed) {
	let s = seed >>> 0;
	return () => { s = (s + 0x6D2B79F5) >>> 0; let t = s; t = Math.imul(t ^ (t >>> 15), t | 1); t ^= t + Math.imul(t ^ (t >>> 7), t | 61); return ((t ^ (t >>> 14)) >>> 0) / 4294967296; };
}

function memStore() {
	const m = new Map();
	return { m, get: async (k) => m.get(k) || null, put: async (k, b) => { m.set(k, b); } };
}

// ---- tokenizer -------------------------------------------------------------------
section('Tokenizer');
check(same([...C.words('Hello, WORLD! hello')], ['hello', 'world']), 'lower case, punctuation splits, one entry per word');
check(same([...C.words('Café naïve résumé')], ['cafe', 'naive', 'resume']), 'diacritics removed');
check(same([...C.words('order #12345 e-mail')], ['order', '12345', 'e', 'mail']), 'numbers are words; a hyphen splits');
check(same([...C.words('Straße Ελληνικά 日本語')].length, 3), 'letters of any script are words');
const long = 'a'.repeat(41);
check(!C.words('x ' + long + ' ' + 'b'.repeat(40)).has(long) && C.words('b'.repeat(40)).has('b'.repeat(40)),
	'a word of 41 characters is not indexed; 40 is');
check(same(C.queryWords('E-mail  e-MAIL invoice'), ['e', 'mail', 'invoice']), 'a query word that splits needs every piece');

// ---- postings --------------------------------------------------------------------
section('One word\'s docs');
{
	const r = rng(7);
	let allRound = true, smaller = true, extendSame = true;
	for (let t = 0; t < 300; t++) {
		const density = [0.001, 0.01, 0.1, 0.3, 0.9][t % 5];
		const docs = [];
		for (let d = 0; d < 3000; d++) if (r() < density) docs.push(d);
		if (!docs.length) docs.push(5);
		const p = C.posting(docs);
		if (!same(C.docsOf(p), docs)) allRound = false;
		// The chosen encoding is never larger than the other.
		const bitmapLen = (docs[docs.length - 1] >> 3) + 1;
		const listLen = docs.reduce((n, d, i) => n + vlen(i === 0 ? d : d - docs[i - 1]), 0);
		if (p.bytes.length !== Math.min(bitmapLen, listLen) || (p.kind === 1) !== (bitmapLen < listLen)) smaller = false;
		const cut = Math.floor(docs.length / 2) || 1;
		const ext = C.extend(C.posting(docs.slice(0, cut)), docs.slice(cut));
		if (docs.length > cut && !(ext.kind === p.kind && same(Array.from(ext.bytes), Array.from(p.bytes)))) extendSame = false;
		if (C.countOf(p) !== docs.length || C.lastOf(p) !== docs[docs.length - 1]) allRound = false;
	}
	check(allRound, 'lists and bitmaps give back their docs (300 random densities)');
	check(smaller, 'the smaller encoding is chosen');
	check(extendSame, 'extending a posting equals encoding the whole list');
	const dense = C.posting([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15]);
	check(dense.kind === 1 && dense.bytes.length === 2, 'a dense word is a bitmap', dense.kind + '/' + dense.bytes.length);
	const sparse = C.posting([3, 900, 2000]);
	check(sparse.kind === 0, 'a sparse word is a list');
}

// ---- ids -------------------------------------------------------------------------
section('Ids');
{
	const ids = [5000000, 4999999, 4999990, 12, 13, 9007199254740, 1];
	check(same(C.decodeIds(C.encodeIds(ids)), ids), 'ids in any order round-trip');
	const vector = JSON.parse(readFileSync(join(here, 'fixtures', 'device_hits_vector.json'), 'utf8'));
	check(C.packIds(vector.ids) === vector.packed, 'packIds matches the vector PHP decodes', C.packIds(vector.ids));
}

// ---- a corpus ----------------------------------------------------------------------
function makeCorpus(n, seed, opts) {
	const r = rng(seed);
	const vocab = opts.vocab;
	const zipf = [];   // cumulative weights for ranks 1..vocab, s = 1.05
	let sum = 0;
	for (let k = 1; k <= vocab; k++) { sum += 1 / Math.pow(k, 1.05); zipf.push(sum); }
	const pick = () => {
		const x = r() * sum;
		let lo = 0, hi = zipf.length - 1;
		while (lo < hi) { const mid = (lo + hi) >> 1; if (zipf[mid] < x) lo = mid + 1; else hi = mid; }
		return 'w' + lo.toString(36);
	};
	return function* () {
		for (let i = 0; i < n; i++) {
			// Word counts log-normal around opts.median, cut at the 32 KB cap (~5,000 words).
			const g = Math.sqrt(-2 * Math.log(r() || 1e-9)) * Math.cos(2 * Math.PI * r());
			const nw = Math.min(opts.maxWords, Math.max(3, Math.round(opts.median * Math.exp(0.9 * g))));
			const parts = new Array(nw);
			for (let j = 0; j < nw; j++) {
				const u = r();
				if (u < opts.unique) parts[j] = 'u' + Math.floor(r() * 1e12).toString(36);   // names, numbers, one-offs
				else if (u < opts.unique + opts.junk) parts[j] = 'x' + Math.floor(r() * 1e15).toString(36).repeat(4);   // tracking codes
				else parts[j] = pick();
			}
			yield { id: 1000 + i * 3, text: parts.join(' ') };
		}
	};
}

function brute(docs, q) {
	const qw = C.queryWords(q);
	return docs.filter((d) => qw.every((w) => d.words.has(w))).map((d) => d.id).sort((a, b) => a - b);
}

// ---- merge schedules and brute force ------------------------------------------------
section('Index against a brute-force scan');
{
	const gen = makeCorpus(3000, 11, { vocab: 5000, median: 60, maxWords: 800, unique: 0.03, junk: 0.005 });
	const docs = [...gen()].map((m) => ({ id: m.id, text: m.text, words: C.words(m.text) }));
	const once = new C.Index(), onceStore = memStore();
	docs.forEach((d) => once.add(d.id, d.text));
	await once.merge(onceStore);
	const inc = new C.Index(), incStore = memStore();
	for (let i = 0; i < docs.length; i++) {
		inc.add(docs[i].id, docs[i].text);
		if (i % 137 === 136) await inc.merge(incStore);
	}
	await inc.merge(incStore);
	let bytesSame = true;
	for (let k = 0; k < C.SHARDS; k++) {
		const a = onceStore.m.get(k), b = incStore.m.get(k);
		if (!a !== !b || (a && !same(Array.from(a), Array.from(b)))) bytesSame = false;
	}
	check(bytesSame, 'merging every 137 messages writes the same shards as one merge');
	check(inc.add(docs[5].id, 'anything') === false, 'a message already indexed is not indexed again');

	const r = rng(3);
	const queries = ['w0', 'w1 w2', 'w3 w0 w1', 'nothing-here', 'w' + (400).toString(36), docs[10].text.split(' ')[0]];
	for (let i = 0; i < 40; i++) {
		const d = docs[Math.floor(r() * docs.length)];
		const ws = [...d.words];
		queries.push(ws[Math.floor(r() * ws.length)] + ' ' + ws[Math.floor(r() * ws.length)]);
	}
	let allSame = true, tailSame = true, firstBad = null;
	for (const q of queries) {
		const want = brute(docs, q);
		if (!same(await inc.search(incStore, q), want)) { allSame = false; firstBad = firstBad || q; }
	}
	check(allSame, 'every query answers what a scan does (' + queries.length + ' queries)', firstBad);
	// Half merged, half still in the tail.
	const half = new C.Index(), halfStore = memStore();
	docs.slice(0, 1500).forEach((d) => half.add(d.id, d.text));
	await half.merge(halfStore);
	docs.slice(1500).forEach((d) => half.add(d.id, d.text));
	for (const q of queries) {
		if (!same(await half.search(halfStore, q), brute(docs, q))) { tailSame = false; firstBad = q; }
	}
	check(tailSame, 'a search sees the shards and the unmerged tail together', firstBad);
	const tailBack = C.shardToMap(C.mapToShard(half.tail));
	let tailRound = tailBack.size === half.tail.size;
	half.tail.forEach((v, k) => { if (!same(tailBack.get(k), v)) tailRound = false; });
	check(tailRound, 'the tail saves and loads whole');
	const reopened = new C.Index(C.decodeIds(C.encodeIds(half.ids)), tailBack);
	check(reopened.tailDocs === 1500, 'a reopened index knows how many messages its tail holds', reopened.tailDocs);
}

// ---- the benchmark -----------------------------------------------------------------
section('Benchmark');
{
	const N = parseInt(process.env.SEARCH_BENCH_MESSAGES || '100000', 10);
	const gen = makeCorpus(N, 99, { vocab: 400000, median: 350, maxWords: 5000, unique: 0.04, junk: 0.01 });
	const idx = new C.Index(), store = memStore();
	const t0 = Date.now();
	let n = 0, textBytes = 0;
	for (const m of gen()) {
		idx.add(m.id, m.text);
		textBytes += m.text.length;
		if (++n % 5000 === 0) await idx.merge(store);
	}
	await idx.merge(store);
	const buildMs = Date.now() - t0;
	let raw = 0, saved = 0;
	for (const [, b] of store.m) {
		raw += b.length;
		saved += gzipSync(b).length + 28;   // + IV and GCM tag per record
	}
	const idsBytes = gzipSync(C.encodeIds(idx.ids)).length + 28;
	saved += idsBytes;
	const MB = (x) => (x / 1048576).toFixed(1) + ' MB';
	console.log('  messages ' + N + ', search text ' + MB(textBytes) + ', build ' + (buildMs / 1000).toFixed(1) + ' s');
	console.log('  shards raw ' + MB(raw) + ', saved (gzip + seal, ids included) ' + MB(saved));
	const perHundredK = saved * 100000 / N;
	check(perHundredK <= 60 * 1048576, 'saved size at most 60 MB per 100,000 messages', MB(perHundredK) + ' per 100k');

	const qs = ['w1 w2', 'w5 w9', 'w0 w3', 'w10 wa0', 'w2 w7'];
	let worst = 0;
	for (const q of qs) {
		// Best of three: the gate shares the machine with the rest of the run.
		let best = Infinity;
		for (let k = 0; k < 3; k++) {
			const s = Date.now();
			await idx.search(store, q);
			best = Math.min(best, Date.now() - s);
		}
		worst = Math.max(worst, best);
	}
	console.log('  slowest two-word query ' + worst + ' ms');
	check(worst < 50, 'a two-word query under 50 ms', worst + ' ms');
}

console.log('');
if (failed === 0) {
	console.log('RESULT: PASS ' + passed + ' ' + failed);
	process.exit(0);
}
console.log('RESULT: FAIL ' + passed + ' ' + failed);
process.exit(1);
