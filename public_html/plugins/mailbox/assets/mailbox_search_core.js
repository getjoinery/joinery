/**
 * MailboxSearchCore - the pure half of a browser's search index over
 * end-to-end (Fortress) mail (specs/client_custody_mail.md § R5). No keys, no
 * storage, no network: the worker (mailbox_search_worker.js) supplies those
 * through a shard store, and node runs the same code in
 * plugins/mailbox/tests/search_index.mjs.
 *
 * The shape is the server's Private index (MailboxIndex § SHAPE): a word maps to
 * the messages that contain it, and nothing else. No text, no positions; a query
 * is whole words that must all be present. On top of that:
 *
 *   - Messages are numbered 0, 1, 2… in the order this browser indexed them
 *     ("docs"); `ids` maps a doc back to its message id. A word's docs are
 *     ascending, so each is stored as its gap from the one before, in unsigned
 *     LEB128: mostly one byte.
 *   - Per word, a bitmap instead when that is smaller (a word in more than
 *     about one message in eight).
 *   - Words longer than MAX_WORD characters are not indexed.
 *   - Words live in SHARDS shards by FNV-1a of their UTF-8 bytes; a query opens
 *     one shard per word. New messages go to a tail (in memory, saved whole)
 *     until it is merged into the shards.
 *
 * Shard (and tail) bytes: varint word count, then per word in UTF-8 byte order:
 * varint bytes shared with the word before, varint length of the rest, the
 * rest, varint (posting length * 2 + kind: 0 list, 1 bitmap), the posting. A
 * list is the first doc then the gaps; a bitmap has bit (d & 7) of byte
 * (d >> 3) set per doc d. A posting's count and last doc are read from it.
 *
 * The tokenizer mirrors SQLite FTS5 unicode61 with remove_diacritics, which
 * the Private index uses: NFKD, combining marks dropped, lower case, split on
 * anything that is not a letter or a number.
 *
 * @version 1.0
 */
