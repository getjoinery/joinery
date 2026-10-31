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
 * Every random input (the key seeds, the X-Wing encapsulation seeds, the IVs)
 * is drawn from a fixed seed: vault-crypto.js runs unchanged, but the
 * getRandomValues it calls hands out the seeded values where it would have
 * drawn random ones, and the two key seals go through VaultCrypto.sealV2With,
 * the same v2 seal with its randomness supplied (X-Wing draws its own inside
 * the vendored module, which nothing here can seed). So a rebuild reproduces
 * the file exactly, and a change to the browser's format shows up as a
 * difference. tests/vault/device_handoff_vector_test.php reproduces the same
 * bytes with the server's SealedBox from the recorded seeds and IVs, so
 * browser, server and the phone ports are held to one set of bytes.
 *
 * Gzip is the one input that is not reproducible: zlib builds compress the
 * same text to different, equally valid bytes (Ubuntu 26.04's Node does), so
 * the build reuses the file's own gzip whenever it unpacks to exactly the text
 * it would compress. The format is held to account, not the compressor.
 *
 * @version 2.0 - X-Wing (specs/post_quantum_sealing.md WP2): key seeds, v2 seals, vault.seed_hex
 * @version 1.1 - the search text's gzip is reused from the file when it unpacks to the text (box-agnostic)
 * @version 1.0
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createHash, webcrypto } from 'node:crypto';
import { gunzipSync } from 'node:zlib';

const here = dirname(fileURLToPath(import.meta.url));
const root = join(here, '..', '..');
const OUT = join(here, 'fixtures', 'device_handoff_vector.json');

const subtle = webcrypto.subtle;
const hex = (b) => Buffer.from(b).toString('hex');
const b64 = (b) => Buffer.from(b).toString('base64');
const seeded = (label, n) => {
	const out = new Uint8Array(n);
	for (let i = 0, block = 0; i < n; block++) {
		const h = createHash('sha256').update('fortress-mobile-vector/' + label + (block ? '/' + block : '')).digest();
		for (let j = 0; j < h.length && i < n; j++) out[i++] = h[j];
	}
	return out;
};

// ---- WebCrypto that hands out seeded values ---------------------------------------

const randomQueue = [];   // byte arrays getRandomValues returns, in order

globalThis.window = {
	crypto: {
		subtle,
		getRandomValues(buf) {
			if (!randomQueue.length) throw new Error('unseeded random bytes were asked for');
			const next = randomQueue.shift();
			if (next.length !== buf.length) throw new Error('seeded ' + next.length + ' bytes, asked for ' + buf.length);
			buf.set(next);
			return buf;
		},
	},
};
// The vendored X-Wing first (vault-crypto.js finds it on window), then the module.
new Function(readFileSync(join(root, 'assets', 'vendor', 'noble', 'noble-post-quantum-xwing.min.js'), 'utf8'))();
globalThis.window.JoineryPQ = globalThis.JoineryPQ;
new Function(readFileSync(join(root, 'assets', 'js', 'vault-crypto.js'), 'utf8'))();
const VC = globalThis.window.VaultCrypto;

// ---- the vector ------------------------------------------------------------------

