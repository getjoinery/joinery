/**
 * A vault session opens only on the public key its own secret gives
 * (assets/js/vault-keyring.js provenSession). The server reports a vault's
 * public key and stores its wrappings, so it can show one key's wrapping
 * beside another key's public half: a planted rotation, whose pending key is
 * an attacker's and whose pending wrapping is a copy of the current one.
 * Every seal a session makes, and every row a rotation moves, goes to the
 * public key the session carries, so that pairing must be refused.
 *
 * The vendored X-Wing, vault-crypto.js and vault-keyring.js run unchanged.
 *
 * Exit 0 = pass. Run by session_key_proof_gate.sh.
 *
 * @version 1.0
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { webcrypto } from 'node:crypto';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const read = (...p) => readFileSync(join(root, ...p), 'utf8');

let failed = 0;
function check(ok, label, detail) {
	console.log((ok ? 'PASS: ' : 'FAIL: ') + label + (ok || detail === undefined ? '' : ' (' + detail + ')'));
	if (!ok) failed++;
}
async function refusal(p) { try { await p; return null; } catch (e) { return e; } }

const consumed = [];
globalThis.window = { crypto: webcrypto };
globalThis.joineryApi = { post: async (action, body) => { consumed.push({ action, body }); return {}; } };
globalThis.window.joineryApi = globalThis.joineryApi;
new Function(read('assets', 'vendor', 'noble', 'noble-post-quantum-xwing.min.js'))();
globalThis.window.JoineryPQ = globalThis.JoineryPQ;
new Function(read('assets', 'js', 'vault-crypto.js'))();
globalThis.VaultCrypto = globalThis.window.VaultCrypto;
new Function(read('assets', 'js', 'vault-keyring.js'))();
const VC = globalThis.window.VaultCrypto;
const VK = globalThis.window.VaultKeyring;

const b64url = (b) => Buffer.from(b).toString('base64url');
const SCOPE = 'mail';

const owner = await VC.generateVaultKeypair();
const attacker = await VC.generateVaultKeypair();
const secretCopy = () => new Uint8Array(owner.secretKeyBytes);

// ---- through the root: the path a planted rotation takes ---------------------------
const rootKek = await VC.kekFromPrf(b64url(webcrypto.getRandomValues(new Uint8Array(32))));
const rootSession = { scopeKek: async () => rootKek };
const rootWrapping = await VC.wrapSecretKey(secretCopy(), rootKek, VK.adFor(SCOPE, 'root'));
const viaRoot = (publicKey) => VK.openThroughRoot(rootSession, SCOPE,
	{ public_key: publicKey, label: 'Mail vault', wrappings: [{ unlocker_type: 'root', wrapped_secret_key: rootWrapping }] });

const honest = await viaRoot(owner.publicKeyB64);
check(honest && honest.publicKey === owner.publicKeyB64 && (await honest.derivedPublicKey()) === owner.publicKeyB64,
	'the vault opens through the root on its own key');
honest.lock();

const planted = await refusal(viaRoot(attacker.publicKeyB64));
check(planted && planted.keyMismatch === true,
	'the current wrapping shown beside another public key is refused', planted && planted.message);

// ---- a passkey ------------------------------------------------------------------------
const passkeyKek = await VC.kekFromPrf(b64url(webcrypto.getRandomValues(new Uint8Array(32))));
const passkeyWrapping = await VC.wrapSecretKey(secretCopy(), passkeyKek, VK.adFor(SCOPE, 'passkey', 'cred-1'));
const viaPasskey = (publicKey) => VK.unlockWithPasskey(SCOPE, passkeyKek, 'cred-1', { set_up: true, public_key: publicKey,
	wrappings: [{ unlocker_type: 'passkey', credential_id: 'cred-1', wrapped_secret_key: passkeyWrapping }] });

const tapped = await viaPasskey(owner.publicKeyB64);
check(tapped && !tapped.locked(), 'a passkey opens the vault on its own key');
tapped.lock();
const tappedPlanted = await refusal(viaPasskey(attacker.publicKeyB64));
check(tappedPlanted && tappedPlanted.keyMismatch === true, 'a passkey refuses another public key', tappedPlanted && tappedPlanted.message);

// ---- a recovery code: refused before the code is spent ------------------------------
const salt = Buffer.from(webcrypto.getRandomValues(new Uint8Array(16))).toString('base64');
const code = 'ABCD-EFGH-JKMN-PQRS-TVWX-YZ01-23';
const codeKek = await VC.kekFromRecoveryCode(code, salt);
const codeWrapping = await VC.wrapSecretKey(secretCopy(), codeKek, VK.adFor(SCOPE, 'recovery'));
const viaCode = (publicKey) => VK.unlockWithRecovery(SCOPE, code, { set_up: true, public_key: publicKey, salt,
	wrappings: [{ id: 5, unlocker_type: 'recovery', is_used: false, wrapped_secret_key: codeWrapping }] });

const codePlanted = await refusal(viaCode(attacker.publicKeyB64));
check(codePlanted && codePlanted.keyMismatch === true, 'a recovery code refuses another public key', codePlanted && codePlanted.message);
check(!consumed.some((c) => c.action === 'vault_client_consume_recovery'), 'and the code is not spent on it');
const viaCodeOk = await viaCode(owner.publicKeyB64);
check(viaCodeOk && viaCodeOk.session && consumed.some((c) => c.action === 'vault_client_consume_recovery' && c.body.wrapping_id === 5),
	'a recovery code opens the vault on its own key and is spent');

console.log(failed ? `${failed} failed` : 'all passed');
process.exit(failed ? 1 : 0);
