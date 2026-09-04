# Manifest trust recovery, and saying so out loud

## The problem

A node can reach a state where the management system can no longer do anything
to it. The machine is up, the agent is running, it polls, it claims jobs — and
it refuses every one of them. It is not unreachable. It has stopped trusting its
own files.

Getjoinery sat in this state from 2026-08-31 to 2026-09-04: four days of failed
backups and two failed upgrades, on the public site. It was recovered by hand
over SSH, which is the transport the agent-management programme is removing. Had
SSH already gone, the node would have had to be rebuilt.

### Why the trap exists

The agent runs as root. The site tree is writable by the web user. So before a
script primitive runs a site file as root, the agent verifies that file against
a signed per-file manifest — `RELEASE_MANIFEST` + `.sig` at the site root,
signed with the release key whose public half is compiled into the agent binary.
Without that check, a web-layer compromise becomes root. The check is right and
is not in question here.

The trap is that verification fails at the level of the *manifest*, not the
file. When the manifest's own signature does not verify, every script primitive
is refused at once — `apply_update` among them. The remedy ships inside the
upgrade, and the upgrade is refused by the thing it would repair. There is no
path back over the agent channel:

- Every primitive that could re-deliver a manifest (`apply_update`,
  `backup_run`, `run_plugin_installers`, the restore set) is a script primitive
  behind the same gate.
- The only non-script primitives are `delete_backup`, `restart_agent` and
  `ssl_probe`. None writes a file.

The observed cause on getjoinery was a publish defect (a republishing site
re-signed its own live tree with its own key), fixed in 576d37ca. That defect is
closed. **This spec is not about that cause.** Any future event that leaves the
site root carrying a manifest the agent cannot verify — a partial deploy, a
restore from a differently-signed release, a key rotation, a bug not yet written
— reproduces the same dead end.

### Nothing said so

The dashboard showed failing jobs. It never showed a node that had fallen out of
trust and could no longer be managed. The reason lived in `mjb_error_message` on
individual jobs, and even that was being overwritten by the version probe until
JobResultProcessor 1.23. Four days passed partly because no surface named the
state, and the working note on the incident said the node would heal itself on
its next upgrade — a belief nothing on any screen contradicted.

## What already exists

The agent has a delivery channel that does not depend on the site manifest, and
it kept working throughout the incident: **self-update**. It fetches a signed
artifact from its source, verifies the Ed25519 signature against the key
compiled into the binary, and installs. No manifest is consulted. Getjoinery's
agent stayed current the entire time it refused every job.

The pattern is also already applied to a *tree* rather than a binary. Machines
with no site have no release manifest, so they fetch a **support bundle** over
the artifact endpoint — a small tree with its own `RELEASE_MANIFEST` and `.sig`
signed by the release key — verify it against the baked-in key, check every
listed hash and that the tree holds nothing unlisted, and unpack it root-owned
to `/opt/joinery-agent/tree`.

So the machinery for this spec is not new. A siteless machine can already obtain
a verified script tree from nothing. A machine *with* a site cannot obtain a
verified manifest for the tree it already has. This spec closes that asymmetry.

## A — recovering trust

### Trigger

Recovery is attempted only when the manifest itself is unusable:

- the manifest file or its signature is absent, or
- the signature does not verify against the compiled-in release key, or
- the manifest body does not parse.

**A per-file hash mismatch never triggers recovery.** That outcome means a file
on disk is not the file the publisher signed, which is the exact event the gate
exists to catch. Fetching a fresh manifest in response would be papering over a
tampered file, and would convert the security control into its opposite. A
mismatch continues to refuse, permanently, with no recovery attempt.

This distinction is the load-bearing one in this spec.

### Source

The manifest is fetched **over the channel from the plane**, as a new artifact
kind (`release_manifest`) alongside `agent_manifest`, `agent_binary`,
`bundle_manifest` and `bundle_body`. The node names the version it is running;
the plane resolves that to the signed manifest from the corresponding release
archive it already stores.

