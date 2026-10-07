# Release keys

Every public key a node will ever trust, in the repository, so that a key
rotation is a commit diff anyone can see (specs/release_transparency.md D5).

```
release/<name>.pub   Ed25519 release keys, base64, one per file. Every manifest
                     and every agent binary is signed by one of these.
statement/<name>.pub P-256 statement keys, base64 PKIX DER, one per file. Every
                     release statement is signed by one of these, and its
                     public-log entry is written under it.
log/<origin>.pub     one checkpoint key per transparency-log origin, base64
                     PKIX DER exactly as Sigstore's trusted root publishes it,
                     e.g. log/log2025-1.rekor.sigstore.dev.pub
```

The publisher refuses to build when its own signing key is not listed under
`release/`, and refuses to log a statement when its statement key is not
listed under `statement/`. The first logged publish mints the statement key
(`config/release_statement_key`) and writes its public half here as
`statement/joinery-<id>.pub`, to be committed with that release; after that a
statement key is only ever added by hand, and signs only once a release
logged under the old key has installed it. It refuses to log at all while Sigstore's trusted
root gives a log a different key than `log/` pins, or lists a future log whose
key is not here yet: every node must hold a log's key before the first release
logged on it. `agent_dist/manifest.json` carries these lists (`release_keys`,
`statement_keys`, `log_keys`), and a node's key files are derived from them
and from nothing else.
