# Restore-Over-Agent — Node-Side Primitives

**Status: IMPLEMENTED — 2026-08-28.** Owner chose the node-side-only scope:
the three restore primitives are built into the agent (1.12.0), compiled and
tested, refused unattended at the destructive ceiling; the plane carries their
builders but gates them off so live restore stays on SSH. Integrated and green
(parity 41/41, restore_primitive_gate 46/46, restore_database_envelope 11/11
against a real db, agent race-clean). The mechanism that makes them
*dispatchable* is deferred to `specs/restore_dispatch_approval_mechanism.md`.

## What this round delivers, in plain terms

The plane can restore a node's backup only over SSH today. That means a node you
harden by removing SSH is a node you can no longer restore — which blocks
hardening the mail host and getjoinery. This round teaches the agent to restore
a database, a project, and a backup chain *itself*, so restore stops depending
on SSH.

It deliberately stops one step short of running: the restore primitives are
**destructive-class**, and a destructive primitive is refused at a compiled
ceiling until a node-verified approval verifier exists (the deferred spec). So at
the end of this round restore-over-agent is **built, registered, and proven to
refuse cleanly** — not yet runnable, and live restore keeps happening over SSH.
That is the intended resting state.

## The three primitives

Mirror the existing operate primitives that wrap a script (`operate_backup_run`,
`operate_run_plugin_installers`, `operate_upload_backup` are the exemplars — read
them before writing these). Each new primitive:

- registers in `primitives/` with **`Class: ClassDestructive`**;
- wraps its `maintenance_scripts/sysadmin_tools/restore_*.sh` script through a
  `ScriptSpec`, which means the agent **verifies the script's hash against the
  signed release manifest before root-exec** (`ExecEnv.Manifest` /
  `SignedTreeVerifier`, already built) — the same rule every script-invoking
  primitive obeys;
- passes its parameters as a **fixed `Args` template** (`StdinFrom: nil`) —
  these scripts take argv, and `operate_provision_certificate` (`Args:
  []string{"{domain}"}`) is the established shape for a bash-script primitive.
  The stdin rule exists to keep *credentials* out of `ps`; safety rule 2
  guarantees none of these params is a secret (the key is a `--key-file` *path*,
  the script path is already visible), so argv is correct here. Write that
  reasoning into each file so the choice is not mistaken for an oversight;
- **refuses any parameter it does not declare** (loud) — `Validate` refuses
  undeclared params regardless of delivery, so unknown-key refusal is unaffected;
- declares its own `Timeout` — restore of a large project/chain is slow; do not
  inherit the 5-minute `DefaultTimeout`. Size it from the SSH path's budget.

| Primitive | Script | Params (must match the plane builders exactly) |
|---|---|---|
| `restore_database` | `restore_database.sh` | `db_name` (**opt** — agent defaults to the node's own configured database; supplied only to restore into a scratch db beside the live one), `file` (req), **`profile` enum site\|manager (req)**, `db_user` (opt). `--non-interactive` always. NO `domain`. Timeout 70m. |

**`db_name` is optional, not required.** There is no stored column for a node's
database name — the node's own config is the only place it exists, so the plane
cannot supply it without inventing a value, and an invented value names the wrong
database. The node knows its own (`cfg.DBName`), so absent `db_name` restores
into the node's own database; a caller supplies it only for the scratch-db case.
Same node-knows-its-own-identity rule as omitting `domain`.
| `restore_project` | `restore_project.sh` | `project_name` (req), `file` (req), **`profile` enum (req)**, `force` bool (req — a `false` refuses rather than blocking on a tty). NO `domain`, no skip flags. Timeout 70m. |
| `restore_chain` | `restore_chain.sh` | `project` (req), `chain_id` (req, `^chain-[0-9_]+$`), `seq` int 0..100000 (opt), `skip_database` bool (opt). **NO `profile`**, NO `domain`, NO artifact path, NO key. Timeout 2h20m. |

**`profile` is required on database and project, absent on chain.** The local
backup base is profile-dependent (`base` vs `base/manager`), and the *same*
filename frequently exists under both — a default would eventually load the
control plane's backup over a site, so the profile is named explicitly (the
`upload_backup` / `delete_backup` rule). On `restore_chain` the profile only
picks a bucket shelf, which is resolved before anything is local; the node-side
workspace is `restore_<chain_id>` under whichever base the chain came from, so
the primitive needs no profile.

