/**
 * DriveCrypto round-trip harness (Node WebCrypto). Exercises the browser
 * client-custody crypto directly — the passkey PRF unlocker can't run under
 * Playwright virtual authenticators, so the crypto correctness is proven here
 * (docs/testing.md WebAuthn note; specs/implemented/drive_encryption.md).
 *
 * Loads the real assets/js/vault-crypto.js + drive-crypto.js under a minimal
 * window shim and asserts: chunked content round-trip, metadata round-trip,
 * file-key seal/open to a vault keypair, empty-file handling, and the AAD
 * tamper/transplant defenses. Exits non-zero on any failure (shell-gate contract).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ASSETS = path.resolve(__dirname, '../../../assets/js');

// ---- minimal browser shim (crypto is already global in Node 20) -------------
globalThis.window = globalThis;
globalThis.TextEncoder = TextEncoder;
globalThis.TextDecoder = TextDecoder;
globalThis.btoa = (s) => Buffer.from(s, 'binary').toString('base64');
globalThis.atob = (s) => Buffer.from(s, 'base64').toString('binary');
// createImageBitmap / canvas are only touched for image thumbnails; the test
// uses non-image blobs, so a stub that never produces a thumbnail suffices.
globalThis.document = { createElement: () => ({ getContext: () => ({ drawImage() {} }), toBlob: (cb) => cb(null) }) };

function load(file) { (0, eval)(fs.readFileSync(path.join(ASSETS, file), 'utf8')); }
load('vault-crypto.js');
load('drive-crypto.js');

const DC = window.DriveCrypto;
const VC = window.VaultCrypto;

let failed = 0;
function check(cond, label) {
	console.log((cond ? 'PASS ' : 'FAIL ') + label);
	if (!cond) failed++;
}
function bytesEq(a, b) { return a.length === b.length && a.every((x, i) => x === b[i]); }

async function main() {
	check(await DC.isSupported(), 'WebCrypto (incl. X25519) supported');

	// ---- content round trip across multiple chunks --------------------------
	const size = DC.CHUNK_BYTES * 2 + 777; // 3 chunks (two full + a remainder)
	const data = new Uint8Array(size);
	for (let i = 0; i < size; i++) data[i] = (i * 31 + 7) & 0xff;
	const file = new Blob([data], { type: 'application/octet-stream' });
	file.name = 'big.bin';

	const packed = await DC.encryptFile(file);
	const cipher = await packed.blob.arrayBuffer();
	check(cipher.byteLength > size, 'ciphertext larger than plaintext (per-chunk IV+tag+len)');
	const plain = await DC.decryptContent(cipher, packed.fkKey, packed.contentId);
	check(bytesEq(plain, data), 'chunked content round-trips exactly');

	// ---- metadata round trip -------------------------------------------------
	const mblob = await DC.encryptMetadata(packed.meta, packed.fkKey);
	const meta = await DC.decryptMetadata(mblob, packed.fkKey);
	check(meta.cid === packed.contentId && meta.size === size && meta.name === 'big.bin', 'metadata round-trips');

	// ---- file-key seal / open to a vault keypair ----------------------------
	const kp = await VC.generateVaultKeypair();
	const wrapped = await DC.wrapFileKeyTo(packed.fkBytes, kp.publicKeyB64);
	const openedFk = await VC.openFromSecretKey(wrapped, kp.secretKeyBytes, kp.publicKeyB64);
	check(bytesEq(openedFk, packed.fkBytes), 'file key seals to and opens from a vault keypair');
	// the reopened FK decrypts the content (the share path)
	const reFk = await DC.importFileKey(openedFk);
	const rePlain = await DC.decryptContent(cipher, reFk, meta.cid);
	check(bytesEq(rePlain, data), 'content decrypts with the unwrapped file key');

	// a different keypair must NOT open the wrapped key
	const other = await VC.generateVaultKeypair();
	let threw = false;
	try { await VC.openFromSecretKey(wrapped, other.secretKeyBytes, other.publicKeyB64); } catch (e) { threw = true; }
	check(threw, 'a wrapped file key never opens under a different vault key');

	// ---- new-version reuse: encryptFileWith keeps the FK + contentId ---------
	// A new version of an encrypted file MUST reuse the original key and content
	// id (every FileKeyGrant wraps that key; prior versions must stay readable).
	const v2data = new Uint8Array(DC.CHUNK_BYTES + 99);
	for (let i = 0; i < v2data.length; i++) v2data[i] = (i * 17 + 3) & 0xff;
	const v2file = new Blob([v2data], { type: 'application/octet-stream' });
	v2file.name = 'big-v2.bin';
	const v2 = await DC.encryptFileWith(v2file, packed.fkBytes, packed.contentId);
	check(v2.contentId === packed.contentId && bytesEq(v2.fkBytes, packed.fkBytes), 'encryptFileWith reuses the file key and content id');
	const v2plain = await DC.decryptContent(await v2.blob.arrayBuffer(), packed.fkKey, packed.contentId);
	check(bytesEq(v2plain, v2data), 'new-version ciphertext decrypts under the ORIGINAL key + content id');
	// and the original version still decrypts (nothing rotated)
	check(bytesEq(await DC.decryptContent(cipher, packed.fkKey, packed.contentId), data), 'prior version still decrypts after a new version');

	// ---- thumbnail round trip from RAW BYTES (the fetch/read path shape) -----
	// The canvas shim can't produce a thumbnail, so build the ciphertext the way
	// maybeThumbnail does (IV || AES-GCM, AAD contentId+':thumb') and feed
	// decryptThumbnail the raw bytes a thumb-URL fetch yields.
	const thumbPlain = new Uint8Array(513);
	for (let i = 0; i < thumbPlain.length; i++) thumbPlain[i] = (i * 7 + 1) & 0xff;
	const tIv = crypto.getRandomValues(new Uint8Array(12));
	const tCt = new Uint8Array(await crypto.subtle.encrypt(
		{ name: 'AES-GCM', iv: tIv, additionalData: new TextEncoder().encode(packed.contentId + ':thumb') },
		packed.fkKey, thumbPlain
	));
	const tBytes = new Uint8Array(tIv.length + tCt.length);
	tBytes.set(tIv, 0); tBytes.set(tCt, tIv.length);
	const tBlob = await DC.decryptThumbnail(tBytes, packed.fkKey, packed.contentId);
	check(bytesEq(new Uint8Array(await tBlob.arrayBuffer()), thumbPlain), 'thumbnail decrypts from raw ciphertext bytes');

	// ---- empty file ----------------------------------------------------------
	const empty = new Blob([], { type: 'application/octet-stream' }); empty.name = 'empty';
	const ep = await DC.encryptFile(empty);
	const eplain = await DC.decryptContent(await ep.blob.arrayBuffer(), ep.fkKey, ep.contentId);
	check(eplain.length === 0, 'empty file round-trips to zero bytes');

	// ---- AAD transplant defense: a chunk from another file fails ------------
	// Re-encrypt the same plaintext under a fresh FK/contentId, then try to
	// decrypt file A's ciphertext with A's key but B's contentId (AAD mismatch).
	threw = false;
	try { await DC.decryptContent(cipher, packed.fkKey, ep.contentId); } catch (e) { threw = true; }
	check(threw, 'wrong contentId (AAD) fails the GCM tag — no transplant');

	// ---- chunk-reorder defense ----------------------------------------------
	// Swap the first two chunk blocks and confirm decryption fails.
	const u8 = new Uint8Array(cipher);
	const view = new DataView(cipher);
	const len0 = view.getUint32(0);
	const block0 = u8.slice(4, 4 + len0);
	const off1 = 4 + len0;
	const len1 = view.getUint32(off1);
	const block1 = u8.slice(off1 + 4, off1 + 4 + len1);
	// rebuild with block1 then block0, then the remainder untouched
	const head = [];
	const be = (n) => { const b = new Uint8Array(4); b[0]=(n>>>24)&255;b[1]=(n>>>16)&255;b[2]=(n>>>8)&255;b[3]=n&255; return b; };
	head.push(be(len1), block1, be(len0), block0);
	const rest = u8.slice(off1 + 4 + len1);
	const reordered = new Blob([...head, rest]);
	threw = false;
	try { await DC.decryptContent(await reordered.arrayBuffer(), packed.fkKey, packed.contentId); } catch (e) { threw = true; }
	check(threw, 'reordered chunks fail the per-index AAD — no reorder');

	console.log(failed === 0 ? '\nALL PASS' : `\n${failed} FAILURE(S)`);
	process.exit(failed === 0 ? 0 : 1);
}

main().catch((e) => { console.error('ERROR', e); process.exit(2); });
