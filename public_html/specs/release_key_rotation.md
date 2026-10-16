# Rotating the release signing keys, with retirement

**Status:** DRAFT 2026-10-11; Q1 and Q2 decided the same day; reviewed by
reviewer2 the same day (K1-K10, applied: release keys learned from the chain,
a trusted set changed only by its authority instead of a retired list, the
three writers replacing the set, the bridge kept by version (since dropped,
Q3), the watch's settle time, fresh installs and both copy floors). BUILT
2026-10-16 (owner: build it), awaiting review: WR1, WR2 and WR3's
serve-verify-key and downgrade refusal, to ship as release M; the copy
floors move with WR4, which waits as below. The code was
inventoried again before building; what changed in the design is under "What
building found" at the end. Comes out of
`hosted_data_promise_disk_encryption` O12. Stands on `release_transparency`
(implemented), whose key chain this extends. Waits on the encryption spec's
D7 step 3 (dev encrypted in place, `config/` under its data root, dev's Linode
backups purged) before any new key is made, and comes before any machine
enrols an escrow slot (that spec's D7).

## Why

Two keys sign every release, and whoever holds them can ship code every
node installs as root:

- `config/agent_signing_key`, Ed25519, the **release key**: signs every
  manifest and every agent binary.
- `config/release_statement_key`, P-256, the **statement key**: signs every
  release statement and the public transparency-log entry made under it.

Both sit in dev's `config/` on dev's plain root disk, and dev has Linode
Backups on (owner, 2026-10-11). So every Linode backup of dev holds both in
plain text, and anyone who read one at any time (a stolen Linode login, say)
holds them today. The encryption spec stops new copies (its D9 moves
`config/` under the encrypted data root, then purges Linode's backups). It
cannot reach copies already taken. Only new keys, and the old ones refused
everywhere, do.

The transparency log makes a release signed with stolen keys visible, but
not refused: a thief can log their own release, and every node would install
it.

## What exists today

From `release_transparency` and the code:

- **The key directory** `maintenance_scripts/install_tools/release_keys/`
  lists every public key a node trusts: `release/joinery.pub`,
  `statement/joinery-7516f013.pub`, and the Sigstore log keys under `log/`
  (Sigstore's, not ours; out of scope). A rotation is a commit diff anyone can
  see.
- **Bringing a new key in works.** A release's statement carries
  `keys_installed`; a release whose keys differ from the last link joins the
  `key_chain`, logged under the keys before it. A node holding older keys walks
  the chain forward (`ReleaseStatementPublisher::walkChain()`,
  `PackageSignature` `keys_proven`), so a node that skipped a release still
  learns the new key.
- **Taking an old key out does not exist.** A site's trust files
  (`config/release_verify_keys`, `config/release_statement_keys`) are written
  by the host converger from `agent_dist/manifest.json` and are append-only:
  "Nothing is ever removed by the converger." The agent's statement keys are
  compiled in (`releaseStatementKeysB64`) and merged with
  `release_statement_keys` in its state directory, also append-only.
- **The agent trusts exactly one release key,** compiled in as
  `updatePubKeyB64`. It is used in four places: self-update (`update.go`), the
  support bundle (`bundle.go`), the manifest healer (`manifestheal.go`), and the
  script primitives' and site tree's manifest checks (`main.go`
  `bundleVerifier`, `releaseVerifier`).

## The design

### R1. A trusted set, changed only by its authority

Each node holds a **trusted set**: the release keys and statement keys it
accepts (the Sigstore log keys are not ours and are unchanged). The set is
the `keys_installed` of the last **set-changing** release the node verified,
not a pile of every key ever seen. A key leaves the set by being absent from
the next set-changing release; that is what retiring a key means.

Each set-changing release also names its **authority**: the keys allowed to
sign the next change to the set. From the first release that carries this
(release M, R5) the authority is the current release key. After that:

- **A change to the set is obeyed only from a release signed by a key in the
  current authority.** Release N, signed by the old key, adds the new keys
  and makes the authority the new key alone. Release N+1, signed by the new
  key, drops the old keys from the set.
- **Between N and N+1 the old key can still sign code** (it is still in the
  set; that is inherent, and why N and N+1 are the same day), **but it can no
  longer change the set** on any node that has verified N. A thief cannot
  retire the new key, or bring in keys of their own, with it.
- **A node that a thief reached before N** (a set change signed by the old
  key while it was still the authority) shows on the fleet column (R6) as a
  node whose set or authority is not ours, and is reinstalled, never
  overridden.

Three writers keep the set on a node, and all three replace it instead of
appending, which is today's behaviour ("Nothing is ever removed"): the host
converger's `_host_files_append_key_lines` (for `config/release_verify_keys`
and `config/release_statement_keys`), `PackageSignature::persistProvenKeys`,
and the agent's `persistReleaseLogKeys` with `heldReleaseLogKeys`. Keys
compiled into a binary are the starting set only; once a node holds a
set-changing release, its held set wins over them. `release_log_required`
stays one-way, as now: it is about the log, not the keys.

Every manifest keeps exactly **one** signature, and the rules read the key
that made it (`PackageSignature`'s `verified_by`), so nobody adds a second
`.sig` and hides a second signer.

Retired public keys move to `release_keys/retired/`, kept for good, so an
auditor can still check historical releases (`verify_release.php`, the
public releases page). Nodes do not read that directory.

### R2. Release keys are learned from the chain

Today a node learns a new **release** key only from the `manifest.json` of a
tree it has already installed: `PackageSignature::verify()` checks the
manifest's signature against `config/release_verify_keys` first and stops at
"unknown key" before any chain walk, and the walk proves statement and log
keys only. So a node that skipped release N could never install N+1. The
agent is the same, checking binaries against its compiled key.

So the walk covers release keys too. Every statement already carries
`keys_installed.release_keys`. `verify()` walks the chain the offered
statement carries before it checks the manifest's signature, then checks the
signature against the held keys plus those the walk proved, and the set is
updated from the proven keys. The agent does the same. This changes
`verify()`'s contract from "the signature first, then the listing is
trusted" to "the logged chain first, then the signature": the spec of
`release_transparency` is amended to say so.

Two rules keep the walk consistent with R1:

- **A chain link signed by a key no longer in the set adds nothing.** It never
  fails the release: the chain is cumulative from genesis, so the link that
  brought in the new keys is signed by the old statement key for ever, and
  every later release carries it.
- **A lagging node walks before it retires.** A node holding only the old
  keys does not yet know of any retirement, so it walks N's link, learns the
  new keys, verifies N+1 under them, and only then adopts N+1's set, which
  drops the old keys.

Both verifiers walk the one chain the offered statement carries, linearly,
and never merge chains (as now), so "a forked chain" on a node means two
releases offered with different chains, and R1's authority decides which can
change the set.

### R3. The agent trusts a list of release keys

`updatePubKeyB64` becomes the starting set compiled in from
`release_keys/release/`. All four uses (self-update, the support bundle, the
manifest healer, the script primitives' and site tree's checks) accept a
signature by any key in the node's current set, learned as R2 says.

### R4. Where the new keys are made

On dev, inside `config/` on the encrypted data root, once the encryption
spec's D7 step 3 has run (dev encrypted in place, `config/` under its data
root, Linode's old backups of dev purged, the Cloudflare token replaced).
The new keys then never touch a plain disk or a Linode backup taken before
encryption.

### R5. The ceremony

1. **Before.** Release M, carrying R1 to R3, published well ahead and
   installed everywhere: every node shows M or later on the fleet column (R6).
   The encryption spec's D7 step 3 done on dev. The release log watch (R7) has
   settled past now with no entry under our statement keys that dev's
   publish records do not hold.
2. **Introduce.** Make the new release key and statement key on dev and add
   their public halves to `release_keys/`. Publish release N, signed with the
   old release key and logged under the old statement key. N's set adds the
   new keys; N's authority is the new release key alone.
3. **Check.** Every node shows N's set and authority, recorded (R6): a node
   that shows the keys without a record still has the whole old set as its
   authority. The watch has settled past N's log entry, which takes its
   600-second settle time plus a
   run (R7), with nothing unrecorded.
4. **Retire.** Publish release N+1, signed with the new release key and
   logged under the new statement key. N+1's set is the new keys alone. Steps
   2 to 4 run on the same day.
5. **Check and finish.** Every node shows only the new keys. The watch has
   settled past N+1 with nothing unrecorded. The old private keys are deleted
   from dev's `config/`; their public halves move to `release_keys/retired/`.

**A node that missed the ceremony.** On M or later, it walks the chain (R2),
installs N+1, and adopts its set. A node older than M cannot walk for release
keys and is not bridged (Q3): before step 2 every node is upgraded to M or
later, or migrated to a new node, and step 1's check holds the ceremony
until it is.

### R6. Seeing what every node trusts

The agent's status check gains, for the site and for the agent, the
fingerprints of the keys in the node's set and of its authority (F14). The
management node shows them on each node page, and the dashboard marks any
node whose set is not the expected one, so steps 1, 3 and 5 are checks, not
hopes, and a node holding a key no publish of ours introduced stands out.

### R7. Watching the window

This exists: `ReleaseLogTail` and `IncidentSourceReleaseLogEntry` open a
critical incident for any log entry under one of our statement keys that the
release ledger does not hold. What the ceremony adds is waiting for it: the
tail reads only up to a checkpoint 600 seconds old (`SETTLE_SECONDS`) with a
240-second budget a run, so "the watch has seen nothing" means nothing until
its settled checkpoint is past the moment in question. The ceremony checks it
before step 2, after N and after N+1.

### R8. What retirement changes elsewhere

After step 4 no node installs a release signed only by the old key:

- **Site copy**, live or from backups, installs the source's exact release, so
  both floors (`JobCommandBuilder::COPY_SOURCE_MIN_VERSION` and
  `COPY_FROM_BACKUPS_MIN_VERSION`) rise to N+1. Restoring a backup in place
  (`restore_chain.sh`) restores data, not code, and is unaffected.
- **A fresh install** takes its first release key from the upgrade server
  (`install.sh` `write_release_verify_key`, which asks
  `upgrade?serve-verify-key=1` when the tree ships no bundle). That endpoint
  serves the current set and never a retired key.
- **Downgrades and reinstalls** of a release older than N+1 are refused, N
  included. A fault in N+1 is fixed forward with N+2, never by rolling back.
- **History** stays checkable against `release_keys/retired/` (R1).

### R9. The thief this guards against

Someone who holds dev's old keys, from an old Linode backup of dev, and may
also hold our Linode login and so write to a machine's plain root disk in
rescue mode (`public_html`, `agent_dist`, the agent). The encryption spec's
Tier 2 refuses to unlock a machine after such an event, but cannot make that
root disk trustworthy again (`hosted_data_promise_disk_encryption`, D3). R1's
authority is what keeps that thief from steering a node's trusted set once it
has seen N.

### R10. Forks

A fork that took this repository's keys removes them (as `release_keys/`'s
README already asks); a fork's nodes that ran an agent built with our keys
are reinstalled from the fork's own build. Retiring our old keys does not
reach a fork's nodes, and need not.

## Work packages

- **WR1 — The trusted set (R1 to R3), shipped as release M.** Set-changing
  releases with an authority; the three writers replacing the set; release
  keys learned from the chain in `PackageSignature` and the agent, with
  `release_transparency`'s contract amended; the agent's list of release keys
  in all four uses; one signature per manifest, read through `verified_by`;
  `release_keys/retired/` for `verify_release.php`.
- **WR2 — Seeing it (R6, R7).** Set and authority fingerprints in the
  agent's status check, on the node page and as a fleet badge; the ceremony's waits on the
  release log watch.
- **WR3 — The other paths (R8).** Both copy floors at N+1 (set at the
  ceremony, with WR4); `serve-verify-key` serving the current set; the
  downgrade refusal with its reason.
- **WR4 — The ceremony (R5).** Run once on dev after the encryption spec's D7
  step 3, every check recorded in this spec's status line.

## Testing

On a scratch origin in the test Linode account, built from source
(`build_dev_from_source.sh`) with keys of its own, and scratch nodes that
follow it (a bare-metal site, a container host). Nothing touches dev's keys
until every test passes.

- **T1** The whole ceremony, M to N+1: every node ends holding only the new
  keys; publishing, upgrading, the support bundle and the script primitives
  all work after.
- **T2** A node offline from before N until after N+1, **site tree and agent
  both**: it walks the chain, installs N+1 and adopts its set.
- **T4** A thief with the old keys, after step 4: a release signed and logged
  under them is refused everywhere; their set change is refused.
- **T5** A thief with the old keys, between steps 2 and 4: a set change signed
  by the old key retiring the new key (a genuine-looking chain) is refused on
  every node that has verified N; their log entry opens the critical incident
  once the watch has settled past it.
- **T6** A key compiled into an agent binary but absent from the node's
  current set is refused.
- **T7** A site copy, live and from backups, of a source before N+1: refused
  with the reason; after the source upgrades, it copies.
- **T8** `verify_release.php` still verifies a release signed by a retired key.
- **T9** A fresh install after step 4 takes the new key from
  `serve-verify-key` and never the old one.
- **T10** The watch's settle lag: an unrecorded entry made just before step 3
  is caught only after the settle time, and the ceremony tooling waits for it.
- **T11** A node holding a key no publish introduced shows on the fleet
  column.
- **T12** A manifest carrying a second signature is refused.

## Decided

- **Q1 — Not now** (owner, 2026-10-11): the new keys are made on dev as R4
  says; `per_channel_release_signing` (a stable key on the Mac mini for
  customer machines) stays its own step. This spec builds two things that one
  needs anyway: the agent's list of release keys (R3, where it bakes one key
  today) and a way to take a key out of every node's trust (R1, which
  `package_signing` R3 recorded as missing and assigned there).
- **Q2 — Accepted** (owner, 2026-10-11): releases signed only by a retired
  key cannot be installed (R8).

- **Q3 — No bridge** (owner, 2026-10-16): a node older than M is upgraded
  or migrated before the ceremony, never served release N on its way to
  N+1.

## What building found (2026-10-16)

The code was inventoried again before WR1 (PHP verifier and publisher, the
host converger, the agent, the management node). Where it differs from the
text above, this section is what is built.

- **F1. The authority covers both keys.** A chain link carries only its
  statement envelope, signed by a statement key; the release key signs
  manifests, which are not in the chain. So the authority names release keys
  AND statement keys: a set change is obeyed only when its statement is
  signed by a statement key in the authority, and, for the release being
  installed, its manifest by a release key in the authority. Release N's
  authority is the new release key and the new statement key.
- **F2. Where the authority is declared.** `release_keys/authority` lists, one
  per line, the files under `release_keys/release/` and
  `release_keys/statement/` that form the authority (`release/joinery.pub`).
  Absent, the authority is every release and statement key listed. Every
  statement payload carries it as `authority` {release_keys,
  statement_keys}; a statement without it (before M) has the whole set as
  its authority. The authority joins the key chain's comparison, so a change
  to it alone makes a link.
- **F3. Only a verdict changes the set.** The host converger writes the key
  files from `agent_dist/manifest.json`, and any release installed on a node
  puts that file there, including one signed by the old key between N and
  N+1. A converger that replaced the set from it would let that release
  rewrite the trust R1 guards. So the set and its authority are written only
  by a root caller from a verdict (`PackageSignature::persistTrust`), into
  `config/release_trust` (the set, the authority, the version that set
  them) with `release_verify_keys` and `release_statement_keys` rewritten to
  match. The converger seeds the files on a node that holds no
  `release_trust` yet and leaves them alone after. The other writers
  (`upgrade_ensure_verify_keys`, `install.sh write_release_verify_key`) only
  ever seed an empty file and stay as they are. The agent reads the site's
  record only when root owns it (the agent runs as root); the site's own
  reader also accepts the tree owner. Every writer runs as root, so the two
  agree; a record written by the tree owner would count for the site and be
  reported by the agent as not recorded.
- **F4. The log keys stay as they are.** Sigstore's keys are not ours (R1),
  so `transparency_log_keys` keeps growing as now; only release and
  statement keys are a replaced set.
- **F5. The verifier walks first.** `verify()` reads the statement the
  manifest lists before the signature check (the statement is
  self-authenticating: logged, and signed by a held statement key), walks
  its chain under R1's rules, checks the manifest's signature against the
  release keys the walk ends with, then checks that the release's own set,
  when it differs, is authorised. A release whose set change is not
  authorised is refused whole (`unauthorized_keys`), not installed with the
  change ignored: a release carrying a set we did not publish is not ours. A
  node without the log required, and a package without a statement, verify
  as before.
- **F6. A downgrade below N+1 refuses by itself.** Its manifest is signed by
  the retired key, which is not in the set; the refusal names the key as
  retired.
- **F7. The agent has six uses of its release key, not four:** also
  `release_archive.go` (`site_install`'s core archive). Its chain walk
  (`releaselog.go`) reads statement and log keys only, and it persists
  statement and log keys append-only in its state directory; it gets a
  `release_trust` file of its own beside them.
- **F8. No earlier agent is kept.** `AgentDistPublisher::publish()` removes
  `agent_dist.old` after a good swap, and the agent kinds never take a
  version, so a bridge would have needed a versioned store. None is built
  (Q3).
- **F9. The watch would stop watching the stolen key.** `ReleaseLogTail`
  takes "our statement keys" from `release_keys/statement/` and the newest
  release's `keys_installed`; once the old key is retired it is in neither.
  The watch also reads `release_keys/retired/statement/`.
- **F10. `verify_release.php` needs no `retired/`.** It checks each release
  against `release_keys/` at that release's own commit. `retired/` is for
  the watch (F9) and for people.
- **F11. `serve-verify-key` serves every key ever appended** to the server's
  `release_verify_keys`. It serves the repository's `release_keys/release/`
  and the server's own public key instead.
- **F12. A key switch must change the agent's version.** On a publish whose
  agent version is unchanged, `AgentDistPublisher` rewrites the manifest's
  `signing_public_key` without rebuilding, so N+1's manifest would name the
  new key over binaries built with and signed by the old one. A publish
  whose signing key differs from the one the current agent was built with
  rebuilds, or is refused until the agent version changes.
- **F13. A package no release publishes keeps the old signature.** An upgrade
  takes every package its source publishes again, signed under the new key;
  one installed from elsewhere is not, and fails the code check after N+1
  until it is reinstalled or removed. Step 5 checks every node's code check.
- **F14. The keys travel in the status check, not the host report.** The host
  report is one per machine and kept under 60 KiB at 100 site containers; a
  key list per container overflows it (62 KB measured). Every site container
  runs an agent of its own and is a node of its own, so the agent's
  check_status reports the site's and the agent's sets for every kind of node,
  and the plane keeps them in the node's status data. The management API's
  stats call does not report them: it cannot read the agent's set, and the
  status fold would replace the agent's report with a half one.
- **F15. A node writes the set after the code it came with** (reviewer2 A).
  The log keys are written at the verdict, before the install, since they
  only grow; the set is written once the code is in place (`upgrade.php`
  after the deploy tier, `install_extension.php` and the extension refresh
  once the package is placed). A deploy that rolls back keeps the set its
  code is signed under; a set not written is written by root's next verify
  of the live tree: `verify_package.php --tree`, the code check each
  housekeeping tick on a host that refuses unsigned code, which writes the
  live core's set; else the plugin installers' run, at least daily. The agent's self-update keeps
  its order: a binary rolled back is never checked against its own record.
- **F16. Nothing reported is marked too** (reviewer2 B). The dashboard marks a
  node whose agent reports its keys (1.81.0 or later) and whose status data
  holds none, so "every node shows" at steps 1, 3 and 5 is read off the
  dashboard; an older agent's row already says it is behind.
