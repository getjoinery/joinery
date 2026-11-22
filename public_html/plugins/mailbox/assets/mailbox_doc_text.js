/**
 * MailboxDocText - an office document's words, read in this browser.
 *
 * A Fortress attachment is ciphertext on the server, so the server's text
 * preview (DocumentText, mailbox/attachment_text) cannot read it. The browser
 * already opens the attachment to download it; this reads the text out of the
 * opened bytes on the device, and nothing leaves it.
 *
 *   kindFor(contentType, filename)  'text' when this can read the file, else null
 *   read(bytes, contentType, filename, {maxBytes, maxChars})
 *                                   a promise of {status, category, text, truncated},
 *                                   status one of ok / empty / failed / secured / too_large
 *
 * The type is decided as the server decides it (DocumentText::bestMimeGuess):
 * a declared type it knows, else the filename's extension; a zip is then named
 * by what it holds (DocumentText::refineContainerMime). Each reader below
 * follows its PHP function in includes/DocumentText.php (docxText, xlsxText,
 * pptxText, odfText, toUtf8, finish), and plugins/mailbox/tests/doc_text.mjs
 * holds the two to the same output over the same files.
 *
 * Read here: Word, Excel and PowerPoint (OOXML), OpenDocument, and plain text.
 * Not read here: PDF, RTF, HTML, saved emails, calendar files, EPUB and zip
 * listings; those keep no preview on Fortress mail.
 *
 * The zip reader is read-only and holds the server's limits (12 MiB per entry,
 * 40 MiB in all, 200:1 above 1 MiB, 400 parts, 50 sheets). A zip's directory
 * states sizes the sender chose, so the inflate loop also counts what really
 * comes out and stops at the entry limit. The XML reader is a single-pass
 * tokenizer, not DOMParser: it runs under Node for the tests, builds no tree,
 * and refuses any document with a <!DOCTYPE (office files never carry one,
 * which removes entity expansion as a subject).
 *
 * No dependence on the page: it is evaluated as-is under Node by the tests.
 *
 * @version 1.1.0 - review fixes (reviewer2): trimming walks indexes (a long run of spaces was quadratic
 *   under a trailing-whitespace regex, B1); the character cap counts only as far as it keeps (B2); a declared
 *   type it cannot read falls back to the extension, as the server's sniff would (B6); an XML part is decoded
 *   by its byte-order mark or declared encoding (B7)
 * @version 1.0.0
 */