Not from the local tree. On a node in this state the local tree is by definition
the thing whose trustworthiness is in question, and the failure actually
observed — a republish rewriting the live tree's manifest — would have equally
overwritten any in-tree spare copy. A local fallback would be a spare key under
the mat of the door it opens.

The plane serving the bytes creates no new trust relationship, for the same
reason already recorded in `artifactsource.go` for agent binaries: the plane does
not hold the release key and cannot sign. A hostile plane serving a hostile
manifest is refused by a check that never leaves the machine.

### What is written

On successful verification the agent writes `RELEASE_MANIFEST` and
`RELEASE_MANIFEST.sig` to the artifact root that failed — the site root for
core, the plugin or theme directory for an independently-shipped artifact, which
keeps the existing no-cross-manifest-fallback rule intact.

Recovery restores **the list, not the files**. If the tree has genuinely drifted,
the drifted files still fail per-file verification afterwards, which is correct.
What it buys is that unmodified files verify again — and `public_html/utils/upgrade.php`
is an unmodified core file in every scenario of this shape, so `apply_update`
becomes runnable and the node can finish healing itself the ordinary way. On
getjoinery this would have replaced four days and an SSH session with about a
minute and no human.

### Restraint

Mirroring the self-update path: a manifest that fails verification is not
retried until the offered bytes change (hash the fetched manifest, back off on a
repeat), transport failures are retried, and the attempt is logged once rather
than every cycle. Recovery is attempted on the same idle cadence as the
self-update check, never inside a job.

## D — naming the state

Independent of A, and worth landing on its own.

The agent knows the moment it refuses for this reason. That fact is currently
visible only as individual failed jobs.

- **Report it.** The claim body already carries the node's own account of itself
  (`primitives`, `bundle_version`, `agent_version`) behind the `extrasDropped`
  compatibility path. Add `script_trust`: whether the site manifest verifies,
  and if not, which artifact failed and why.
- **Store it** on the node record, with the time it was first observed.
- **Show it.** A node that cannot verify its own scripts is not "a node with
  some failing jobs" — it is a node that can no longer be managed, and the
  dashboard should say that in those words, with how long it has been true.
  Fleet health should surface it the way it surfaces backup failures.

D is what makes A's failure visible too. If a recovery attempt keeps failing —
the plane has no manifest for that version, the tree has genuinely diverged —
the node must not sit silently in a loop nobody can see.

## Open questions

1. **Plugin and theme manifests.** Core is the case observed. Recovery keyed by
   owning artifact is the natural generalisation, but plugin archives upgrade
   independently of core and the plane's ability to serve the right manifest for
   an arbitrary installed plugin version needs checking before it is promised.
2. **Which version to ask for.** Reading `public_html/VERSION` from an untrusted
   tree is acceptable — a wrong answer yields a manifest that fails verification,
   which is a safe failure — but it is worth confirming there is no better source.
3. **Nodes whose plane has no matching release.** A node running a version whose
   archive has been pruned from `static_files` cannot be served its manifest.
   Retention interacts with recoverability; D at minimum must make this legible.
4. **Ordering against agent migration.** A is agent-side work and needs a fleet
   with a current agent to be useful; the nodes most likely to need recovery may
   be the ones furthest behind.

## Acceptance

1. A site whose `RELEASE_MANIFEST.sig` is replaced with one signed by a
   different key recovers without intervention, and `apply_update` then runs.
2. A site with a valid manifest and one tampered site file does **not** recover,
   and the tampered file still fails verification. No manifest is fetched.
3. A manifest served by the plane that does not verify against the compiled-in
   key is refused, not written, and not retried until the offered bytes change.
4. A node in the untrusted state is identifiable as such from the dashboard,
   with the reason and the duration, without opening a job.
5. A plane that does not serve the new artifact kind leaves an older agent
   working exactly as it does today.
