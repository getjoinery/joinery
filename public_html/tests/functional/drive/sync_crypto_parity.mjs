/**
 * Rust↔JS crypto parity harness (Node side). Drives the REAL browser modules
 * (assets/js/vault-crypto.js + drive-crypto.js) under Node's WebCrypto, in the
 * same vector-file protocol as jd-crypto's `jd-crypto-parity` binary
 * ({repo root}/sync/jd-crypto/src/bin/parity.rs — the schema's other half).
 *
 *   node sync_crypto_parity.mjs emit <out.json>    — encrypt fixtures
 *   node sync_crypto_parity.mjs verify <in.json>   — decrypt + check vectors
 *                                                    produced by the Rust side
 *
 * Run by tests/functional/drive/sync_crypto_parity_gate.sh in both directions.
 * Exit code: 0 = all pass, non-zero = any failure (shell-gate contract).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ASSETS = path.resolve(__dirname, '../../../assets/js');
const ARGON2_BUNDLE = path.resolve(__dirname, '../../../assets/vendor/argon2/argon2-bundled.min.js');

// ---- minimal browser shim ---------------------------------------------------
globalThis.window = globalThis;
globalThis.TextEncoder = TextEncoder;
globalThis.TextDecoder = TextDecoder;
globalThis.btoa = (s) => Buffer.from(s, 'binary').toString('base64');
globalThis.atob = (s) => Buffer.from(s, 'base64').toString('binary');
globalThis.document = { createElement: () => ({ getContext: () => ({ drawImage() {} }), toBlob: (cb) => cb(null) }) };

// Pre-provide window.argon2 so VaultCrypto.loadArgon2 short-circuits (its
// script-tag loader can't run here). The vendored bundle inlines the WASM as
// base64 in an inner module, but its Emscripten Node branch would try to
// fetch a file path — extract the binary and hand it over directly.
{
  const require = createRequire(import.meta.url);
  const src = fs.readFileSync(ARGON2_BUNDLE, 'utf8');
  const m = src.match(/exports="(AGFzbQ[^"]+)"/);
  if (!m) { console.error('ERROR: cannot find inlined WASM in argon2 bundle'); process.exit(2); }
  globalThis.self = globalThis;
  globalThis.Module = { wasmBinary: Buffer.from(m[1], 'base64') };
  globalThis.argon2 = require(ARGON2_BUNDLE);
}

function load(file) { (0, eval)(fs.readFileSync(path.join(ASSETS, file), 'utf8')); }
load('vault-crypto.js');
load('drive-crypto.js');

const DC = window.DriveCrypto;
const VC = window.VaultCrypto;

// ---- helpers ----------------------------------------------------------------

const b64 = (bytes) => Buffer.from(bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes)).toString('base64');
const unb64 = (s) => new Uint8Array(Buffer.from(s, 'base64'));
const patternBytes = ({ len, mul, add }) => {
  const out = new Uint8Array(len);
  for (let i = 0; i < len; i++) out[i] = (i * mul + add) & 0xff;
  return out;
};
const bytesEq = (a, b) => a.length === b.length && a.every((x, i) => x === b[i]);
const randB64 = (n) => b64(crypto.getRandomValues(new Uint8Array(n)));

function namedBlob(bytes, name, type = 'application/octet-stream') {
  const blob = new Blob([bytes], { type });
  blob.name = name;
  return blob;
}

async function kekFrom(src) {
  if (src.type === 'recovery') return VC.kekFromRecoveryCode(src.code, src.salt_b64);
  if (src.type === 'passphrase') {
    return VC.kekFromPassphrase(src.passphrase, src.salt_b64, {
      alg: 'argon2id', mem: src.mem, time: src.time, parallelism: src.parallelism, hashLen: 32,
    });
  }
  throw new Error('unknown kek source: ' + src.type);
}

// Thumbnail ciphertext the way DriveCrypto.maybeThumbnail builds it (the canvas
// shim can't produce one from an image, so encrypt the bytes directly).
async function encryptThumb(plain, fkKey, contentId) {
  const iv = crypto.getRandomValues(new Uint8Array(12));
  const ct = new Uint8Array(await crypto.subtle.encrypt(
    { name: 'AES-GCM', iv, additionalData: new TextEncoder().encode(contentId + ':thumb') },
    fkKey, plain
  ));
  const out = new Uint8Array(iv.length + ct.length);
  out.set(iv, 0); out.set(ct, iv.length);
  return out;
}

// ---- emit -------------------------------------------------------------------

async function emit(outPath) {
  const v = { producer: 'node', content: [], metadata: [], thumbs: [], sealed: [], wrapped_keys: [], refusals: [] };

  const cases = [
    ['empty', { len: 0, mul: 1, add: 0 }],
    ['small', { len: 5, mul: 31, add: 7 }],
    ['exact-boundary', { len: DC.CHUNK_BYTES, mul: 13, add: 5 }],
    ['multi-chunk', { len: DC.CHUNK_BYTES + 777, mul: 17, add: 3 }],
  ];
  for (const [label, pattern] of cases) {
    const packed = await DC.encryptFile(namedBlob(patternBytes(pattern), label + '.bin'));
    v.content.push({
      label, pattern,
      fk_b64: b64(packed.fkBytes),
      content_id: packed.contentId,
      cipher_b64: b64(await packed.blob.arrayBuffer()),
    });
  }

  {
    const fk = await DC.newFileKey();
    const cid = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    const meta = {
      v: 1, name: 'Répor t: finäl 😀.xlsx', mime: 'application/vnd.ms-excel',
      size: 12345, cid, chunk: DC.CHUNK_BYTES, thumb: true, mtime: '2026-07-20T12:34:56.000Z',
    };
    v.metadata.push({
      label: 'unicode+mtime', fk_b64: b64(fk.fkBytes),
      blob: await DC.encryptMetadata(meta, fk.fkKey), expect: meta,
    });

    const fk2 = await DC.newFileKey();
    const meta2 = { v: 1, name: 'plain.txt', mime: 'text/plain', size: 0, cid: 'ffeeddccbbaa99887766554433221100', chunk: DC.CHUNK_BYTES, thumb: false };
    v.metadata.push({
      label: 'no-mtime', fk_b64: b64(fk2.fkBytes),
      blob: await DC.encryptMetadata(meta2, fk2.fkKey), expect: meta2,
    });
  }

  {
    const fk = await DC.newFileKey();
    const cid = '00112233445566778899aabbccddeeff';
    const pattern = { len: 513, mul: 7, add: 1 };
    v.thumbs.push({
      fk_b64: b64(fk.fkBytes), content_id: cid, pattern,
      cipher_b64: b64(await encryptThumb(patternBytes(pattern), fk.fkKey, cid)),
    });
  }

  for (const [label, payload] of [
    ['file-key', crypto.getRandomValues(new Uint8Array(32))],
    ['pkcs8-handoff', (await VC.generateVaultKeypair()).secretKeyBytes],
  ]) {
    const kp = await VC.generateVaultKeypair();
    v.sealed.push({
      label,
      recipient_public_key_b64: kp.publicKeyB64,
      recipient_secret_pkcs8_b64: b64(kp.secretKeyBytes),
      plaintext_b64: b64(payload),
      sealed_b64: await VC.sealToPublicKey(payload, kp.publicKeyB64),
    });
  }

  {
    const secret = (await VC.generateVaultKeypair()).secretKeyBytes;
    const recovery = { type: 'recovery', code: 'oO0i-Il1L 2345', salt_b64: randB64(16) };
    v.wrapped_keys.push({
      label: 'recovery', kek: recovery, ad: 'vault:drive:recovery',
      secret_b64: b64(secret),
      blob: await VC.wrapSecretKey(secret, await kekFrom(recovery), 'vault:drive:recovery'),
    });

    const passphrase = { type: 'passphrase', passphrase: 'correct horse battery staple', salt_b64: randB64(16), mem: 8192, time: 2, parallelism: 1 };
    v.wrapped_keys.push({
      label: 'passphrase', kek: passphrase, ad: 'vault:drive:passphrase',
      secret_b64: b64(secret),
      blob: await VC.wrapSecretKey(secret, await kekFrom(passphrase), 'vault:drive:passphrase'),
    });
  }

  {
    const data = patternBytes({ len: DC.CHUNK_BYTES + 99, mul: 11, add: 9 });
    const packed = await DC.encryptFile(namedBlob(data, 'refusal.bin'));
    const cipher = new Uint8Array(await packed.blob.arrayBuffer());
    const fk_b64 = b64(packed.fkBytes);

    v.refusals.push({ kind: 'content', reason: 'wrong_cid', fk_b64, content_id: '99887766554433221100ffeeddccbbaa', cipher_b64: b64(cipher) });

    const view = new DataView(cipher.buffer);
    const len0 = view.getUint32(0);
    const swapped = new Uint8Array(cipher.length);
    swapped.set(cipher.subarray(4 + len0), 0);
    swapped.set(cipher.subarray(0, 4 + len0), cipher.length - (4 + len0));
    v.refusals.push({ kind: 'content', reason: 'reordered', fk_b64, content_id: packed.contentId, cipher_b64: b64(swapped) });

    const tampered = cipher.slice();
    tampered[tampered.length - 1] ^= 0x01;
    v.refusals.push({ kind: 'content', reason: 'tampered', fk_b64, content_id: packed.contentId, cipher_b64: b64(tampered) });

    const kp = await VC.generateVaultKeypair();
    const other = await VC.generateVaultKeypair();
    v.refusals.push({
      kind: 'sealed', reason: 'wrong_keypair',
      sealed_b64: await VC.sealToPublicKey(packed.fkBytes, kp.publicKeyB64),
      secret_pkcs8_b64: b64(other.secretKeyBytes),
      public_key_b64: other.publicKeyB64,
    });

    const kek = { type: 'recovery', code: 'ABCD2345', salt_b64: randB64(16) };
    v.refusals.push({
      kind: 'wrapped_key', reason: 'wrong_ad',
      blob: await VC.wrapSecretKey(packed.fkBytes, await kekFrom(kek), 'vault:drive:recovery'),
      kek, ad: 'vault:passwords:recovery',
    });
  }

  fs.writeFileSync(outPath, JSON.stringify(v));
  console.log('emitted node vectors to ' + outPath);
}

// ---- verify -----------------------------------------------------------------

let failed = 0;
function check(ok, label) {
  console.log((ok ? 'PASS ' : 'FAIL ') + label);
  if (!ok) failed++;
}
async function attempt(fn) {
  try { return { ok: true, value: await fn() }; } catch (e) { return { ok: false }; }
}

async function verify(inPath) {
  const v = JSON.parse(fs.readFileSync(inPath, 'utf8'));
  console.log(`verifying ${inPath} vectors from producer=${v.producer}`);

  for (const c of v.content) {
    const r = await attempt(async () => {
      const fkKey = await DC.importFileKey(unb64(c.fk_b64));
      return DC.decryptContent(unb64(c.cipher_b64).buffer, fkKey, c.content_id);
    });
    check(r.ok && bytesEq(r.value, patternBytes(c.pattern)), 'content:' + c.label);
  }

  for (const m of v.metadata) {
    const r = await attempt(async () => {
      const fkKey = await DC.importFileKey(unb64(m.fk_b64));
      return DC.decryptMetadata(m.blob, fkKey);
    });
    const match = r.ok && Object.keys(m.expect).every(
      (k) => JSON.stringify(r.value[k]) === JSON.stringify(m.expect[k])
    );
    check(match, 'metadata:' + m.label);
  }

  for (const th of v.thumbs) {
    const r = await attempt(async () => {
      const fkKey = await DC.importFileKey(unb64(th.fk_b64));
      const blob = await DC.decryptThumbnail(unb64(th.cipher_b64), fkKey, th.content_id);
      return new Uint8Array(await blob.arrayBuffer());
    });
    check(r.ok && bytesEq(r.value, patternBytes(th.pattern)), 'thumbnail');
  }

  for (const s of v.sealed) {
    const r = await attempt(() =>
      VC.openFromSecretKey(s.sealed_b64, unb64(s.recipient_secret_pkcs8_b64), s.recipient_public_key_b64));
    check(r.ok && bytesEq(r.value, unb64(s.plaintext_b64)), 'sealed:' + s.label);
  }

  for (const w of v.wrapped_keys) {
    const r = await attempt(async () =>
      VC.unwrapSecretKey(w.blob, await kekFrom(w.kek), w.ad));
    check(r.ok && bytesEq(r.value, unb64(w.secret_b64)), 'wrapped_key:' + w.label);
  }

  for (const r of v.refusals) {
    let refused = false;
    if (r.kind === 'content') {
      const a = await attempt(async () => {
        const fkKey = await DC.importFileKey(unb64(r.fk_b64));
        return DC.decryptContent(unb64(r.cipher_b64).buffer, fkKey, r.content_id);
      });
      refused = !a.ok;
    } else if (r.kind === 'sealed') {
      const a = await attempt(() =>
        VC.openFromSecretKey(r.sealed_b64, unb64(r.secret_pkcs8_b64), r.public_key_b64));
      refused = !a.ok;
    } else if (r.kind === 'wrapped_key') {
      const a = await attempt(async () =>
        VC.unwrapSecretKey(r.blob, await kekFrom(r.kek), r.ad));
      refused = !a.ok;
    }
    check(refused, `refusal:${r.kind}:${r.reason}`);
  }

  console.log(failed === 0 ? '\nALL PASS' : `\n${failed} FAILURE(S)`);
  process.exit(failed === 0 ? 0 : 1);
}

// ---- main -------------------------------------------------------------------

const [mode, file] = process.argv.slice(2);
if (mode === 'emit' && file) {
  emit(file).catch((e) => { console.error('ERROR', e); process.exit(2); });
} else if (mode === 'verify' && file) {
  verify(file).catch((e) => { console.error('ERROR', e); process.exit(2); });
} else {
  console.error('usage: node sync_crypto_parity.mjs emit|verify <vectors.json>');
  process.exit(2);
}
