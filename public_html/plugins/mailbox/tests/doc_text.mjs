/**
 * Holds the browser's document reader (assets/mailbox_doc_text.js) to the
 * server's (includes/DocumentText.php).
 *
 * A Fortress attachment's text is read in its owner's browser, so the browser
 * reader decides what the preview shows. Three things are checked:
 *
 *   - Parity. fixtures/doc_text/ holds one file per shape (a Word file with a
 *     table, footnote, header and footers; a two-sheet workbook with shared,
 *     rich, phonetic and inline strings; a deck with notes; OpenDocument text,
 *     sheet and slides; UTF-8 with a byte-order mark, windows-1252, CSV,
 *     Markdown). expected.json is what DocumentText::extractBytes() answered
 *     for each; the browser's answer must be the same status, category and
 *     text, character for character.
 *   - Refusals, built here: an entry whose directory claims 1 KB and inflates
 *     past the entry limit, a directory over 40 MiB in all, a ratio over 200:1,
 *     an encrypted entry, an OLE file, a <!DOCTYPE, a file over maxBytes, and
 *     text over maxChars.
 *   - The button. kindFor() offers 'text' exactly where the server's
 *     canPreview() does for the categories read here, and null for the ones
 *     left to the server (can_preview.json, written by PHP).
 *
 *   node plugins/mailbox/tests/doc_text.mjs           check
 *   node plugins/mailbox/tests/doc_text.mjs --write   rebuild the fixtures and
 *                                                      expectations from PHP
 *
 * @version 1.1 - a sender's long whitespace run, a long text and a large part each finish inside a time bound
 *   (reviewer2 B1, B2); an Office file declared application/zip offers the eye (B6); a UTF-16 part reads (B7)
 * @version 1.0
 */

import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import { deflateRawSync } from 'node:zlib';

const here = dirname(fileURLToPath(import.meta.url));
const fixtures = join(here, 'fixtures', 'doc_text');

if (process.argv.includes('--write')) {
	process.stdout.write(execFileSync('php', [join(here, 'lib', 'doc_text_fixtures.php'), fixtures]));
}

// Decode windows-1252 as a browser does (lib/whatwg_text_decoder.js).
createRequire(import.meta.url)(join(here, 'lib', 'whatwg_text_decoder.js'));
new Function(readFileSync(join(here, '..', 'assets', 'mailbox_doc_text.js'), 'utf8'))();
const DocText = globalThis.MailboxDocText;

let passed = 0;
let failed = 0;
function check(label, ok, detail) {
	if (ok) { passed++; console.log('ok - ' + label); }
	else { failed++; console.log('FAIL - ' + label + (detail ? ' (' + detail + ')' : '')); }
}

const LIMITS = { maxBytes: 15728640, maxChars: 200000 };

// ---- parity with the server -------------------------------------------------

const expected = JSON.parse(readFileSync(join(fixtures, 'expected.json'), 'utf8'));
const names = readdirSync(fixtures).filter((n) => !n.endsWith('.json')).sort();
check('every fixture has a server answer', names.every((n) => expected[n]) && names.length === Object.keys(expected).length,
	'files=' + names.join(',') + ' expected=' + Object.keys(expected).join(','));
for (const name of names) {
	const want = expected[name];
	if (!want) continue;
	const bytes = new Uint8Array(readFileSync(join(fixtures, name)));
	const got = await DocText.read(bytes, 'application/octet-stream', name, LIMITS);
	check(name + ': the server\'s status, category and text',
		got.status === want.status && got.category === want.category && got.text === want.text && got.truncated === false,
		'js=' + JSON.stringify(got) + ' php=' + JSON.stringify(want));
}

// ---- refusals -----------------------------------------------------------------

