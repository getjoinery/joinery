/**
 * MailboxMime - an RFC 5322 / MIME message parser for the browser
 * (specs/client_custody_mail.md § R9, "Parse, browser").
 *
 * A message sealed at the relay reaches the server as ciphertext the server
 * cannot read, so the owner's browser opens it and does the parse the server
 * would have done: headers, bodies and attachments, which it then seals back
 * under the row's DEK. Everything here works on the raw bytes in memory; there
 * is no DOM, no network and no library, so the same file runs under Node for
 * the gate (plugins/mailbox/tests/mime_parser_gate.sh).
 *
 *   MailboxMime.parse(bytes)  bytes: a Uint8Array holding the whole message.
 *     Returns {headers, from, to, cc, subject, date, messageId, inReplyTo,
 *     references, textPlain, textHtml, attachments: [{filename, contentType,
 *     contentId, mimePart, inline, bytes}]}. It never throws: a part that
 *     cannot be read becomes an opaque attachment or is dropped, and input that
 *     is not bytes at all yields the same shape with every field empty.
 *
 *   MailboxMime.headerValue(parsed, name)  the first top-level header called
 *     `name` (any case), unfolded and with its RFC 2047 words decoded, or ''.
 *
 * What it understands: header folding; CRLF and bare-LF line endings alike;
 * RFC 2047 encoded words (B and Q, any charset TextDecoder knows, adjacent
 * words joined); multipart/* nested to MAX_DEPTH (deeper is kept whole as an
 * attachment); base64, quoted-printable and the identity encodings; RFC 2231
 * parameters (continuations, charset'lang'%XX, and both together) and the
 * RFC 2047-in-quotes filenames real mailers send. mimePart numbers follow IMAP
 * (a single-part message is '1'; a multipart's children count from 1), the
 * same numbers the server records, since an attachment's AD is built from it.
 * message/rfc822 is not descended into: it is an attachment like any other.
 *
 * Choices the spec leaves open:
 *   - A text part that declares us-ascii, or no charset at all, is read as
 *     UTF-8 when its bytes are valid UTF-8 and as windows-1252 otherwise: the
 *     label is routinely wrong in exactly that direction.
 *   - A multipart with no boundary, or none of whose boundaries appear, is read
 *     as a text/plain body, so a broken message still shows its words.
 *   - Body text keeps the message's own line endings.
 *
 * @version 1.0
 */
