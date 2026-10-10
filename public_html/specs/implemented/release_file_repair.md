# Release file repair: a failed upgrade must never need a person to fix it

**Status:** BUILT 2026-10-10 (agent 1.71.0 in `/home/user1/joinery-agent`, Server Manager 1.30.54).
Verified: the agent's Go suite (guard around `apply_update`, snapshot and verified
restore, the symlink and wrong-bytes refusals, both words, the poll report, the plane
fetch), `release_manifest_source` (each file the plane serves hashes to the signed
manifest's value, on a real published archive), `script_trust`, `job_command_builder`,
`staged_rollout` and `primitive_transport_parity`. Not yet run: a real stopped upgrade on
a node, `restore_release_file` against a wedged node, and a rollout with the readiness
check on a fleet that reports it; those are in the live verification queue under 'Release file repair'.

## What went wrong

The 0.8.477 staged rollout stopped at its first node, galactictribune. The
upgrade copied its own new files (`utils/upgrade.php`, `includes/DeploymentHelper.php`)
over the live ones, as it always does first, then stopped on a check
(the node's custom theme is not in the release). The release itself never
went in, so the node was left on 0.8.475 with 0.8.477's deployment files.

Those files no longer match the 0.8.475 signed manifest, so the node's agent
treats `utils/upgrade.php` as tampered with and refuses to run it. Every later
`apply_update` is refused the same way, and no release can repair it, because
the repair would be an `apply_update`. A person with a shell has to put the two
files back.

A failed deploy that a deploy cannot fix is the worst failure this system has.
This spec closes it from three directions and makes the fourth visible.

## What is already built (platform, `utils/upgrade.php` 1.15)

A run that stops before deploying puts the replaced files back and empties
staging. It cannot cover a run that is killed (the agent's 60-minute timeout,
an out-of-memory kill, a reboot), and it protects a node only once that node
holds a release that carries it. Both gaps need the agent.

## The four pieces

All four deal only in release code files: public, signed, and neither user data
nor a secret (rule 8 of `agent_recipes_and_vocabulary.md`).

### 1. The agent guards `apply_update` (no new word)

The agent runs outside the upgrade's process group, so it survives whatever
kills the upgrade. Around every `apply_update` it does two things.

- **Before:** for each of the five self-update files
  (`public_html/utils/upgrade.php`, `utils/update_database.php`,
  `includes/DatabaseUpdater.php`, `includes/DeploymentHelper.php`,
  `includes/PackageSignature.php`) that currently matches the installed signed
  manifest, it keeps a copy under `/etc/joinery-agent/release-files/` with the
  release's VERSION. A file that does not match is not kept: a snapshot is only
  ever of a file the publisher signed.
- **After, whatever happened:** if the live VERSION is unchanged and any of the
  five no longer matches the installed manifest, it puts the kept copy back,
  after checking the copy's hash against the manifest again. If the VERSION
  changed, a release went in and the new manifest speaks for the new files, so
  it does nothing. It also runs at agent start when a snapshot is waiting, which
  covers an agent restarted mid-job.

It writes only bytes whose sha256 is the one the signed manifest lists, so a
corrupted or planted snapshot can restore nothing but the signed file.

### 2. `restore_release_file {file}` (operate)

For a node that is already wedged, and for any node where a self-update file has
changed. `file` is one of the five names above (closed parameter, rule 1).

The agent takes the bytes from its own snapshot when one verifies, and otherwise
asks the management node for the file from the published core archive of the
installed version (new artifact kind `release_file`, same channel and same
signed request as `release_manifest`). It checks the sha256 against the
installed signed manifest before writing anything, writes through a descriptor
on the site root as `manifestheal.go` does, keeps the mode and owner of the file
it replaces, and keeps what it replaced in `/etc/joinery-agent/release-files/replaced/`
(named with the time) so the evidence of a modified file is not lost. Refused
when the file already matches, when the manifest is unusable (that is the
manifest healer's case, not this one), or when the plane has no archive.

Hostile-caller review (rule 5): the worst a compromised management node can do is
cause one of five files to be put back to the bytes the publisher signed. It
cannot name a path, a version or a source; the hash decides what is written.
Accepted.

### 3. `upgrade_preflight` (observe, no parameters)

Reports whether an upgrade would stop before it starts, so a rollout finds out
before it touches anything. Checks, each with a pass or fail and a short reason:
the manifest is usable; the five self-update files match it; the site's VERSION
is readable; the upgrade lock is free; the deploy and staging disks have room
(same figure `upgrade.php` demands); the active theme directory exists (the
theme's name from the site's settings, nothing else read). Output is a fixed
object of those checks; no file contents, no row data (rule 8).

### 4. The node says which files differ (poll report)

The poll's existing `script_trust` field gains the value the plane already
understands, `untrusted_file`, and a new `script_trust_files` field naming the
self-update files that differ (names only, at most five). A node that checked
and found none sends an empty list, which is how the plane knows to clear the
state when the file is repaired. An older agent sends neither and is read as
before.

## The plane

- **Rollout pre-flight.** Before a staged rollout queues a node's apply, it
  queues `upgrade_preflight` on that node and judges the answer. A failing
  check halts the rollout at that node with the check's reason, before anything
  is changed. A node whose agent does not report the word is applied as before.
- **Restore button.** The node's trust incident lists the differing files and
  offers *Restore signed copy* per file (a POST, `restore_release_file`),
  for a node whose agent reports the word.
- **Clearing.** A poll that checked and found no differing files clears
  `untrusted_file`; before, only a later successful job did.
- **Serving the file.** `ReleaseManifestSource::read_file($version, $file)` reads
  the named member from `joinery-core-{version}.tar.gz`; the node never sends a
  path (the file is one of the five, the version is checked against the version
  pattern). Same logging as `release_manifest`, same absent-archive answer.
- **Halt wording.** Already built: a rollout that stops on a modified-file
  refusal names the file and says another release will not fix it.

## Versions

Agent 1.71.0 (two words and the guard; the vocabulary pin tests name them);
Server Manager plugin version bump; `AgentVocabulary` declares the words for the
rollout pre-flight so a node without them shows the standard *needs a newer
agent* state (rule 7, *Different agent versions across the fleet*).

## Work packages

| WP | Scope |
|----|-------|
| WP1 | Agent: the self-update file set, snapshot, verified restore, and the guard around `apply_update` (and at start). |
| WP2 | Agent: `release_file` fetch, `restore_release_file`. Plane: `ReleaseManifestSource::read_file`, the `release_file` artifact kind. |
| WP3 | Agent: `upgrade_preflight`. Plane: builder, vocabulary, rollout pre-flight gate. |
| WP4 | Agent: `script_trust_files` and `untrusted_file`. Plane: intake, clearing, incident detail, *Restore* button. |
| WP5 | Docs (overview, agent vocabulary table), tests on both sides, agent release. |

## Open questions

None for the build. Watch on the first rollout after release: nodes below 1.71.0
have none of this, and a node's first upgrade to the release that carries
`upgrade.php` 1.15 has no snapshot taken for it until its agent is updated.
