/**
 * MailboxMimeBuild - a whole RFC822 message built in the browser from what a
 * Fortress row holds, the twin of the server's MessageRebuild
 * (plugins/mailbox/includes/MessageRebuild.php).
 *
 * The server keeps no original of an end-to-end message and cannot read its
 * fields, so a Fortress mailbox export (mailbox_export.js) builds each message
 * here from the opened header block, bodies and parts. The tree and the bytes
 * are MessageRebuild's, FORMAT 1, for the same inputs: the retained header
 * block minus its MIME headers (or headers made from the row's fields), then
 * multipart/mixed over multipart/related (HTML and its inline parts by
 * Content-ID) over multipart/alternative (plain and HTML); bodies UTF-8
 * quoted-printable, parts base64 at 76 columns, CRLF throughout, boundaries
 * from the message id. plugins/mailbox/tests/mime_build_js_test.php builds
 * the same message both ways and compares them byte for byte.
 *
 *   var bytes = await MailboxMimeBuild.build({
 *     id, raw_headers, sender, to, cc, bcc, recipient, direction, subject,
 *     received_time, message_id_header, body_plain, body_html,
 *     parts: [{content_type, filename, content_id, inline, mime_part, bytes}]   // in id order
 *   });
 *   MailboxMimeBuild.marked(bytes)   // X-Joinery-Rebuilt: yes in front
 *   MailboxMimeBuild.mboxEntry(bytes, labels, envelopeOf(sender), unixTime)   // MailExport::mboxEntry's bytes
 *
 * Vanilla JS; runs under node too (module.exports). @version 1.1 - From-escaping starts lines after \n only, as the server does
 */