/** A zip from [{name, data (Uint8Array), method, claimSize, claimComp, flags}] . */
function makeZip(entries) {
	const locals = [];
	const central = [];
	let offset = 0;
	for (const e of entries) {
		const name = Buffer.from(e.name, 'utf8');
		const raw = e.data || new Uint8Array(0);
		const method = e.method == null ? 8 : e.method;
		const body = method === 8 ? deflateRawSync(raw) : Buffer.from(raw);
		const comp = e.claimComp != null ? e.claimComp : body.length;
		const size = e.claimSize != null ? e.claimSize : raw.length;
		const flags = e.flags || 0;
		const lh = Buffer.alloc(30);
		lh.writeUInt32LE(0x04034b50, 0); lh.writeUInt16LE(20, 4); lh.writeUInt16LE(flags, 6);
		lh.writeUInt16LE(method, 8); lh.writeUInt32LE(comp >>> 0, 18); lh.writeUInt32LE(size >>> 0, 22);
		lh.writeUInt16LE(name.length, 26);
		locals.push(lh, name, body);
		const ch = Buffer.alloc(46);
		ch.writeUInt32LE(0x02014b50, 0); ch.writeUInt16LE(20, 4); ch.writeUInt16LE(20, 6);
		ch.writeUInt16LE(flags, 8); ch.writeUInt16LE(method, 10);
		ch.writeUInt32LE(comp >>> 0, 20); ch.writeUInt32LE(size >>> 0, 24);
		ch.writeUInt16LE(name.length, 28); ch.writeUInt32LE(offset, 42);
		central.push(ch, name);
		offset += 30 + name.length + body.length;
	}
	const cd = Buffer.concat(central);
	const end = Buffer.alloc(22);
	end.writeUInt32LE(0x06054b50, 0);
	end.writeUInt16LE(entries.length, 8); end.writeUInt16LE(entries.length, 10);
	end.writeUInt32LE(cd.length, 12); end.writeUInt32LE(offset, 16);
	return new Uint8Array(Buffer.concat([...locals, cd, end]));
}

const docXml = (text) => '<?xml version="1.0"?><w:document xmlns:w="x"><w:body><w:p><w:r><w:t>'
	+ text + '</w:t></w:r></w:p></w:body></w:document>';
const enc = (s) => new Uint8Array(Buffer.from(s, 'utf8'));

async function readAs(name, bytes, limits) {
	return DocText.read(bytes, 'application/octet-stream', name, limits || LIMITS);
}

