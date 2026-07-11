# argon2-browser (vendored, hash-pinned)

Source: argon2-browser@1.18.0 (https://github.com/antelle/argon2-browser), MIT.
File: argon2-bundled.min.js — self-contained UMD build with the WebAssembly
module inlined as base64 (no separate .wasm fetch, no CDN, no runtime npm).

This is the Sealed Vault's sanctioned exception to the platform's
vanilla-JS-only rule: a vendored, hash-pinned crypto primitive for the
client-custody passphrase-fallback KDF (Argon2id, 64 MiB / t=3 / p=4).

SHA-256 pin (verify before trusting):
  argon2-bundled.min.js  77c64b946baf1a5116dc591f4b9965d636b1b455f75edd2d4a587cb75e01687b

Loaded by assets/js/vault-crypto.js, which exposes window.argon2.hash(...).
