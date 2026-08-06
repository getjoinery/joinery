/**
 * Produce a Fortress ciphertext container the way a browser produces one, by
 * running the REAL browser source (assets/js/drive-crypto.js) under Node's
 * WebCrypto with the smallest shim that file needs.
 *
 * Nothing here reimplements the container format — that is the whole point. The
 * artifact this writes is what a browser writes, so
 * tests/vault/sealed_file_container_test.php can pin the PHP reader to it
 * instead of assuming the two agree.
 *
 * Usage:  node tests/tools/make_drive_container_fixture.mjs <out_dir> [plain_bytes] [basename]
 *
 * The checked-in fixture (tests/fixtures/drive/) is a small single-chunk one —
 * a multi-chunk artifact would be 8 MB of git history for what the same format
 * already pins. The test generates a multi-chunk one on the fly when node is
 * available, and says so when it is not.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '../..');           // public_html

const OUT      = process.argv[2];
const SIZE     = process.argv[3] ? parseInt(process.argv[3], 10) : 3000;
const BASENAME = process.argv[4] || 'drive_fortress_container';

if (!OUT) {
	console.error('usage: node make_drive_container_fixture.mjs <out_dir> [plain_bytes] [basename]');
	process.exit(1);
}

// --- the shim ---------------------------------------------------------------
// node 20 already exposes globalThis.crypto (WebCrypto), Blob and File.
globalThis.window = globalThis;

globalThis.VaultCrypto = {
	isSupported: () => true,
	randomBytes: (n) => crypto.getRandomValues(new Uint8Array(n)),
	importDek: (bytes) => crypto.subtle.importKey('raw', bytes, 'AES-GCM', false, ['encrypt', 'decrypt']),
	b64encode: (u8) => Buffer.from(u8).toString('base64'),
};

// --- load the real module ---------------------------------------------------
new Function(readFileSync(`${ROOT}/assets/js/drive-crypto.js`, 'utf8'))();
const DC = globalThis.window.DriveCrypto;

// --- encrypt a deterministic plaintext --------------------------------------
// Deterministic so the PHP side can assert the exact bytes it recovers without
// the fixture having to carry a plaintext copy.
const plain = Buffer.alloc(SIZE);
for (let i = 0; i < SIZE; i++) plain[i] = (i * 31 + 7) & 0xff;

const fkBytes = crypto.getRandomValues(new Uint8Array(32));
const contentId = 'fixedcontentid00000000000000abcd';   // 32 hex chars, as randomHex(16) produces

const file = new File([plain], 'fixture.bin', { type: 'application/octet-stream' });
const result = await DC.encryptFileWith(file, fkBytes, contentId);
const cipher = Buffer.from(await result.blob.arrayBuffer());

writeFileSync(`${OUT}/${BASENAME}.bin`, cipher);
writeFileSync(`${OUT}/${BASENAME}.json`, JSON.stringify({
	note: 'Produced by assets/js/drive-crypto.js under Node WebCrypto — regenerate with tests/tools/make_drive_container_fixture.mjs',
	file_key_b64: Buffer.from(fkBytes).toString('base64'),
	content_id: contentId,
	chunk_bytes: DC.CHUNK_BYTES,
	plain_size: SIZE,
	cipher_size: cipher.length,
	plain_sha256: createHash('sha256').update(plain).digest('hex'),
	plain_formula: 'byte i = (i * 31 + 7) & 0xff',
}, null, 2) + '\n');

console.log(`${BASENAME}: ${cipher.length} ciphertext bytes for ${SIZE} plaintext bytes`);