{
	const ok = await readAs('a.docx', makeZip([{ name: 'word/document.xml', data: enc(docXml('Hello there')) }]));
	check('a zip built here reads (the builder is sound)', ok.status === 'ok' && ok.text === 'Hello there', JSON.stringify(ok));

	const stored = await readAs('a.docx', makeZip([{ name: 'word/document.xml', data: enc(docXml('Stored text')), method: 0 }]));
	check('a stored (uncompressed) entry reads', stored.status === 'ok' && stored.text === 'Stored text', JSON.stringify(stored));

	// The directory claims 1 KB; the stream inflates to 13 MiB.
	const big = new Uint8Array(13 * 1024 * 1024).fill(0x41);
	const liar = await readAs('a.docx', makeZip([{ name: 'word/document.xml', data: big, claimSize: 1024 }]));
	check('an entry that inflates past 12 MiB whatever its directory claims answers failed', liar.status === 'failed', JSON.stringify(liar));

	const total = await readAs('a.docx', makeZip([
		{ name: 'word/document.xml', data: enc(docXml('x')) },
		{ name: 'a.bin', data: enc('a'), claimSize: 11 * 1048576, claimComp: 1048576 },
		{ name: 'b.bin', data: enc('b'), claimSize: 11 * 1048576, claimComp: 1048576 },
		{ name: 'c.bin', data: enc('c'), claimSize: 11 * 1048576, claimComp: 1048576 },
		{ name: 'd.bin', data: enc('d'), claimSize: 11 * 1048576, claimComp: 1048576 }
	]));
	check('a directory over 40 MiB in all answers failed', total.status === 'failed', JSON.stringify(total));

	const ratio = await readAs('a.docx', makeZip([
		{ name: 'word/document.xml', data: enc(docXml('x')) },
		{ name: 'bomb.bin', data: enc('z'), claimSize: 3 * 1048576, claimComp: 1024 }
	]));
	check('a ratio over 200:1 above 1 MiB answers failed', ratio.status === 'failed', JSON.stringify(ratio));

	const entry = await readAs('a.docx', makeZip([
		{ name: 'word/document.xml', data: enc(docXml('x')), claimSize: 13 * 1048576, claimComp: 13 * 1048576 }
	]));
	check('a directory entry over 12 MiB answers failed', entry.status === 'failed', JSON.stringify(entry));

	const locked = await readAs('a.docx', makeZip([{ name: 'word/document.xml', data: enc(docXml('x')), flags: 1 }]));
	check('an encrypted entry answers secured', locked.status === 'secured', JSON.stringify(locked));

	const ole = new Uint8Array(512);
	ole.set([0xd0, 0xcf, 0x11, 0xe0, 0xa1, 0xb1, 0x1a, 0xe1]);
	const oleAns = await readAs('locked.xlsx', ole);
	check('an OLE file (a password-protected Office document) answers secured', oleAns.status === 'secured', JSON.stringify(oleAns));

	const dtd = await readAs('a.docx', makeZip([{ name: 'word/document.xml',
		data: enc('<?xml version="1.0"?><!DOCTYPE r [<!ENTITY e "boom">]><w:document xmlns:w="x"><w:p><w:t>&e;</w:t></w:p></w:document>') }]));
	check('a <!DOCTYPE answers failed', dtd.status === 'failed', JSON.stringify(dtd));

	const plainDtd = await readAs('a.docx', makeZip([{ name: 'word/document.xml',
		data: enc('<?xml version="1.0"?><!DOCTYPE w:document><w:document xmlns:w="x"><w:p><w:t>hi</w:t></w:p></w:document>') }]));
	check('a <!DOCTYPE with no entities answers failed too', plainDtd.status === 'failed', JSON.stringify(plainDtd));

	const undef = await readAs('a.docx', makeZip([{ name: 'word/document.xml', data: enc(docXml('a &nbsp; b')) }]));
	check('an undeclared entity makes the part unreadable, not text', undef.status === 'empty', JSON.stringify(undef));

	const notZip = await readAs('a.docx', enc('this is not a zip'));
	check('a .docx that is not a zip answers failed', notZip.status === 'failed', JSON.stringify(notZip));

	const noPart = await readAs('a.docx', makeZip([{ name: 'readme.txt', data: enc('hi') }]));
	check('a zip with no document in it answers failed', noPart.status === 'failed', JSON.stringify(noPart));

	const tooLarge = await readAs('a.txt', enc('0123456789'), { maxBytes: 9, maxChars: 100 });
	check('a file over maxBytes answers too_large without reading', tooLarge.status === 'too_large' && tooLarge.text === '', JSON.stringify(tooLarge));

	const cut = await readAs('a.txt', enc('é'.repeat(30)), { maxBytes: 1000, maxChars: 10 });
	check('text over maxChars is cut, counted in characters, and marked truncated',
		cut.status === 'ok' && cut.truncated === true && cut.text === 'é'.repeat(10) + DocText.truncationMarker, JSON.stringify(cut));

	const empty = await readAs('a.txt', new Uint8Array(0));
	check('no bytes answers empty', empty.status === 'empty', JSON.stringify(empty));

	const pdf = await readAs('a.pdf', enc('%PDF-1.4'));
	check('a category read only by the server answers failed if asked', pdf.status === 'failed', JSON.stringify(pdf));
}

// ---- cost: what a sender chooses must not freeze the page ---------------------

async function timed(label, fn, ms) {
	const t0 = Date.now();
	const out = await fn();
	const took = Date.now() - t0;
	check(label + ' (' + took + ' ms, bound ' + ms + ')', took < ms && out, 'answer ' + out);
}
{
	const run = ' '.repeat(1048576);
	// Inside a zip, a part over 1 MiB of spaces is refused for its ratio before
	// it is parsed; just under 1 MiB reaches the XML reader.
	const zrun = ' '.repeat(1000000);
	await timed('1 MiB of spaces then a letter, as text', async () => {
		const r = await readAs('a.txt', enc('a' + run + 'x'));
		return r.status === 'ok' && r.text.length > 1000;
	}, 1500);
	await timed('~1 MB of spaces inside a closing tag', async () => {
		const r = await readAs('a.docx', makeZip([{ name: 'word/document.xml',
			data: enc('<w:document xmlns:w="x"><w:p><w:t>hi</w:t></w:p' + zrun + 'x></w:document>') }]));
		return r.status === 'empty' || r.status === 'failed';
	}, 1500);
	await timed('~1 MB of spaces inside an attribute name', async () => {
		const r = await readAs('a.docx', makeZip([{ name: 'word/document.xml',
			data: enc('<w:document xmlns:w="x"><w:p a' + zrun + 'x="1"><w:t>hi</w:t></w:p></w:document>') }]));
		return r.status === 'empty' || r.status === 'failed';
	}, 1500);
	await timed('~1 MB of spaces then a letter in an xlsx cell and a pptx note', async () => {
		const x = await readAs('a.xlsx', makeZip([{ name: 'xl/worksheets/sheet1.xml',
			data: enc('<worksheet><sheetData><row><c t="inlineStr"><is><t>a' + zrun + 'x</t></is></c></row></sheetData></worksheet>') }]));
		const p = await readAs('a.pptx', makeZip([
			{ name: 'ppt/slides/slide1.xml', data: enc('<p:sld xmlns:p="p" xmlns:a="a"><a:p><a:t>s</a:t></a:p></p:sld>') },
			{ name: 'ppt/notesSlides/notesSlide1.xml', data: enc('<p:notes xmlns:p="p" xmlns:a="a"><a:p><a:t>a' + zrun + 'x</a:t></a:p></p:notes>') }]));
		return x.status === 'ok' && p.status === 'ok';
	}, 2500);
	await timed('15 MiB of text cut at 200,000 characters', async () => {
		const r = await readAs('a.txt', new Uint8Array(15 * 1048576).fill(0x61), { maxBytes: 15728640, maxChars: 200000 });
		return r.status === 'ok' && r.truncated && r.text.length === 200000 + DocText.truncationMarker.length;
	}, 2000);
	const astral = await readAs('a.txt', enc('😀'.repeat(20)), { maxBytes: 1000, maxChars: 5 });
	check('the cap counts a character outside the BMP as one', astral.text === '😀'.repeat(5) + DocText.truncationMarker, JSON.stringify(astral));
}

