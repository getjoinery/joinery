/**
 * The browser's X-Wing, held to the shared vectors (specs/post_quantum_sealing.md
 * R5, R9, WP2): the vendored module (assets/vendor/noble/) and
 * assets/js/vault-crypto.js, run unchanged in node.
 *
 *   - The vendored file is the one pinned: its SHA-256 matches the README's
 *     pin and the Subresource Integrity hash vault-crypto.js loads it with.
 *   - The module reproduces every X-Wing vector in
 *     tests/vault/fixtures/xwing_vector.json (draft Appendix C): the public
 *     key from the seed, the ciphertext and shared secret from the
 *     encapsulation seed, the shared secret again by decapsulation.
 *   - vault-crypto.js reproduces every v2 seal vector (the same file; made by
 *     includes/SealedBox.php) byte for byte and opens it, and refuses it under
 *     another scope; selfCheck() resolves true; sealing to a 32-byte key is
 *     refused naming the old key.
 *   - halfOpenKeyBox(): for a v2 box the posted value is the dek-scope AES key
 *     (it opens the box's AES-GCM, the X-Wing shared secret does not); a key
 *     whose fingerprint is not the box's is refused; a v1 box gets the X25519
 *     of the key with its ephemeral key.
 *
 *   node tests/vault/xwing_vector.mjs     (run by xwing_browser_gate.sh)
 *
 * @version 1.0
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash, createPublicKey, diffieHellman, generateKeyPairSync, webcrypto } from 'node:crypto';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const BUNDLE = join(root, 'assets', 'vendor', 'noble', 'noble-post-quantum-xwing.min.js');
const VC_PATH = join(root, 'assets', 'js', 'vault-crypto.js');

const hex = (b) => Buffer.from(b).toString('hex');
const b64 = (b) => Buffer.from(b).toString('base64');
const fromHex = (h) => new Uint8Array(Buffer.from(h, 'hex'));
const fromB64 = (s) => new Uint8Array(Buffer.from(s, 'base64'));
const sha256 = (b) => createHash('sha256').update(b).digest();

let passed = 0, failed = 0;
function check(ok, label, detail) {
	if (ok) { passed++; console.log('  PASS: ' + label); }
	else { failed++; console.log('  FAIL: ' + label + (detail ? ' (' + detail + ')' : '')); }
}
async function refuses(p) { try { await p; return null; } catch (e) { return e.message || String(e); } }

// ---- the pin -----------------------------------------------------------------------
console.log('\n== the vendored module is the pinned one ==');
const bundleBytes = readFileSync(BUNDLE);
const vcText = readFileSync(VC_PATH, 'utf8');
const readme = readFileSync(join(root, 'assets', 'vendor', 'noble', 'README.md'), 'utf8');
const sri = 'sha256-' + sha256(bundleBytes).toString('base64');
const sriInJs = (vcText.match(/XWING_SRI = '([^']+)'/) || [])[1];
check(sriInJs === sri, 'vault-crypto.js loads it with its own Subresource Integrity hash', sriInJs + ' vs ' + sri);
check(readme.includes(sha256(bundleBytes).toString('hex')) && readme.includes(sri), 'the README pins the same hash, in both forms');
const urlV = (vcText.match(/XWING_URL = '[^'?]+\?v=([0-9a-f]+)'/) || [])[1];
check(urlV === sha256(bundleBytes).toString('hex').slice(0, 12), 'its URL carries the hash, so a rebuilt file is never served from a cache', urlV);

// ---- load as a page would ------------------------------------------------------------
globalThis.window = { crypto: webcrypto };
new Function(bundleBytes.toString('utf8'))();
globalThis.window.JoineryPQ = globalThis.JoineryPQ;
new Function(vcText)();
const VC = globalThis.window.VaultCrypto;
const X = globalThis.JoineryPQ.xwing;
const vectors = JSON.parse(readFileSync(join(here, 'fixtures', 'xwing_vector.json'), 'utf8'));

console.log('\n== X-Wing known answers (' + vectors.draft + ') ==');
vectors.xwing.forEach((v, i) => {
	const kp = X.keygen(fromHex(v.seed));
	check(hex(kp.publicKey) === v.pk, 'vector ' + (i + 1) + ': the public key from the seed');
	const enc = X.encapsulate(fromHex(v.pk), fromHex(v.eseed));
	check(hex(enc.cipherText) === v.ct && hex(enc.sharedSecret) === v.ss, 'vector ' + (i + 1) + ': encapsulation gives the ciphertext and shared secret');
	check(hex(X.decapsulate(fromHex(v.ct), fromHex(v.seed))) === v.ss, 'vector ' + (i + 1) + ': decapsulation gives the shared secret');
});

console.log('\n== the v2 seal, as SealedBox.php makes it ==');
for (const s of vectors.seal) {
	const seed = fromHex(s.seed_hex);
	check(b64(await VC.xwingPublicKey(seed)) === s.public_b64, s.scope + ': the public key from the seed');
	const sealed = await VC.sealV2With(fromHex(s.plaintext_hex), s.public_b64, s.scope, fromHex(s.eseed_hex), fromHex(s.iv_hex));
	check(sealed === s.sealed_b64, s.scope + ': sealed byte for byte');
	const opened = s.scope === 'dek'
		? await VC.openFromSecretKey(s.sealed_b64, seed, s.public_b64)
		: await VC.openChallenge(s.sealed_b64, seed, s.public_b64, s.scope);
	check(hex(opened) === s.plaintext_hex, s.scope + ': and opened');
	const wrong = s.scope === 'dek'
		? VC.openChallenge(s.sealed_b64, seed, s.public_b64, 'joinery-decommission-approval:')
		: VC.openFromSecretKey(s.sealed_b64, seed, s.public_b64);
	check(await refuses(wrong) !== null, s.scope + ': refused under another scope');
	if (s.scope === 'dek') {
		// The approval page's opener cannot be aimed at a key seal (reviewer2 WP2 B1).
		const why = await refuses(VC.openChallenge(s.sealed_b64, seed, s.public_b64, 'dek'));
		check(why !== null && /not a challenge/.test(why), 'openChallenge refuses scope dek: a page cannot open a data key into a form', why);
	}
}
check(await VC.selfCheck() === true, 'VaultCrypto.selfCheck() resolves true');
check(await VC.isSupported() === true, 'VaultCrypto.isSupported() resolves true');
const pair = await VC.generateVaultKeypair();
check(pair.secretKeyBytes.length === 32 && fromB64(pair.publicKeyB64).length === 1216, 'a fresh vault key is a 32-byte seed and a 1216-byte public key');
const oldKey = b64(createPublicKey(generateKeyPairSync('x25519').privateKey).export({ format: 'der', type: 'spki' }).subarray(-32));
const why = await refuses(VC.sealToPublicKey(new Uint8Array(32), oldKey));
check(why !== null && /old X25519 key/.test(why) && /rotate/.test(why), 'sealing to a 32-byte key is refused, naming the old key and the rotation', why);

console.log('\n== a key box the agent finishes opening ==');
{
	const seed = VC.randomBytes(32);
	const pub = await VC.xwingPublicKey(seed);
	const fpr = hex(sha256(pub));
	const dataKey = VC.randomBytes(32);
	const box = fromB64(await VC.sealToPublicKey(dataKey, b64(pub)));
	const ct = box.slice(0, 1120), iv = box.slice(1120, 1132), body = box.slice(1132);
	const r = await VC.halfOpenKeyBox({ version: 2, ciphertext: b64(ct) }, b64(seed), fpr);
	check(r.pub === b64(pub), 'v2: the public half posted is the box\'s key');
	const aes = await webcrypto.subtle.importKey('raw', fromB64(r.shared), 'AES-GCM', false, ['decrypt']);
	const got = new Uint8Array(await webcrypto.subtle.decrypt({ name: 'AES-GCM', iv }, aes, body));
	check(hex(got) === hex(dataKey), 'v2: the posted value is the dek-scope key, which opens the box');
	const ss = X.decapsulate(ct, seed);
	check(hex(fromB64(r.shared)) !== hex(ss), 'v2: and it is not the X-Wing shared secret');
	const wrong = await refuses(VC.halfOpenKeyBox({ version: 2, ciphertext: b64(ct) }, b64(VC.randomBytes(32)), fpr));
	check(wrong !== null && /fingerprint/.test(wrong), 'v2: another key is refused by fingerprint', wrong);
	check(await refuses(VC.halfOpenKeyBox({ version: 2, ciphertext: b64(ct) }, b64(VC.randomBytes(31)), fpr)) !== null, 'a key that is not 32 bytes is refused');
	const noVersion = await refuses(VC.halfOpenKeyBox({ ciphertext: b64(ct) }, b64(seed), fpr));
	check(noVersion !== null && /version/.test(noVersion), 'a box naming no seal version is refused', noVersion);

	// v1: a backup sealed to an old recovery key.
	const rec = generateKeyPairSync('x25519');
	const eph = generateKeyPairSync('x25519');
	const recScalar = rec.privateKey.export({ format: 'der', type: 'pkcs8' }).subarray(-32);
	const recPub = rec.publicKey.export({ format: 'der', type: 'spki' }).subarray(-32);
	const ephPub = eph.publicKey.export({ format: 'der', type: 'spki' }).subarray(-32);
	const r1 = await VC.halfOpenKeyBox({ version: 1, ephemeralPublic: b64(ephPub) }, b64(recScalar), hex(sha256(recPub)));
	const expect = diffieHellman({ privateKey: rec.privateKey, publicKey: eph.publicKey });
	check(r1.shared === b64(expect) && r1.pub === b64(recPub), 'v1: the X25519 of the key with the box\'s ephemeral key, and the old public half');
}

console.log('');
console.log('RESULT: ' + (failed === 0 ? 'PASS' : 'FAIL') + ' ' + passed + ' ' + failed);
process.exit(failed === 0 ? 0 : 1);