async function build(knownPacked) {
	const deviceSeed = seeded('device-seed', 32);
	const vaultSeed = seeded('mail-vault-seed', 32);

	// Both key pairs as vault-crypto.js makes them (generateVaultKeypair).
	randomQueue.push(deviceSeed, vaultSeed);
	const device = await VC.generateVaultKeypair();
	const vault = await VC.generateVaultKeypair();

	// The handoff: the browser seals the vault's secret, its 32-byte seed, to the
	// device key (vault-keyring.js session.sealSecretKeyTo -> VaultCrypto.sealToPublicKey).
	const handoffEseed = seeded('handoff-eseed', 64);
	const handoffIv = seeded('handoff-iv', 12);
	const handoffBlob = await VC.sealV2With(vault.secretKeyBytes, device.publicKeyB64, 'dek', handoffEseed, handoffIv);

	// One Fortress row, as the server or a browser seals it.
	const id = 4242;
	const adPrefix = 'mail:';
	const dek = seeded('row-dek', 32);
	const dekEseed = seeded('row-dek-eseed', 64);
	const dekIv = seeded('row-dek-iv', 12);
	const sealedDek = 'v2.edgeseal.mail.' + await VC.sealV2With(dek, vault.publicKeyB64, 'dek', dekEseed, dekIv);
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
	let packed = knownPacked;
	if (!packed || unpackGz(packed) !== searchText) {
		const gz = await new Response(new Blob([new TextEncoder().encode(searchText)]).stream()
			.pipeThrough(new CompressionStream('gzip'))).arrayBuffer();
		packed = 'gz:' + b64(new Uint8Array(gz));
	}
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

	if (randomQueue.length) throw new Error('seeded values left over: the browser code changed what it draws');

	return {
		_about: 'Device handoff and one Fortress row, built by assets/js/vault-crypto.js from seeded randomness '
			+ '(tests/vault/device_handoff_vector.mjs). Standard base64 with padding throughout. Every key is X-Wing '
			+ '(draft-connolly-cfrg-xwing-kem): the secret a 32-byte seed, the public key 1216 bytes. Every key seal is v2: '
			+ 'base64(ct[1120] ‖ IV[12] ‖ AES-256-GCM ‖ tag[16]), no AAD, AES key = HKDF-SHA256(X-Wing shared secret, '
			+ 'salt empty, info "joinery-seal:v2:dek"). A phone: (1) opens handoff.blob with device.seed_hex and gets '
			+ 'vault.seed_hex, the mail secret; (2) opens row.sealed_dek (strip "v2.edgeseal.mail.", same seal) with it '
			+ 'to get row.dek_hex; (3) opens each v1.edge. value (base64 IV[12] ‖ ct ‖ tag[16]) under the DEK with its AD. '
			+ 'A search text whose plaintext starts "gz:" is base64 gzip. A part is the stored text itself, same box, '
			+ 'AD mail:{id}:att:{mime_part}. The eseed_hex values are the X-Wing encapsulation seeds, for a runtime that '
			+ 'can reproduce the seals byte for byte.',
		kdf_info: 'joinery-seal:v2:dek',
		device: { seed_hex: hex(deviceSeed), public_b64: device.publicKeyB64 },
		vault: { seed_hex: hex(vaultSeed), public_b64: vault.publicKeyB64 },
		handoff: { eseed_hex: hex(handoffEseed), iv_hex: hex(handoffIv), blob: handoffBlob },
		row: {
			id, ad_prefix: adPrefix, dek_hex: hex(dek),
			sealed_dek: sealedDek, dek_eseed_hex: hex(dekEseed), dek_iv_hex: hex(dekIv),
			fields, search_text: search, part,
		},
	};
}

// ---- the check -------------------------------------------------------------------

/** 'gz:' + base64 gzip back to its text, or null when it is not that. */
function unpackGz(packed) {
	if (typeof packed !== 'string' || !packed.startsWith('gz:')) return null;
	try { return gunzipSync(Buffer.from(packed.slice(3), 'base64')).toString('utf8'); } catch (e) { return null; }
}

let passed = 0, failed = 0;
function check(ok, label, detail) {
	if (ok) { passed++; console.log('  PASS: ' + label); }
	else { failed++; console.log('  FAIL: ' + label + (detail ? ' (' + detail + ')' : '')); }
}

async function openAll(v) {
	const fromHex = (h) => new Uint8Array(Buffer.from(h, 'hex'));
	check(v.vault.seed_hex.length === 64 && v.device.seed_hex.length === 64, 'both secrets are 32-byte seeds');
	check(await VC.publicKeyFromSecret(fromHex(v.device.seed_hex)) === v.device.public_b64
		&& Buffer.from(v.device.public_b64, 'base64').length === 1216, 'the device public key is its seed\'s 1216-byte X-Wing key');
	check(await VC.publicKeyFromSecret(fromHex(v.vault.seed_hex)) === v.vault.public_b64, 'the vault public key is its seed\'s X-Wing key');
	const seed = await VC.openFromSecretKey(v.handoff.blob, fromHex(v.device.seed_hex), v.device.public_b64);
	check(hex(seed) === v.vault.seed_hex, 'the handoff blob opens with the device seed to the vault seed');
	let refused = false;
	try { await VC.openFromSecretKey(v.handoff.blob, fromHex(v.vault.seed_hex), v.vault.public_b64); } catch (e) { refused = true; }
	check(refused, 'the handoff blob refuses another key');

	const blob = v.row.sealed_dek.slice('v2.edgeseal.mail.'.length);
	check(v.row.sealed_dek.startsWith('v2.edgeseal.mail.'), 'the row DEK carries the v2.edgeseal.mail. prefix');
	const dek = await VC.openFromSecretKey(blob, seed, v.vault.public_b64);
	check(hex(dek) === v.row.dek_hex, 'the row DEK opens with the mail seed');
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

let onDisk = '';
try { onDisk = readFileSync(OUT, 'utf8'); } catch (e) { onDisk = ''; }
let diskPacked = null;
try { diskPacked = JSON.parse(onDisk).row.search_text.packed; } catch (e) { diskPacked = null; }
const built = await build(diskPacked);
const text = JSON.stringify(built, null, '\t') + '\n';
if (process.argv.includes('--write')) {
	writeFileSync(OUT, text);
	console.log('wrote ' + OUT);
}
console.log('\n== device handoff vector ==');
check(onDisk === text, 'the file is what vault-crypto.js builds today (rebuild with --write if the format changed on purpose)');
if (onDisk) await openAll(JSON.parse(onDisk));

console.log('');
console.log('RESULT: ' + (failed === 0 ? 'PASS' : 'FAIL') + ' ' + passed + ' ' + failed);
process.exit(failed === 0 ? 0 : 1);
