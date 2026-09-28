/**
 * Exercises the browser MIME parser (assets/mailbox_mime.js) under Node.
 *
 * A message sealed at the relay is parsed in the owner's browser, not on the
 * server, so this parser decides what the owner reads: the sender, subject and
 * bodies, and which bytes become which attachment under which MIME part number
 * (the number is part of each attachment's AD, so it must match the server's
 * IMAP-style numbering exactly). The file is a plain browser script; it is
 * evaluated here as-is and read back from globalThis.MailboxMime.
 *
 * Each fixture in fixtures/mime/ pins one family of real-world shapes:
 *   plain.eml                     a single text/plain part, folded Subject
 *   alternative.eml               text + html alternatives, preamble/epilogue,
 *                                 transport padding after a boundary
 *   nested_inline_attachment.eml  mixed > related > alternative, a cid image
 *                                 and a base64 pdf whose bytes must round-trip
 *   qp_latin1.eml                 quoted-printable ISO-8859-1, soft breaks
 *   rfc2047_subject.eml           B and Q encoded words, adjacent words joined
 *   rfc2231_filename.eml          2231 continuations with charset, a 2047
 *                                 filename in quotes, the name*= form
 *   bare_lf.eml                   the same structure with LF-only lines
 *   malformed.eml                 no close boundary, junk in base64, a
 *                                 multipart with no boundary parameter
 * plus built-in cases: input that is not a message, nesting past the depth
 * cap, and a message with no Content-Type at all. The parser must never throw.
 *
 * @version 1.0
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
new Function(readFileSync(join(here, '..', 'assets', 'mailbox_mime.js'), 'utf8'))();
const Mime = globalThis.MailboxMime;

let passed = 0;
let failed = 0;

function eq(label, actual, expected) {
	if (actual === expected) {
		console.log('ok - ' + label);
		passed++;
	} else {
		console.log('FAIL - ' + label + ' (got ' + JSON.stringify(actual)
			+ ', want ' + JSON.stringify(expected) + ')');
		failed++;
	}
}

function ok(label, cond) { eq(label, !!cond, true); }

function sameBytes(a, b) {
	if (!(a instanceof Uint8Array) || a.length !== b.length) { return false; }
	for (let i = 0; i < a.length; i++) { if (a[i] !== b[i]) { return false; } }
	return true;
}

function load(name) {
	return new Uint8Array(readFileSync(join(here, 'fixtures', 'mime', name)));
}

function parse(name) {
	try {
		return Mime.parse(load(name));
	} catch (e) {
		eq(name + ' parses without throwing', String(e), 'no exception');
		return null;
	}
}

const text = (s) => new TextEncoder().encode(s);

if (!Mime || typeof Mime.parse !== 'function' || typeof Mime.headerValue !== 'function') {
	console.log('FAIL - mailbox_mime.js did not set globalThis.MailboxMime {parse, headerValue}');
	console.log('RESULT: FAIL 0 1');
	process.exit(1);
}

console.log('== plain.eml ==');
{
	const p = parse('plain.eml');
	eq('from', p.from, 'Alice Example <alice@example.com>');
	eq('to, the list as written', p.to, 'Bob <bob@example.org>, carol@example.org');
	eq('cc absent is empty', p.cc, '');
	eq('folded subject unfolded', p.subject, 'A plain message whose subject is folded across two lines');
	eq('date raw', p.date, 'Mon, 28 Sep 2026 10:00:00 +0000');
	eq('message-id raw', p.messageId, '<plain-1@example.com>');
	eq('in-reply-to absent', p.inReplyTo, '');
	eq('utf-8 8bit body', p.textPlain, 'Hello Bob, this is naïve café text.\r\nSecond line.\r\n');
	eq('no html', p.textHtml, '');
	eq('no attachments', p.attachments.length, 0);
	ok('headers is the raw block from the first line', p.headers.indexOf('Return-Path: <alice@example.com>\r\n') === 0);
	ok('headers keeps the fold as written', p.headers.indexOf('subject\r\n is folded') > 0);
	ok('headers stops before the blank line', /Content-Transfer-Encoding: 8bit\r\n$/.test(p.headers));
	eq('headerValue is case-insensitive and unfolded', Mime.headerValue(p, 'SUBJECT'), p.subject);
	eq('headerValue of a missing header', Mime.headerValue(p, 'X-Nope'), '');
	eq('headerValue first occurrence', Mime.headerValue(p, 'return-path'), '<alice@example.com>');
}

console.log('== alternative.eml ==');
{
	const p = parse('alternative.eml');
	eq('plain alternative', p.textPlain, 'Plain version');
	eq('html alternative', p.textHtml, '<p>HTML version</p>');
	eq('cc', p.cc, 'dave@example.org');
	eq('in-reply-to', p.inReplyTo, '<parent@example.com>');
	eq('references unfolded', p.references, '<root@example.com>\t<parent@example.com>');
	eq('bodies are not attachments', p.attachments.length, 0);
}

console.log('== nested_inline_attachment.eml ==');
{
	const p = parse('nested_inline_attachment.eml');
	const pdf = new Uint8Array([0x25, 0x50, 0x44, 0x46, 0x2d, 0x31, 0x2e, 0x34, 0x0a, 0x00, 0x01, 0x02,
		0xfe, 0xff, 0x80, 0x0d, 0x0a, 0x25, 0x25, 0x45, 0x4f, 0x46]);
	const png = new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x00, 0x00, 0x00, 0x0d,
		0x49, 0x48, 0x44, 0x52]);
	eq('plain found through mixed > related > alternative', p.textPlain, 'See the logo.');
	eq('html found likewise', p.textHtml, '<p>See <img src="cid:logo@example.com"></p>');
	eq('two attachments', p.attachments.length, 2);
	const [img, doc] = p.attachments;
	eq('image part number', img && img.mimePart, '1.2');
	eq('image type', img && img.contentType, 'image/png');
	eq('image content-id without brackets', img && img.contentId, 'logo@example.com');
	eq('image with cid inside related is inline', img && img.inline, true);
	eq('image has no filename', img && img.filename, '');
	ok('image bytes decoded', img && sameBytes(img.bytes, png));
	eq('pdf part number', doc && doc.mimePart, '2');
	eq('pdf type', doc && doc.contentType, 'application/pdf');
	eq('pdf filename', doc && doc.filename, 'report.pdf');
	eq('pdf is not inline', doc && doc.inline, false);
	eq('pdf has no content-id', doc && doc.contentId, '');
	ok('pdf bytes round-trip exactly', doc && sameBytes(doc.bytes, pdf));
}

console.log('== qp_latin1.eml ==');
{
	const p = parse('qp_latin1.eml');
	eq('latin-1 qp with a soft break and trailing space removed', p.textPlain,
		'Un café crème, s\'il vous plaît. Cette ligne est très longue et se poursuit ici.\r\na=b\r\n');
}

console.log('== rfc2047_subject.eml ==');
{
	const p = parse('rfc2047_subject.eml');
	eq('B and Q words, adjacent words joined, plain text kept', p.subject, 'Ça va très bien et fin €');
	eq('encoded display name in From', p.from, 'Jürgen Müller <juergen@example.de>');
	eq('encoded word inside quotes in To', p.to, '"André" <andre@example.fr>');
	eq('headerValue decodes too', Mime.headerValue(p, 'from'), p.from);
}

console.log('== rfc2231_filename.eml ==');
{
	const p = parse('rfc2231_filename.eml');
	eq('body', p.textPlain, 'Two files.');
	eq('three attachments', p.attachments.length, 3);
	const [a, b, c] = p.attachments;
	eq('2231 continuation with charset and a plain segment', a && a.filename, 'Übersicht Q3 €.txt');
	eq('its part number', a && a.mimePart, '2');
	ok('its bytes', a && sameBytes(a.bytes, text('one')));
	eq('2047 name in quotes', b && b.filename, 'été.pdf');
	eq('a named text/plain is an attachment, not the body', b && b.contentType, 'text/plain');
	eq('its part number', b && b.mimePart, '3');
	eq('name*= with a latin-1 charset', c && c.filename, 'café.jpg');
	eq('disposition inline is inline', c && c.inline, true);
	eq('attachment disposition is not inline', a && a.inline, false);
}

console.log('== bare_lf.eml ==');
{
	const p = parse('bare_lf.eml');
	eq('fold unfolded on LF lines', p.subject, 'Bare  LF');
	eq('plain', p.textPlain, 'Line one\nLine two');
	eq('html', p.textHtml, '<b>bold</b>');
	eq('no attachments', p.attachments.length, 0);
	ok('headers end on LF', /Content-Type: multipart\/alternative; boundary=b1\n$/.test(p.headers));
}

console.log('== malformed.eml ==');
{
	const p = parse('malformed.eml');
	ok('parsed', p !== null);
	eq('subject', p.subject, 'Broken');
	eq('text before the missing close boundary', p.textPlain, 'Readable text.');
	eq('the rest become attachments', p.attachments.length, 2);
	const [junk, rest] = p.attachments;
	eq('junk characters in base64 are skipped', junk && new TextDecoder().decode(junk.bytes), 'hello world');
	eq('its filename', junk && junk.filename, 'junk.bin');
	eq('a multipart with no boundary reads as text', rest && rest.contentType, 'text/plain');
	eq('the unterminated last part runs to the end', rest && new TextDecoder().decode(rest.bytes),
		'a multipart with no boundary\r\n');
	eq('its part number', rest && rest.mimePart, '3');
}

console.log('== never throws ==');
{
	const shape = ['headers', 'from', 'to', 'cc', 'subject', 'date', 'messageId', 'inReplyTo',
		'references', 'textPlain', 'textHtml', 'attachments'];
	const cases = {
		'null': null,
		'a number': 42,
		'empty bytes': new Uint8Array(0),
		'random bytes': Uint8Array.from({ length: 4096 }, (_, i) => (i * 7919 + 13) % 256),
		'headers only, no blank line': text('Subject: x\r\nFrom: y'),
		'an unknown charset and encoding': text('Content-Type: text/plain; charset=x-klingon\r\n'
			+ 'Content-Transfer-Encoding: x-rot13\r\n\r\nqapla'),
		'a boundary that never appears': text('Content-Type: multipart/mixed; boundary=zz\r\n\r\nno parts'),
	};
	for (const [label, input] of Object.entries(cases)) {
		let p = null;
		try { p = Mime.parse(input); } catch (e) { p = null; }
		ok(label + ' returns the full shape', p && shape.every((k) => k in p) && Array.isArray(p.attachments));
	}
	eq('unknown charset falls back to utf-8', Mime.parse(text('Content-Type: text/plain; charset=x-klingon\r\n\r\nqapla')).textPlain, 'qapla');
	eq('no Content-Type is text/plain', Mime.parse(text('Subject: s\r\n\r\nbody')).textPlain, 'body');
	eq('headerValue of nonsense', Mime.headerValue(null, 'subject'), '');

	let deep = 'Content-Type: text/plain\r\n\r\ninnermost\r\n';
	for (let i = 0; i < 40; i++) {
		deep = 'Content-Type: multipart/mixed; boundary="d' + i + '"\r\n\r\n--d' + i + '\r\n' + deep + '--d' + i + '--\r\n';
	}
	let dp = null;
	try { dp = Mime.parse(text(deep)); } catch (e) { dp = null; }
	ok('nesting past the depth cap returns', dp !== null);
	eq('and keeps the too-deep subtree as one attachment', dp && dp.attachments.length, 1);
	eq('whose type is the multipart it stopped at', dp && dp.attachments[0].contentType, 'multipart/mixed');
	eq('numbered thirty levels down', dp && dp.attachments[0].mimePart.split('.').length, 30);
}

console.log('');
if (failed === 0) {
	console.log('RESULT: PASS ' + passed + ' ' + failed);
	process.exit(0);
}
console.log('RESULT: FAIL ' + passed + ' ' + failed);
process.exit(1);