(function (root) {
	'use strict';

	var FORMAT = 1;
	var MAX_WORD = 40;
	var SHARDS = 256;
	var LIST = 0, BITMAP = 1;

	var SPLIT = /[^\p{L}\p{N}]+/u;
	var MARKS = /\p{M}+/gu;
	var utf8 = new TextEncoder();
	var fromUtf8 = new TextDecoder();

	// ---- words -------------------------------------------------------------------

	function tokens(text) {
		return String(text || '').normalize('NFKD').replace(MARKS, '').toLowerCase().split(SPLIT);
	}

	/** The distinct words of a message worth indexing. */
	function words(text) {
		var out = new Set();
		var parts = tokens(text);
		for (var i = 0; i < parts.length; i++) {
			var w = parts[i];
			if (w !== '' && w.length <= MAX_WORD) out.add(w);
		}
		return out;
	}

	/** A query's distinct words, long ones kept (they match nothing). */
	function queryWords(q) {
		var out = [];
		var seen = new Set();
		var parts = tokens(q);
		for (var i = 0; i < parts.length; i++) {
			var w = parts[i];
			if (w !== '' && !seen.has(w)) { seen.add(w); out.push(w); }
		}
		return out;
	}

	function fnv1a(bytes) {
		var h = 0x811c9dc5;
		for (var i = 0; i < bytes.length; i++) {
			h ^= bytes[i];
			h = Math.imul(h, 0x01000193) >>> 0;
		}
		return h >>> 0;
	}

	function shardOf(wordBytes) { return fnv1a(wordBytes) % SHARDS; }

	function compareBytes(a, b) {
		var n = Math.min(a.length, b.length);
		for (var i = 0; i < n; i++) {
			if (a[i] !== b[i]) return a[i] - b[i];
		}
		return a.length - b.length;
	}

	// ---- bytes -------------------------------------------------------------------

	function Out(size) { this.buf = new Uint8Array(size || 1024); this.n = 0; }
	Out.prototype.room = function (k) {
		if (this.n + k <= this.buf.length) return;
		var next = new Uint8Array(Math.max(this.buf.length * 2, this.n + k));
		next.set(this.buf.subarray(0, this.n));
		this.buf = next;
	};
	Out.prototype.byte = function (b) { this.room(1); this.buf[this.n++] = b; };
	// Values up to 2^53: arithmetic, not bit operators, which work on 32 bits.
	Out.prototype.varint = function (v) {
		this.room(8);
		while (v >= 128) { this.buf[this.n++] = (v % 128) + 128; v = Math.floor(v / 128); }
		this.buf[this.n++] = v;
	};
	Out.prototype.bytes = function (b) { this.room(b.length); this.buf.set(b, this.n); this.n += b.length; };
	Out.prototype.done = function () { return this.buf.slice(0, this.n); };

	function In(buf) { this.buf = buf; this.p = 0; }
	In.prototype.varint = function () {
		var v = 0, mul = 1, b;
		do {
			if (this.p >= this.buf.length) throw new Error('A search index record is cut short.');
			b = this.buf[this.p++];
			v += (b & 127) * mul;
			mul *= 128;
		} while (b & 128);
		return v;
	};
	In.prototype.byte = function () {
		if (this.p >= this.buf.length) throw new Error('A search index record is cut short.');
		return this.buf[this.p++];
	};
	In.prototype.take = function (n) {
		if (this.p + n > this.buf.length) throw new Error('A search index record is cut short.');
		var s = this.buf.subarray(this.p, this.p + n);
		this.p += n;
		return s;
	};

	function varintLength(v) {
		var n = 1;
		while (v >= 128) { v = Math.floor(v / 128); n++; }
		return n;
	}

	// ---- one word's docs ---------------------------------------------------------

	// A posting is {kind, bytes}. Its count and last doc are read from the bytes
	// when needed, not stored: most words are in one message, and every stored
	// byte is paid per word.

	function bitmapLength(last) { return (last >> 3) + 1; }

	function encodeList(docs, prev, out) {
		for (var i = 0; i < docs.length; i++) {
			out.varint(prev < 0 ? docs[i] : docs[i] - prev);
			prev = docs[i];
		}
	}

	function listBytes(docs) {
		var out = new Out(docs.length + 8);
		encodeList(docs, -1, out);
		return out.done();
	}

	function bitmapBytes(docs, last) {
		var b = new Uint8Array(bitmapLength(last));
		for (var i = 0; i < docs.length; i++) b[docs[i] >> 3] |= 1 << (docs[i] & 7);
		return b;
	}

	/** The smaller encoding of an ascending doc list; a tie goes to the list. */
	function posting(docs) {
		var last = docs[docs.length - 1];
		var list = listBytes(docs);
		if (bitmapLength(last) < list.length) return { kind: BITMAP, bytes: bitmapBytes(docs, last) };
		return { kind: LIST, bytes: list };
	}

	function docsOf(p) {
		var out = [];
		var b = p.bytes;
		if (p.kind === LIST) {
			var r = new In(b);
			var d = -1;
			while (r.p < b.length) {
				d = (d < 0) ? r.varint() : d + r.varint();
				out.push(d);
			}
			return out;
		}
		for (var byte = 0; byte < b.length; byte++) {
			var v = b[byte];
			if (!v) continue;
			for (var bit = 0; bit < 8; bit++) {
				if (v & (1 << bit)) out.push((byte << 3) + bit);
			}
		}
		return out;
	}

	function countOf(p) {
		var b = p.bytes, n = 0, i;
		if (p.kind === LIST) {
			for (i = 0; i < b.length; i++) if (b[i] < 128) n++;
			return n;
		}
		for (i = 0; i < b.length; i++) { var v = b[i]; while (v) { n += v & 1; v >>= 1; } }
		return n;
	}

	function lastOf(p) {
		if (p.kind === BITMAP) {
			var b = p.bytes;
			for (var byte = b.length - 1; byte >= 0; byte--) {
				if (b[byte]) return (byte << 3) + (31 - Math.clz32(b[byte]));
			}
			return -1;
		}
		var r = new In(p.bytes), d = -1;
		while (r.p < p.bytes.length) d = (d < 0) ? r.varint() : d + r.varint();
		return d;
	}

	/**
	 * $p with more docs appended; every one of `more` is past p's last, ascending.
	 * The same choice posting() makes, so a merged shard is byte-for-byte the
	 * shard a build from scratch writes.
	 */
	function extend(p, more) {
		if (!p) return posting(more);
		var last = more[more.length - 1];
		if (p.kind === LIST) {
			var out = new Out(p.bytes.length + more.length + 8);
			out.bytes(p.bytes);
			encodeList(more, lastOf(p), out);
			var list = out.done();
			if (bitmapLength(last) < list.length) return { kind: BITMAP, bytes: bitmapBytes(docsOf(p).concat(more), last) };
			return { kind: LIST, bytes: list };
		}
		var b = new Uint8Array(bitmapLength(last));
		b.set(p.bytes);
		for (var i = 0; i < more.length; i++) b[more[i] >> 3] |= 1 << (more[i] & 7);
		// Gone sparse (a word common early and rare since): a list may be smaller now.
		if (countOf(p) + more.length <= b.length) {
			var asList = listBytes(docsOf(p).concat(more));
			if (asList.length <= b.length) return { kind: LIST, bytes: asList };
		}
		return { kind: BITMAP, bytes: b };
	}

	// ---- shards ------------------------------------------------------------------

	// A word is written as the bytes it shares with the word before it, then the
	// rest: sorted neighbours share most of their bytes.
	function shared(a, b) {
		var n = Math.min(a.length, b.length), i = 0;
		while (i < n && a[i] === b[i]) i++;
		return i;
	}

	function Writer() { this.out = new Out(1024); this.prev = new Uint8Array(0); this.words = 0; }
	Writer.prototype.entry = function (word, p) {
		var k = shared(this.prev, word);
		this.out.varint(k);
		this.out.varint(word.length - k);
		this.out.bytes(word.subarray(k));
		this.out.varint(p.bytes.length * 2 + p.kind);
		this.out.bytes(p.bytes);
		this.prev = word.slice();
		this.words++;
	};
	Writer.prototype.done = function () {
		var head = new Out(8);
		head.varint(this.words);
		var out = new Uint8Array(head.n + this.out.n);
		out.set(head.buf.subarray(0, head.n), 0);
		out.set(this.out.buf.subarray(0, this.out.n), head.n);
		return out;
	};

	function Reader(shard) {
		this.r = new In(shard);
		this.left = this.r.varint();
		this.buf = new Uint8Array(256);   // a word is at most MAX_WORD characters, 4 bytes each
		this.len = 0;
	}
	/**
	 * The next {word, p}, or null. `word` is a view of the reader's own buffer,
	 * valid until the next call: a lookup compares without copying.
	 */
	Reader.prototype.next = function () {
		if (this.left <= 0) return null;
		this.left--;
		var k = this.r.varint();
		var rest = this.r.take(this.r.varint());
		if (k + rest.length > this.buf.length) {
			var grown = new Uint8Array(k + rest.length);
			grown.set(this.buf.subarray(0, k));
			this.buf = grown;
		}
		this.buf.set(rest, k);
		this.len = k + rest.length;
		var lk = this.r.varint();
		return { word: this.buf.subarray(0, this.len), p: { kind: lk % 2, bytes: this.r.take(Math.floor(lk / 2)) } };
	};

	/**
	 * A shard with `adds` merged in: [{word: Uint8Array, docs: ascending}],
	 * sorted by word bytes, every doc past anything the shard holds for it.
	 * One pass over both; nothing is held per word.
	 */
	function mergeShard(shard, adds) {
		var w = new Writer();
		var rd = shard ? new Reader(shard) : null;
		var cur = rd ? rd.next() : null;
		var i = 0;
		while (cur || i < adds.length) {
			var c = !cur ? 1 : (i >= adds.length ? -1 : compareBytes(cur.word, adds[i].word));
			if (c < 0) {
				w.entry(cur.word, cur.p);
			} else if (c > 0) {
				w.entry(adds[i].word, posting(adds[i].docs));
				i++;
			} else {
				w.entry(cur.word, extend(cur.p, adds[i].docs));
				i++;
			}
			if (c <= 0) cur = rd.next();
		}
		return w.done();
	}

	/** One word's posting in a shard, or null. Words are sorted, so it stops early. */
	function findWord(shard, word) {
		if (!shard) return null;
		var rd = new Reader(shard);
		for (var e = rd.next(); e; e = rd.next()) {
			var c = compareBytes(e.word, word);
			if (c === 0) return e.p;
			if (c > 0) return null;
		}
		return null;
	}

	/** Every word of a shard (the tail), as a Map word -> docs. */
	function shardToMap(shard) {
		var m = new Map();
		if (!shard) return m;
		var rd = new Reader(shard);
		for (var e = rd.next(); e; e = rd.next()) m.set(fromUtf8.decode(e.word), docsOf(e.p));
		return m;
	}

	function sortedAdds(map) {
		var adds = [];
		map.forEach(function (docs, word) { adds.push({ word: utf8.encode(word), docs: docs }); });
		adds.sort(function (a, b) { return compareBytes(a.word, b.word); });
		return adds;
	}

	function mapToShard(map) { return mergeShard(null, sortedAdds(map)); }

	// ---- ids ---------------------------------------------------------------------

	// Doc order is not id order (a first build walks newest first, catch-up
	// oldest first), so each id is its signed difference from the one before,
	// zigzagged.
	function encodeIds(ids) {
		var out = new Out(ids.length * 2 + 8);
		out.varint(ids.length);
		var prev = 0;
		for (var i = 0; i < ids.length; i++) {
			var d = ids[i] - prev;
			out.varint(d >= 0 ? d * 2 : -d * 2 - 1);
			prev = ids[i];
		}
		return out.done();
	}

	function decodeIds(bytes) {
		var r = new In(bytes);
		var n = r.varint();
		var ids = new Array(n);
		var prev = 0;
		for (var i = 0; i < n; i++) {
			var z = r.varint();
			prev += (z % 2 === 0) ? z / 2 : -(z + 1) / 2;
			ids[i] = prev;
		}
		return ids;
	}

	/** Ascending message ids as base64 LEB128 gaps: what thread_list reads as device_hits. */
	function packIds(sorted) {
		var out = new Out(sorted.length * 3 + 8);
		var prev = 0;
		for (var i = 0; i < sorted.length; i++) {
			out.varint(sorted[i] - prev);
			prev = sorted[i];
		}
		var b = out.done();
		var s = '';
		for (var j = 0; j < b.length; j += 0x8000) {
			s += String.fromCharCode.apply(null, b.subarray(j, j + 0x8000));
		}
		return btoa(s);
	}

	// ---- the index ---------------------------------------------------------------

	/**
	 * The in-memory half of an index: ids and the tail. Shards live behind
	 * `store`: {get(k) -> Promise<Uint8Array|null>, put(k, bytes) -> Promise}.
	 */
	function Index(ids, tail) {
		this.ids = ids || [];
		this.known = new Set(this.ids);
		this.tail = tail || new Map();
		// The docs added since the last merge: every tail doc is one of them.
		var from = this.ids.length;
		this.tail.forEach(function (docs) { if (docs[0] < from) from = docs[0]; });
		this.tailDocs = this.ids.length - from;
	}

	Index.prototype.has = function (id) { return this.known.has(id); };

	/** Add one message; false when it is already indexed. */
	Index.prototype.add = function (id, text) {
		if (this.known.has(id)) return false;
		var doc = this.ids.length;
		this.ids.push(id);
		this.known.add(id);
		var tail = this.tail;
		words(text).forEach(function (w) {
			var docs = tail.get(w);
			if (docs) docs.push(doc); else tail.set(w, [doc]);
		});
		this.tailDocs++;
		return true;
	};

	/** Merge the tail into the shards it touches. */
	Index.prototype.merge = async function (store) {
		var groups = new Map();
		sortedAdds(this.tail).forEach(function (a) {
			var k = shardOf(a.word);
			var g = groups.get(k);
			if (g) g.push(a); else groups.set(k, [a]);
		});
		var keys = Array.from(groups.keys()).sort(function (a, b) { return a - b; });
		for (var i = 0; i < keys.length; i++) {
			var k = keys[i];
			await store.put(k, mergeShard(await store.get(k), groups.get(k)));
		}
		this.tail = new Map();
		this.tailDocs = 0;
		return keys;
	};

	/**
	 * The message ids holding every word of `q`, ascending. A message is wholly
	 * in the shards or wholly in the tail (its words move together at a merge),
	 * so each side is intersected on its own.
	 */
	Index.prototype.search = async function (store, q) {
		var qw = queryWords(q);
		if (!qw.length) return [];
		var postings = [], tails = [];
		for (var i = 0; i < qw.length; i++) {
			if (qw[i].length > MAX_WORD) return [];
			var wb = utf8.encode(qw[i]);
			postings.push(findWord(await store.get(shardOf(wb)), wb));
			tails.push(this.tail.get(qw[i]) || null);
		}
		var docs = intersectPostings(postings).concat(intersectLists(tails));
		var ids = this.ids;
		return docs.map(function (d) { return ids[d]; }).sort(function (a, b) { return a - b; });
	};

	// Lists: walk the shortest, keep what every other holds. Bitmaps: AND the
	// bytes first (the common words), then test the lists against the result.
	function intersectPostings(ps) {
		var lists = [], maps = [];
		for (var i = 0; i < ps.length; i++) {
			if (!ps[i]) return [];
			(ps[i].kind === BITMAP ? maps : lists).push(ps[i]);
		}
		var and = null;
		if (maps.length) {
			var len = Math.min.apply(null, maps.map(function (m) { return m.bytes.length; }));
			and = maps[0].bytes.slice(0, len);
			for (var j = 1; j < maps.length; j++) {
				var b = maps[j].bytes;
				for (var k = 0; k < len; k++) and[k] &= b[k];
			}
		}
		if (!lists.length) return docsOf({ kind: BITMAP, bytes: and });
		lists.sort(function (a, c) { return a.bytes.length - c.bytes.length; });
		var docs = docsOf(lists[0]);
		if (and) docs = docs.filter(function (d) { return (d >> 3) < and.length && (and[d >> 3] & (1 << (d & 7))) !== 0; });
		for (var n = 1; n < lists.length && docs.length; n++) {
			var other = new Set(docsOf(lists[n]));
			docs = docs.filter(function (d) { return other.has(d); });
		}
		return docs;
	}

	function intersectLists(ls) {
		for (var i = 0; i < ls.length; i++) if (!ls[i]) return [];
		var sorted = ls.slice().sort(function (a, b) { return a.length - b.length; });
		var docs = sorted[0].slice();
		for (var n = 1; n < sorted.length && docs.length; n++) {
			var other = new Set(sorted[n]);
			docs = docs.filter(function (d) { return other.has(d); });
		}
		return docs;
	}

	var api = {
		FORMAT: FORMAT, MAX_WORD: MAX_WORD, SHARDS: SHARDS,
		words: words, queryWords: queryWords, shardOf: shardOf, fnv1a: fnv1a,
		posting: posting, extend: extend, docsOf: docsOf, countOf: countOf, lastOf: lastOf,
		mergeShard: mergeShard, findWord: findWord, shardToMap: shardToMap, mapToShard: mapToShard,
		encodeIds: encodeIds, decodeIds: decodeIds, packIds: packIds,
		Index: Index,
		utf8: utf8
	};
	root.MailboxSearchCore = api;
	if (typeof module !== 'undefined' && module.exports) module.exports = api;
})(typeof self !== 'undefined' ? self : this);
