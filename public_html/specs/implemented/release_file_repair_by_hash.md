# Release file repair by hash: a node whose VERSION and manifest disagree still gets out

**Status:** BUILT 2026-10-15 (agent 1.79.0 in `/home/user1/joinery-agent`, Server Manager 1.30.77).
Verified: the agent's Go suites (restore across a rollback asks by hash with the VERSION as a
hint; a node with no readable VERSION is repaired; `repair_release_files` puts every differing
file back, names what it could not, refuses when nothing differs or the manifest is unusable;
recipe `release_files` unwedges an orgs-shaped fixture end to end through the loop in two ticks
and leaves an unusable manifest to the healer; the fetch carries the sha256 and refuses to ask
without one), and on the plane `release_manifest_source` (found by hash in the release that signed
it, with an older hint and with none; every bad file, hash and hint finds nothing),
`agent_artifact_channel` (the `sha256` field's shape) and `job_command_builder` (the word's
route and its needs-a-newer-agent refusal). Not yet run: the recipe on a real wedged node, and
*Put them all back* against one. Those are in the live verification queue under
'Release file repair by hash'.

## What went wrong, again

`release_file_repair.md` (implemented) closed the case of an upgrade that stops after
copying its five deployment files in: the old release is left with the new release's
files, the agent refuses `upgrade.php` as modified, and no `apply_update` can fix it. It
gave the agent a kept copy, the `restore_release_file` word, `upgrade_preflight`, and the
poll report. It left one assumption in all of them: **the site's `VERSION` names the
release whose manifest is at the site root.**

On 2026-10-14 the 0.8.495 staged rollout failed its deploy tier on getjoinery-orgs and
rolled back. The rollback of that release restored `public_html` alone (fixed in 0.8.498,
`utils/upgrade.php` 1.22), so the node was left with 0.8.494's code and `VERSION` under
0.8.495's manifest, with 0.8.495's `maintenance_scripts`. Three of the five deployment
files differed from the manifest. Every way out assumed the VERSION:

- the agent's kept copy was tagged 0.8.494 and hashed to 0.8.494's manifest, not the one
  on disk, so the guard restored nothing;
- *Restore signed copy* asked the plane for the file **for 0.8.494**, checked the bytes
  against the **0.8.495** manifest, and refused them;
- `apply_update` was refused before it started, by the same check.

A person opened a root shell in the container and copied three files from the preserved
failed tree. That is the state this system must never need: the guard that wedged the
node was right to refuse, and nothing on the node could make the files and the manifest
agree again.

## The one truth

The manifest is signed by the release key and verified against the key compiled into the
agent. `VERSION` is a plain file. Where they disagree the manifest wins, and every repair
asks for **the bytes whose sha256 the manifest lists**, never for a release by number.

## What is built

### Agent 1.79.0

1. **The fetch asks by hash.** `ExecEnv.FetchReleaseFile(ctx, version, file, sha256)`;
   the `release_file` request carries `sha256`. `version` is the site's `VERSION` or `""`
   when it cannot be read, sent as a hint of where the plane should look first and
   nothing more. A fetch without a hash is never made.
2. **`restore_release_file`** uses it. A node whose `VERSION` cannot be read is no longer
   refused: the kept copy is skipped (it is tagged by version) and the plane is asked by
   hash. Everything else is as before: the manifest's hash decides, wrong bytes are
   refused, the replaced file is kept.
