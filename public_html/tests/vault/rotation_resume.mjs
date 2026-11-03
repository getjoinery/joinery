/**
 * A committed key rotation leaves the new key reopenable on the next page
 * (assets/js/joinery-sealed.js resealScope): the tab's resume record is the
 * new key's, and no `drop` of the tab's server half goes out beside the
 * `put` of the new one. Sent together, the two race, and a drop that lands
 * second erases the new key's half, so the next page asked for an unlock.
 *
 * joinery-sealed.js runs unchanged in a vm context with stand-ins for the
 * page: storage, the DOM events it listens for, joineryApi (which records
 * every call), and the keyring and crypto it calls (fake sessions; the
 * resume key's HKDF and AES are Node's real WebCrypto).
 *
 * Exit 0 = pass. Run by rotation_resume_gate.sh.
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
	return {
		get length() { return m.size; },
		key: (i) => Array.from(m.keys())[i] ?? null,
		getItem: (k) => (m.has(k) ? m.get(k) : null),
		setItem: (k, v) => { m.set(k, String(v)); },
		removeItem: (k) => { m.delete(k); },
	};
}

// A keyring session as VaultKeyring.makeSession shapes it, minus the crypto.
function fakeSession(scope, publicKey) {
	let secret = new Uint8Array(32).fill(7);
	return {
		scope, publicKey,
		locked: () => secret === null,
		wrapUnder: async () => 'wrapped-under-' + publicKey,
		openSealed: async () => new Uint8Array(32),
		sealTo: async () => 'sealed',
		lock: () => { if (secret) { secret.fill(0); secret = null; } },
	};
}

const calls = [];
const api = {
	post: async (action, body) => {
		calls.push({ action, body: Object.assign({}, body) });
		if (action === 'vault_client_resume') {
			return body.op === 'put' ? { kept: true } : body.op === 'drop' ? { dropped: true } : { share: null };
		}
		if (action === 'vault_client_reseal_rows') return { rows: [], next: null, remaining: 0 };
		return {};
	},
};

const NEW_KEY = 'new-public-key';
const OLD_KEY = 'old-public-key';
const doc = {
	readyState: 'complete',
	addEventListener() {},
	querySelector: () => null,
	dispatchEvent() {},
};
const win = {
	addEventListener() {},
	joineryApi: api,
	VaultKeyring: {
		status: async () => ({ set_up: true, root_wrapped: false, pending_key_generation: null, label: 'Mail vault', wrappings: [] }),
		ensureUnlocked: async () => null,
		rotationPlan: async () => ({ wrappings: [{ unlocker_type: 'passkey', wrapped_secret_key: 'w' }], recoveryCodes: null }),
		showRecoveryCodes: async () => {},
		sessionFrom: (scope, bytes, pub) => fakeSession(scope, pub),
		adFor: () => 'ad',
	},
	VaultCrypto: {
		generateVaultKeypair: async () => ({ secretKeyBytes: new Uint8Array(32).fill(9), publicKeyB64: NEW_KEY }),
		b64encode: (b) => Buffer.from(b).toString('base64'),
		b64decode: (s) => new Uint8Array(Buffer.from(s, 'base64')),
	},
};
const ctx = {
	window: win, document: doc, sessionStorage: storage(), localStorage: storage(),
	crypto: webcrypto, CustomEvent: class { constructor(t, o) { this.type = t; this.detail = o && o.detail; } },
	TextEncoder, Uint8Array, Promise, setTimeout, clearTimeout, console, JSON, Date, Math, Object, Array, Error, parseInt, String,
};
Object.assign(ctx, { joineryApi: win.joineryApi, VaultKeyring: win.VaultKeyring, VaultCrypto: win.VaultCrypto });
ctx.window = Object.assign(win, { document: doc });
vm.createContext(ctx);
vm.runInContext(source, ctx, { filename: 'joinery-sealed.js' });
const Sealed = ctx.window.JoinerySealed;
const settle = () => new Promise((r) => setTimeout(r, 50));

// The mail vault open on its old key, with this tab's resume record kept.
Sealed.adopt('mail', fakeSession('mail', OLD_KEY));
await settle();
const before = JSON.parse(ctx.sessionStorage.getItem('jy_vault_resume:mail') || 'null');
check(before && before.public_key === OLD_KEY, 'the old key is kept for a reload before the rotation');

const mark = calls.length;
await Sealed.resealScope('mail');
await settle();
const after = calls.slice(mark).filter((c) => c.action === 'vault_client_resume' && c.body.scope === 'mail');
const commitAt = calls.findIndex((c) => c.action === 'vault_client_rotate_commit');
check(commitAt >= mark, 'the rotation committed');
check(!after.some((c) => c.body.op === 'drop'),
	'no drop of the tab\'s resume half races the new key\'s put', JSON.stringify(after.map((c) => c.body.op)));
check(after.filter((c) => c.body.op === 'put').length === 1, 'the new key\'s half is put once');
const rec = JSON.parse(ctx.sessionStorage.getItem('jy_vault_resume:mail') || 'null');
check(rec && rec.public_key === NEW_KEY, 'the tab\'s resume record is the new key\'s', rec && rec.public_key);
check(Sealed.isOpen('mail'), 'the new key is open on this page');

// A rotation that stops holds nothing and forgets the halves.
const mark2 = calls.length;
const answer = api.post;
api.post = async (action, body) => {
	if (action === 'vault_client_rotate_commit') {
		calls.push({ action, body: Object.assign({}, body) });
		throw new Error('refused');
	}
	return answer(action, body);
};
let threw = false;
try { await Sealed.resealScope('mail'); } catch (e) { threw = true; }
await settle();
const stopped = calls.slice(mark2).filter((c) => c.action === 'vault_client_resume' && c.body.scope === 'mail');
check(threw, 'a refused commit fails the rotation');
check(stopped.some((c) => c.body.op === 'drop'), 'a stopped rotation drops the tab\'s resume half');
check(ctx.sessionStorage.getItem('jy_vault_resume:mail') === null, 'and its resume record');
check(!Sealed.isOpen('mail'), 'and leaves the vault shut');

console.log(failed ? `${failed} failed` : 'all passed');
process.exit(failed ? 1 : 0);
