# Release keys

Every public key a node will ever trust, in the repository, so that a key
rotation is a commit diff anyone can see (spec release_transparency D5).

```
release/<name>.pub   Ed25519 release keys, base64, one per file. Every manifest
                     and every agent binary is signed by one of these.
statement/<name>.pub P-256 statement keys, base64 PKIX DER, one per file. Every
                     release statement is signed by one of these, and its
                     public-log entry is written under it.
authority            optional: the files above that may sign the next change to
                     the keys nodes trust, one per line ('release/joinery.pub',
                     'statement/joinery-7516f013.pub'). Absent, every release
                     and statement key listed. At least one of each kind.
retired/statement/   statement keys a rotation took out, kept for good: nodes
retired/release/     never read them; the release log watch keeps looking for
                     entries under the retired statement keys.
log/<origin>.pub     the checkpoint keys of one transparency-log origin, one per
                     line, base64 PKIX DER exactly as Sigstore's trusted root
                     publishes it, e.g. log/log2025-1.rekor.sigstore.dev.pub.
                     Usually one line; while Sigstore rotates a log's key in
                     place, the old and the new, so a release can carry the
                     new one to nodes before it takes over.
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
`statement_keys`, `log_keys`), and a node's key files are first written from
them. After that a node's release and statement keys are one trusted set,
changed only by a logged release signed by the keys the set's authority
names: every statement records the keys it installs and the authority, and a
key is retired by a release, signed by that authority, that leaves it out
(docs/deploy_and_upgrade.md, "The trusted set and its authority";
specs/release_key_rotation.md). A rotation is two releases on one day: one
signed by the old keys adding the new ones and making them the authority, then
one signed by the new keys leaving the old ones out, whose public halves then
move to retired/.

The agent is built with every key under `release/`, `statement/` and `log/`
compiled in as its starting set, and an agent
holding both requires the public log of every later self-update. A fork that
publishes its own releases without logging them removes `statement/` and
`log/` from its tree before its first agent build; a fork's nodes that ever
ran an agent built with this repository's keys keep them in
`/etc/joinery-agent/`, and are reinstalled from the fork's own build.