**`domain` is deliberately omitted from all three.** Both project/chain scripts
accept `--domain` and default to the machine's own config domain when it is
absent — so the node keeps its own identity and the plane cannot redirect a
restore onto a different domain (the `run_plugin_installers` argument). Restore-
over-agent restores a node's *own* backup onto itself; moving a site to a new box
is `install_node`, not this.

### Two non-negotiable safety rules (same doctrine as backup/upload/delete)

1. **The plane cannot express a path — only a name.** `file` is a bare filename;
   the primitive resolves it *inside the compiled-in `/backups` directory* and
   rejects anything with a slash or `..`. Under SSH the plane composed absolute
   paths, i.e. read/write anywhere on the node; the primitive closes that.
2. **No key material crosses the wire.** Restore decrypts with the node's *own*
   key on disk. Trace each `build_restore_*_ssh` to see how it resolves the key
   today and preserve that resolution node-side; the plane supplies no
   `--key-file` value and no recovery key, and a job carrying key material is
   refused (A4 doctrine, read side).
   - `restore_database`: the node resolves the key itself. Point `--key-file` at
     the node's on-disk key, and **for an envelope-sealed archive fall back to
     its `<archive>.keys.json` sidecar** — see the platform-tree note below.
   - `restore_project`: `restore_project.sh` 1.3.0 already resolves the sidecar
     itself; nothing extra needed.
   - `restore_chain`: the chain data key is recovered on the node via
     `backup_envelope.php open` against the node's own recovery key — see the
     restore_chain staging seam below.

### restore_chain staging seam (this round: refuse-when-unstaged)

`restore_chain.sh` needs `--artifacts DIR` (already holding `manifest.json` plus
the downloaded artifacts) and `--key-file` (the chain data key). Under SSH the
plane composes *six* steps around it — workspace, manifest fetch,
`backup_envelope.php open`, artifact download, pre-restore dump, restore — and a
single `ScriptSpec` primitive can only start one program. This round the
primitive **resolves the artifact dir and key at a fixed node-side path**
(`{backup_base}/restore_{chain_id}`, key at `chain.key` within it) and **refuses
legibly when either is absent**, naming envelope-open and artifact-download as
what the dispatch round must add. That refusal is correct: restore_chain is
destructive and refused at the ceiling this round regardless. The candidate end
state — one node-side `restore_chain_job.sh` that opens the envelope, downloads
what the manifest names, and calls `restore_chain.sh`, so the plane composes no
heredoc — is recorded in the deferred spec, along with the bucket-read-credential
question artifact-download raises.

### One platform-tree edit this round (restore_database.sh sidecar fallback)

`restore_database.sh` has no envelope-sidecar fallback — today the plane opens
`<archive>.keys.json` with the node's own `backup_site_key` and passes
`--key-file`. Without that, an archive sealed to an envelope (rather than the
legacy `~/.joinery_backup_key`) is un-restorable over the primitive though it
restores fine over SSH — and **jeremytunnell's archives are envelope-sealed to
its own recovery key**, so the primary target would be un-restorable. Give
`restore_database.sh` the sidecar fallback `restore_project.sh` 1.3.0 already
has, **additive and firing only when `--key-file` is absent** so the SSH path
(which always passes `--key-file`) is byte-for-byte unchanged. This is plane-tree
work — 74's lane.

## Plane side — parity without dispatch

