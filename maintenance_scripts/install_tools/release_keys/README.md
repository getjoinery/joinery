# Release keys

Every public key a node will ever trust, in the repository, so that a key
rotation is a commit diff anyone can see (specs/release_transparency.md D5).

```
release/<name>.pub   Ed25519 release keys, base64, one per file. Every manifest
                     and every release statement is signed by one of these.
log/<origin>.pub     one checkpoint key per transparency-log origin, base64,
                     e.g. log/log2025-1.rekor.sigstore.dev.pub
```

The publisher refuses to build when its own signing key is not listed under
`release/`. `agent_dist/manifest.json` carries these lists (`release_keys`,
`log_keys`), and a node's `config/release_verify_keys` and
`config/transparency_log_keys` are derived from them and from nothing else.
