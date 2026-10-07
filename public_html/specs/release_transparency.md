# Release transparency: the code on a node is the public code, and the node checks

**Status:** DRAFT 2026-10-07. Written from the owner's goal (below) after a
code survey the same day; revised the same day after reviewer2's design review
(verdict NOT VALID as first written; B1–B7 and Q1–Q7 folded in, then B8, B9,
Q8, Q9 from the re-review; reviewer2 VALID 2026-10-07 with those folded in).
WP2 built and released (0.8.465). WP3-WP5 committed; 0.8.466 was the
genesis logged release (log2025-1 index 142718338). WP1's live test changed D4's key: see D-C.
WP1 committed (8e14752a). Where the statement goes changed while WP3 was
built, see D-F. WP6 under way: what ships was found wider than the commit and
cut back first (D2). Stands on `implemented/package_signing.md` and
`implemented/agent_release_channel.md`; independent of any disk-encryption
work.

## The goal, in one sentence

**A customer can prove that the software a release put on their machine is a
specific public commit, because their machine refused to install anything
that was not written to a public, append-only log that we do not control.**

## Scope, stated plainly (B1)

What this spec bounds is the **release channel**: every byte that reaches a
node through an upgrade, a marketplace install or an agent self-update.

- On a **self-hosted node** we are not root. The release channel is the only
  door we have, so this spec is a full proof there: the owner can show that
  nothing we could have done reaches their machine unlogged.
- On a **hosted or multi-tenant node** we are root on the host. A hand edit
  to a file on the box reads a site's data with no build, no publish and no
  log entry. This spec does not cover that and does not claim to; it bounds
  the release channel only. A continuous check of the live tree against the
  manifest would run under the same root that could edit it, so it could be a
  deterrent and never a proof — if wanted, it is its own spec, not a line in
  this one.
