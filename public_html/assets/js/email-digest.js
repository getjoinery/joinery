/**
 * The AI email digest, built in the browser — a byte-for-byte port of
 * EmailSecurityDigest::buildFromColumns() and
 * EmailAttachmentDigest::buildFromManifest(), and of UntrustedEnvelope's wrap
 * (specs/fortress_mail_device_ai.md § R5).
 *
 * The owner's browser opens an end-to-end encrypted message and asks a model
 * they named for a verdict. The model must see exactly what a server run
 * would show it — the digest's format and caps are corpus-validated — so every
 * PHP step is reproduced here with PHP's semantics rather than JavaScript's
 * nearest equivalent: strip_tags()'s state machine, html_entity_decode() with
 * the HTML5 table (html-entities.js, checked against PHP), PHP's trim()
 * character set, PCRE's \s with and without /u, lengths in code points, and
 * parse_url()'s host. plugins/mailbox/tests/device_ai_digest_parity_test.php
 * builds the same messages both ways and requires the same bytes.
 *
 * Needs window.HtmlEntities. Exposes window.EmailDigest:
 *   build(c)              the digest from opened columns (keys as the PHP side)
 *   attachments(manifest) the ATTACHMENTS section, metadata only ('' for none)
 *   wrapBlock(text, nonce), neutralize(text)   the untrusted-input envelope
 *
 * Vanilla JS, no framework. @version 1.1 - the marker rewrite covers invisible characters (B3)
 */
