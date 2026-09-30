/**
 * The device-handoff vector: what a phone must open to read Fortress mail
 * (specs/fortress_mobile_apps.md § F1, WP1), produced by the browser's own
 * code (assets/js/vault-crypto.js) and pinned in
 * tests/vault/fixtures/device_handoff_vector.json.
 *
 *   node device_handoff_vector.mjs          check: rebuild and compare byte for byte,
 *                                           then open every blob in the file with
 *                                           vault-crypto.js and compare the plaintexts
 *   node device_handoff_vector.mjs --write  rewrite the file
 *
 * Every random input (the key pairs, the ephemeral keys, the IVs) is drawn
 * from a fixed seed: vault-crypto.js runs unchanged, but the WebCrypto it
 * calls hands out the seeded values where it would have drawn random ones.
 * So a rebuild reproduces the file exactly, and a change to the browser's
 * format shows up as a difference. tests/vault/device_handoff_vector_test.php
 * reproduces the same bytes with the server's SealedBox from the recorded
 * ephemeral keys and IVs, so browser, server and the phone ports are held to
 * one set of bytes.
 *
 * @version 1.0
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash, createPrivateKey, createPublicKey, webcrypto } from 'node:crypto';
import { gunzipSync } from 'node:zlib';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const OUT = join(here, 'fixtures', 'device_handoff_vector.json');
const PKCS8_X25519_PREFIX = '302e020100300506032b656e04220420';

const subtle = webcrypto.subtle;
const hex = (b) => Buffer.from(b).toString('hex');
const b64 = (b) => Buffer.from(b).toString('base64');
const seeded = (label, n) => new Uint8Array(createHash('sha256').update('fortress-mobile-vector/' + label).digest()).slice(0, n);

// ---- WebCrypto that hands out seeded values ---------------------------------------

const randomQueue = [];   // byte arrays getRandomValues returns, in order
const keyQueue = [];      // X25519 secrets generateKey returns, in order

function pkcs8Of(secret) { return Buffer.concat([Buffer.from(PKCS8_X25519_PREFIX, 'hex'), Buffer.from(secret)]); }

function publicOf(secret) {
	const jwk = createPublicKey(createPrivateKey({ key: pkcs8Of(secret), format: 'der', type: 'pkcs8' })).export({ format: 'jwk' });
	return new Uint8Array(Buffer.from(jwk.x, 'base64url'));
}

const seededSubtle = new Proxy(subtle, {
	get(target, prop) {
		if (prop === 'generateKey') {
			return async function (alg, extractable, usages) {
				if (!alg || alg.name !== 'X25519') return target.generateKey(alg, extractable, usages);
				if (!keyQueue.length) throw new Error('an unseeded X25519 key was asked for');
				const secret = keyQueue.shift();
				return {
					privateKey: await target.importKey('pkcs8', pkcs8Of(secret), { name: 'X25519' }, true, usages),
					publicKey: await target.importKey('raw', publicOf(secret), { name: 'X25519' }, true, []),
				};
			};
		}
		const v = target[prop];
		return typeof v === 'function' ? v.bind(target) : v;
	},
});

globalThis.window = {
	crypto: {
		subtle: seededSubtle,
		getRandomValues(buf) {
			if (!randomQueue.length) throw new Error('unseeded random bytes were asked for');
			const next = randomQueue.shift();
			if (next.length !== buf.length) throw new Error('seeded ' + next.length + ' bytes, asked for ' + buf.length);
			buf.set(next);
			return buf;
		},
	},
};
new Function(readFileSync(join(root, 'assets', 'js', 'vault-crypto.js'), 'utf8'))();
const VC = globalThis.window.VaultCrypto;

// ---- the vector ------------------------------------------------------------------

async function build() {
	const deviceSecret = seeded('device-secret', 32);
	const vaultSecret = seeded('mail-vault-secret', 32);

	// Both key pairs as vault-crypto.js makes them (generateVaultKeypair).
	keyQueue.push(deviceSecret, vaultSecret);
	const device = await VC.generateVaultKeypair();
	const vault = await VC.generateVaultKeypair();

	// The handoff: the browser seals the vault's PKCS#8 secret to the device key
	// (vault-keyring.js session.sealSecretKeyTo -> VaultCrypto.sealToPublicKey).
	const handoffEph = seeded('handoff-ephemeral', 32);
	const handoffIv = seeded('handoff-iv', 12);
	keyQueue.push(handoffEph);
	randomQueue.push(handoffIv);
	const handoffBlob = await VC.sealToPublicKey(vault.secretKeyBytes, device.publicKeyB64);

	// One Fortress row, as the server or a browser seals it.
	const id = 4242;
	const adPrefix = 'mail:';
	const dek = seeded('row-dek', 32);
	const dekEph = seeded('row-dek-ephemeral', 32);
	const dekIv = seeded('row-dek-iv', 12);
	keyQueue.push(dekEph);
	randomQueue.push(dekIv);
	const sealedDek = 'v1.edgeseal.mail.' + await VC.sealToPublicKey(dek, vault.publicKeyB64);
	const dekKey = await VC.importDek(dek);

	const fieldPlain = {
		iem_subject: 'Quarterly numbers — draft ✓',
		iem_sender: 'Zoë Adams <zoe@example.test>',
	};
	const fields = [];
	for (const [column, plaintext] of Object.entries(fieldPlain)) {
		const iv = seeded('field-iv/' + column, 12);
		randomQueue.push(iv);
		const ad = adPrefix + id + ':' + column;
		fields.push({ column, ad, plaintext, iv_hex: hex(iv), value: 'v1.edge.' + await VC.encrypt(plaintext, dekKey, ad) });
	}

	// The search text as packSearchText() stores a long one: 'gz:' + base64(gzip).
	const searchText = ('Zoë Adams zoe@example.test Quarterly numbers draft report.pdf '
		+ 'the quarterly numbers are attached, revenue up in every region '.repeat(12)).trim();
	const gz = await new Response(new Blob([new TextEncoder().encode(searchText)]).stream()
		.pipeThrough(new CompressionStream('gzip'))).arrayBuffer();
	const packed = 'gz:' + b64(new Uint8Array(gz));
	const searchIv = seeded('field-iv/iem_search_text', 12);
	randomQueue.push(searchIv);
	const searchAd = adPrefix + id + ':iem_search_text';
	const search = { column: 'iem_search_text', ad: searchAd, packed, text: searchText, iv_hex: hex(searchIv),
		value: 'v1.edge.' + await VC.encrypt(packed, dekKey, searchAd) };

	// One part's bytes: stored (and served) as the text 'v1.edge.' + base64(IV ‖ ct ‖ tag)
	// under AD mail:{id}:att:{mime_part} (mailbox_fortress.js sealEdgeBytes / attachmentBytes).
	const partBytes = new Uint8Array(300);
	for (let i = 0; i < partBytes.length; i++) partBytes[i] = (i * 37 + 11) & 0xff;
	const partIv = seeded('part-iv/2', 12);
	const partAd = adPrefix + id + ':att:2';
	const partCt = new Uint8Array(await subtle.encrypt({ name: 'AES-GCM', iv: partIv, additionalData: new TextEncoder().encode(partAd) },
		dekKey, partBytes));
	const part = { mime_part: '2', ad: partAd, bytes_b64: b64(partBytes), sha256_hex: createHash('sha256').update(partBytes).digest('hex'),
		iv_hex: hex(partIv), stored: 'v1.edge.' + b64(Buffer.concat([partIv, partCt])) };

	if (randomQueue.length || keyQueue.length) throw new Error('seeded values left over: the browser code changed what it draws');

	return {
		_about: 'Device handoff and one Fortress row, built by assets/js/vault-crypto.js from seeded randomness '
			+ '(tests/vault/device_handoff_vector.mjs). Standard base64 with padding throughout. A phone: (1) opens '
			+ 'handoff.blob with device.secret (X25519, HKDF-SHA256 salt empty info "sealed-vault:dek" ‖ ephPub ‖ devicePub, '
			+ 'AES-256-GCM, no AAD) and gets vault.pkcs8_hex, whose last 32 bytes are the mail secret; (2) opens '
			+ 'row.sealed_dek (strip "v1.edgeseal.mail.", same seal) with that secret to get row.dek_hex; (3) opens each '
			+ 'v1.edge. value (base64 IV[12] ‖ ct ‖ tag[16]) under the DEK with its AD. A search text whose plaintext '
			+ 'starts "gz:" is base64 gzip. A part is the stored text itself, same box, AD mail:{id}:att:{mime_part}.',
		kdf_info_prefix: 'sealed-vault:dek',
		device: { secret_hex: hex(deviceSecret), public_b64: device.publicKeyB64 },
		vault: { secret_hex: hex(vaultSecret), public_b64: vault.publicKeyB64, pkcs8_hex: hex(vault.secretKeyBytes) },
		handoff: { eph_secret_hex: hex(handoffEph), iv_hex: hex(handoffIv), blob: handoffBlob },
		row: {
			id, ad_prefix: adPrefix, dek_hex: hex(dek),
			sealed_dek: sealedDek, dek_eph_secret_hex: hex(dekEph), dek_iv_hex: hex(dekIv),
			fields, search_text: search, part,
		},
	};
}

// ---- the check -------------------------------------------------------------------

let passed = 0, failed = 0;
function check(ok, label, detail) {
	if (ok) { passed++; console.log('  PASS: ' + label); }
	else { failed++; console.log('  FAIL: ' + label + (detail ? ' (' + detail + ')' : '')); }
}

async function openAll(v) {
	const fromHex = (h) => new Uint8Array(Buffer.from(h, 'hex'));
	const pkcs8 = await VC.openFromSecretKey(v.handoff.blob, fromHex(PKCS8_X25519_PREFIX + v.device.secret_hex), v.device.public_b64);
	check(hex(pkcs8) === v.vault.pkcs8_hex, 'the handoff blob opens with the device secret to the vault PKCS#8');
	check(v.vault.pkcs8_hex === PKCS8_X25519_PREFIX + v.vault.secret_hex, 'the PKCS#8 is the fixed prefix and the raw scalar');
	check(b64(publicOf(fromHex(v.vault.secret_hex))) === v.vault.public_b64, 'the vault public key is the secret\'s X25519 public key');
	let refused = false;
	try { await VC.openFromSecretKey(v.handoff.blob, fromHex(PKCS8_X25519_PREFIX + v.vault.secret_hex), v.vault.public_b64); } catch (e) { refused = true; }
	check(refused, 'the handoff blob refuses another key');

	const blob = v.row.sealed_dek.slice('v1.edgeseal.mail.'.length);
	check(v.row.sealed_dek.startsWith('v1.edgeseal.mail.'), 'the row DEK carries the v1.edgeseal.mail. prefix');
	const dek = await VC.openFromSecretKey(blob, pkcs8, v.vault.public_b64);
	check(hex(dek) === v.row.dek_hex, 'the row DEK opens with the mail secret');
	const dekKey = await VC.importDek(dek);
	for (const f of v.row.fields.concat([v.row.search_text])) {
		check(f.ad === v.row.ad_prefix + v.row.id + ':' + f.column, f.column + ': AD is {prefix}{id}:{column}');
		const pt = await VC.decrypt(f.value.slice('v1.edge.'.length), dekKey, f.ad);
		check(pt === (f.plaintext !== undefined ? f.plaintext : f.packed), f.column + ' opens under the DEK and its AD');
		let wrong = false;
		try { await VC.decrypt(f.value.slice('v1.edge.'.length), dekKey, v.row.ad_prefix + (v.row.id + 1) + ':' + f.column); } catch (e) { wrong = true; }
		check(wrong, f.column + ' refuses another row\'s AD');
	}
	const st = v.row.search_text;
	check(st.packed.startsWith('gz:') && gunzipSync(Buffer.from(st.packed.slice(3), 'base64')).toString('utf8') === st.text,
		'the search text unpacks (gz: + base64 gzip) to the text');
	const raw = Buffer.from(v.row.part.stored.slice('v1.edge.'.length), 'base64');
	const partPt = new Uint8Array(await subtle.decrypt({ name: 'AES-GCM', iv: raw.subarray(0, 12),
		additionalData: new TextEncoder().encode(v.row.part.ad) }, dekKey, raw.subarray(12)));
	check(b64(partPt) === v.row.part.bytes_b64 && createHash('sha256').update(partPt).digest('hex') === v.row.part.sha256_hex,
		'the stored part opens to its bytes under mail:{id}:att:{mime_part}');
}

const built = await build();
const text = JSON.stringify(built, null, '\t') + '\n';
if (process.argv.includes('--write')) {
	writeFileSync(OUT, text);
	console.log('wrote ' + OUT);
}
console.log('\n== device handoff vector ==');
let onDisk = '';
try { onDisk = readFileSync(OUT, 'utf8'); } catch (e) { onDisk = ''; }
check(onDisk === text, 'the file is what vault-crypto.js builds today (rebuild with --write if the format changed on purpose)');
if (onDisk) await openAll(JSON.parse(onDisk));

console.log('');
console.log('RESULT: ' + (failed === 0 ? 'PASS' : 'FAIL') + ' ' + passed + ' ' + failed);
process.exit(failed === 0 ? 0 : 1);