// ---- encodings a part may declare ---------------------------------------------

{
	const xml = '<?xml version="1.0" encoding="UTF-16"?><w:document xmlns:w="x"><w:p><w:t>Grüße</w:t></w:p></w:document>';
	const le = Buffer.concat([Buffer.from([0xFF, 0xFE]), Buffer.from(xml, 'utf16le')]);
	const r = await readAs('a.docx', makeZip([{ name: 'word/document.xml', data: new Uint8Array(le) }]));
	check('a UTF-16 part with a byte-order mark reads', r.status === 'ok' && r.text === 'Grüße', JSON.stringify(r));
	const latin = Buffer.concat([Buffer.from('<?xml version="1.0" encoding="ISO-8859-1"?><w:document xmlns:w="x"><w:p><w:t>caf'), Buffer.from([0xE9]), Buffer.from('</w:t></w:p></w:document>')]);
	const r2 = await readAs('a.docx', makeZip([{ name: 'word/document.xml', data: new Uint8Array(latin) }]));
	check('a part declaring ISO-8859-1 reads in it', r2.status === 'ok' && r2.text === 'café', JSON.stringify(r2));
}

// ---- the button ------------------------------------------------------------------

{
	const can = JSON.parse(readFileSync(join(fixtures, 'can_preview.json'), 'utf8'));
	const reads = { docx: 1, xlsx: 1, pptx: 1, odf: 1, text: 1 };
	for (const ext of Object.keys(can)) {
		const server = can[ext];
		const kind = DocText.kindFor('application/octet-stream', 'file.' + ext);
		const want = (server.preview && reads[server.category]) ? 'text' : null;
		check('kindFor(.' + ext + ') = ' + want + ' (server: ' + server.category + ', preview ' + server.preview + ')', kind === want, 'got ' + kind);
	}
	for (const ext of ['pdf', 'rtf', 'html', 'eml', 'ics', 'epub', 'zip']) {
		check('kindFor(.' + ext + ') is null: the server reads it, this does not', DocText.kindFor('', 'x.' + ext) === null);
	}
	for (const ext of ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'txt', 'csv']) {
		check('kindFor(.' + ext + ') is text', DocText.kindFor('', 'x.' + ext) === 'text');
	}
	check('a declared Office type is read whatever the name',
		DocText.kindFor('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'scan.bin') === 'text');
	check('a declared type read only by the server gives way to the name, as the server\'s sniff would',
		DocText.kindFor('application/zip', 'Budget.docx') === 'text'
		&& DocText.kindFor('application/x-zip-compressed', 'Budget.xlsx') === 'text'
		&& DocText.kindFor('application/pdf', 'x.docx') === 'text');
	check('a declared type read only by the server, on a name this does not read, stays null',
		DocText.kindFor('application/zip', 'photos.zip') === null && DocText.kindFor('application/pdf', 'x.pdf') === null);
}

console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