(function () {
	'use strict';

	var SUBJECT_CAP_CHARS = 1024;
	var BODY_CAP_CHARS = 4096;
	var URL_CAP = 20;
	var DOMAIN_CAP = 15;
	var ANCHOR_TEXT_CAP_CHARS = 120;
	var WHITESPACE_ANNOTATE_THRESHOLD = 200;
	var ATT_MAX_PARTS = 10;
	var ATT_FILENAME_CAP_CHARS = 120;

	var WHITESPACE_RUN = /[ \t ​‌‍⁠﻿　]{4,}/gu;
	// PCRE's \s without /u: ASCII whitespace only.
	var S_ASCII = '[ \\t\\n\\x0B\\f\\r]';
	// PCRE's \s with /u (PHP turns on UCP), as PHP matches it — enumerated over
	// every code point, NEL and U+180E included.
	var S_UCP = '[\\t\\n\\x0B\\f\\r \\u0085\\u00A0\\u1680\\u180E\\u2000-\\u200A\\u2028\\u2029\\u202F\\u205F\\u3000]';

	// ---- PHP's primitives ----------------------------------------------------

	function cps(s) { return Array.from(String(s)); }
	function mbLen(s) { return cps(s).length; }
	function mbSub(s, n) { return cps(s).slice(0, n).join(''); }

	var PHP_TRIM = ' \t\n\r\0\x0B';
	function trim(s, chars) {
		chars = chars === undefined ? PHP_TRIM : chars;
		s = String(s);
		var a = 0, b = s.length;
		while (a < b && chars.indexOf(s.charAt(a)) !== -1) a++;
		while (b > a && chars.indexOf(s.charAt(b - 1)) !== -1) b--;
		return s.slice(a, b);
	}
	function rtrim(s, chars) {
		s = String(s);
		var b = s.length;
		while (b > 0 && chars.indexOf(s.charAt(b - 1)) !== -1) b--;
		return s.slice(0, b);
	}
	function asciiLower(s) {
		return String(s).replace(/[A-Z]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) + 32); });
	}
	function isCSpace(c) { return c === ' ' || c === '\t' || c === '\n' || c === '\x0B' || c === '\f' || c === '\r'; }

	/** PHP's strip_tags() with no allowed tags (php_strip_tags_ex's states). */
	function stripTags(str) {
		str = String(str);
		var out = '';
		var state = 0, depth = 0, inQ = '', lc = '', br = 0, isXml = false;
		var n = str.length;
		for (var i = 0; i < n; i++) {
			var c = str.charAt(i);
			var prev = i > 0 ? str.charAt(i - 1) : '';
			if (c === '\0') continue;
			if (state === 0) {
				if (c === '<') {
					if (inQ) continue;
					if (i + 1 < n && isCSpace(str.charAt(i + 1))) { out += c; continue; }
					lc = '<';
					state = 1;
					continue;
				}
				if (c === '>') {
					if (depth) { depth--; continue; }
					if (inQ) continue;
					out += c;
					continue;
				}
				out += c;
				continue;
			}
			if (state === 1) {
				if (c === '<') {
					if (inQ) continue;
					if (i + 1 < n && isCSpace(str.charAt(i + 1))) continue;
					depth++;
					continue;
				}
				if (c === '>') {
					if (depth) { depth--; continue; }
					if (inQ) continue;
					lc = '>';
					if (isXml && prev === '-') continue;
					inQ = ''; state = 0; isXml = false;
					continue;
				}
				if (c === '"' || c === '\'') {
					if (i !== 0 && (!inQ || c === inQ)) { inQ = inQ ? '' : c; }
					continue;
				}
				if (c === '!' && prev === '<') { state = 3; lc = c; continue; }
				if (c === '?' && prev === '<') { br = 0; state = 2; continue; }
				continue;
			}
			if (state === 2) {
				if (c === '(') { if (lc !== '"' && lc !== '\'') { lc = '('; br++; } continue; }
				if (c === ')') { if (lc !== '"' && lc !== '\'') { lc = ')'; br--; } continue; }
				if (c === '>') {
					if (depth) { depth--; continue; }
					if (inQ) continue;
					if (!br && lc !== '"' && prev === '?') { inQ = ''; state = 0; }
					continue;
				}
				if (c === '"' || c === '\'') {
					if (prev !== '\\') {
						if (lc === c) lc = '';
						else if (lc !== '\\') lc = c;
					}
					if (i !== 0 && (!inQ || c === inQ)) { inQ = inQ ? '' : c; }
					continue;
				}
				if ((c === 'l' || c === 'L') && i > 4 && (prev === 'm' || prev === 'M')
						&& (str.charAt(i - 2) === 'x' || str.charAt(i - 2) === 'X')
						&& str.charAt(i - 3) === '?' && str.charAt(i - 4) === '<') {
					state = 1; isXml = true;
				}
				continue;
			}
			if (state === 3) {
				if (c === '>') {
					if (depth) { depth--; continue; }
					if (inQ) continue;
					inQ = ''; state = 0;
					continue;
				}
				if (c === '"' || c === '\'') {
					if (i !== 0 && prev !== '\\' && (!inQ || c === inQ)) { inQ = inQ ? '' : c; }
					continue;
				}
				if (c === '-' && i >= 2 && prev === '-' && str.charAt(i - 2) === '!') { state = 4; continue; }
				if ((c === 'E' || c === 'e') && i > 6 && asciiLower(str.slice(i - 6, i)) === 'doctyp') {
					state = 1;
					continue;
				}
				continue;
			}
			// state 4: inside <!-- ... -->
			if (c === '>' && !inQ && i >= 2 && prev === '-' && str.charAt(i - 2) === '-') {
				inQ = ''; state = 0;
			}
		}
		return out;
	}

	function cpAllowedHtml5(cp) {
		return (cp >= 0x20 && cp <= 0x7E) || (cp >= 0x09 && cp <= 0x0D && cp !== 0x0B)
			|| (cp >= 0xA0 && cp <= 0xD7FF)
			|| (cp >= 0xE000 && cp <= 0x10FFFF && (cp & 0xFFFF) < 0xFFFE && (cp < 0xFDD0 || cp > 0xFDEF));
	}

	/** PHP's html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'). */
	function decodeEntities(s) {
		s = String(s);
		if (s.indexOf('&') === -1) return s;
		var table = window.HtmlEntities || {};
		var out = '';
		var i = 0, n = s.length;
		while (i < n) {
			var c = s.charAt(i);
			if (c !== '&' || i + 3 >= n) { out += c; i++; continue; }
			var next;
			if (s.charAt(i + 1) === '#') {
				var j = i + 2;
				var hex = s.charAt(j) === 'x' || s.charAt(j) === 'X';
				if (hex) j++;
				var start = j;
				var re = hex ? /[0-9A-Fa-f]/ : /[0-9]/;
				while (j < n && re.test(s.charAt(j))) j++;
				next = j;
				if (j === start || s.charAt(j) !== ';') { out += s.slice(i, next); i = next; continue; }
				var digits = s.slice(start, j).replace(/^0+(?=.)/, '');
				var cp = digits.length > 8 ? Infinity : parseInt(digits, hex ? 16 : 10);
				if (!(cp <= 0x10FFFF) || !cpAllowedHtml5(cp) || cp === 0x0D) { out += s.slice(i, next); i = next; continue; }
				out += String.fromCodePoint(cp);
				i = j + 1;
				continue;
			}
			var k = i + 1;
			while (k < n && /[A-Za-z0-9]/.test(s.charAt(k))) k++;
			next = k;
			if (k === i + 1 || s.charAt(k) !== ';') { out += s.slice(i, next); i = next; continue; }
			var name = s.slice(i + 1, k);
			if (!Object.prototype.hasOwnProperty.call(table, name)) { out += s.slice(i, next); i = next; continue; }
			out += table[name];
			i = k + 1;
		}
		return out;
	}

	/** parse_url($url, PHP_URL_HOST), lower-cased as strtolower() does; '' for none. */
	function urlHost(url) {
		url = String(url);
		var rest;
		var m = /^([a-zA-Z][a-zA-Z0-9+.\-]*):/.exec(url);
		if (m) {
			rest = url.slice(m[0].length);
			if (rest.indexOf('//') !== 0) return '';
			rest = rest.slice(2);
		} else if (url.indexOf('//') === 0) {
			rest = url.slice(2);
		} else {
			return '';
		}
		var end = rest.search(/[\/?#]/);
		var auth = end === -1 ? rest : rest.slice(0, end);
		var at = auth.lastIndexOf('@');
		if (at !== -1) auth = auth.slice(at + 1);
		var host = auth;
		if (!(auth.charAt(0) === '[' && auth.charAt(auth.length - 1) === ']')) {
			var colon = auth.lastIndexOf(':');
			if (colon !== -1) {
				var port = auth.slice(colon + 1);
				if (port !== '') {
					if (!/^[0-9]{1,5}$/.test(port) || parseInt(port, 10) > 65535) return '';
				}
				host = auth.slice(0, colon);
			}
		}
		if (host === '') return '';
		return asciiLower(host.replace(/[\x00-\x1F\x7F]/g, '_'));
	}

	// ---- the digest's own steps ------------------------------------------------

	function collapse(text) {
		var before = mbLen(text);
		var collapsed = String(text).replace(WHITESPACE_RUN, ' ');
		return [collapsed, Math.max(0, before - mbLen(collapsed))];
	}

	function capSize(text, cap) {
		var total = mbLen(text);
		if (total <= cap) return [text, total];
		return [mbSub(text, cap) + '\n[truncated, ' + total + ' characters total]', total];
	}

	function annotation(removed) {
		if (removed <= WHITESPACE_ANNOTATE_THRESHOLD) return '';
		return '; preprocessor removed ' + removed + ' invisible/whitespace characters';
	}

	function selectBody(plain, html) {
		if (trim(plain) !== '') return [plain, 'text/plain'];
		if (trim(html) !== '') return [decodeEntities(stripTags(html)), 'text/html tag-stripped'];
		return ['(no body text)', 'text/plain'];
	}

	function anchorText(inner) {
		var text = decodeEntities(stripTags(inner));
		text = trim(text.replace(new RegExp(S_UCP + '+', 'gu'), ' '));
		if (mbLen(text) > ANCHOR_TEXT_CAP_CHARS) text = mbSub(text, ANCHOR_TEXT_CAP_CHARS) + '…';
		return text;
	}

	function addUrl(found, url, text) {
		url = trim(url);
		if (url === '') return;
		if (!found.has(url)) found.set(url, text);
		else if (found.get(url) === '' && text !== '') found.set(url, text);
	}

	function extractUrls(html, plain) {
		var found = new Map();
		var m;
		if (trim(html) !== '') {
			var a = new RegExp('<a\\b[^>]*href' + S_ASCII + '*=' + S_ASCII + '*["\']([^"\']+)["\'][^>]*>([\\s\\S]*?)<\\/a>', 'gi');
			while ((m = a.exec(html)) !== null) {
				addUrl(found, decodeEntities(m[1]), anchorText(m[2]));
				if (m[0] === '') a.lastIndex++;
			}
			var h = new RegExp('href' + S_ASCII + '*=' + S_ASCII + '*["\']([^"\']+)["\']', 'gi');
			while ((m = h.exec(html)) !== null) {
				addUrl(found, decodeEntities(m[1]), '');
			}
		}
		var visible = trim(plain + ' ' + stripTags(html));
		var u = new RegExp('\\bhttps?:\\/\\/(?:(?!' + S_ASCII + ')[^"\'<>])+', 'gi');
		while ((m = u.exec(visible)) !== null) {
			addUrl(found, rtrim(m[0], '.,;:!?)]}\'"'), '');
		}
		var out = [];
		found.forEach(function (text, url) { out.push({ url: url, text: text }); });
		return out;
	}

	function domainSummary(urls) {
		var counts = new Map();
		urls.forEach(function (e) {
			var host = urlHost(e.url);
			if (host === '') return;
			counts.set(host, (counts.get(host) || 0) + 1);
		});
		if (!counts.size) return '';
		var list = [];
		counts.forEach(function (nn, host) { list.push([host, nn]); });
		list.sort(function (x, y) { return y[1] - x[1]; }); // stable, as PHP 8's arsort
		var parts = list.slice(0, DOMAIN_CAP).map(function (p) { return p[0] + ' (' + p[1] + ')'; });
		if (list.length > DOMAIN_CAP) parts.push('+' + (list.length - DOMAIN_CAP) + ' more domains');
		return parts.join(', ');
	}

	/** RFC 2047 encoded words, as iconv_mime_decode(CONTINUE_ON_ERROR) reads them. */
	function decodeHeaderValue(value) {
		value = String(value || '');
		if (value === '') return '';
		if (value.indexOf('=?') === -1) return value;
		var word = /=\?([^?\s]+)\?([BbQq])\?([^?\s]*)\?=/g;
		var out = '';
		var last = 0;
		var prevWasWord = false;
		var m;
		while ((m = word.exec(value)) !== null) {
			var between = value.slice(last, m.index);
			if (!(prevWasWord && /^[ \t\r\n]*$/.test(between))) out += between;
			var decoded = decodeWord(m[1], m[2], m[3]);
			out += decoded === null ? m[0] : decoded;
			prevWasWord = decoded !== null;
			last = word.lastIndex;
		}
		return out + value.slice(last);
	}

	function decodeWord(charset, enc, text) {
		var bytes;
		try {
			if (enc === 'B' || enc === 'b') {
				var bin = atob(text);
				bytes = new Uint8Array(bin.length);
				for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
			} else {
				var q = text.replace(/_/g, ' ');
				var arr = [];
				for (var j = 0; j < q.length; j++) {
					var ch = q.charAt(j);
					if (ch === '=' && /^[0-9A-Fa-f]{2}$/.test(q.substr(j + 1, 2))) {
						arr.push(parseInt(q.substr(j + 1, 2), 16));
						j += 2;
					} else {
						arr.push(q.charCodeAt(j) & 0xFF);
					}
				}
				bytes = new Uint8Array(arr);
			}
			return new TextDecoder(charset.split('*')[0], { fatal: true }).decode(bytes);
		} catch (e) {
			return null;
		}
	}

	/** The first occurrence of a header's unfolded value, or null (EmailSecurityDigest::extractHeader). */
	function extractHeader(raw, name) {
		var norm = String(raw).split('\r\n').join('\n');
		var split = norm.indexOf('\n\n');
		var block = split !== -1 ? norm.slice(0, split) : norm;
		name = asciiLower(name);
		var collecting = false;
		var current = '';
		var lines = block.split('\n');
		for (var i = 0; i < lines.length; i++) {
			var line = lines[i];
			if (line !== '' && (line.charAt(0) === ' ' || line.charAt(0) === '\t')) {
				if (collecting) current += ' ' + trim(line);
				continue;
			}
			if (collecting) return trim(current);
			var colon = line.indexOf(':');
			if (colon === -1) continue;
			if (asciiLower(trim(line.slice(0, colon))) === name) {
				collecting = true;
				current = trim(line.slice(colon + 1));
			}
		}
		return collecting ? trim(current) : null;
	}

	/** Every occurrence, unfolded (AuthenticationResults::extractHeaders). */
	function extractHeaders(raw, name) {
		var norm = String(raw).split('\r\n').join('\n');
		var split = norm.indexOf('\n\n');
		var block = split !== -1 ? norm.slice(0, split) : norm;
		name = asciiLower(name);
		var values = [];
		var collecting = false;
		var current = '';
		block.split('\n').forEach(function (line) {
			if (line !== '' && (line.charAt(0) === ' ' || line.charAt(0) === '\t')) {
				if (collecting) current += ' ' + trim(line);
				return;
			}
			if (collecting) { values.push(current); collecting = false; current = ''; }
			var colon = line.indexOf(':');
			if (colon === -1) return;
			if (asciiLower(trim(line.slice(0, colon))) === name) {
				collecting = true;
				current = trim(line.slice(colon + 1));
			}
		});
		if (collecting) values.push(current);
		return values;
	}

	/** The DKIM d= from our own Authentication-Results stamp (AuthenticationResults::fromMessage). */
	function dkimDomain(raw, authservId) {
		if (raw === null) return '';
		var ours = asciiLower(trim(String(authservId || '')));
		if (ours === '') return '';
		var domain = null;
		extractHeaders(raw, 'authentication-results').forEach(function (line) {
			var segs = line.split(';').map(function (p) { return trim(p); }).filter(function (p) { return p !== ''; });
			if (!segs.length) return;
			var first = trim(segs[0]).split(new RegExp(S_ASCII + '+'));
			if (asciiLower(first[0] || '') !== ours) return;
			for (var i = 1; i < segs.length; i++) {
				var seg = trim(segs[i]);
				var mm = new RegExp('^([A-Za-z][A-Za-z0-9-]*)' + S_ASCII + '*=' + S_ASCII + '*([A-Za-z0-9]+)').exec(seg);
				if (!mm || asciiLower(mm[1]) !== 'dkim') continue;
				var result = asciiLower(mm[2]);
				var d = null;
				['header.d', 'header.i'].some(function (key) {
					var pm = new RegExp('\\b' + key.replace('.', '\\.') + S_ASCII + '*=' + S_ASCII + '*"?([^";' + S_ASCII.slice(1, -1) + ']+)"?', 'i').exec(seg);
					if (pm) { d = asciiLower(rtrim(trim(pm[1]), '.')); return true; }
					return false;
				});
				if (d !== null && (domain === null || result === 'pass')) domain = d;
			}
		});
		return domain === null ? '' : domain;
	}

	// ---- the digest -------------------------------------------------------------

	/** EmailSecurityDigest::buildFromColumns(), key for key. */
	function build(c) {
		var raw = (c.raw !== undefined && c.raw !== null && c.raw !== '') ? String(c.raw) : null;
		var fromRaw = raw !== null ? extractHeader(raw, 'from') : String(c.sender || '');
		var replyRaw = raw !== null ? extractHeader(raw, 'reply-to') : null;
		var returnRaw = raw !== null ? extractHeader(raw, 'return-path') : null;
		var toRaw = raw !== null ? extractHeader(raw, 'to') : String(c.recipient || '');
		var dateRaw = raw !== null ? extractHeader(raw, 'date') : String(c.received_time || '');
		var subjectRaw = raw !== null ? extractHeader(raw, 'subject') : String(c.subject || '');

		var from = decodeHeaderValue(fromRaw === null ? '' : fromRaw);
		var replyTo = replyRaw !== null && trim(replyRaw) !== '' ? decodeHeaderValue(replyRaw) : '(none)';
		var returnPath = returnRaw !== null && trim(returnRaw) !== '' ? trim(returnRaw, '<> \t') : '(none)';
		var to = decodeHeaderValue(toRaw === null ? '' : toRaw);
		var date = trim(dateRaw === null ? '' : dateRaw) !== '' ? trim(dateRaw) : '(unknown)';

		var spf = String(c.spf_result || '') || 'unverified';
		var dkim = String(c.dkim_result || '') || 'unverified';
		var dmarc = String(c.dmarc_result || '') || 'unverified';
		var dDomain = dkimDomain(raw, c.authserv_id);

		var subj = collapse(decodeHeaderValue(subjectRaw === null ? '' : subjectRaw));
		var subjectText = capSize(subj[0], SUBJECT_CAP_CHARS)[0];

		var plain = String(c.body_plain || '');
		var html = String(c.body_html || '');
		var sel = selectBody(plain, html);
		var body = collapse(sel[0]);
		var bodyText = capSize(body[0], BODY_CAP_CHARS)[0];

		var urls = extractUrls(html, plain);

		var lines = [];
		lines.push('=== EMAIL DIGEST ===');
		lines.push('FROM: ' + from);
		lines.push('REPLY-TO: ' + replyTo);
		lines.push('RETURN-PATH: ' + returnPath);
		lines.push('TO: ' + to);
		lines.push('DATE: ' + date);
		lines.push('AUTHENTICATION: spf=' + spf + ' dkim=' + dkim + ' (d=' + (dDomain !== '' ? dDomain : 'none') + ') dmarc=' + dmarc);
		lines.push('');
		lines.push('SUBJECT (decoded' + annotation(subj[1]) + '):');
		lines.push(subjectText);
		lines.push('');
		lines.push('URLS FOUND (' + urls.length + '):');
		if (!urls.length) {
			lines.push('(none found)');
		} else {
			var summary = domainSummary(urls);
			if (summary !== '') lines.push('DOMAINS: ' + summary);
			var shown = urls.slice(0, URL_CAP);
			shown.forEach(function (e, i) {
				var line = (i + 1) + '. ' + e.url;
				if (e.text !== '' && e.text !== e.url) line += ' — link text: "' + e.text + '"';
				lines.push(line);
			});
			if (urls.length > shown.length) lines.push('(+' + (urls.length - shown.length) + ' more)');
		}
		lines.push('');
		lines.push('BODY (' + sel[1] + ', decoded' + annotation(body[1]) + '):');
		lines.push(bodyText);
		return lines.join('\n');
	}

	/** EmailAttachmentDigest::buildFromManifest(). */
	function attachments(manifest) {
		var parts = (Array.isArray(manifest) ? manifest : []).filter(function (e) {
			return e && typeof e === 'object' && !e.inline;
		});
		if (!parts.length) return '';
		var lines = ['ATTACHMENTS (' + parts.length + '):'];
		var shown = 0;
		for (var i = 0; i < parts.length && shown < ATT_MAX_PARTS; i++) {
			shown++;
			var e = parts[i];
			var filename = trim(e.filename == null ? '' : String(e.filename)).replace(WHITESPACE_RUN, ' ');
			if (filename === '') filename = '(unnamed)';
			else if (mbLen(filename) > ATT_FILENAME_CAP_CHARS) filename = mbSub(filename, ATT_FILENAME_CAP_CHARS);
			var type = trim(e.content_type == null ? '' : String(e.content_type));
			if (type === '') type = 'application/octet-stream';
			var size = parseInt(e.size, 10) || 0;
			lines.push(shown + '. ' + filename + ' — ' + type + ', ' + size + ' bytes');
		}
		if (parts.length > shown) lines.push('(+' + (parts.length - shown) + ' more attachments)');
		return lines.join('\n');
	}

	// ---- the untrusted-input envelope (UntrustedEnvelope) -------------------------

	// UntrustedEnvelope::MARKER_PATTERN: whitespace or invisible formatting
	// characters tolerated around the slash.
	var GAP = '[' + S_UCP.slice(1, -1) + '\\u00AD\\u200B-\\u200F\\u202A-\\u202E\\u2060-\\u2064\\u2066-\\u206F\\uFEFF]*';
	var MARKER = new RegExp('<<' + GAP + '\\/?' + GAP + 'UNTRUSTED_', 'giu');
	function neutralize(content) { return String(content).replace(MARKER, '[marker removed]'); }
	function wrapBlock(content, nonce) {
		return '<<UNTRUSTED_' + nonce + '>>\n' + neutralize(content) + '\n<</UNTRUSTED_' + nonce + '>>';
	}

	window.EmailDigest = {
		build: build,
		attachments: attachments,
		wrapBlock: wrapBlock,
		neutralize: neutralize,
		// The PHP ports, for the parity suite.
		_php: { stripTags: stripTags, decodeEntities: decodeEntities, urlHost: urlHost, trim: trim,
			decodeHeaderValue: decodeHeaderValue, dkimDomain: dkimDomain },
	};
})();