(function (root) {
	'use strict';

	var FORMAT = 1;
	var LINE = 76;
	var MARKER = 'X-Joinery-Rebuilt: yes';
	var enc = new TextEncoder();

	function utf8(s) { return enc.encode(String(s == null ? '' : s)); }

	function concat(list) {
		var n = 0, i;
		for (i = 0; i < list.length; i++) n += list[i].length;
		var out = new Uint8Array(n), at = 0;
		for (i = 0; i < list.length; i++) { out.set(list[i], at); at += list[i].length; }
		return out;
	}

	function crlf(s) { return String(s).replace(/\r\n|\n|\r/g, '\r\n'); }

	function isComposed(direction) { return direction === 'outbound' || direction === 'draft'; }

	// ── encoders (MessageRebuild's) ────────────────────────────────────────

	/** Quoted-printable over the UTF-8 bytes; CRLF line breaks, soft breaks before 76. */
	function qp(text) {
		text = String(text == null ? '' : text).replace(/\r\n|\r/g, '\n');
		var lines = text.split('\n'), out = [];
		for (var l = 0; l < lines.length; l++) {
			var bytes = utf8(lines[l]), encoded = '', current = 0;
			for (var i = 0; i < bytes.length; i++) {
				var o = bytes[i], last = (i === bytes.length - 1), token;
				if ((o >= 33 && o <= 126 && o !== 61) || ((o === 32 || o === 9) && !last)) {
					token = String.fromCharCode(o);
				} else {
					token = '=' + (o < 16 ? '0' : '') + o.toString(16).toUpperCase();
				}
				if (current + token.length > LINE - 1) {
					encoded += '=\r\n';
					current = 0;
				}
				encoded += token;
				current += token.length;
			}
			out.push(encoded);
		}
		return out.join('\r\n');
	}

	function base64(bytes) {
		var s = '', CH = 3 * 8192;
		for (var i = 0; i < bytes.length; i += CH) {
			s += btoa(String.fromCharCode.apply(null, bytes.subarray(i, i + CH)));
		}
		return s;
	}

	/** base64 at 76 columns, CRLF between lines, none after the last. */
	function base64Lines(bytes) {
		if (!bytes.length) return '';
		var b = base64(bytes), out = [];
		for (var i = 0; i < b.length; i += LINE) out.push(b.slice(i, i + LINE));
		return out.join('\r\n');
	}

	function plainAscii(s) { return /^[\x20-\x7e]*$/.test(s); }

	/** UTF-8 base64 encoded words of at most 45 source bytes, folded. */
	function encodedWords(text) {
		var words = [], chunk = [], size = 0;
		for (var ch of text) {
			var b = utf8(ch);
			if (size + b.length > 45) {
				words.push('=?UTF-8?B?' + base64(concat(chunk)) + '?=');
				chunk = []; size = 0;
			}
			chunk.push(b); size += b.length;
		}
		if (size) words.push('=?UTF-8?B?' + base64(concat(chunk)) + '?=');
		return words.join('\r\n ');
	}

	function encodeText(text) {
		text = String(text == null ? '' : text).replace(/[\r\n\t]/g, ' ').trim();
		return plainAscii(text) ? text : encodedWords(text);
	}

	function encodePhrase(name) {
		name = String(name).replace(/[\r\n\t"\\]/g, ' ').trim();
		return plainAscii(name) ? '"' + name + '"' : encodedWords(name);
	}

	function nameParam(param, name) {
		if (/^[\x20-\x7e]+$/.test(name) && !/["\\]/.test(name)) return param + '="' + name + '"';
		return param + "*=UTF-8''" + encodeURIComponent(name).replace(/[!'()*]/g, function (c) {
			return '%' + c.charCodeAt(0).toString(16).toUpperCase();
		});
	}

	// ── addresses (MailAddressList::parse) ─────────────────────────────────

	function splitTopLevel(s) {
		var pieces = [], buf = '', inQuote = false, depth = 0, inAngle = false;
		for (var i = 0; i < s.length; i++) {
			var c = s[i];
			if (inQuote) {
				if (c === '\\' && i + 1 < s.length) { buf += c + s[++i]; continue; }
				if (c === '"') inQuote = false;
				buf += c; continue;
			}
			if (depth > 0) {
				if (c === '\\' && i + 1 < s.length) { buf += c + s[++i]; continue; }
				if (c === '(') depth++; else if (c === ')') depth--;
				buf += c; continue;
			}
			if (c === '"') { inQuote = true; buf += c; }
			else if (c === '(') { depth = 1; buf += c; }
			else if (c === '<') { inAngle = true; buf += c; }
			else if (c === '>') { inAngle = false; buf += c; }
			else if (c === ':') { if (inAngle) buf += c; else buf = ''; }
			else if (c === ',' || c === ';') { if (inAngle) buf += c; else { pieces.push(buf); buf = ''; } }
			else buf += c;
		}
		pieces.push(buf);
		return pieces;
	}

	function stripComments(s) {
		var out = '', inQuote = false, depth = 0;
		for (var i = 0; i < s.length; i++) {
			var c = s[i];
			if (inQuote) {
				if (c === '\\' && i + 1 < s.length) { out += c + s[++i]; continue; }
				if (c === '"') inQuote = false;
				out += c; continue;
			}
			if (depth > 0) {
				if (c === '\\' && i + 1 < s.length) { i++; continue; }
				if (c === '(') depth++; else if (c === ')') depth--;
				continue;
			}
			if (c === '"') inQuote = true;
			else if (c === '(') { depth = 1; continue; }
			out += c;
		}
		return out;
	}

	function cleanName(name) { return name.replace(/[\r\n\t"<>]+/g, ' ').replace(/\s+/g, ' ').trim(); }

	function trimChars(s, chars) {
		var a = 0, b = s.length;
		while (a < b && chars.indexOf(s[a]) !== -1) a++;
		while (b > a && chars.indexOf(s[b - 1]) !== -1) b--;
		return s.slice(a, b);
	}

	function parseEntry(piece) {
		var name = '', email = '';
		var re = /<([^<>]*)>/g, m, last = null;
		while ((m = re.exec(piece)) !== null) last = m;
		if (last) {
			email = last[1].trim();
			name = piece.slice(0, last.index);
		} else {
			email = stripComments(piece).trim();
		}
		email = trimChars(email, ' \t"\'');
		if (email === '' || email.indexOf('@') === -1) return null;
		name = stripComments(name).trim();
		if (name.length >= 2 && name[0] === '"' && name[name.length - 1] === '"') {
			name = name.slice(1, -1).replace(/\\(.)/g, '$1');
		}
		return { name: cleanName(name), email: email };
	}

	function parseAddresses(header) {
		var out = [], seen = {};
		splitTopLevel(String(header || '')).forEach(function (piece) {
			piece = piece.trim();
			if (!piece) return;
			var e = parseEntry(piece);
			if (!e) return;
			var key = e.email.toLowerCase();
			if (seen[key]) return;
			seen[key] = true;
			out.push(e);
		});
		return out;
	}

	function addressList(entries) {
		return entries.map(function (e) {
			var email = e.email.replace(/[\s<>"]/g, '');
			var name = (e.name || '').trim();
			return name ? encodePhrase(name) + ' <' + email + '>' : '<' + email + '>';
		}).join(',\r\n ');
	}

	// ── the message ────────────────────────────────────────────────────────

	var DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
	var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
	function two(n) { return (n < 10 ? '0' : '') + n; }

	function rfcDate(utc) {
		var t = utc ? Date.parse(String(utc).replace(' ', 'T').replace(/(\.\d{3})\d*$/, '$1') + 'Z') : NaN;
		var d = new Date(isNaN(t) ? 0 : Math.floor(t / 1000) * 1000);
		return DAYS[d.getUTCDay()] + ', ' + two(d.getUTCDate()) + ' ' + MONTHS[d.getUTCMonth()] + ' ' + d.getUTCFullYear()
			+ ' ' + two(d.getUTCHours()) + ':' + two(d.getUTCMinutes()) + ':' + two(d.getUTCSeconds()) + ' +0000';
	}

	function synthesizedHeaders(m) {
		var lines = ['Date: ' + rfcDate(m.received_time)];
		var from = parseAddresses(m.sender);
		if (from.length) lines.push('From: ' + addressList(from));
		var to = String(m.to || '').trim();
		if (to === '' && isComposed(m.direction)) to = String(m.recipient || '');
		var list;
		if (to !== '' && (list = parseAddresses(to)).length) lines.push('To: ' + addressList(list));
		var cc = String(m.cc || '').trim();
		if (cc !== '' && (list = parseAddresses(cc)).length) lines.push('Cc: ' + addressList(list));
		if (isComposed(m.direction)) {
			var bcc = String(m.bcc || '').trim();
			if (bcc !== '' && (list = parseAddresses(bcc)).length) lines.push('Bcc: ' + addressList(list));
		}
		lines.push('Subject: ' + encodeText(m.subject));
		var mid = String(m.message_id_header || '').trim();
		if (/^<[^<>\s]+>$/.test(mid)) lines.push('Message-ID: ' + mid);
		return lines.join('\r\n');
	}

	function messageHeaders(m) {
		var retained = String(m.raw_headers || '');
		if (retained.trim() === '') return synthesizedHeaders(m);
		var out = [], skip = false;
		trimChars(crlf(retained), '\r\n').split(/\r\n|\n|\r/).forEach(function (line) {
			if (line !== '' && (line[0] === ' ' || line[0] === '\t')) {
				if (!skip) out.push(line);
				return;
			}
			var colon = line.indexOf(':');
			var name = colon === -1 ? '' : line.slice(0, colon).trim().toLowerCase();
			skip = (name === 'mime-version' || name.indexOf('content-') === 0);
			if (!skip && line !== '') out.push(line);
		});
		return out.join('\r\n');
	}

	async function boundary(subtype, id) {
		var digest = await crypto.subtle.digest('SHA-256', utf8('mail_apps|' + FORMAT + '|' + id + '|' + subtype));
		var hex = Array.prototype.map.call(new Uint8Array(digest), function (b) { return (b < 16 ? '0' : '') + b.toString(16); }).join('');
		return '=_jm' + FORMAT + '_' + hex.slice(0, 28);
	}

	function textPart(type, text) {
		return { headers: 'Content-Type: ' + type + '; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable', segments: [qp(text)] };
	}

	function attachmentPart(p, inline) {
		var type = String(p.content_type || '').trim().toLowerCase();
		if (!/^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/.test(type)) type = 'application/octet-stream';
		var name = String(p.filename || '').replace(/[\r\n\t]/g, ' ').trim() || 'attachment';
		var headers = 'Content-Type: ' + type + '; ' + nameParam('name', name)
			+ '\r\nContent-Disposition: ' + (inline ? 'inline' : 'attachment') + '; ' + nameParam('filename', name)
			+ '\r\nContent-Transfer-Encoding: base64';
		var cid = trimChars(String(p.content_id || ''), '<> ');
		if (cid !== '' && /^[\x21-\x7e]+$/.test(cid)) headers += '\r\nContent-ID: <' + cid + '>';
		return { headers: headers, segments: [p] };
	}

	async function multipart(subtype, id, parts) {
		var b = await boundary(subtype, id), segments = [];
		parts.forEach(function (part) {
			segments.push('--' + b + '\r\n' + part.headers + '\r\n\r\n');
			part.segments.forEach(function (s) { segments.push(s); });
			segments.push('\r\n');
		});
		segments.push('--' + b + '--');
		return { headers: 'Content-Type: multipart/' + subtype + '; boundary="' + b + '"', segments: segments };
	}

	/** The rebuilt message's bytes (unmarked). */
	async function build(m) {
		var id = parseInt(m.id, 10);
		var plain = String(m.body_plain || ''), html = String(m.body_html || '');
		var parts = (m.parts || []).slice();
		// An attached message travels whole; its insides are not served again beside it.
		var enclosing = parts.filter(function (p) { return String(p.content_type || '').toLowerCase().indexOf('message/') === 0; })
			.map(function (p) { return String(p.mime_part || '') + '.'; });
		var inline = [], attached = [];
		parts.forEach(function (p) {
			var part = String(p.mime_part || '');
			for (var i = 0; i < enclosing.length; i++) {
				if (enclosing[i] !== '.' && part.indexOf(enclosing[i]) === 0) return;
			}
			var cid = trimChars(String(p.content_id || ''), '<> ');
			if (p.inline && cid !== '' && html !== '') inline.push(p); else attached.push(p);
		});

		var texts = [];
		if (plain !== '' || html === '') texts.push(textPart('text/plain', plain));
		if (html !== '') texts.push(textPart('text/html', html));
		var body = texts.length === 2 ? await multipart('alternative', id, texts) : texts[0];
		if (inline.length) body = await multipart('related', id, [body].concat(inline.map(function (p) { return attachmentPart(p, true); })));
		if (attached.length) body = await multipart('mixed', id, [body].concat(attached.map(function (p) { return attachmentPart(p, false); })));

		var out = [utf8(messageHeaders(m) + '\r\nMIME-Version: 1.0\r\n' + body.headers + '\r\n\r\n')];
		body.segments.forEach(function (s) {
			out.push(typeof s === 'string' ? utf8(s) : utf8(base64Lines(s.bytes || new Uint8Array(0))));
		});
		out.push(utf8('\r\n'));
		return concat(out);
	}

	// ── mbox (the server's MailExport::mboxEntry) ──────────────────────────

	// A byte string: one char per byte, both ways, with no text decoding.
	function toBin(u8) {
		var s = '', CH = 8192;
		for (var i = 0; i < u8.length; i += CH) s += String.fromCharCode.apply(null, u8.subarray(i, i + CH));
		return s;
	}
	function fromBin(s) {
		var out = new Uint8Array(s.length);
		for (var i = 0; i < s.length; i++) out[i] = s.charCodeAt(i);
		return out;
	}

	/** asctime(3), UTC: "Thu Jan  1 00:00:00 2026". */
	function asctime(t) {
		var d = new Date(t * 1000), date = d.getUTCDate();
		return DAYS[d.getUTCDay()] + ' ' + MONTHS[d.getUTCMonth()] + ' ' + (date < 10 ? ' ' : '') + date + ' '
			+ two(d.getUTCHours()) + ':' + two(d.getUTCMinutes()) + ':' + two(d.getUTCSeconds()) + ' ' + d.getUTCFullYear();
	}

	/** The separator's sender: the message's From address when it is a plain one. */
	function envelopeOf(sender) {
		var list = parseAddresses(sender || '');
		var email = list.length ? list[0].email : '';
		return /^[^\s<>"@]+@[^\s<>"@]+$/.test(email) ? email : 'MAILER-DAEMON';
	}

	/**
	 * One message as an mbox entry: the separator, the X-Gmail-Labels header,
	 * the message with its From lines escaped (mboxrd), a blank line.
	 */
	function mboxEntry(raw, labels, envelope, time) {
		var text = toBin(raw);
		var crlf = text.indexOf('\r\n'), lf = text.indexOf('\n');
		var eol = (crlf !== -1 && crlf <= lf) ? '\r\n' : '\n';
		// Line starts as PHP's /m sees them: after \n only, never after a bare \r.
		text = (toBin(utf8('X-Gmail-Labels: ' + labels)) + eol + text).replace(/(^|\n)(>*From )/g, '$1>$2');
		if (text.slice(-1) !== '\n') text += eol;
		return fromBin('From ' + envelope + ' ' + asctime(time) + '\n' + text + eol);
	}

	function marked(bytes) { return concat([utf8(MARKER + '\r\n'), bytes]); }

	var api = { FORMAT: FORMAT, build: build, marked: marked, mboxEntry: mboxEntry, envelopeOf: envelopeOf,
		parseAddresses: parseAddresses, qp: qp, base64Lines: base64Lines };
	if (typeof module !== 'undefined' && module.exports) module.exports = api;
	else root.MailboxMimeBuild = api;
})(typeof window !== 'undefined' ? window : this);