(function (root) {
	'use strict';

	// ---- the server's type rules (DocumentText::CATEGORY / EXTENSION_MIME) ----

	var CATEGORY = {
		'application/pdf': 'pdf',
		'text/html': 'html', 'application/xhtml+xml': 'html',
		'text/plain': 'text', 'text/markdown': 'text', 'text/x-markdown': 'text',
		'text/csv': 'text', 'application/csv': 'text', 'application/json': 'text',
		'application/x-yaml': 'text', 'application/yaml': 'text', 'application/toml': 'text',
		'application/x-sh': 'text', 'application/sql': 'text', 'application/javascript': 'text',
		'application/x-httpd-php': 'text',
		'text/rtf': 'rtf', 'application/rtf': 'rtf',
		'text/xml': 'xml', 'application/xml': 'xml', 'image/svg+xml': 'xml',
		'message/rfc822': 'eml',
		'text/calendar': 'ics', 'application/ics': 'ics', 'text/x-vcalendar': 'ics',
		'application/zip': 'archive', 'application/x-zip-compressed': 'archive',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'docx',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'pptx',
		'application/vnd.oasis.opendocument.text': 'odf',
		'application/vnd.oasis.opendocument.spreadsheet': 'odf',
		'application/vnd.oasis.opendocument.presentation': 'odf',
		'application/epub+zip': 'epub'
	};

	var EXTENSION_MIME = {
		pdf: 'application/pdf', htm: 'text/html', html: 'text/html', xhtml: 'application/xhtml+xml',
		txt: 'text/plain', text: 'text/plain', log: 'text/plain', ini: 'text/plain',
		conf: 'text/plain', cfg: 'text/plain', srt: 'text/plain', vtt: 'text/plain',
		vcf: 'text/plain', tsv: 'text/plain', md: 'text/markdown', markdown: 'text/markdown',
		csv: 'text/csv', json: 'application/json', yml: 'application/x-yaml', yaml: 'application/x-yaml',
		toml: 'application/toml', sh: 'application/x-sh', sql: 'application/sql',
		js: 'application/javascript', rtf: 'application/rtf', xml: 'application/xml',
		svg: 'image/svg+xml', eml: 'message/rfc822', ics: 'text/calendar', ical: 'text/calendar',
		zip: 'application/zip',
		docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		odt: 'application/vnd.oasis.opendocument.text',
		ods: 'application/vnd.oasis.opendocument.spreadsheet',
		odp: 'application/vnd.oasis.opendocument.presentation',
		epub: 'application/epub+zip'
	};

	/** The categories this file reads; every other one keeps no preview here. */
	var READS = { docx: 1, xlsx: 1, pptx: 1, odf: 1, text: 1 };
	var ZIP_CATEGORIES = { docx: 1, xlsx: 1, pptx: 1, odf: 1 };

	var LIMITS = {
		entryBytes: 12582912,  // DocumentText::ZIP_MAX_ENTRY_BYTES
		totalBytes: 41943040,  // ZIP_MAX_TOTAL_BYTES
		ratio: 200,            // ZIP_MAX_RATIO, above 1 MiB
		parts: 400,            // ZIP_MAX_PARTS
		sheets: 50             // ZIP_MAX_SHEETS
	};

	var TRUNCATION_MARKER = '\n\n[... attachment text truncated ...]';

	function normalizeMime(mime) {
		mime = String(mime == null ? '' : mime).trim().toLowerCase();
		var semi = mime.indexOf(';');
		if (semi !== -1) mime = mime.slice(0, semi).trim();
		return mime;
	}

	function categoryForMime(mime) {
		mime = normalizeMime(mime);
		if (mime === '') return null;
		if (Object.prototype.hasOwnProperty.call(CATEGORY, mime)) return CATEGORY[mime];
		if (mime.indexOf('text/') === 0) return 'text';
		return null;
	}

	function mimeForExtension(name) {
		if (name == null) return null;
		var ext = String(name).trim().toLowerCase();
		if (ext === '') return null;
		var dot = ext.lastIndexOf('.');
		if (dot !== -1) ext = ext.slice(dot + 1);
		return Object.prototype.hasOwnProperty.call(EXTENSION_MIME, ext) ? EXTENSION_MIME[ext] : null;
	}

	function bestMimeGuess(declared, filename) {
		declared = normalizeMime(declared);
		if (categoryForMime(declared) !== null) return declared;
		var byExt = mimeForExtension(filename);
		return byExt !== null ? byExt : declared;
	}

	/**
	 * The category read here. A declared type that names a category this file
	 * does not read (application/zip on a .docx, the way some mailers declare
	 * every Office file) gives way to the extension: the server would sniff
	 * those bytes and name them by what the zip holds.
	 */
	function readCategory(contentType, filename) {
		var c = categoryForMime(bestMimeGuess(contentType, filename));
		if (c && READS[c]) return c;
		var byExt = categoryForMime(mimeForExtension(filename));
		return (byExt && READS[byExt]) ? byExt : c;
	}

	function kindFor(contentType, filename) {
		var c = readCategory(contentType, filename);
		return (c && READS[c]) ? 'text' : null;
	}

	// ---- outcomes ------------------------------------------------------------

	function Refusal(status, why) {
		this.status = status;
		this.message = why;
	}

	function fail(why) { return new Refusal('failed', why); }

	// ---- text hygiene (DocumentText::toUtf8, scrub, finish) ----------------------

	/**
	 * PHP's trim(): space, tab, newline, carriage return, NUL, vertical tab
	 * only. Walked in from both ends: a trailing-whitespace regex retries from
	 * every space of a run that does not reach the end, which is quadratic, and
	 * a sender chooses the run.
	 */
	var PHP_SPACE = { 32: 1, 9: 1, 10: 1, 13: 1, 0: 1, 11: 1 };
	function phpTrim(s) {
		var a = 0, b = s.length;
		while (a < b && PHP_SPACE[s.charCodeAt(a)]) a++;
		while (b > a && PHP_SPACE[s.charCodeAt(b - 1)]) b--;
		return (a === 0 && b === s.length) ? s : s.slice(a, b);
	}

	/** Trailing XML whitespace off a name, walked (see phpTrim). */
	function trimNameEnd(s) {
		var b = s.length;
		while (b > 0 && /\s/.test(s.charAt(b - 1))) b--;
		return b === s.length ? s : s.slice(0, b);
	}

	function scrub(s) {
		// eslint-disable-next-line no-control-regex
		return s.replace(/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/g, '');
	}

	/** Bytes as UTF-8 when they are, else windows-1252; a byte-order mark is kept, as PHP keeps it. */
	function toUtf8(bytes) {
		var s;
		try {
			s = new TextDecoder('utf-8', { fatal: true, ignoreBOM: true }).decode(bytes);
		} catch (e) {
			s = new TextDecoder('windows-1252').decode(bytes);
		}
		return scrub(s);
	}

	/** {text, truncated}: line endings, controls, blank runs and the character cap, as finish() does. */
	function finish(text, maxChars) {
		text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
		text = scrub(text);
		text = text.replace(/\n{3,}/g, '\n\n');
		text = phpTrim(text);
		var truncated = false;
		if (maxChars > 0 && text.length > maxChars) {
			// Counted in characters (code points), as mb_strlen counts them, and
			// only as far as the cap: the rest of a long text is never walked.
			var at = 0, count = 0;
			while (at < text.length && count < maxChars) {
				var cp = text.codePointAt(at);
				at += cp > 0xFFFF ? 2 : 1;
				count++;
			}
			if (at < text.length) {
				text = text.slice(0, at) + TRUNCATION_MARKER;
				truncated = true;
			}
		}
		return { text: text, truncated: truncated };
	}

	// ---- XML: one pass, no tree ---------------------------------------------------

	var MALFORMED = { malformed: true };
	var NAMED = { lt: '<', gt: '>', amp: '&', quot: '"', apos: "'" };

	function decodeEntities(s) {
		if (s.indexOf('&') === -1) return s;
		var out = '';
		var i = 0;
		for (;;) {
			var amp = s.indexOf('&', i);
			if (amp === -1) { out += s.slice(i); break; }
			out += s.slice(i, amp);
			var semi = s.indexOf(';', amp);
			if (semi === -1 || semi - amp > 12) throw MALFORMED;
			var ref = s.slice(amp + 1, semi);
			var cp = null;
			if (ref.charAt(0) === '#') {
				cp = (ref.charAt(1) === 'x' || ref.charAt(1) === 'X')
					? (/^[0-9a-fA-F]+$/.test(ref.slice(2)) ? parseInt(ref.slice(2), 16) : NaN)
					: (/^[0-9]+$/.test(ref.slice(1)) ? parseInt(ref.slice(1), 10) : NaN);
				if (!(cp > 0 && cp <= 0x10FFFF) || (cp >= 0xD800 && cp <= 0xDFFF)) throw MALFORMED;
				out += String.fromCodePoint(cp);
			} else if (Object.prototype.hasOwnProperty.call(NAMED, ref)) {
				out += NAMED[ref];
			} else {
				throw MALFORMED;   // an entity nothing declared
			}
			i = semi + 1;
		}
		return out;
	}

	var NAME_END = /[\s/>]/;

	/**
	 * Walk an XML document: on.open(name, attrs, selfClosing),
	 * on.close(name, selfClosing, depthAfter), on.text(text). Text outside the
	 * root element is not reported (it is whitespace or the document is not
	 * well formed). Throws MALFORMED where libxml would refuse the document, and
	 * a 'failed' Refusal for any <!DOCTYPE.
	 */
	function walk(xml, on) {
		xml = xml.replace(/\r\n?/g, '\n');
		var n = xml.length;
		var i = 0;
		var stack = [];
		var seenRoot = false;
		while (i < n) {
			var lt = xml.indexOf('<', i);
			if (lt === -1) lt = n;
			if (lt > i) {
				var raw = xml.slice(i, lt);
				if (stack.length) {
					if (on.text) on.text(decodeEntities(raw));
				} else if (/[^ \t\n﻿]/.test(raw)) {
					throw MALFORMED;
				}
				i = lt;
				continue;
			}
			var end;
			if (xml.startsWith('<!--', i)) {
				end = xml.indexOf('-->', i + 4);
				if (end === -1) throw MALFORMED;
				i = end + 3;
				continue;
			}
			if (xml.startsWith('<![CDATA[', i)) {
				if (!stack.length) throw MALFORMED;
				end = xml.indexOf(']]>', i + 9);
				if (end === -1) throw MALFORMED;
				if (on.text) on.text(xml.slice(i + 9, end));
				i = end + 3;
				continue;
			}
			if (xml.charAt(i + 1) === '!') {
				// <!DOCTYPE and anything else a document declares: refused outright.
				throw fail('xml carries a document type declaration');
			}
			if (xml.charAt(i + 1) === '?') {
				end = xml.indexOf('?>', i + 2);
				if (end === -1) throw MALFORMED;
				i = end + 2;
				continue;
			}
			if (xml.charAt(i + 1) === '/') {
				end = xml.indexOf('>', i + 2);
				if (end === -1) throw MALFORMED;
				var closing = trimNameEnd(xml.slice(i + 2, end));
				if (!stack.length || stack.pop() !== closing) throw MALFORMED;
				if (on.close) on.close(closing, false, stack.length);
				i = end + 1;
				continue;
			}
			// An open tag: its name, then attributes up to an unquoted '>'.
			var j = i + 1;
			while (j < n && !NAME_END.test(xml.charAt(j))) j++;
			var name = xml.slice(i + 1, j);
			if (name === '') throw MALFORMED;
			var attrs = {};
			var selfClosing = false;
			for (;;) {
				while (j < n && /\s/.test(xml.charAt(j))) j++;
				if (j >= n) throw MALFORMED;
				var ch = xml.charAt(j);
				if (ch === '>') { j++; break; }
				if (ch === '/') {
					if (xml.charAt(j + 1) !== '>') throw MALFORMED;
					selfClosing = true;
					j += 2;
					break;
				}
				var eq = xml.indexOf('=', j);
				if (eq === -1) throw MALFORMED;
				var aname = trimNameEnd(xml.slice(j, eq));
				if (aname === '' || /[\s<>"'/]/.test(aname)) throw MALFORMED;
				j = eq + 1;
				while (j < n && /\s/.test(xml.charAt(j))) j++;
				var q = xml.charAt(j);
				if (q !== '"' && q !== "'") throw MALFORMED;
				var close = xml.indexOf(q, j + 1);
				if (close === -1) throw MALFORMED;
				var value = xml.slice(j + 1, close);
				if (value.indexOf('<') !== -1) throw MALFORMED;
				// Attribute-value normalization: each tab and newline is a space.
				attrs[aname] = decodeEntities(value.replace(/[\t\n]/g, ' '));
				j = close + 1;
				var after = xml.charAt(j);
				if (after !== '>' && after !== '/' && !/\s/.test(after)) throw MALFORMED;
			}
			if (!stack.length) {
				if (seenRoot) throw MALFORMED;   // a second root element
				seenRoot = true;
			}
			if (on.open) on.open(name, attrs, selfClosing);
			if (selfClosing) {
				if (on.close) on.close(name, true, stack.length);
			} else {
				stack.push(name);
			}
			i = j;
		}
		if (stack.length || !seenRoot) throw MALFORMED;
	}

	/** Run a walk; false when the document is not well formed (libxml's null). */
	function tryWalk(xml, on) {
		try {
			walk(xml, on);
			return true;
		} catch (e) {
			if (e === MALFORMED) return false;
			throw e;
		}
	}

	/**
	 * DocumentText::xmlText(): the document's text, a line break after each
	 * closing tag named in breakAfter (a self-closed one gets none, as the PHP's
	 * string replace of '</tag>' gives none), '' when not well formed.
	 */
	function xmlText(xml, breakAfter) {
		var brk = {};
		(breakAfter || []).forEach(function (t) { brk[t] = 1; });
		var out = [];
		var ok = tryWalk(xml, {
			text: function (t) { out.push(t); },
			close: function (name, selfClosing, depthAfter) {
				if (!selfClosing && brk[name] && depthAfter > 0) out.push('\n');
			}
		});
		return ok ? out.join('') : '';
	}

	// ---- zip: read-only ----------------------------------------------------------

	function u16(b, o) { return b[o] | (b[o + 1] << 8); }
	function u32(b, o) { return (b[o] | (b[o + 1] << 8) | (b[o + 2] << 16)) + b[o + 3] * 0x1000000; }

	var utf8 = new TextDecoder('utf-8');

	/**
	 * The central directory, bounded BEFORE anything is read (openZip()):
	 * per-entry size, summed size, ratio. Returns {bytes, entries, byName, names}.
	 */
	function openZip(bytes) {
		var len = bytes.length;
		var eocd = -1;
		for (var p = len - 22, stop = Math.max(0, len - 22 - 65535); p >= stop; p--) {
			if (bytes[p] === 0x50 && bytes[p + 1] === 0x4b && bytes[p + 2] === 0x05 && bytes[p + 3] === 0x06) { eocd = p; break; }
		}
		if (eocd === -1) throw fail('not a readable zip container');
		if (u16(bytes, eocd + 4) !== 0 || u16(bytes, eocd + 6) !== 0) throw fail('multi-disk zip');
		var count = u16(bytes, eocd + 10);
		var cdSize = u32(bytes, eocd + 12);
		var cdOffset = u32(bytes, eocd + 16);
		if (count === 0xFFFF || cdSize === 0xFFFFFFFF || cdOffset === 0xFFFFFFFF) throw fail('zip64');
		if (cdOffset + cdSize > len) throw fail('not a readable zip container');

		var entries = [];
		var byName = {};
		var total = 0;
		var o = cdOffset;
		for (var k = 0; k < count; k++) {
			if (o + 46 > len || u32(bytes, o) !== 0x02014b50) throw fail('not a readable zip container');
			var flags = u16(bytes, o + 8);
			var method = u16(bytes, o + 10);
			var comp = u32(bytes, o + 20);
			var size = u32(bytes, o + 24);
			var nameLen = u16(bytes, o + 28);
			var extraLen = u16(bytes, o + 30);
			var commentLen = u16(bytes, o + 32);
			var disk = u16(bytes, o + 34);
			var local = u32(bytes, o + 42);
			if (comp === 0xFFFFFFFF || size === 0xFFFFFFFF || local === 0xFFFFFFFF) throw fail('zip64');
			if (disk !== 0) throw fail('multi-disk zip');
			var name = utf8.decode(bytes.subarray(o + 46, o + 46 + nameLen));
			if (flags & 1) throw new Refusal('secured', 'encrypted zip entry: ' + name);
			if (size > LIMITS.entryBytes) throw fail('zip member too large: ' + name);
			if (comp > 0 && size > 1048576 && (size / comp) > LIMITS.ratio) throw fail('zip member compression ratio too high: ' + name);
			total += size;
			if (total > LIMITS.totalBytes) throw fail('zip total uncompressed size too large');
			var e = { name: name, method: method, comp: comp, size: size, local: local };
			entries.push(e);
			if (!Object.prototype.hasOwnProperty.call(byName, name)) byName[name] = e;
			o += 46 + nameLen + extraLen + commentLen;
		}
		return { bytes: bytes, entries: entries, byName: byName, names: entries.map(function (x) { return x.name; }), inflated: 0 };
	}

	/** Inflate raw deflate, counting what comes out; over `cap` is a refusal. */
	async function inflateCapped(data, cap) {
		var stream = new Blob([data]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
		var reader = stream.getReader();
		var chunks = [];
		var got = 0;
		for (;;) {
			var r = await reader.read();
			if (r.done) break;
			got += r.value.length;
			if (got > cap) {
				try { await reader.cancel(); } catch (e) { /* stopping anyway */ }
				throw fail('zip member inflates past the limit');
			}
			chunks.push(r.value);
		}
		var out = new Uint8Array(got);
		var at = 0;
		chunks.forEach(function (c) { out.set(c, at); at += c.length; });
		return out;
	}

	/**
	 * One member's bytes, or null where ZipArchive::getFromName() gives false
	 * (absent, a method other than stored or deflated, a damaged stream). The
	 * entry and total limits hold against the bytes that really come out.
	 */
	async function entryBytes(zip, name) {
		var e = Object.prototype.hasOwnProperty.call(zip.byName, name) ? zip.byName[name] : null;
		if (!e) return null;
		var b = zip.bytes;
		if (e.local + 30 > b.length || u32(b, e.local) !== 0x04034b50) return null;
		var start = e.local + 30 + u16(b, e.local + 26) + u16(b, e.local + 28);
		if (start + e.comp > b.length) return null;
		var data = b.subarray(start, start + e.comp);
		var cap = Math.min(LIMITS.entryBytes, LIMITS.totalBytes - zip.inflated);
		var out;
		if (e.method === 0) {
			if (data.length > cap) throw fail('zip member too large: ' + name);
			out = data;
		} else if (e.method === 8) {
			try {
				out = await inflateCapped(data, cap);
			} catch (err) {
				if (err instanceof Refusal) throw err;
				return null;
			}
		} else {
			return null;
		}
		zip.inflated += out.length;
		return out;
	}

	/**
	 * An XML part's text in the encoding it says it is in: a byte-order mark,
	 * else the declaration's encoding="", else UTF-8 (what libxml does). OOXML
	 * allows UTF-16; an encoding the browser does not know is read as UTF-8.
	 */
	function xmlDecode(bytes) {
		if (bytes.length >= 2 && bytes[0] === 0xFE && bytes[1] === 0xFF) return new TextDecoder('utf-16be').decode(bytes.subarray(2));
		if (bytes.length >= 2 && bytes[0] === 0xFF && bytes[1] === 0xFE) return new TextDecoder('utf-16le').decode(bytes.subarray(2));
		if (bytes.length >= 3 && bytes[0] === 0xEF && bytes[1] === 0xBB && bytes[2] === 0xBF) return utf8.decode(bytes.subarray(3));
		var head = '';
		for (var i = 0; i < Math.min(bytes.length, 200); i++) head += String.fromCharCode(bytes[i]);
		var m = /^<\?xml[^>]*\bencoding\s*=\s*["']([A-Za-z0-9._-]+)["']/.exec(head);
		if (m && !/^utf-?8$/i.test(m[1])) {
			try { return new TextDecoder(m[1].toLowerCase()).decode(bytes); } catch (e) { /* unknown label: as UTF-8 */ }
		}
		return utf8.decode(bytes);
	}

	async function entryText(zip, name) {
		var bytes = await entryBytes(zip, name);
		return bytes === null ? null : xmlDecode(bytes);
	}

	/** strnatcmp: runs of digits compare as numbers. */
	function natCompare(a, b) {
		var re = /(\d+|\D+)/g;
		var x = a.match(re) || [];
		var y = b.match(re) || [];
		for (var i = 0; i < Math.min(x.length, y.length); i++) {
			var p = x[i], q = y[i];
			if (p === q) continue;
			var pd = /^\d/.test(p), qd = /^\d/.test(q);
			if (pd && qd) {
				var pn = p.replace(/^0+(?=\d)/, ''), qn = q.replace(/^0+(?=\d)/, '');
				if (pn.length !== qn.length) return pn.length - qn.length;
				if (pn !== qn) return pn < qn ? -1 : 1;
				continue;
			}
			return p < q ? -1 : 1;
		}
		return x.length - y.length;
	}

	/** zipMembers(): members matching a pattern, in natural order, capped, non-empty. */
	async function zipMembers(zip, pattern, cap) {
		var hit = zip.names.filter(function (n) { return pattern.test(n); });
		hit.sort(natCompare);
		hit = hit.slice(0, cap == null ? LIMITS.parts : cap);
		var out = [];
		var seen = {};
		for (var i = 0; i < hit.length; i++) {
			if (seen[hit[i]]) continue;
			seen[hit[i]] = 1;
			var xml = await entryText(zip, hit[i]);
			if (xml !== null && xml !== '') out.push([hit[i], xml]);
		}
		return out;
	}

	/** refineContainerMime(): a zip named by the members it holds. */
	async function containerCategory(zip) {
		var has = function (n) { return Object.prototype.hasOwnProperty.call(zip.byName, n); };
		if (has('word/document.xml')) return 'docx';
		if (has('xl/workbook.xml') || has('xl/worksheets/sheet1.xml')) return 'xlsx';
		if (has('ppt/presentation.xml') || has('ppt/slides/slide1.xml')) return 'pptx';
		var declared = await entryText(zip, 'mimetype');
		if (declared) {
			declared = normalizeMime(declared);
			if (Object.prototype.hasOwnProperty.call(CATEGORY, declared)) return CATEGORY[declared];
		}
		if (has('content.xml')) return 'odf';
		if (has('META-INF/container.xml')) return 'epub';
		return 'archive';
	}

	// ---- the readers --------------------------------------------------------------

	async function docxText(zip) {
		var parts = await zipMembers(zip, /^word\/(document|header\d*|footer\d*|footnotes|endnotes)\.xml$/);
		if (!parts.length) throw fail('docx: no word/document.xml');
		return parts.map(function (p) { return xmlText(p[1], ['w:p', 'w:tr']); }).join('\n');
	}

	async function xlsxText(zip) {
		// A cell's text is an index into sharedStrings.xml, not the text itself.
		var shared = [];
		var ss = await entryText(zip, 'xl/sharedStrings.xml');
		if (ss) {
			var found = [];
			var cur = null, siDepth = 0;
			var ok = tryWalk(ss, {
				open: function (name, a, selfClosing) {
					if (name === 'si') {
						if (siDepth === 0) cur = [];
						if (!selfClosing) siDepth++;
						else if (siDepth === 0) { found.push(''); cur = null; }
					}
				},
				close: function (name, selfClosing) {
					if (name === 'si' && !selfClosing) {
						siDepth--;
						if (siDepth === 0) { found.push(cur.join('')); cur = null; }
					}
				},
				text: function (t) { if (cur) cur.push(t); }
			});
			if (ok) shared = found;
		}

		var titles = [];
		var wb = await entryText(zip, 'xl/workbook.xml');
		if (wb) {
			var names = [];
			if (tryWalk(wb, { open: function (name, a) { if (name === 'sheet') names.push(a.name != null ? a.name : ''); } })) {
				titles = names;
			}
		}

		var sheets = await zipMembers(zip, /^xl\/worksheets\/sheet\d+\.xml$/, LIMITS.sheets);
		if (!sheets.length) throw fail('xlsx: no worksheets');

		var out = [];
		var n = 0;
		sheets.forEach(function (pair) {
			var lines = [];
			var cells = null, rowDepth = 0;
			var cell = null, cDepth = 0;
			var vText = null, vDepth = 0, vSeen = false;
			var ok = tryWalk(pair[1], {
				open: function (name, a, selfClosing) {
					if (name === 'row') {
						if (rowDepth === 0) cells = [];
						if (!selfClosing) rowDepth++;
						else if (rowDepth === 0) { endRow(); }
					} else if (name === 'c' && cells) {
						if (cDepth === 0) { cell = { t: a.t != null ? a.t : '', text: [] }; vSeen = false; vText = null; }
						if (!selfClosing) cDepth++;
						else if (cDepth === 0) { endCell(); }
					} else if (name === 'v' && cell && !vSeen) {
						vSeen = true;
						vText = [];
						if (!selfClosing) vDepth = 1;
					} else if (vDepth > 0 && !selfClosing) {
						vDepth++;
					}
				},
				close: function (name, selfClosing) {
					if (selfClosing) return;
					if (vDepth > 0) { vDepth--; return; }
					if (name === 'c' && cDepth > 0) {
						cDepth--;
						if (cDepth === 0) endCell();
					} else if (name === 'row' && rowDepth > 0) {
						rowDepth--;
						if (rowDepth === 0) endRow();
					}
				},
				text: function (t) {
					if (cell) cell.text.push(t);
					if (vDepth > 0) vText.push(t);
				}
			});
			function endCell() {
				var v = vText ? vText.join('') : '';
				if (cell.t === 's') {
					var idx = parseInt(v, 10);
					if (isNaN(idx)) idx = 0;
					v = (idx >= 0 && idx < shared.length) ? shared[idx] : '';
				} else if (cell.t === 'inlineStr') {
					v = cell.text.join('');
				}
				cells.push(v);
				cell = null; vText = null;
			}
			function endRow() {
				if (phpTrim(cells.join('')) !== '') lines.push(cells.join('\t'));
				cells = null;
			}
			if (!ok) { n++; return; }
			var title = titles[n];
			out.push('[' + (title != null ? title : pair[0].replace(/^.*\//, '').replace(/\.xml$/, '')) + ']');
			n++;
			lines.forEach(function (l) { out.push(l); });
		});
		return out.join('\n');
	}

	async function pptxText(zip) {
		var slides = await zipMembers(zip, /^ppt\/slides\/slide\d+\.xml$/);
		var notes = await zipMembers(zip, /^ppt\/notesSlides\/notesSlide\d+\.xml$/);
		if (!slides.length) throw fail('pptx: no slides');
		var noteBy = {};
		notes.forEach(function (p) { noteBy[p[0]] = p[1]; });
		var out = [];
		slides.forEach(function (p, k) {
			var i = k + 1;
			out.push('[Slide ' + i + ']');
			out.push(xmlText(p[1], ['a:p']));
			var noteName = 'ppt/notesSlides/notesSlide' + i + '.xml';
			if (Object.prototype.hasOwnProperty.call(noteBy, noteName)) {
				var note = phpTrim(xmlText(noteBy[noteName], ['a:p']));
				// A notes part echoes the slide number; a note that is only that is not a note.
				if (note !== '' && note !== String(i)) {
					out.push('[Speaker notes]');
					out.push(note);
				}
			}
		});
		return out.join('\n');
	}

	async function odfText(zip) {
		var content = await entryText(zip, 'content.xml');
		if (!content) throw fail('odf: no content.xml');
		return xmlText(content, ['text:p', 'text:h', 'table:table-row', 'table:table-cell']);
	}

	var READERS = { docx: docxText, xlsx: xlsxText, pptx: pptxText, odf: odfText };

	function startsWith(bytes, sig) {
		if (bytes.length < sig.length) return false;
		for (var i = 0; i < sig.length; i++) if (bytes[i] !== sig[i]) return false;
		return true;
	}
	var ZIP_SIG = [0x50, 0x4b, 0x03, 0x04];
	var ZIP_EMPTY_SIG = [0x50, 0x4b, 0x05, 0x06];
	// How Office stores a password-protected document: an OLE compound file.
	var OLE_SIG = [0xd0, 0xcf, 0x11, 0xe0, 0xa1, 0xb1, 0x1a, 0xe1];

	/** The text of one attachment, read here. Never rejects. */
	async function read(bytes, contentType, filename, limits) {
		limits = limits || {};
		var maxBytes = Number(limits.maxBytes) || 0;
		var maxChars = Number(limits.maxChars) || 0;
		if (bytes instanceof ArrayBuffer) bytes = new Uint8Array(bytes);
		var category = readCategory(contentType, filename);
		var answer = function (status, text, truncated) {
			return { status: status, category: category, text: text || '', truncated: !!truncated };
		};
		if (!category || !READS[category]) return answer('failed');
		if (maxBytes > 0 && bytes.length > maxBytes) return answer('too_large');
		if (!bytes.length) return answer('empty');
		try {
			var raw;
			if (startsWith(bytes, ZIP_SIG) || startsWith(bytes, ZIP_EMPTY_SIG)) {
				var zip = openZip(bytes);
				category = await containerCategory(zip);
				if (!READERS[category]) throw fail('not a document this reader opens: ' + category);
				raw = await READERS[category](zip);
			} else if (ZIP_CATEGORIES[category]) {
				if (startsWith(bytes, OLE_SIG)) throw new Refusal('secured', 'password-protected document');
				throw fail('not a readable zip container');
			} else {
				raw = toUtf8(bytes);
			}
			var done = finish(raw, maxChars);
			if (done.text === '') return answer('empty');
			return answer('ok', done.text, done.truncated);
		} catch (e) {
			if (e instanceof Refusal) return answer(e.status);
			if (e === MALFORMED) return answer('failed');
			return answer('failed');
		}
	}

	root.MailboxDocText = {
		kindFor: kindFor,
		read: read,
		limits: LIMITS,
		truncationMarker: TRUNCATION_MARKER
	};
})(typeof window !== 'undefined' ? window : globalThis);
