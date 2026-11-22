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
 * Read here: Word, Excel and PowerPoint (OOXML), Word 97-2003 (.doc), OpenDocument, and plain text.
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
 * @version 1.2.0 - Word 97-2003 (.doc): the compound file and piece table read here, as DocumentText::docText()
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
		'application/epub+zip': 'epub',
		'application/msword': 'doc'
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
		epub: 'application/epub+zip',
		doc: 'application/msword'
	};

	/** The categories this file reads; every other one keeps no preview here. */
	var READS = { docx: 1, xlsx: 1, pptx: 1, odf: 1, text: 1, doc: 1 };
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

	// ---- Word 97-2003 (.doc): DocumentText::cfbOpen() .. docClean() ----------------

	var CP1252_HIGH = [0x20AC, 0x0081, 0x201A, 0x0192, 0x201E, 0x2026, 0x2020, 0x2021,
		0x02C6, 0x2030, 0x0160, 0x2039, 0x0152, 0x008D, 0x017D, 0x008F, 0x0090, 0x2018, 0x2019,
		0x201C, 0x201D, 0x2022, 0x2013, 0x2014, 0x02DC, 0x2122, 0x0161, 0x203A, 0x0153, 0x009D,
		0x017E, 0x0178];

	function cu16(b, o) {
		if (o < 0 || o + 2 > b.length) throw fail('compound file: read past the end');
		return b[o] | (b[o + 1] << 8);
	}
	function cu32(b, o) {
		if (o < 0 || o + 4 > b.length) throw fail('compound file: read past the end');
		return (b[o] | (b[o + 1] << 8) | (b[o + 2] << 16)) + b[o + 3] * 0x1000000;
	}

	var utf16 = new TextDecoder('utf-16le');

	function cfbOpen(b) {
		var len = b.length;
		if (len < 512 || !startsWith(b, OLE_SIG)) throw fail('not a compound file');
		var shift = cu16(b, 0x1E);
		if (shift !== 9 && shift !== 12) throw fail('compound file: bad sector size');
		if (cu16(b, 0x20) !== 6) throw fail('compound file: bad mini sector size');
		var ss = 1 << shift;
		var nsec = Math.floor((Math.max(0, len - ss) + ss - 1) / ss);
		var cfb = { b: b, ss: ss, nsec: nsec, fat: [], minifat: [], mini: null, cutoff: cu32(b, 0x38) };

		var numFat = Math.min(cu32(b, 0x2C), nsec);
		var fat = [];
		for (var i = 0; i < 109 && fat.length < numFat; i++) fat.push(cu32(b, 0x4C + 4 * i));
		var next = cu32(b, 0x44);
		var seen = {};
		var per = ss / 4 - 1;
		while (fat.length < numFat && next < nsec && !seen[next]) {
			seen[next] = true;
			var base = (next + 1) * ss;
			for (var k = 0; k < per && fat.length < numFat; k++) fat.push(cu32(b, base + 4 * k));
			next = cu32(b, base + 4 * per);
		}
		cfb.fat = fat;

		var dir = cfbRead(cfb, cu32(b, 0x30), Infinity, false);
		cfb.minifat = cfbChain(cfb, cu32(b, 0x3C), false);
		var count = Math.floor(dir.length / 128);
		function entry(i) {
			if (i < 0 || i >= count) return null;
			var o = i * 128;
			var nameLen = Math.min(64, cu16(dir, o + 0x40));
			var name = nameLen >= 2 ? utf16.decode(dir.subarray(o, o + nameLen - 2)) : '';
			return { name: name.toLowerCase(), type: dir[o + 0x42], left: cu32(dir, o + 0x44), right: cu32(dir, o + 0x48),
				child: cu32(dir, o + 0x4C), start: cu32(dir, o + 0x74), size: cu32(dir, o + 0x78) };
		}
		var root = entry(0);
		if (!root || root.type !== 5) throw fail('compound file: no root');
		cfb.root = [root.start, root.size];

		var streams = {};
		var stack = [root.child];
		var visited = {};
		while (stack.length) {
			var j = stack.pop();
			if (j >= count || visited[j]) continue;
			visited[j] = true;
			var e = entry(j);
			if (!e) continue;
			if ((e.type === 2 || e.type === 1) && !Object.prototype.hasOwnProperty.call(streams, e.name)) {
				streams[e.name] = [e.type, e.start, e.size];
			}
			stack.push(e.left);
			stack.push(e.right);
		}
		cfb.streams = streams;
		return cfb;
	}

	function cfbChain(cfb, start, mini) {
		var out = [];
		var seen = {};
		var per = cfb.ss / 4;
		var limit = mini ? cfb.minifat.length * per : cfb.nsec;
		var s = start;
		while (s >= 0 && s < limit && !seen[s]) {
			seen[s] = true;
			out.push(s);
			var table = mini ? cfb.minifat : cfb.fat;
			var fs = Math.floor(s / per);
			if (fs >= table.length || table[fs] >= cfb.nsec) break;
			s = cu32(cfb.b, (table[fs] + 1) * cfb.ss + 4 * (s % per));
		}
		return out;
	}

	function cfbRead(cfb, start, size, mini) {
		var chain = cfbChain(cfb, start, mini);
		var unit = mini ? 64 : cfb.ss;
		var src = mini ? cfb.mini : cfb.b;
		var parts = [];
		var have = 0;
		for (var i = 0; i < chain.length && have < size; i++) {
			var o = mini ? chain[i] * 64 : (chain[i] + 1) * cfb.ss;
			var part = src.subarray(Math.min(o, src.length), Math.min(o + unit, src.length));
			parts.push(part);
			have += unit;
		}
		var total = 0;
		parts.forEach(function (p) { total += p.length; });
		var out = new Uint8Array(total);
		var at = 0;
		parts.forEach(function (p) { out.set(p, at); at += p.length; });
		return size === Infinity ? out : out.subarray(0, Math.min(size, out.length));
	}

	function cfbStream(cfb, name) {
		var e = Object.prototype.hasOwnProperty.call(cfb.streams, name.toLowerCase()) ? cfb.streams[name.toLowerCase()] : null;
		if (!e || e[0] !== 2) return null;
		if (e[2] < cfb.cutoff) {
			if (cfb.mini === null) cfb.mini = cfbRead(cfb, cfb.root[0], cfb.root[1], false);
			return cfbRead(cfb, e[1], e[2], true);
		}
		return cfbRead(cfb, e[1], e[2], false);
	}

	function docText(bytes) {
		var cfb = cfbOpen(bytes);
		if (cfb.streams.encryptedpackage || cfb.streams.encryptioninfo) throw new Refusal('secured', 'encrypted document');
		var wd = cfbStream(cfb, 'WordDocument');
		if (wd === null) throw fail('doc: no WordDocument stream');
		var ident = cu16(wd, 0);
		if (ident === 0xA5DC) throw fail('doc: a Word 95 or older file');
		if (ident !== 0xA5EC) throw fail('doc: not a Word document');
		if (cu16(wd, 2) < 0x00C0) throw fail('doc: a Word 95 or older file');
		var flags = cu16(wd, 0x0A);
		if ((flags & 0x0100) || (flags & 0x8000)) throw new Refusal('secured', 'doc: encrypted');
		var table = cfbStream(cfb, (flags & 0x0200) ? '1Table' : '0Table');
		if (table === null) throw fail('doc: no table stream');

		var csw = cu16(wd, 32);
		var lw = 34 + 2 * csw + 2;
		var cslw = cu16(wd, 34 + 2 * csw);
		if (cslw < 11) throw fail('doc: short FIB');
		var ccp = {};
		for (var i = 3; i <= 10; i++) ccp[i] = cu32(wd, lw + 4 * i);
		var fcl = lw + 4 * cslw;
		if (cu16(wd, fcl) <= 33) throw fail('doc: short FIB');
		var fcClx = cu32(wd, fcl + 2 + 33 * 8);
		var lcbClx = cu32(wd, fcl + 2 + 33 * 8 + 4);
		if (fcClx + lcbClx > table.length || lcbClx < 5) throw fail('doc: no piece table');
		var clx = table.subarray(fcClx, fcClx + lcbClx);

		var p = 0;
		while (p < clx.length && clx[p] === 1) {
			var cb = cu16(clx, p + 1);
			if (cb >= 0x8000) throw fail('doc: bad piece table');
			p += 3 + cb;
		}
		if (p >= clx.length || clx[p] !== 2) throw fail('doc: bad piece table');
		var lcb = cu32(clx, p + 1);
		var plc = clx.subarray(p + 5, p + 5 + lcb);
		if (plc.length !== lcb || lcb < 16 || (lcb - 4) % 12 !== 0) throw fail('doc: bad piece table');
		var n = (lcb - 4) / 12;
		var cps = [];
		for (var k = 0; k <= n; k++) {
			cps[k] = cu32(plc, 4 * k);
			if (k > 0 && cps[k] <= cps[k - 1]) throw fail('doc: bad piece table');
		}
		// No more characters than the text stream has bytes (DocumentText::docText()).
		if (cps[0] !== 0 || cps[n] > Math.min(LIMITS.totalBytes, wd.length)) throw fail('doc: bad piece table');

		// The whole document as UTF-16 code units, one per character position.
		var u = new Uint16Array(cps[n]);
		for (k = 0; k < n; k++) {
			var fc = cu32(plc, 4 * (n + 1) + 8 * k + 2);
			var count = cps[k + 1] - cps[k];
			var at = cps[k];
			var c, off;
			if (fc & 0x40000000) {
				off = Math.floor((fc & 0x3FFFFFFF) / 2);
				for (c = 0; c < count && off + c < wd.length; c++) {
					var by = wd[off + c];
					u[at + c] = (by >= 0x80 && by <= 0x9F) ? CP1252_HIGH[by - 0x80] : by;
				}
			} else {
				off = fc;
				for (c = 0; c < count && off + 2 * c + 1 < wd.length; c++) {
					u[at + c] = wd[off + 2 * c] | (wd[off + 2 * c + 1] << 8);
				}
			}
			// A piece that runs past its stream keeps its length (zeros).
		}

		var ftn = ccp[3], hdd = ftn + ccp[4], atn = hdd + ccp[5] + ccp[6], edn = atn + ccp[7];
		var stories = [[0, ccp[3]], [ftn, ccp[4]], [edn, ccp[8]], [hdd, ccp[5]]];
		var out = [];
		stories.forEach(function (st) {
			if (st[1] <= 0) return;
			var from = Math.min(st[0], u.length), to = Math.min(st[0] + st[1], u.length);
			if (to <= from) return;
			var slice = u.subarray(from, to);
			out.push(docClean(utf16.decode(new Uint8Array(slice.buffer, slice.byteOffset, slice.byteLength))));
		});
		return out.join('\n');
	}

	/**
	 * DocumentText::docClean(): fields show their results; breaks are lines;
	 * cells tabs; rows lines. Runs of ordinary characters are copied whole.
	 */
	function docClean(s) {
		var out = '';
		var run = -1;          // start of the ordinary run being copied
		var fields = [];
		var inCode = 0;
		var prev = '';
		for (var i = 0; i < s.length; i++) {
			var code = s.charCodeAt(i);
			if (code >= 0x20 && inCode === 0) {
				if (run < 0) run = i;
				prev = '';
				continue;
			}
			if (run >= 0) { out += s.slice(run, i); run = -1; }
			var ch = s.charAt(i);
			if (ch === '\x13') {
				fields.push(false);
				inCode++;
			} else if (ch === '\x14') {
				if (fields.length && !fields[fields.length - 1]) { fields[fields.length - 1] = true; inCode--; }
			} else if (ch === '\x15') {
				if (fields.length) { if (!fields.pop()) inCode--; }
			} else if (inCode > 0) {
				// inside a field's code
			} else if (ch === '\r' || ch === '\x0B' || ch === '\x0C') {
				out += '\n';
			} else if (ch === '\x07') {
				if (prev === '\x07' && out.charAt(out.length - 1) === '\t') out = out.slice(0, -1) + '\n';
				else out += '\t';
			} else if (ch === '\x1E') {
				out += '-';
			} else if (ch === '\t') {
				out += '\t';
			}
			// any other control character (an anchor, a note reference, a separator): no text
			prev = ch;
		}
		if (run >= 0) out += s.slice(run);
		return out;
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
			} else if (startsWith(bytes, OLE_SIG) && (category === 'doc' || ZIP_CATEGORIES[category])) {
				// A compound file: a Word 97-2003 document, or (named as an
				// Office Open XML file) the way Office stores a password-protected one.
				var cfb = cfbOpen(bytes);
				if (cfb.streams.encryptedpackage || cfb.streams.encryptioninfo) throw new Refusal('secured', 'encrypted document');
				if (!cfb.streams.worddocument) {
					if (ZIP_CATEGORIES[category]) throw new Refusal('secured', 'password-protected document');
					throw fail('doc: no WordDocument stream');
				}
				category = 'doc';
				raw = docText(bytes);
			} else if (ZIP_CATEGORIES[category] || category === 'doc') {
				throw fail(category === 'doc' ? 'not a compound file' : 'not a readable zip container');
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