- A **sideloaded package** (an admin's upload installed with "Install
  anyway") is not the release channel either: it is the site owner's own act,
  behind a second factor, announced to every superadmin and marked on the
  node. It stays possible; D6 says how the log check treats it, and the
  node's provenance page lists it apart from what was logged.

Customer-facing wording follows the scope: "every release installed on your
machine is public and logged" is true everywhere; "we cannot reach your data"
is a statement about self-hosted nodes.

## The owner's goal (2026-10-07)

The promise to a hosted customer is "we cannot read your data". Today that is
true of the software as shipped: the agent has no word that reads a site's data
(`file_head` and `log_table_tail` are compiled, redacted lists; `copy_export` is
sealed to the destination's key and needs the owner's recovery key; backups on
the shelf are ciphertext under a key that never leaves the node). The agent's
source is public, so adding such a word would be visible. The owner's phrase:
**the code is the canary.**

The canary has a gap. Reading a site's data through the release channel does
not need a new word — it needs a *build*. An upgrade carries PHP that holds the
database password and host installers that run as root, and a node installs any
archive signed with our key. A build signed for one node and served to that
node only is accepted exactly like a release, and the public repository never
changes. The canary does not fire. That is the precise shape of what Apple was
ordered to do in 2016 and what Tutanota was ordered to do in 2020 (owner's
precedent review, 2026-10-07).

This spec closes the gap. After it, a build that could read a customer's data
through the release channel has two possible outcomes, both visible: it is in
the public log, where anyone can see an unexplained release; or it is not, and
the node will not install it.

## What exists (measured 2026-10-07)

| Piece | Where | State |
|---|---|---|
| Per-file manifest, sorted, byte-stable | `TreeManifestPublisher::build()` | Built. "<sha256>  <path>" lines, `ksort`ed; the same tree always yields the same bytes |
| Ed25519 signature over the manifest | `TreeManifestPublisher::write()`, `config/agent_signing_key` | Built, in every core, plugin and theme archive |
| Node refuses an archive that is not `signed` | `PackageSignature::verify()` in `utils/upgrade.php`, `install_extension.php`, host converger | Built. Verdicts: signed / unsigned / unknown_key / no_keys / tampered. The walk **skips** excluded paths (`cache`, `uploads`, `logs`, `specs`, `backups`, `node_modules`, top-level `config`, `vendor`) rather than refusing them — fine for a live tree, a hole for a staged archive (B6) |
| Trusted release keys on a node | `config/release_verify_keys`, root-owned, written by the host converger (`_host_files.sh` `host_files_write_release_verify_keys()`) from `agent_dist/manifest.json`'s `signing_public_key`; append-only, exact whole-line match | Built. Any key a release puts in `manifest.json` is appended bare; a line with anything after the key never matches and is duplicated (B2) |
| Agent binary signed, sha256 over the raw binary | `AgentDistPublisher`, verified in `update.go` against the key baked in with `-X main.updatePubKeyB64` | Built |
| Agent build flags | `AgentDistPublisher::buildBinary()`: `CGO_ENABLED=0 -trimpath -buildvcs=false`, ldflags carry only version and public key | Built — already the reproducible-build recipe, bar the toolchain pin |
| Toolchain pin | `joinery-agent/go.mod` says `go 1.22`, no `toolchain` line; the box builds with go1.22.2 | **Missing** |
| Commit recorded in a release | nowhere — `publish_upgrade.php` never calls git | **Missing** |
| Clean-tree check at publish | none; publish ships whatever is on dev's disk (the `unpublished_file` incident class exists because of this) | **Missing** |
| Files in the archive that are not in git | `VERSION` (written at publish); `maintenance_scripts/install_tools/joinery-install.sql.gz` (a `pg_dump --schema-only` of dev, gitignored); `agent_dist/` (built; `.gz` via `gzencode($raw, 9)` on this box's zlib, `manifest.json` carries signatures and a key); `support_bundle.tar.gz` (plain `tar -czf`, non-deterministic); `plugins/mailbox/provisioning/bin/relay-sealer-*` (built, gitignored) | Each handled in D2 |
| Origin node | `DeploymentHelper::isOriginNode()` aborts `upgrade.php` | The management node never installs an archive; nothing to add for it |
| Public log entry, inclusion proof, node-side proof check | none | **Missing** — the subject of this spec |
| A customer-visible statement of what is running | none beyond the version string | **Missing** |

Everything that is tracked is in one public repository (`getjoinery/joinery`:
core, `maintenance_scripts/`, all twelve plugins, all eleven themes). The agent
is a second public repository (`getjoinery/joinery-agent`).

## The design

### D1. A release is a commit

Publish refuses to run unless both trees are clean and their HEAD is on the
public remote:

- `getjoinery/joinery` at the publishing site root, and
- `getjoinery/joinery-agent` at `AgentDistPublisher::DEFAULT_SOURCE_PATH`.

**Publish never runs `git commit` or `git push` (Q2).** The owner commits. The
sequence is: publish writes the generated files (D2), finds the tree dirty,
refuses, and prints the files and the commit command; the owner commits and
pushes; publish runs again, finds both trees clean and both HEADs ancestors of
the public `main`, and builds. The refusal names the dirty files. There is no
`--allow-dirty`.

A release is then: core commit `C`, agent commit `A`, version `V`.

A republishing site (`TreeManifestPublisher::authority()` says "carried")
skips D1–D4 entirely: it carries the manifest, signature and statement it
received, byte for byte, exactly as it carries the manifest today.

### D2. Every shipped byte derives from the commit

The manifest makes the archive verifiable: a verifier who can regenerate
`RELEASE_MANIFEST` from `C` has verified the archive without the tarball
being byte-identical. So the rule is "every manifest line is derivable from
`C` and `A`, or is a signature over something that is". The files that are
not today:

| File | Rule |
|---|---|
| `VERSION` | Publish writes it; it is committed before the build; it is in `C` |
| `joinery-install.sql.gz` | Publish generates it with `--restrict-key=joinery-install`, no `Generated at` line, a fixed hash for the seed admin row, `gzip -n`; un-ignored and committed; it is in `C` as text an auditor can read (O1) |
| `agent_dist/joinery-agent-linux-*.gz` | Raw binary built from `A` with the pinned toolchain (D3). The **raw** sha256 is what the statement and `manifest.json` record; the `.gz` bytes depend on the compressor, so the statement records the compressor and its version (B7), and a verifier compares the decompressed binary |
| `agent_dist/manifest.json` | Derived: every key in it comes from the repo's key directory (D5), the binary hashes from the build, the signatures from the release key. A verifier checks the signatures; it does not regenerate them (B7) |
| `support_bundle.tar.gz` | Built with `tar --sort=name --mtime=@0 --owner=0 --group=0 --numeric-owner` and `gzip -n`; the statement records the tar and gzip versions; its inner manifest lists its files, so a verifier compares content when the compressed bytes differ (B7) |
| `relay-sealer-<arch>` | Built like the agent from source in `C`, toolchain pinned |

`config/` and `vendor/` stay outside the manifest; a node's config is its own
and there is no vendor directory under the site root.

**Only what git knows, or publish builds, ships (found in WP6's survey,
2026-10-07).** The first logged release, 0.8.466, broke the rule above: its
core archive carried 196 working screenshots from the top of `public_html/`
(one a user list with names and email addresses), a local email-test corpus
and its four scripts, all ignored by git; its themes carried 209 images the
`*.png` ignore rule had kept out of every commit; the mailbox plugin carried a
stray `relay-sealer` build beside its source. Publish copied directories, and
the clean-tree check (D1) cannot see an ignored file. And the catalog's
download endpoint cut a fresh tarball from the live directory whenever a file
there was newer than the published archive, replacing it. Fixed: a file ships
when git knows it or publish builds it (`ReleaseCommit::joineryFileShips()`;
the built set is the agent bundle, the relay sealer binaries, the license
copies and the statements); the manifests and the component tree hashes read
only those, and every archive is cut from its own manifest's listing
(`TreeManifestPublisher::archiveMembers()`); the download endpoint serves only
the published archive. The ignore rule covers top-level screenshots only, so
theme images are committed. Owner, 2026-10-07: the older archives stay where
they are.

**A fresh archive carries nothing the manifest does not cover (B6).**
`PackageSignature::verify()` gains a `fresh_archive` mode, used by
`upgrade.php` and `install_extension.php` on a staged archive: any member on
an excluded path (`cache/`, `uploads/`, `logs/`, `specs/`, `backups/`,
`node_modules/` anywhere; `config/`, `vendor/` at top level) is a refusal,
verdict `extra_file` — "a file is present that the manifest does not list",
which is literally what it is and a sentence the verifier already has (Q9) —
naming the path. Live-tree callers (the host installer
gate, `trustedListing()`) keep today's skip, because a live tree legitimately
holds cache and uploads. The publisher's rsync already excludes all of these,
so a genuine archive never trips it. `RELEASE_STATEMENT` is not excluded: it
is a listed file, and the statement records each manifest without its
`RELEASE_STATEMENT` line (D-F).

### D3. Reproducible binaries

`go.mod` in both Go sources gains `toolchain goX.Y.Z` matching the publisher's
Go, and the build runs with `GOTOOLCHAIN=local` so a mismatch fails instead of
silently downloading another compiler. With `-trimpath`, `CGO_ENABLED=0`, a
pinned toolchain and `go.sum`, a Go build is bit-identical across machines.
The release statement records the toolchain version.

### D4. One release statement, logged once

Per release, the publisher writes one statement — a DSSE envelope over a JSON
payload:

```
version:          0.8.470
core_commit:      <C>
agent_commit:     <A>
go_toolchain:     go1.22.2
compressors:      zlib 1.3 (gzencode), gzip 1.12, tar 1.35
artifacts:
  core:                     sha256 of RELEASE_MANIFEST without its
                            RELEASE_STATEMENT lines (D-F), and so for each:
  plugin/<name> (each):     its RELEASE_MANIFEST
  theme/<name> (each):      its RELEASE_MANIFEST
  agent/linux-amd64:        sha256 of the raw binary
  agent/linux-arm64:        sha256 of the raw binary
  support_bundle:           sha256 of the tarball
  relay-sealer/<arch>:      sha256 of each
keys_installed:   the full key set this release puts on a node (D5):
                  release keys, statement keys, log origins with
                  checkpoint keys — the same three lists as
                  agent_dist/manifest.json, which publish checks
published_at:     RFC 3339
```

`key_chain` (D5) is not in the payload: `RELEASE_STATEMENT` carries it beside
the envelope. Each link is already signed and logged, and a payload holding
the chain would hold every earlier chain inside every link.

**The statement key is ECDSA P-256 (D-C).** The envelope is signed with a
P-256 key, `config/release_statement_key` on the publisher, over the DSSE
pre-authentication encoding (PAE) — `openssl_sign(…, OPENSSL_ALGO_SHA256)` in
PHP, `crypto/ecdsa` in Go, both native. The Ed25519 release key keeps signing
every manifest and binary; the statement key signs only statements. Two keys in
the same `config/` on the same box were never independent, so two are not
weaker than one. Its public half is `release_keys/statement/<name>.pub` and
reaches a node like every other key (D5).

The envelope is written to the public Sigstore Rekor log as a `hashedrekord`
entry whose digest is `sha256(PAE)` and whose signature is the envelope's own
signature — the form Rekor v2 names for a DSSE envelope. The log stores the
digest, the signature and the statement key; anyone holding the statement can
recompute the digest and find it. The log is the one Sigstore's Rekor v2 signing config names (today
`log2025-1.rekor.sigstore.dev`; shards rotate, so the publisher reads the
signing config rather than hardcoding a URL — O2). The log answers with an
inclusion proof and a signed checkpoint. The publisher stores the statement,
the envelope, the entry's leaf bytes, the proof and the checkpoint together as
`RELEASE_STATEMENT` (JSON: `format`, `envelope`, `entry`, `key_chain`), and
puts a copy in every artifact, listed in its manifest (D-F): `public_html/`
and `public_html/agent_dist/` in the core, and each plugin's and theme's own
directory. A plugin or theme archive installed on its own therefore carries
everything its node needs (reviewer2's answer to ask 2), and the agent updater
reads `agent_dist/RELEASE_STATEMENT` beside `manifest.json` (which cannot hold
it: the core manifest covers `manifest.json`, and the statement covers the
core manifest).

The payload is not canonicalised. The envelope carries the payload's exact
bytes, base64, and those bytes are what the PAE, the signature and the log's
digest cover; a verifier reads the bytes it was given and never re-encodes
them. The ECDSA signature takes a fresh random nonce each time, so signing
the same statement twice gives different bytes: nothing may treat a
statement's signature as reproducible, only verifiable.

A log that cannot be reached means no publish. There is no "log later" and no
"skip the log" flag: the single thing this spec proves is that no unlogged
build was ever installed through the release channel.

### D5. The node refuses what is not in the log

`PackageSignature::verify()` gains one more verdict, `unlogged`, checked after
`signed`; on a node that requires logging (D6), `signed` alone no longer
installs. The check runs offline on bytes already on disk, against keys the
node **already holds** — never a key read from the archive or manifest being
verified (Q4):

1. `RELEASE_STATEMENT` is present and its DSSE signature verifies against a
   statement key in `config/release_statement_keys`.
2. Some artifact entry in the statement equals the sha256 of the
   `RELEASE_MANIFEST` the node just verified with its `RELEASE_STATEMENT`
   lines removed (`PackageSignature::statementSubject()`, D-F) — membership,
   not name, since the manifest binds every path (Q6) — **and every line so
   removed lists the sha256 of the statement being verified**
   (`PackageSignature::statementLines()`). Without the second half a signed
   manifest could list other bytes under the name, at any depth, and ship
   them unlogged (reviewer2 B1).
3. The checkpoint's signature verifies against the checkpoint key for its
   origin in `config/transparency_log_keys` (C2SP signed note). The current
   Rekor v2 shard's checkpoint key is Ed25519 (measured 2026-10-07 from
   Sigstore's trusted root): `sodium_crypto_sign_verify_detached` in PHP,
   `crypto/ed25519` in Go; the note key id is the first four bytes of
   SHA-256 over `origin ‖ "\n" ‖ 0x01 ‖ public key`.
4. **The leaf is bound to this statement (B5).** The stored leaf bytes are
   Rekor v2's canonical JSON for a `hashedrekord` 0.0.2 entry
   (`spec.hashedRekordV002`: `data.digest` with algorithm `SHA2_256`,
   `signature.content`, `signature.verifier.publicKey.rawBytes` with
   `keyDetails` `PKIX_ECDSA_P256_SHA_256`), and the node checks
   `digest == sha256(PAE of the statement)`, `signature == the statement's
   signature`, and `verifier ∈ the node's statement keys`. Only then is the
   leaf hashed (RFC 6962: leaf
   `SHA256(0x00 ‖ bytes)`, node `SHA256(0x01 ‖ left ‖ right)`) and the
   inclusion proof walked to the checkpoint's root. Without this step an
   unlogged statement shipped with a logged statement's proof and leaf
   bytes would pass; WP4's test is exactly that archive.

All four are a few hundred lines of PHP with `sodium` and `hash`; no new
dependency. The agent updater does the same four in Go before swapping a
binary, from `agent_dist/RELEASE_STATEMENT` and the keys baked into the
running binary or in root-owned files it already holds, with the Go standard
library only (WP5 wrote the note and proof checks by hand, line for line
against `TransparencyProof`, rather than add a module to a reproducible
build). Its heartbeat state distinguishes `unlogged` from
`verify_failed` (Q4).

A node never needs network access to verify, and never needs Rekor to be up to
install a release that was logged while Rekor was up.

The leaf check is strict — the stored leaf bytes must equal the canonical
encoding of what they decode to, so they cannot be read two ways — and it is
safe to be strict because it fails closed at publish: the publisher runs all
four checks on the log's answer before anything ships, so a change in
Rekor's encoding stops a publish, never a node.

A node's statement-key reader accepts P-256 keys only, the way the
publisher's does; the leaf check would refuse any other kind, but it is not
the only line that should.

**Every key a node will ever trust lives in the repo (B3).** A directory in
`C`, `maintenance_scripts/install_tools/release_keys/`:

```
release/<name>.pub              Ed25519 release keys (base64)
statement/<name>.pub            P-256 statement keys (base64 PKIX DER)
log/<origin>.pub                one checkpoint key per log origin (base64
                                PKIX DER, exactly as Sigstore's trusted root
                                publishes it), e.g.
                                log/log2025-1.rekor.sigstore.dev.pub
```

`agent_dist/manifest.json`, `config/release_verify_keys`,
`config/release_statement_keys` and `config/transparency_log_keys` are
derived from these files and nothing else, so a key rotation is a commit
diff, and `verify_release.php` regenerates them. `manifest.json` carries them
as three lists, `release_keys`, `statement_keys` and `log_keys` (`[origin,
key]` pairs), and the converger loops over each; the single
`signing_public_key` field is not extended, it is kept for the binaries it
already describes and nothing else reads it for trust (Q8).
`transparency_log_keys` line format: `<origin> <base64 key>` (Q5) — the note
key id hashes the origin, so the key is meaningless without it. The statement's
`keys_installed` lists the same set, so the Rekor entry itself shows a
rotation; `verify_release.php` and the public releases page compare every
checkpoint key against Sigstore's published trusted root and flag any that is
not there — which is the only way a rotation to a key we hold could ever
read as anything but the canary firing.

**Keys are introduced by a chain, and the node walks it (B3, B4).** The
converger stays append-only (R3 of `package_signing`). `RELEASE_STATEMENT`
carries `key_chain`: the ordered list of every statement that introduced a key
since genesis, each with its own proof, each logged under the keys before it.
Genesis heads it; a release is added to the next one's chain when its
`keys_installed` holds a key the chain's last link did not. In the walk a later
link's checkpoint key for an origin replaces the earlier one, since a log's key
may rotate in place (reviewer2 Q8); the publisher walks the chain the same way
before shipping (`ReleaseStatementPublisher::walkChain()`).
A node verifying a statement whose keys it does not hold walks the chain
forward from whatever keys it does hold — the shape of TUF root rotation. So a
node that skipped the release that carried a new checkpoint key is not stuck:
the next release it does install carries the chain that gets it there. A new
log key is vouched for only by something the publisher does not hold — a log
the node already trusts — never by the release or statement key, which a
compelled publisher holds. The
same chain governs release-key and statement-key rotation; release-key
rotation has the same latent hole today.

**The walk reads; the caller writes (B9).** `verify()`'s contract stays
"reads nothing outside `$dir` and the key files", and its host-installer
caller runs unprivileged by design (`package_signing` round 2, R1). So
`verify()` walks the chain in memory and returns the keys it proved on the
`PackageVerdict` as `keys_proven`; the root callers — `upgrade.php`,
`install_extension.php`, the converger — persist them to the two key files,
append-only, after the verdict. The same split in Go: the updater's verify
returns keys, the install step writes. Nothing is ever removed by the
converger.

**Shards are shipped ahead (B4).** Rekor freezes a shard when the next goes
live, and the first release logged under the new shard would be refused by
every node that holds only the old key. Sigstore's signing config lists a
shard before its validity start. Publish therefore checks two key sets,
which answer two different questions: **shipping**, `release_keys/log/` in
the tree being published (what this release puts on nodes), and **held**, the
`log_keys` of the last release that carried a statement (what nodes have
now).

- A future shard's key must be in shipping; if not, publish refuses and the
  message names the file and the key to add. This release then ships it.
- The live shard's key must be in shipping **and** held. Pinned but not held
  is its own refusal: "pinned but not yet shipped — publish once on the old
  log first".
- Every key must equal the one Sigstore's trusted root gives for that log at
  that time (the entry whose validity window covers it, since a log's key may
  rotate in place).
- A future shard whose key Sigstore's trusted root does not publish yet is
  not refused: nobody can act on it, and refusing would only stop releases.
  Discovery returns it as `waiting` and the O6 watch raises it (reviewer2 Q2,
  2026-10-07; WP1 first refused it).

The future-shard refusal fires while the old shard still takes writes, which
is the window in which it can be acted on. **Genesis:** before any release has
carried a statement there is no held set; the first logged release is
accepted on shipping alone, and the publisher prints that it is genesis —
the same trust-on-first-install D5 already admits for `install.sh`.

The first install's keys are the root of trust: `install.sh` writes them from
the installer bundle (TOFU, admitted), and a customer who wants to check them
compares them with the repo's `release_keys/` and with Sigstore's trusted
root — from their own machine, not from the node (Q7).

### D6. Who requires it (B2, B8)

"Logged required" is a property of the **node**, not of a key line:
`config/release_log_required`, an empty root-owned file. When it exists,
every key in `release_verify_keys` is subject to D5. No key-line syntax
changes, so `host_files_write_release_verify_keys()` is untouched and its
exact-line match stays correct.

**It is monotone: a release can only tighten (B8).** `install.sh` and the
hosted/multi-tenant host installer write it at birth, and the host converger
creates it on every tick when it is absent — so every node that exists today,
and every container born before this spec, gets it on the first converge after
the release that carries it. All three go through one writer
(`host_files_write_release_verify_keys`), which creates it only once the node
holds a statement key and a log key from its bundle: a node required to check
the log with nothing to check it by would refuse every release, including the
one that would give it the keys (WP4, 2026-10-07). The converger never removes it, and no agent word
writes or removes either file. A fork owner publishing their own releases with
their own key opts out with a second root-owned file,
`config/release_log_optional`, which they create by hand on their own box: the
converger creates `required` only when `optional` is absent, and never writes
`optional` itself.

Hosted and multi-tenant nodes: `required` is always present; the host
installer writes it at birth and the converger backfills the containers that
predate it.

**Sideloading stays (2026-10-07).** Today an admin can upload a package that
does not verify and install it with **Install anyway**: a POST behind the
second-factor step-up, minting an acknowledgement root checks
(`PackageAcknowledgement`), installed under the unsigned restrictions (the
database half as `www-data`, no `host_installer`, an **Unsigned** badge, an
event-log row and an email to every superadmin). `release_log_required` does
not remove that path. The channels this spec closes — `upgrade.php`, a
marketplace install, the agent's self-update — have no acknowledgement path
and refuse anything that is not `signed` and logged.

A package signed with our release key but not in the log gets the verdict
`unlogged`, and on the upload path it is handled exactly like `unsigned`:
refused by default, installable with Install anyway under the same
restrictions, recorded as `plg_trust` / `thm_trust` = `unlogged` so the badge,
the email and the event-log row can say which. Its warning names the case:
"signed by Joinery but not in the public log — the shape of a build Joinery
was made to produce; do not install it unless you know exactly where it came
from." Refusing it outright would buy nothing: removing
`RELEASE_MANIFEST.sig` turns the same bytes into an `unsigned` package that
the same button installs, and it would stop an owner sideloading an archive
from a release published before logging began.

### D7. What a customer can check (Q7)

- **The proof, from their own machine.** `utils/verify_release.php
  <version>`, in the public repo. It fetches the published archives from the
  release's upgrade source — which must serve them publicly, without a node
  credential — reads the statement, regenerates every manifest from a clone at
  `C`, rebuilds the agent from `A` with the recorded toolchain and compares the
  raw binary, compares decompressed content where compressed bytes differ,
  verifies (not regenerates) the signatures, derives the key files from
  `release_keys/` and compares, and checks every checkpoint key against
  Sigstore's trusted root. Then it prints the Rekor entry it expects and
  compares it with the log. A customer runs it on a laptop. This is what turns
  "the code is public" into "the code on my machine is that code".
- **The pointer, on the node.** `/admin/admin_release_provenance`
  (superadmin) shows: running version, core commit, agent commit, each
  artifact's manifest hash, the Rekor log index and checkpoint origin, the
  agent binary's sha256, the verdict the node reached when it installed and
  when — each linked to the public commit and the public log entry — and,
  apart from all of that, every extension installed with Install anyway
  (trust `unsigned` or `unlogged`): its name, version, who approved it and
  when, said plainly as "installed by an administrator of this site, not
  logged". The page never says everything on the machine was logged when
  something was sideloaded. It is
  served by the machine it describes, so it is informational: it tells the
  customer what to verify and how, in plain words first ("This machine is
  running release 0.8.470, which is public commit abc123, logged publicly on
  2026-10-08. To check this yourself, run …").
- **Over time.** A public page on getjoinery lists every release: version,
  commit, log index, and the keys it installs, with every checkpoint key
  checked against Sigstore's root. An entry there that was never announced, a
  log entry with no release page, or a key the root does not know is the
  canary firing. The page also shows the current release, which is how a
  customer notices they are behind (Q3).

### D8. What this proves and what it does not

Proves: a node with `release_log_required` has only ever installed, through
the release channel, code whose statement is in a public log, and that
statement names public commits from which anyone can reproduce every hash and
every key the release installed.

Does not prove: that the public code is benign (public review is for that);
that root on the machine did not change the tree by hand — on a hosted node
root is us, and the scope section says so; or anything about a running
process's memory. It does not stop a compelled release — it makes one public,
which is the whole point. It does not stop a node being served an older
*logged* release than the current one (a downgrade is gated by a typed
confirmation on the node already; a release newer than the node but older
than the latest is not): the public releases page is where a customer sees
they are behind (Q3).

## Work packages

Each is self-contained and lands on its own.

**WP1 — The log client.** `ReleaseLogClient`: reads Sigstore's Rekor v2
signing config and trusted root through the four fetches of O2, pins the
active shard and its checkpoint key against `release_keys/log/`, signs the
DSSE envelope with the P-256 statement key and submits it as a
`hashedrekord` entry over `sha256(PAE)`, returns leaf bytes + proof +
checkpoint; refuses to return without all three, each verified. The
shard-ahead refusal of D5. Unit tests against a recorded response. The
offline checks (checkpoint note, inclusion proof, leaf binding, envelope
signature) live in their own class, `TransparencyProof`, which WP4 reuses on
the node. The live test that settled D-C ran first.

**WP2 — A release is a commit. DONE 2026-10-07: release 0.8.465 (core 72e25ec4, agent c2b08235) published under it, live on getjoinery and joinerydemo; the scratch host unpacked the deterministic bundle. The auto-bump path had nothing to bump that day and is proven by the next publish that bumps.** `publish_upgrade.php`: clean-tree and
on-remote checks for both repositories, the refusal that prints the commit
command (D1); `VERSION` and the install SQL generated for the owner to commit
(`--restrict-key`, no timestamp line, fixed seed hash, `gzip -n`); support
bundle and relay sealer built deterministically (D2); `go.mod` toolchain pins
and `GOTOOLCHAIN=local` (D3); `release_keys/` directory created from the
current keys and `manifest.json` derived from it. Test: publish twice from the
same commit on two checkouts and diff every manifest, every raw binary
sha256, the support bundle's content listing — zero differences.

**WP3 — The release statement. BUILT 2026-10-07, uncommitted.** Written at
publish after every artifact's manifest is signed; DSSE-signed;
`keys_installed` and `key_chain` filled; logged through WP1;
`RELEASE_STATEMENT` placed in every artifact as a listed file (D-F), each
manifest signed again listing it, the node's four checks run on the result,
and only then are archives tarred. Carried sites carry it as received.
`AgentDistPublisher::repoKeyLists()` gains `statement_keys` (through
`ReleaseLogClient::repoStatementKeys()`, the same rule the publisher signs
by) and `manifest.json` carries it. The statement key is minted on the first
logged publish only, its public half written to `release_keys/statement/` for
the owner to commit; after genesis a missing key is refused (restore it, never
re-mint) and so is one the last logged release did not install. Held = the
`keys_installed` of the newest release row holding a statement
(`upg_release_statement`), or null (genesis, printed as such). Discovery runs
before anything is written. The watch (O6): `WatchReleaseLog`, hourly, asks
Sigstore once a day and every hour while anything is wrong; incidents
`plane:release_log` and `plane:release_log_blind` on the management node's
own node. Files: `ReleaseStatementPublisher`, `ReleaseLogWatch`, the two
incident sources, `TreeManifestPublisher::restamp()`,
`PackageSignature::statementSubject()`, `ReleaseLogClient` 1.1 (refusals
classified for the watch). Reviewed by reviewer2 2026-10-07: VALID with
B1–B4 and Q1, Q2, Q4–Q8, all folded in; re-verified VALID the same night (statementLines; live manifest
restamped last; `ReleaseLogEntry` ledger and spent numbers; the unfinished-row
guard; pinned-not-shipped stays open; an unpublished future key is waiting,
not refused; the key check right after the bundle; `submitEntry()`; publish
walks the key chain before shipping). Tests: `release_statement_test.php`
(73), `release_log_entries_test.php` (7, test-db). Proven live on dev: the
watch concluded clear against Sigstore. Not yet run: a real logged publish
(the first one is genesis, and mints the statement key).

**WP4 — Node verification, PHP. BUILT 2026-10-07, uncommitted.** `PackageSignature` 1.3
(`unlogged`, `nodeLog()`, `checkLogged()` with the chain walk, `fresh`,
`persistProvenKeys()`); `upgrade.php` 1.9 (fresh check on every staged archive,
`unlogged` refusal, keys persisted, `TransparencyProof` loaded from staging only
after its hash matches the release's signed listing, and kept OUT of the
self-update set because the re-run is one-shot); `install_extension.php`
1.7 and `AbstractExtensionManager` 1.4; `verify_package.php` 1.1; `_host_files.sh` 1.4
(statement and log key files, `release_log_required` once both hold a key);
`fix_permissions.sh` 4.7; `_plugin_installers_start.sh` 2.26; the publisher tars
archives without excluded paths (the mailbox plugin shipped a `.gitignore`);
badges, VaultHealth and the warning text for `unlogged`. Tests:
`release_log_verify_test.php` (35), the host converger gate (123), contract and
staging tests updated. Rollout: the release that first carries WP4 must itself
be logged (genesis or later); a node switches the requirement on only after the
converger has written its keys from that release. A plugin kept from before
logging that never takes an upgrade stops running its host installer (it
carries no statement); the upgrade transcript names it and the converger logs
it each tick. The publisher refuses a plugin or theme holding a file on a path
no manifest lists (other than git's own), rather than dropping it from the
archive. The upload path's `unlogged` handling is held by text checks on
`install_extension.php`, not an executed install (it needs root and the
RootRequest machinery). Reviewed by reviewer2 2026-10-07: VALID WITH FIXES
(B1 staged helper unchecked as root, B2 silent installer stop, Q3a, Q5), all
folded in. As specified:
`PackageSignature`: `unlogged` verdict and
the four checks of D5 including the leaf decoder; `fresh_archive` mode
refusing excluded-path members as `extra_file`; check 2 through
`PackageSignature::statementSubject()` (D-F); the key chain walk returning `keys_proven` on the
verdict, persisted by the root callers (B9); `config/transparency_log_keys`
written by `_host_files.sh` looping the bundle manifest's `log_keys` list
with the origin per line, `release_verify_keys` from its `release_keys` list
(Q8), `release_statement_keys` from its `statement_keys` list (P-256 keys
only, filtered on read), all three pinned root:root 0644 in
`fix_permissions.sh`'s `PINNED` list; `config/release_log_required`
written by `install.sh` and the host installer at birth and created by the
converger when absent unless `release_log_optional` exists (B8); `upgrade.php`, `install_extension.php` and the host
converger act on the new verdict; the upload path keeps Install anyway and
treats `unlogged` like `unsigned`, with its own warning text and trust value
`unlogged` (D6, Sideloading stays). Tests in `tests/unit/`: a hand-built log
with a known key; a valid proof; **a real proof and leaf with a different
statement** (B5); a checkpoint signed by a stranger; a statement whose
artifact set does not contain the manifest hash; an archive carrying
`public_html/x/cache/y.php` (B6); a chain that introduces a key the node does
not hold (verdict carries it in `keys_proven`, no file written); a node with
`release_log_optional` installing a signed-but-unlogged archive; a converger
tick on a node with neither file creating `required`; an `unlogged` upload
refused without an acknowledgement and installed with one under the unsigned
restrictions, recorded `unlogged`. Each its own verdict.

**WP5 — Node verification, Go. BUILT 2026-10-07, uncommitted (agent 1.64.0).**
New `releaselog.go` in the agent: the four checks, the leaf decoder and the
chain walk, the standard library only, mirroring `TransparencyProof` and
`PackageSignature::checkLogged()` (the leaf canonicaliser matches PHP's
quirks too: an empty array renders `{}`, an object keyed `"0".."n-1"` is
refused). The statement must record this binary's sha256 under
`agent/<platform>`; it is checked before the binary is fetched. Keys held =
the ones compiled in (`-X main.releaseStatementKeysB64`, `-X
main.releaseLogKeysB64`, set by `AgentDistPublisher` 2.3 from
`repoKeyLists()` and recorded in the manifest as `baked_keys`, the build
input WP6's rebuild needs) plus `/etc/joinery-agent/release_statement_keys`
and `transparency_log_keys` (root-owned, no group/other write, else read as
empty). Required = holding both a statement key and a log key; no file
switch, because a machine with no site has no config/ and the agent decides
from what it holds. Before a swap the install step appends every key held and
every key the chain proved, never removing, each file replaced by a rename,
and no swap happens when they cannot be written (reviewer2 B2), so the
requirement outlives a later binary built without keys. A machine with no keys never asks for a
statement and updates on the signature alone. Served path: the artifact
kind `agent_statement` (`AgentChannelEndpoint` 1.34), its own request under
a 1 MiB cap because the manifest's answer is read under the 64 KiB job cap
and a statement grows with every key rotated; a plane without the kind is a
fetch failure, retried, not a verdict. Heartbeat state `unlogged`; the
dashboard names it. Backoff is keyed on manifest plus statement, so a
manifest seen before its statement is retried when the statement lands.
Tests: `releaselog_test.go` (20, incl. the B1 case twins and the B2 keys-before-swap: a logged release installs and its keys are
kept; stripped statement; borrowed proof (B5); stranger's checkpoint;
statement recording other bytes; unheld statement key; key chain to a new
log, a forged link proving nothing, nothing written by the check; no keys
updates on the signature; the requirement outlives the binary; untrusted key
file; append-only persist; over the channel; an old plane; inclusion proofs
for every leaf of trees 1-9; canonical JSON parity; a non-canonical leaf;
baked keys read; **a real Sigstore log2025-1 entry verifies**, and fails under
a stranger's log key, a stranger's statement key and a wrong index). The full
agent suite passes under `-race`. PHP: `agent_artifact_channel` (kinds now
checked against the agent source on the box) and `agent_release_channel`
(the `-X` value form; the variable names checked against the source, since
`go build` ignores `-X` for a name it does not find). A build with the
repository's keys was confirmed to carry them in the binary. **Reviewed by reviewer2 2026-10-07: B1 (traced) Go's
encoding/json matched field names in any case and took the last of two
case-twins, so `{"artifacts":honest,"ARTIFACTS":hostile}` showed the agent
one hash and every PHP reader another — fixed: every document is read as
raw objects by exact key, as PHP reads them; B2 (read) the keys were written
after the swap with a truncating write, and a failure only logged — fixed:
written before the swap, temp file plus rename, no swap without them. Parity
otherwise traced (Go stricter in base64, integers, non-ASCII leaf keys: the
closed direction); no brick found in rotation or the skip path. Q1 a machine
holding one kind of key but not the other does not require the log, as D6's
two-file rule; Q2 the fork case is a release_keys README line; Q3 the
statement fetch is unmetered like the manifest's. Re-verified VALID by reviewer2 the same night (B1, B2 closed; the directory is synced after the rename too).** Tests now 20. Rollout: agents
up to 1.63.0 hold no keys and take 1.64.0 on its signature; 1.64.0 built from
a tree with a statement key requires the log for every update after it. The
first 1.64.0 bundle is built by the next publish (genesis or later); a
genesis build happens after the statement key is committed, so it carries it.

**WP6 — The customer's view.** First, what ships was cut back to what git
knows (D2, found in this package's survey; `ReleaseCommit` 1.1,
`TreeManifestPublisher` 1.6, `publish_theme.php` 1.6.0, `release_commit` test
78). Then: `utils/verify_release.php`;
`/admin/admin_release_provenance`, listing sideloaded extensions apart (D7); the public releases page on getjoinery
with the trusted-root comparison; the upgrade source serving archives
publicly for the verifier.

**WP7 — Live proof.** One real publish from a clean commit; one node upgrades
and its page shows the log entry; a second machine runs `verify_release.php`
against the version and matches the Rekor entry; then, served to a test node:
a signed archive with the statement stripped (refused `unlogged`), a signed
archive carrying a logged statement's proof over a different payload
(refused), a signed archive with a member under `cache/` (refused); and the
O6 drill on the management node — discovery pointed at a doctored signing
config naming an unpinned future shard opens the incident, pinning keeps it
open as "pinned, not yet shipped", and a logged publish closes it.

## Decisions

**D-A. Where the log entry lives: Rekor, not our own log.** A log we run is
ours to edit; the statement must live somewhere we cannot quietly alter.
Rekor is public, append-only, witnessed and free. The cost is a dependency at
publish time only — nodes verify offline. A self-hosted tlog would remove the
dependency and the proof with it. No other option is live.

**D-B. One statement per release, not one log entry per artifact.**
A release is ~27 artifacts. One statement is one log index to show a customer,
one record that reads as a release, one thing `verify_release.php` compares,
and it lists the key set, which per-artifact entries could not. A lone plugin
or theme archive still verifies on its own because it carries the whole
statement.

**D-C. The statement key is P-256, logged as `hashedrekord` — settled by
WP1's live test, 2026-10-07.** The plan was one key: the Ed25519 release key
signing a `dsse` entry. Against `log2025-1.rekor.sigstore.dev`:

- A `dsse` request is refused whatever signs it: `invalid type, must be
  hashedrekord` (HTTP 400). Rekor v2's server accepts `hashedrekord` only; its
  API marks the DSSE request "Deprecated: Use HashedRekordRequestV002 with the
  hash of the DSSE PAE instead". A made-up field is refused differently
  (`unknown field`), so the request shape was understood and the type itself
  is what is closed.
- `hashedrekord` refuses pure Ed25519 by design (the server's source rejects
  any algorithm without a digest; the live shard answered HTTP 500). It
  accepts ECDSA P-256/P-384/P-521, RSA, and Ed25519ph. PHP's sodium has no
  Ed25519ph, so keeping the one key would mean hand-written signing and
  verification arithmetic on every node.
- A throwaway P-256 key's `hashedrekord` over `sha256(PAE)` was accepted
  (log index 142362519). The response carried the canonical leaf body, an
  18-hash inclusion proof and a checkpoint; offline, the checkpoint signature
  verified against the trusted root's Ed25519 key, the proof walked to the
  checkpoint's root, and the leaf's digest, signature and key matched what
  was sent. That response is WP1's recorded fixture.

So D4's P-256 fallback applies, through `hashedrekord`: a P-256 statement key
beside the Ed25519 release key, native in PHP (openssl) and Go (stdlib). The
statement keeps its DSSE envelope form, because the PAE binds the payload type
to the payload. The refusal of pure Ed25519 is structural, not a server bug:
a `hashedrekord` verifier checks a signature against a digest, and pure
Ed25519 signs the message, not a digest. The statement key is machine-held in
`config/` (`release_statement_key`, pinned 600 root:root by
`fix_permissions.sh` beside `agent_signing_key`), not a person's key, so a
second key adds nothing a person has to hold or protect.

**D-D. No bypass.** No flag skips the log at publish, none skips the check at
install on a node with `release_log_required`. The value of the spec is
exactly the absence of that flag.

**D-E. Publish never commits.** The owner commits; publish refuses a dirty
tree and says what to commit (Q2). This matches the standing rule that the
owner runs `git commit`, and it means nothing ships that a human did not sign
off as a commit.

**D-F. The statement is a listed file — found while building WP3,
2026-10-07.** As first written, `RELEASE_STATEMENT` sat in every archive and
was excluded from the manifest. Every node verifying today refuses a file the
manifest does not list (`extra_file`), and it verifies the core archive with
its *installed* verifier before the self-update that would bring a newer one.
So the first release carrying a statement would have been refused by every
node in the fleet, with no release able to fix it.

So the statement is listed like any other file, and what the statement
records for a manifest is that manifest with its `RELEASE_STATEMENT` lines
removed (`PackageSignature::statementSubject()`). The publisher signs every
manifest, logs the statement over those bodies, writes the statement into each
artifact and signs each manifest again with its line in it
(`TreeManifestPublisher::restamp()`); removing that line gives back the bytes
the statement recorded. Removing them is safe only because every removed line
must hash to the statement itself (D5 check 2); publish refuses a statement
path anywhere but where it writes one before logging, and checks every line's
hash after. A verifier that has never heard of statements sees a
signed file with a matching hash. A verifier regenerating a manifest from the
commit gets the subject directly, since no commit holds a statement. The
alternatives were worse: shipping the verifier one release ahead leaves every
node that skips that release stuck for good, and serving the statement beside
the archives gives up a lone plugin archive carrying everything it needs.

`agent_dist/manifest.json` does not carry the statement either: the core
manifest covers it and the statement covers the core manifest. The agent reads
`agent_dist/RELEASE_STATEMENT` beside it; agent binaries are served through
`serve_agent_binary()`, not static files, so WP5 adds a served path for it.

**One number, one statement (reviewer2 B3, B4).** A logged entry is
permanent, and a publish that fails after logging deletes its release row so
the work can be redone. So every statement is recorded in
`rle_release_log_entries` (`ReleaseLogEntry`) the moment the log's answer is
verified, and never removed: a version found there is spent, auto-detect skips
it and an explicit one is refused, so one number is never logged twice, and
every entry under our key is accounted for (O5's canary reads this table).
A publish killed between logging and recording leaves a release row with no
statement after one that has; the next logging publish refuses before writing
anything and names it, because its archives may be installable and the keys
nodes hold cannot be known from here.

## Open items

- **O1 — RESOLVED 2026-10-07.** Two runs of `create_install_sql.php` on dev
  four seconds apart differ in exactly three places, each a one-line fix in
  WP2: (a) our own `-- Generated at:` header line — drop it; (b) the
  `\restrict <token>` / `\unrestrict` pairs pg_dump 16.10+ emits with a random
  token per run — pass `--restrict-key=joinery-install` (the installed 16.15
  supports it); (c) the seed admin row's bcrypt hash, which takes a fresh
  salt each run — emit one fixed hash of the default password (the account is
  `usr_force_password_change`, so a shared salt costs nothing). With those
  three lines excluded the dumps were byte-identical.
- **O2 — RESOLVED 2026-10-07.** No TUF client. Discovery is four plain HTTPS
  fetches from `tuf-repo-cdn.sigstore.dev`, each named by the one before:
  `timestamp.json` → `<n>.snapshot.json` → `<n>.targets.json` →
  `targets/<sha256>.signing_config_rekor_v2.v0.2.json` and
  `targets/<sha256>.trusted_root.json`, the last two checked against the
  sha256 the targets file names. Today that yields one v2 shard,
  `https://log2025-1.rekor.sigstore.dev` (API v2, valid from 2026-01-01),
  with an Ed25519 checkpoint key, and the v1 log beside it. Verifying the TUF
  root signature chain is not needed: the pinned key in the repo is the
  trust anchor, and the public page and `verify_release.php` compare it to
  Sigstore's root on every run.
- **O3.** The management node itself runs the tree it publishes and never
  installs an archive (`isOriginNode()`). Its own `unpublished_file` incident
  already alarms on an edit after publish; with D1 the dev box's working tree
  may be dirty between releases without affecting any node, since nothing
  ships until it is committed.
- **O4.** Hotfixes. There is no fast path: every fix is a commit, a push and a
  logged publish. The only added step over today is the commit, which the
  owner already runs by hand.
- **O5. Watching the log for our key (WP6).** Rekor v2 has no search API:
  "an entry under our statement key with no release page" can only be found by
  tailing the log's tiles for entries whose verifier is a key in
  `release_keys/statement/` (what Sigstore's `rekor-monitor` does). WP6's
  public page needs that tail to make the canary fire on its own rather than
  only when a node or customer goes looking.
- **O6. The missed shard window — DECIDED 2026-10-07 (owner): don't miss
  it.** Sigstore rotates its Rekor shard about yearly and published
  log2025-1's key about three months before the shard went live. If no
  publish happened during that whole overlap, the next release could only be
  logged on a shard whose key nodes do not hold, and no release could hand
  them the key: a statement introducing a log key would itself be logged under
  that key. Letting a node accept a new log key on our own statement key is
  refused, because a compelled publisher holds that key (D5: a new log key is
  vouched for only by a log the node already trusts).

  So the rule is the overlap window, made impossible to miss: the management
  node runs discovery daily and raises an incident as soon as Sigstore lists a
  future shard whose key is not pinned (WP3).

  One mistake here is very bad, so the watch is built to never fail quietly:

  - **Silence is never green.** The only all-clear is a discovery run that
    completed and saw no future shard nodes do not hold the key of yet. A
    key committed but not yet shipped by a logged release is still open
    ("pinned, not yet shipped: publish once before <date>"), since publish
    checks a future shard against the tree only (reviewer2 Q1); so is a
    future shard whose key Sigstore has not published. A run that cannot reach
    Sigstore, cannot parse what it gets back (a format change), or has not
    concluded in three days raises its own incident, "the release-log watch
    cannot see Sigstore" — the same rule as `plane:monitoring_broken`.
  - **It escalates.** The incident names the date the new shard takes over;
    it opens as a warning and turns critical inside 30 days. The cannot-see
    incident turns critical after two weeks without a conclusion. While
    anything is wrong the task asks every hour, not once a day.
  - **Two independent tripwires.** Publish itself refuses while a listed
    future shard's key is unpinned (WP1's shard-ahead refusal, built and
    tested), and releases go out several times a week, so any publish in the
    window forces the pin even if the daily task never ran; the publish after
    the pin is the one that ships it.
  - **Proven, not assumed.** Unit tests drive the incident source with
    recorded Sigstore documents: an unpinned future shard opens it, pinning
    leaves it open until a logged release holds the key, an unreachable CDN, an unknown format and a stale last success
    each open the cannot-see incident. WP7's live proof includes a drill on
    the management node — discovery pointed at a doctored signing config —
    with the incident seen to open and to close. A week of notice is enough to
  pin the key and publish once; months of silence means the project has
  stopped, and releases stopping with it is the right failure. There is no
  recovery path for a missed window beyond adding the key on each node by
  hand. Witness-cosignature recovery was considered (reviewer2) and not taken:
  machinery for a case the incident prevents.
- **O7. A refused agent update is seen only on the box itself (found in
  WP5).** The agent writes its update state (`verify_failed`, `unlogged`,
  `version_rejected`) to its own site's `ahb_agent_heartbeats` row; a machine
  with no site writes none, and nothing reaches the management node. So a
  node whose agent refuses an update shows only as an agent version behind on
  its row. By the incidents-only rule this belongs in an `IncidentSource`
  fed by the state the agent reports over the channel. Predates this spec
  (true of `verify_failed` today); open for the owner.
