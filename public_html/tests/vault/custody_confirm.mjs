/**
 * Moving stored row keys off a browser-held vault asks the person first
 * (assets/js/joinery-sealed.js changeCustody, confirmHandOver). The server
 * says a move is due and names the key the rows go to, so only the person's
 * own yes starts it: a no, a closed dialog, and a page with no dialog all
 * leave the vault shut and post nothing.
 *
 * joinery-sealed.js runs unchanged in a vm context with stand-ins for the
 * page, the API, the keyring and the dialog.
 *
 * Exit 0 = pass. Run by custody_confirm_gate.sh.
 *
 * @version 1.0
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { webcrypto } from 'node:crypto';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));
const source = readFileSync(join(here, '..', '..', 'assets', 'js', 'joinery-sealed.js'), 'utf8');

let failed = 0;
function check(ok, label, detail) {
	console.log((ok ? 'PASS: ' : 'FAIL: ') + label + (ok || detail === undefined ? '' : ' (' + detail + ')'));
	if (!ok) failed++;
}
function storage() {
	const m = new Map();
	return { get length() { return m.size; }, key: (i) => Array.from(m.keys())[i] ?? null,
		getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => { m.set(k, String(v)); }, removeItem: (k) => { m.delete(k); } };
}
function node(tag) {
	return { tag, textContent: '', children: [], appendChild(c) { this.children.push(c); } };
}
const textOf = (n) => n.textContent + n.children.map(textOf).join(' ');

const calls = [];
let unlocks = 0;
const rows = [{ model: 'M', id: 1, sealed_dek: 'v2.edgeseal.mail.x', target_scope: 'user', target_public_key: 'server-key' }];
const api = { post: async (action, body) => {
	calls.push({ action, body: Object.assign({}, body) });
	if (action === 'vault_custody_rows') return { rows, next: null, remaining: 37 };
	if (action === 'vault_client_resume') return {};
	return {};
} };
const session = { scope: 'mail', publicKey: 'own', locked: () => false, derivedPublicKey: async () => 'own',
	wrapUnder: async () => 'w', openSealed: async () => new Uint8Array(32), sealTo: async (b, k) => 'sealed-to-' + k, lock() {} };

// The dialog: answers with the button whose label matches `press`, or closes with none.
let press = null, shown = [], changes = [{ what: 'jo@example.com', level: 'Standard', count: 37 }];
const modal = { open(content, options) {
	shown.push({ text: textOf(content), labels: options.buttons.map((b) => b.label) });
	const dialog = { addEventListener(type, fn) {
		setTimeout(() => {
			const b = options.buttons.find((x) => press && press.test(x.label));
			if (b && b.onClick) b.onClick(dialog);
			fn();
		}, 0);
	} };
	return { dialog };
} };

const doc = { readyState: 'complete', addEventListener() {}, querySelector: () => null, dispatchEvent() {}, createElement: node };
const win = { addEventListener() {}, joineryApi: api, JoineryModal: modal,
	VaultKeyring: { status: async () => ({ set_up: true, wrappings: [] }), ensureUnlocked: async () => { unlocks++; return session; }, adFor: () => 'ad' },
	VaultCrypto: { b64encode: (b) => Buffer.from(b).toString('base64'), b64decode: (s) => new Uint8Array(Buffer.from(s, 'base64')),
		randomBytes: (n) => webcrypto.getRandomValues(new Uint8Array(n)) } };
const ctx = { window: win, document: doc, sessionStorage: storage(), localStorage: storage(), crypto: webcrypto,
	CustomEvent: class { constructor(t, o) { this.type = t; this.detail = o && o.detail; } },
	TextEncoder, Uint8Array, Promise, setTimeout, clearTimeout, console, JSON, Date, Math, Object, Array, Error, parseInt, String };
Object.assign(ctx, { joineryApi: api, VaultKeyring: win.VaultKeyring, VaultCrypto: win.VaultCrypto });
ctx.window = Object.assign(win, { document: doc });
vm.createContext(ctx);
vm.runInContext(source, ctx, { filename: 'joinery-sealed.js' });
const Sealed = ctx.window.JoinerySealed;

async function run() {
	const mark = calls.length, before = unlocks;
	let error = null, result = null;
	try { result = await Sealed.changeCustody('mail', { noun: 'message', changes }); } catch (e) { error = e.message; }
	return { error, result, posted: calls.slice(mark).map((c) => c.action), unlocked: unlocks - before };
}
const declined = (r) => calls.slice(-r.posted.length).filter((c) => c.action === 'vault_custody_declined').map((c) => c.body);
const untouched = (r) => r.error !== null && /^Nothing was moved\./.test(r.error) && r.unlocked === 0 && !r.posted.includes('vault_row_custody');

press = /^No/;
const no = await run();
check(untouched(no), 'a no moves nothing and the vault is not opened', JSON.stringify(no));
const noted = declined(no);
check(noted.length === 1 && noted[0].scope === 'mail' && noted[0].how === 'no', 'a no is posted for the audit log', JSON.stringify(noted));
check(shown.length === 1 && /Did you change jo@example\.com to Standard\?/.test(shown[0].text)
	&& /37 stored messages/.test(shown[0].text) && /the server can read them/.test(shown[0].text),
	'the question names the mailbox, its new level, how many and what it means', shown[0] && shown[0].text);
check(shown[0] && /^No/.test(shown[0].labels[0]), 'no is the first button', shown[0] && shown[0].labels.join(' | '));

press = null;
const closed = await run();
check(untouched(closed), 'a dialog closed without an answer moves nothing');
check(declined(closed).length === 1 && declined(closed)[0].how === 'closed', 'and is posted as closed');

win.JoineryModal = undefined;
const noDialog = await run();
check(untouched(noDialog), 'a page with no dialog to ask in moves nothing', JSON.stringify(noDialog));
check(declined(noDialog).length === 0, 'and posts no answer, since nobody was asked');
win.JoineryModal = modal;

press = /^Yes/;
const yes = await run();
const sent = calls.filter((c) => c.action === 'vault_row_custody').pop();
check(yes.error === null && yes.result && yes.result.moved === 1 && yes.unlocked === 1 && sent && sent.body.rows.length === 1,
	'an explicit yes opens the vault and moves the rows', JSON.stringify(yes));
check(!yes.posted.includes('vault_custody_declined'), 'a yes posts no refusal');
check(yes.posted.filter((a) => a === 'vault_custody_rows').length === 1, 'the rows are listed once, not once to ask and again to move');

changes = [{ what: 'jo@example.com', level: 'Standard', count: 30 }, { what: 'example.org', level: 'Private', count: 7 }];
press = /^No/;
await run();
const several = shown[shown.length - 1].text;
check(/Did you make these changes\?/.test(several) && /jo@example\.com changed to Standard \(30 stored messages\)/.test(several)
	&& /example\.org changed to Private \(7 stored messages\)/.test(several), 'several changes are each named', several);
changes = undefined;
await run();
check(/Did you lower a protection level yourself\?/.test(shown[shown.length - 1].text), 'with nothing named the question is the plain one');

rows.length = 0;
const mark = shown.length;
const empty = await run();
check(empty.error === null && empty.result.moved === 0 && shown.length === mark && empty.unlocked === 0, 'nothing to move asks nothing');

console.log(failed ? `${failed} failed` : 'all passed');
process.exit(failed ? 1 : 0);
