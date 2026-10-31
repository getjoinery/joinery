# @noble/post-quantum X-Wing (vendored, hash-pinned)

Source: @noble/post-quantum@0.7.1 (https://github.com/paulmillr/noble-post-quantum), MIT,
with its dependencies @noble/curves, @noble/hashes and @noble/ciphers 2.4.0, MIT.
File: noble-post-quantum-xwing.min.js — one self-contained IIFE exposing
`JoineryPQ.xwing` (noble's `ml_kem768_x25519`, which is X-Wing,
draft-connolly-cfrg-xwing-kem: keygen from a 32-byte seed, encapsulate,
decapsulate) and `JoineryPQ.version`. No CDN, no runtime npm.

The browser has no ML-KEM in WebCrypto, so the X-Wing KEM comes from here;
the AEAD and HKDF around it stay WebCrypto (assets/js/vault-crypto.js). It
reproduces every vector in tests/vault/fixtures/xwing_vector.json, the same
file the PHP implementation (includes/XWing.php) is held to.

SHA-256 pin (verify before trusting; vault-crypto.js loads it with this as
its Subresource Integrity hash, so a changed file does not run):
  noble-post-quantum-xwing.min.js  c755cc88401a2eda9b19fcc71f6c4d651136560c4a1108aff7fda0eb5431bb98
  sha256-x1XMiEAaLtqbGfzHH2xNZRE2VgxKEQiv9/2g61Qxu5g=

Rebuild (entry.mjs is in this directory):
  npm install --ignore-scripts @noble/post-quantum@0.7.1 esbuild@0.25.0
  npx esbuild entry.mjs --bundle --minify --format=iife --global-name=JoineryPQ \
    --target=es2020 --legal-comments=inline "--footer:js=globalThis.JoineryPQ=JoineryPQ;" \
    --outfile=noble-post-quantum-xwing.min.js

A new build is a reviewed change: update both pins here, the SRI constant in
vault-crypto.js, and tests/vault/xwing_browser_gate.sh, which fails until all
three agree.