`primitive_transport_parity_test` is **bidirectional and strict**: every agent
primitive must have a `JobCommandBuilder::build_<name>_primitive()`, and every
such builder must name a primitive the agent ships. So the three plane builders
are mandatory this round — omitting them fails the gate.

But adding them must **not** cause the plane to route restore to the primitive
transport (which would refuse at the ceiling and break live restore). Therefore:

- Add `build_restore_database_primitive`, `build_restore_project_primitive`,
  `build_restore_chain_primitive`. Each composes the `{primitive, params}`
  envelope through `createFromBuild`, validating and name-checking the same
  params its `_ssh` sibling does. Reuse the sibling's validation.
- **Gate `has_primitive()` so destructive operations are never selected as the
  transport in this build.** Add the seam explicitly: a `DESTRUCTIVE_PRIMITIVES`
  set (or read the class), and a `node_can_dispatch_destructive($node)` that
  returns **false unconditionally** in this build — documented as the single
  place the approval-mechanism round flips to true. `has_primitive()` returns
  false for a destructive op while that is false, so `transports_for()` yields
  the SSH transport and live restore is untouched.
- **Keep every `build_restore_*_ssh` path.** They remain the live restore path
  until the primitive path is proven runnable (next round).
- Add `restore_database`, `restore_project`, `restore_chain` rows to
  `PRIMITIVE_MIN_AGENT_VERSION` (=> the agent version introducing them) for
  contract uniformity, even though the destructive gate makes them moot for now.

## Agent version

`main.go` 1.11.0 → **1.12.0** (three new primitives). Update the `gate_test`
vocabulary pin (both directions) and any `Names()`-count assertion.

## Tests

- **Agent:** per-primitive — config validation and unknown-key refusal; class is
  `ClassDestructive`; a `path`/`..` in `file` is rejected; a job carrying key
  material is rejected; and **dispatch refuses at the ceiling** with the
  approval-verifier message. The gate tests (class set is exactly three; only
  `script.go` starts a process; vocabulary pin) stay green with three more
  destructive primitives. `go build`, `go vet`, `go test`, `-race` clean.
- **Plane:** `primitive_transport_parity_test` green (methods exist both sides);
  a new check that `has_primitive()` returns **false** for each restore op so
  `transports_for()` yields SSH; config-composition test proving name-only
  (path-like input refused) and no wire key. `safe` tier green.

## Found and fixed during the build (durable gotchas)

- **The agent has no `$HOME`.** It runs as root and `joinery-agent.service` sets
  no `User=`, so systemd leaves `$HOME` unset. The two restore scripts fail in
  *opposite* directions on that: `restore_database.sh` (`set -o pipefail` only)
  silently resolves `"$HOME/.joinery_backup_key"` to `/.joinery_backup_key` and
  reports the node has no key; `restore_project.sh` (`set -euo pipefail`) dies
  mid-restore on the unbound variable. A node that backs itself up fine by hand
  would fail over the agent with nothing in the output saying why. Fixed in the
  agent's `script.go`: the child environment is `os.Environ()` plus a `HOME`
  resolved from the passwd entry of the account the process runs as, set only
  when absent/empty and never from a job. Blast radius checked — no other shipped
  primitive reads `$HOME`; the restore family is the first. Any future
  script-invoking primitive that reads `$HOME`, or any per-user path, inherits
  this guarantee rather than hoping for it.
- **`ScriptSpec.ArgsFrom`** (new) is the argv twin of `StdinFrom`: it composes
  argv from validated params *and* the node's resolved paths, because a plain
  `"{param}"` slot can only emit what the wire sent — which would force either a
  plane-supplied path (rule 1's exact hole) or a hardcoded `/backups`. `Register`
  panics if a spec sets both `Args` and `ArgsFrom`.

## Out of scope this round (all in the deferred spec)

The approval verifier, the node-issued challenge, the passkey-on-node storage,
flipping `node_can_dispatch_destructive()`, retiring the `_ssh` restore paths,
and hardening any node. `decommission_node` is untouched.