3. **`repair_release_files`** (operate, no parameters, new). Every one of the five that
   differs, restored as above, in one word. Result: `ok`, `version`, `restored[]` (file,
   source, replaced_kept), `failed[]` (file, reason). Refused when the manifest is
   unusable (the healer's case) and when nothing differs; fails outright only when nothing
   could be put back, so a partial repair still reports what remains.
4. **Recipe `release_files`** (release-scoped, new). Check: `upgrade_preflight`, read for its
   `manifest` and `files` facts only; an unusable manifest is *unknown* (never repaired),
   its disk, lock, VERSION and theme checks never fail this recipe. Repair:
   `repair_release_files`. The loop's standard clock applies: a check every ten minutes, a
   repair after two failing checks, a case after three repairs that do not take. A node in
   the orgs state is upgradeable again within about twenty minutes with no one's hand on
   it, and the poll's empty `script_trust_files` clears the plane's incident.

5. **A source checkout is never written.** The publishing site and a developer's box
   have a `.git` at the site root (or the web root); there a deployment file
   legitimately differs from the last published manifest between publishes, and a
   repair would put the released bytes over the developer's edit. So
   `primitives.IsSourceCheckout` makes both words refuse on such a site, naming the
   checkout, and the recipe's new `ScopeRelease` (a site installed from a release)
   does not tick there at all: the claim reports `not-applicable`, as a host-scoped
   recipe does in a container. The plane already excuses the publishing site's own
   differing files (`NodeMonitorHealth`, `TreeManifestPublisher::signsItsOwnTree`);
   this is the agent-side half of the same rule, and it holds without the plane.

**Security review (owner's question, 2026-10-15).** What this adds to an attacker's
hand, by position:

- *A compromised management node* can still only have the five deployment files put
  back to bytes the publisher signed; the hash it must match comes from the node's own
  key-verified manifest, which the plane never supplies in this flow. Unchanged.
- *Whoever can write the manifest pair at a site root* could swap in an older release's
  genuine signed manifest and have the five files follow it. That pair is root-owned
  (0644 root:root on a container site; the web user cannot write it), so this needs
  root, which already has everything. The existing restore button had the same
  property; the recipe removes the human click, not a check. Accepted.
- *Any enrolled node* can now make the plane extract up to one member from each
  published core archive per request (ten archives on dev, tens of MB each, gzip) where
  before it was one. Bounded by node authentication, the archive count and logging, and
  the agent asks only for a file that differs. Residual R1: a cheap index of
  (file, sha256) to version written at publish would make it one read; add it if the
  plane's logs ever show the lookup in volume.
- *Information:* a node learns which release carries a deployment file with a given
  hash. Release manifests are public and signed; nothing new is disclosed.
- *The write path* (descriptor-relative, no symlink followed, mode and owner kept, the
  replaced file saved) is unchanged, and the recipe adds no parameter and no value of
  its own.

The hostile-caller review is unchanged and applies five times over: the worst a
compromised management node can do is have the five deployment files put back to the
bytes the publisher signed, which is the effect of their never having been changed. No
path, release or source of bytes can be named from the wire; the recipe adds no
parameter and no value of its own.

### Plane (Server Manager 1.30.77)

- `ReleaseManifestSource::read_file_by_hash(file, sha256, hint)`: the published core
  archive whose copy of the file has that hash, with its version. Order tried: the hint;
  every published version above it, oldest first (a rollback leaves the manifest one
  release ahead); the rest, newest first. `published_core_versions()` lists what this
  plane holds. A bad file, hash or hint finds nothing and reaches nothing.
- `AgentChannelEndpoint`: the `release_file` request accepts `sha256` (64 lower-case hex,
  closed by pattern); with it the file is found by hash and the version is a hint, without
  it an agent below 1.79.0 is served as before.
- `JobCommandBuilder::build_repair_release_files`, the `repair_release_files` node action,
  `JobResultProcessor::process_repair_release_files`.
- Node page: **Put them all back** above the per-file *Restore signed copy* on a node whose
  agent reports the word, saying the node also does this itself; the Agent box names the
  recipe; the `plane:unmanageable` health text names both.

## What it does not do

- It does not touch the other files of a mixed tree. Orgs had 68 `public_html` files that
  were 0.8.494's under a 0.8.495 manifest; the agent verifies only the five deployment
  files and the scripts it runs, and the first `apply_update` after the repair replaces the
  tree. The repair's job is to make that `apply_update` possible.
- It does not change which release a node is on. Putting the manifest's deployment files
  back is what the failed deploy would have left had its rollback been whole; it is not a
  rollback and not an upgrade.
- It does not decide for an unusable manifest. That is `manifestheal.go`'s case, with the
  opposite remedy.

## Versions

Agent 1.79.0; Server Manager 1.30.77; `ReleaseManifestSource` 1.4; `AgentChannelEndpoint`
1.48; `JobCommandBuilder` 1.125; `JobResultProcessor` 1.76; `node_detail_actions_logic`
1.63; overview tab 1.26; `NodeMonitorHealth` 1.26.

## Live verification

- A node in the orgs state (code and VERSION one release behind the manifest at the site
  root, deployment files differing) with agent 1.79.0: within twenty minutes the agent's
  ledger shows `release_files` repaired, the poll clears `untrusted_file`, and
  `apply_update` to the current release goes through. Pass: no shell, no button.
- A source checkout (dev itself, or any node with a `.git` at its root) with an edited
  deployment file: the node's Agent box shows `release_files` as not here, the ledger
  has one not-applicable line, and the edit is still there an hour later.
- The same state, *Put them all back* clicked: the job completes with every file in
  `restored`, source `management node`, and the files match the manifest.
- The incident that produced this is `/var/www/html/joinerytest/incidents/2026-10-14-getjoinery-orgs-wedged-after-failed-deploy.md`.