(function (root) {
	'use strict';

	var MAX_DEPTH = 30;
	var CR = 13, LF = 10, SP = 32, TAB = 9, DASH = 45, EQ = 61;

	// ── bytes → text ─────────────────────────────────────────────────────

	var CHARSET_ALIASES = {
		'utf8': 'utf-8', 'utf-8': 'utf-8', 'unicode-1-1-utf-8': 'utf-8',
		'ascii': 'us-ascii', 'us_ascii': 'us-ascii', 'ansi_x3.4-1968': 'us-ascii',
		'latin1': 'iso-8859-1', 'latin-1': 'iso-8859-1', 'iso8859-1': 'iso-8859-1',
		'iso_8859-1': 'iso-8859-1', 'iso-latin-1': 'iso-8859-1',
		'cp1252': 'windows-1252', 'win-1252': 'windows-1252',
		'ks_c_5601-1987': 'euc-kr', 'x-sjis': 'shift_jis', 'sjis': 'shift_jis',
		'gb2312': 'gbk', 'x-gbk': 'gbk', 'cp936': 'gbk', 'big5-hkscs': 'big5'
	};

	var decoderCache = {};

	function decoderFor(label) {
		if (Object.prototype.hasOwnProperty.call(decoderCache, label)) {
			return decoderCache[label];
		}
		var d = null;
		try {
			d = new TextDecoder(label, { fatal: false });
		} catch (e) {
			d = null;
		}
		decoderCache[label] = d;
		return d;
	}

	function isValidUtf8(bytes) {
		try {
			new TextDecoder('utf-8', { fatal: true }).decode(bytes);
			return true;
		} catch (e) {
			return false;
		}
	}

	function hasHighBytes(bytes) {
		for (var i = 0; i < bytes.length; i++) {
			if (bytes[i] > 127) { return true; }
		}
		return false;
	}

	/** Bytes to a string under a charset label; an unknown label is utf-8. */
	function decodeText(bytes, charset) {
		var label = String(charset || '').trim().toLowerCase().replace(/^["']|["']$/g, '');
		label = CHARSET_ALIASES[label] || label;
		if (label === '' || label === 'us-ascii') {
			if (!hasHighBytes(bytes)) { return latin1(bytes); }
			label = isValidUtf8(bytes) ? 'utf-8' : 'windows-1252';
		}
		var d = decoderFor(label) || decoderFor('utf-8');
		try {
			return d.decode(bytes);
		} catch (e) {
			return decoderFor('utf-8').decode(bytes);
		}
	}

	/** Each byte as one code unit: for ASCII syntax that must not be re-coded. */
	function latin1(bytes, start, end) {
		start = start || 0;
		end = end === undefined ? bytes.length : end;
		var out = '';
		for (var i = start; i < end; i += 8192) {
			out += String.fromCharCode.apply(null, bytes.subarray(i, Math.min(end, i + 8192)));
		}
		return out;
	}

	/** A header block's text: UTF-8 when it is valid UTF-8 (RFC 6532), else 1252. */
	function headerText(bytes) {
		if (!hasHighBytes(bytes)) { return latin1(bytes); }
		return decoderFor(isValidUtf8(bytes) ? 'utf-8' : 'windows-1252').decode(bytes);
	}

	function utf8Bytes(str) {
		return new TextEncoder().encode(str);
	}

	// ── transfer encodings ───────────────────────────────────────────────

	var B64 = (function () {
		var t = new Int16Array(256);
		var abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
		for (var i = 0; i < 256; i++) { t[i] = -1; }
		for (var j = 0; j < 64; j++) { t[abc.charCodeAt(j)] = j; }
		t['-'.charCodeAt(0)] = 62;  // the URL-safe alphabet, seen in the wild
		t['_'.charCodeAt(0)] = 63;
		return t;
	})();

	/**
	 * Base64 to bytes. Whitespace and characters outside the alphabet are
	 * skipped; '=' closes the current quantum, so blocks that were
	 * concatenated with their padding still decode.
	 */
	function decodeBase64(src) {
		var out = new Uint8Array(Math.floor(src.length * 3 / 4) + 3);
		var o = 0, acc = 0, n = 0;
		for (var i = 0; i < src.length; i++) {
			var c = src[i];
			if (c === EQ) {
				if (n === 2) { out[o++] = (acc >> 4) & 255; }
				else if (n === 3) { out[o++] = (acc >> 10) & 255; out[o++] = (acc >> 2) & 255; }
				acc = 0; n = 0;
				continue;
			}
			var v = B64[c];
			if (v < 0) { continue; }
			acc = (acc << 6) | v;
			if (++n === 4) {
				out[o++] = (acc >> 16) & 255;
				out[o++] = (acc >> 8) & 255;
				out[o++] = acc & 255;
				acc = 0; n = 0;
			}
		}
		if (n === 2) { out[o++] = (acc >> 4) & 255; }
		else if (n === 3) { out[o++] = (acc >> 10) & 255; out[o++] = (acc >> 2) & 255; }
		return out.slice(0, o);
	}

	function hexVal(c) {
		if (c >= 48 && c <= 57) { return c - 48; }
		if (c >= 65 && c <= 70) { return c - 55; }
		if (c >= 97 && c <= 102) { return c - 87; }
		return -1;
	}

	/**
	 * Quoted-printable to bytes: =XX, soft line breaks (= then optional
	 * whitespace then a line end), and the trailing whitespace RFC 2045 says a
	 * decoder removes. A stray '=' is kept as itself.
	 */
	function decodeQuotedPrintable(src) {
		var out = new Uint8Array(src.length);
		var o = 0, lineStart = 0;
		for (var i = 0; i < src.length; i++) {
			var c = src[i];
			if (c === EQ) {
				var h1 = i + 1 < src.length ? hexVal(src[i + 1]) : -1;
				var h2 = i + 2 < src.length ? hexVal(src[i + 2]) : -1;
				if (h1 >= 0 && h2 >= 0) {
					out[o++] = (h1 << 4) | h2;
					i += 2;
					continue;
				}
				var j = i + 1;
				while (j < src.length && (src[j] === SP || src[j] === TAB)) { j++; }
				if (j >= src.length) { i = j; continue; }
				if (src[j] === CR && src[j + 1] === LF) { i = j + 1; lineStart = o; continue; }
				if (src[j] === LF) { i = j; lineStart = o; continue; }
				out[o++] = c;
				continue;
			}
			if (c === CR || c === LF) {
				while (o > lineStart && (out[o - 1] === SP || out[o - 1] === TAB)) { o--; }
				out[o++] = c;
				if (c === CR && src[i + 1] === LF) { out[o++] = LF; i++; }
				lineStart = o;
				continue;
			}
			out[o++] = c;
		}
		while (o > lineStart && (out[o - 1] === SP || out[o - 1] === TAB)) { o--; }
		return out.slice(0, o);
	}

	function decodeTransfer(bytes, cte) {
		var enc = String(cte || '').trim().toLowerCase();
		if (enc === 'base64') { return decodeBase64(bytes); }
		if (enc === 'quoted-printable') { return decodeQuotedPrintable(bytes); }
		return bytes.slice();
	}

	// ── RFC 2047 encoded words ──────────────────────────────────────────

	function qWordBytes(text) {
		var out = [];
		for (var i = 0; i < text.length; i++) {
			var ch = text.charAt(i);
			if (ch === '_') { out.push(32); continue; }
			if (ch === '=' && i + 2 < text.length) {
				var h1 = hexVal(text.charCodeAt(i + 1));
				var h2 = hexVal(text.charCodeAt(i + 2));
				if (h1 >= 0 && h2 >= 0) { out.push((h1 << 4) | h2); i += 2; continue; }
			}
			var code = text.charCodeAt(i);
			if (code < 128) { out.push(code); }
			else {
				var u = utf8Bytes(ch);
				for (var k = 0; k < u.length; k++) { out.push(u[k]); }
			}
		}
		return new Uint8Array(out);
	}

	var ENCODED_WORD = /=\?([^?\s]+)\?([BbQq])\?([^?]*)\?=/g;

	/**
	 * Decodes every encoded word in a header value. Words separated only by
	 * whitespace are joined without it, and adjacent words in one charset are
	 * decoded as one byte run, so a character split across two words survives.
	 */
	function decodeWords(value) {
		var s = String(value || '');
		if (s.indexOf('=?') < 0) { return s; }
		var out = '';
		var last = 0;
		var pending = null;  // {charset, parts: [Uint8Array]}
		var m;

		function flush() {
			if (!pending) { return; }
			var total = 0, i;
			for (i = 0; i < pending.parts.length; i++) { total += pending.parts[i].length; }
			var all = new Uint8Array(total), at = 0;
			for (i = 0; i < pending.parts.length; i++) { all.set(pending.parts[i], at); at += pending.parts[i].length; }
			out += decodeText(all, pending.charset);
			pending = null;
		}

		ENCODED_WORD.lastIndex = 0;
		while ((m = ENCODED_WORD.exec(s)) !== null) {
			var between = s.slice(last, m.index);
			var joined = pending !== null && /^[ \t\r\n]*$/.test(between);
			if (!joined) {
				flush();
				out += between;
			}
			var charset = m[1].split('*')[0].toLowerCase();
			var bytes = m[2].toUpperCase() === 'B'
				? decodeBase64(latin1Bytes(m[3]))
				: qWordBytes(m[3]);
			if (pending && pending.charset !== charset) { flush(); }
			if (!pending) { pending = { charset: charset, parts: [] }; }
			pending.parts.push(bytes);
			last = m.index + m[0].length;
		}
		flush();
		out += s.slice(last);
		return out;
	}

	function latin1Bytes(str) {
		var out = new Uint8Array(str.length);
		for (var i = 0; i < str.length; i++) { out[i] = str.charCodeAt(i) & 255; }
		return out;
	}

	// ── headers ─────────────────────────────────────────────────────────

	/**
	 * Where an entity's header block ends: {headerEnd, bodyStart}. headerEnd
	 * is just past the last header line's terminator; bodyStart just past the
	 * blank line. No blank line means the whole range is headers.
	 */
	function splitHeaders(bytes, start, end) {
		// A part whose first line is not a header has no headers at all: its
		// content starts at once (RFC 2046 allows a part with none).
		var firstNl = indexOfByte(bytes, LF, start, end);
		var first = latin1(bytes, start, Math.min(firstNl < 0 ? end : firstNl, start + 1000));
		if (first !== '' && first !== '\r' && !/^[!-9;-~]+[ \t]*:/.test(first) && first.indexOf('From ') !== 0) {
			return { headerEnd: start, bodyStart: start };
		}
		var i = start;
		while (i < end) {
			// i is at the start of a line
			if (bytes[i] === LF) { return { headerEnd: i, bodyStart: i + 1 }; }
			if (bytes[i] === CR && i + 1 < end && bytes[i + 1] === LF) {
				return { headerEnd: i, bodyStart: i + 2 };
			}
			if (bytes[i] === CR && i + 1 === end) { return { headerEnd: i, bodyStart: end }; }
			var nl = indexOfByte(bytes, LF, i, end);
			if (nl < 0) { return { headerEnd: end, bodyStart: end }; }
			i = nl + 1;
		}
		return { headerEnd: end, bodyStart: end };
	}

	var HEADER_NAME = /^[!-9;-~]+[ \t]*$/;

	/** Header text to [{name (lowercase), value (unfolded, raw)}], in order. */
	function parseHeaderFields(text) {
		var lines = text.split('\n');
		var fields = [];
		var cur = null;
		for (var i = 0; i < lines.length; i++) {
			var line = lines[i];
			if (line.charAt(line.length - 1) === '\r') { line = line.slice(0, -1); }
			if (line === '') { continue; }
			var first = line.charAt(0);
			if ((first === ' ' || first === '\t') && cur) {
				cur.value += line;
				continue;
			}
			var colon = line.indexOf(':');
			if (colon <= 0 || !HEADER_NAME.test(line.slice(0, colon))) {
				cur = null;  // an mbox "From " line or plain garbage
				continue;
			}
			cur = { name: line.slice(0, colon).trim().toLowerCase(), value: line.slice(colon + 1) };
			fields.push(cur);
		}
		return fields;
	}

	function firstField(fields, name) {
		name = String(name).toLowerCase();
		for (var i = 0; i < fields.length; i++) {
			if (fields[i].name === name) { return fields[i].value.trim(); }
		}
		return null;
	}

	/** Splits on a delimiter outside quoted strings. */
	function splitOutsideQuotes(s, delim) {
		var parts = [], cur = '', inQ = false;
		for (var i = 0; i < s.length; i++) {
			var ch = s.charAt(i);
			if (inQ) {
				cur += ch;
				if (ch === '\\' && i + 1 < s.length) { cur += s.charAt(++i); }
				else if (ch === '"') { inQ = false; }
				continue;
			}
			if (ch === '"') { inQ = true; cur += ch; continue; }
			if (ch === delim) { parts.push(cur); cur = ''; continue; }
			cur += ch;
		}
		parts.push(cur);
		return parts;
	}

	function unquote(v) {
		v = v.trim();
		if (v.length >= 2 && v.charAt(0) === '"' && v.charAt(v.length - 1) === '"') {
			return { value: v.slice(1, -1).replace(/\\(.)/g, '$1'), quoted: true };
		}
		if (v.charAt(0) === '"') {  // an unterminated quote: take the rest
			return { value: v.slice(1).replace(/\\(.)/g, '$1'), quoted: true };
		}
		return { value: v, quoted: false };
	}

	function percentBytes(s) {
		var out = [];
		for (var i = 0; i < s.length; i++) {
			var c = s.charCodeAt(i);
			if (c === 37 && i + 2 < s.length) {
				var h1 = hexVal(s.charCodeAt(i + 1)), h2 = hexVal(s.charCodeAt(i + 2));
				if (h1 >= 0 && h2 >= 0) { out.push((h1 << 4) | h2); i += 2; continue; }
			}
			if (c < 128) { out.push(c); }
			else {
				var u = utf8Bytes(s.charAt(i));
				for (var k = 0; k < u.length; k++) { out.push(u[k]); }
			}
		}
		return out;
	}

	/**
	 * A structured header value ("type/sub; a=b; c*0*=...") to
	 * {value (lowercased token), params {name: decoded string}}, with RFC 2231
	 * continuations and charsets assembled and RFC 2047 words decoded in plain
	 * values.
	 */
	function parseStructured(raw) {
		var pieces = splitOutsideQuotes(String(raw || ''), ';');
		var value = pieces[0].replace(/\([^)]*\)/g, '').trim().toLowerCase();
		var plain = {};
		var ext = {};  // base -> [{index, encoded, value}]
		for (var i = 1; i < pieces.length; i++) {
			var p = pieces[i];
			var eq = p.indexOf('=');
			if (eq <= 0) { continue; }
			var key = p.slice(0, eq).trim().toLowerCase();
			var uq = unquote(p.slice(eq + 1));
			var m = /^([^*]+)\*(?:(\d+)\*?)?$/.exec(key);
			if (m && key.indexOf('*') >= 0) {
				var encoded = key.charAt(key.length - 1) === '*';
				var index = m[2] === undefined ? 0 : parseInt(m[2], 10);
				(ext[m[1]] = ext[m[1]] || []).push({ index: index, encoded: encoded, value: uq.value });
			} else if (!Object.prototype.hasOwnProperty.call(plain, key)) {
				plain[key] = decodeWords(uq.value);
			}
		}
		var params = {};
		var k;
		for (k in plain) {
			if (Object.prototype.hasOwnProperty.call(plain, k)) { params[k] = plain[k]; }
		}
		for (k in ext) {
			if (!Object.prototype.hasOwnProperty.call(ext, k)) { continue; }
			var segs = ext[k].sort(function (a, b) { return a.index - b.index; });
			var charset = '';
			var bytes = [];
			var anyEncoded = false;
			for (var s = 0; s < segs.length; s++) {
				var seg = segs[s];
				var v = seg.value;
				if (seg.encoded) {
					anyEncoded = true;
					if (s === 0) {
						var q1 = v.indexOf('\''), q2 = q1 >= 0 ? v.indexOf('\'', q1 + 1) : -1;
						if (q1 >= 0 && q2 >= 0) {
							charset = v.slice(0, q1);
							v = v.slice(q2 + 1);
						}
					}
					bytes = bytes.concat(percentBytes(v));
				} else {
					var u = utf8Bytes(v);
					for (var b = 0; b < u.length; b++) { bytes.push(u[b]); }
				}
			}
			var text = decodeText(new Uint8Array(bytes), anyEncoded ? (charset || 'utf-8') : 'utf-8');
			params[k] = anyEncoded ? text : decodeWords(text);
		}
		return { value: value, params: params };
	}

	// ── byte search ─────────────────────────────────────────────────────

	function indexOfByte(bytes, b, from, end) {
		for (var i = from; i < end; i++) {
			if (bytes[i] === b) { return i; }
		}
		return -1;
	}

	/**
	 * The multipart body's parts as [start, end) ranges. A delimiter is
	 * "--boundary" at the start of a line, followed only by whitespace (or
	 * "--" then whitespace for the close). The line end before a delimiter
	 * belongs to it. Returns null when no delimiter appears at all.
	 */
	function splitMultipart(bytes, start, end, boundary) {
		var delim = latin1Bytes('--' + boundary);
		var dl = delim.length;
		var ranges = [];
		var partStart = -1;
		var found = false;
		var i = start;
		while (i + dl <= end) {
			// i is the start of a line
			var match = true;
			for (var k = 0; k < dl; k++) {
				if (bytes[i + k] !== delim[k]) { match = false; break; }
			}
			var nl = indexOfByte(bytes, LF, i, end);
			var lineEnd = nl < 0 ? end : nl;
			if (match) {
				var j = i + dl;
				var close = false;
				if (j + 1 < lineEnd && bytes[j] === DASH && bytes[j + 1] === DASH) { close = true; j += 2; }
				var rest = true;
				for (var r = j; r < lineEnd; r++) {
					var c = bytes[r];
					if (c !== SP && c !== TAB && c !== CR) { rest = false; break; }
				}
				if (rest) {
					found = true;
					if (partStart >= 0) {
						var pe = i;
						if (pe > partStart && bytes[pe - 1] === LF) { pe--; }
						if (pe > partStart && bytes[pe - 1] === CR) { pe--; }
						ranges.push([partStart, Math.max(partStart, pe)]);
					}
					if (close) { return ranges; }
					partStart = nl < 0 ? end : nl + 1;
				}
			}
			if (nl < 0) { break; }
			i = nl + 1;
		}
		if (!found) { return null; }
		if (partStart >= 0 && partStart < end) {
			ranges.push([partStart, end]);  // no close delimiter: the rest is the last part
		}
		return ranges;
	}

	// ── the walk ────────────────────────────────────────────────────────

	/**
	 * Reads one entity in [start, end) and appends its leaves to `leaves`.
	 * `ancestors` is the list of enclosing multipart subtypes.
	 */
	function walk(bytes, start, end, partNo, depth, ancestors, leaves) {
		var split = splitHeaders(bytes, start, end);
		var fields = parseHeaderFields(headerText(bytes.subarray(start, split.headerEnd)));
		var parent = ancestors.length ? ancestors[ancestors.length - 1] : '';

		var ctRaw = firstField(fields, 'content-type');
		var ct = parseStructured(ctRaw || '');
		var type = ct.value;
		if (!ctRaw || !/^[^\/\s]+\/[^\/\s]+$/.test(type)) {
			type = (!ctRaw && parent === 'digest') ? 'message/rfc822' : 'text/plain';
			ct.params.charset = ct.params.charset || 'us-ascii';
		}

		if (type.indexOf('multipart/') === 0 && depth < MAX_DEPTH) {
			var boundary = ct.params.boundary;
			var ranges = boundary ? splitMultipart(bytes, split.bodyStart, end, boundary) : null;
			if (ranges) {
				var sub = type.slice(10);
				var inner = ancestors.concat([sub]);
				for (var i = 0; i < ranges.length; i++) {
					var childNo = partNo === '' ? String(i + 1) : partNo + '.' + (i + 1);
					try {
						walk(bytes, ranges[i][0], ranges[i][1], childNo, depth + 1, inner, leaves);
					} catch (e) {
						leaves.push(opaqueLeaf(bytes, ranges[i][0], ranges[i][1], childNo));
					}
				}
				return;
			}
			// No boundary, or none present: show the body as text.
			type = 'text/plain';
		}

		var disp = parseStructured(firstField(fields, 'content-disposition') || '');
		var cid = (firstField(fields, 'content-id') || '').replace(/^[\s<]+|[\s>]+$/g, '');
		var filename = disp.params.filename || ct.params.name || '';
		if (!filename && (type === 'message/rfc822' || type === 'message/global')) {
			filename = 'message.eml';
		}
		var decoded;
		try {
			decoded = decodeTransfer(bytes.subarray(split.bodyStart, end),
				firstField(fields, 'content-transfer-encoding'));
		} catch (e) {
			decoded = bytes.slice(split.bodyStart, end);
		}
		leaves.push({
			type: type,
			charset: ct.params.charset || '',
			disposition: disp.value,
			hasName: !!(disp.params.filename || ct.params.name),
			filename: filename,
			contentId: cid,
			mimePart: partNo === '' ? '1' : partNo,
			inRelated: ancestors.indexOf('related') >= 0,
			bytes: decoded
		});
	}

	function opaqueLeaf(bytes, start, end, partNo) {
		return {
			type: 'application/octet-stream', charset: '', disposition: 'attachment',
			hasName: false, filename: '', contentId: '', mimePart: partNo || '1',
			inRelated: false, bytes: bytes.slice(start, end)
		};
	}

	function emptyResult() {
		return {
			headers: '', from: '', to: '', cc: '', subject: '', date: '', messageId: '',
			inReplyTo: '', references: '', textPlain: '', textHtml: '', attachments: []
		};
	}

	function toBytes(input) {
		if (input instanceof Uint8Array) { return input; }
		if (typeof ArrayBuffer !== 'undefined') {
			if (input instanceof ArrayBuffer) { return new Uint8Array(input); }
			if (ArrayBuffer.isView && ArrayBuffer.isView(input)) {
				return new Uint8Array(input.buffer, input.byteOffset, input.byteLength);
			}
		}
		if (typeof input === 'string') { return utf8Bytes(input); }
		return null;
	}

	function parse(input) {
		var result = emptyResult();
		var bytes;
		try {
			bytes = toBytes(input);
		} catch (e) {
			bytes = null;
		}
		if (!bytes) { return result; }

		try {
			var split = splitHeaders(bytes, 0, bytes.length);
			result.headers = headerText(bytes.subarray(0, split.headerEnd));
			var fields = parseHeaderFields(result.headers);
			var dec = function (n) { var v = firstField(fields, n); return v === null ? '' : decodeWords(v).trim(); };
			var raw = function (n) { var v = firstField(fields, n); return v === null ? '' : v; };
			result.from = dec('from');
			result.to = dec('to');
			result.cc = dec('cc');
			result.subject = dec('subject');
			result.date = raw('date');
			result.messageId = raw('message-id');
			result.inReplyTo = raw('in-reply-to');
			result.references = raw('references');
		} catch (e) {
			// headers stay as far as they got
		}

		var leaves = [];
		try {
			walk(bytes, 0, bytes.length, '', 0, [], leaves);
		} catch (e) {
			if (!leaves.length) { leaves.push(opaqueLeaf(bytes, 0, bytes.length, '1')); }
		}

		var plainAt = -1, htmlAt = -1;
		for (var i = 0; i < leaves.length; i++) {
			var l = leaves[i];
			if (l.disposition === 'attachment' || l.hasName) { continue; }
			if (plainAt < 0 && l.type === 'text/plain') { plainAt = i; }
			else if (htmlAt < 0 && l.type === 'text/html') { htmlAt = i; }
		}
		for (var j = 0; j < leaves.length; j++) {
			var leaf = leaves[j];
			try {
				if (j === plainAt) { result.textPlain = decodeText(leaf.bytes, leaf.charset); continue; }
				if (j === htmlAt) { result.textHtml = decodeText(leaf.bytes, leaf.charset); continue; }
			} catch (e) {
				// an unreadable body falls through to an attachment
			}
			result.attachments.push({
				filename: leaf.filename,
				contentType: leaf.type,
				contentId: leaf.contentId,
				mimePart: leaf.mimePart,
				inline: leaf.disposition === 'inline' || (leaf.contentId !== '' && leaf.inRelated),
				bytes: leaf.bytes
			});
		}
		return result;
	}

	function headerValue(parsed, name) {
		try {
			var v = firstField(parseHeaderFields(String((parsed && parsed.headers) || '')), name);
			return v === null ? '' : decodeWords(v).trim();
		} catch (e) {
			return '';
		}
	}

	root.MailboxMime = { parse: parse, headerValue: headerValue };
})(typeof window !== 'undefined' ? window : globalThis);
