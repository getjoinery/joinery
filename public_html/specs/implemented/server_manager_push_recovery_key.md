# Server Manager pushes the recovery key to its nodes

**Status:** SUPERSEDED by `specs/fleet_scheduled_backups.md`. The push half was
built and then deliberately removed — the `push_recovery_key` job type, its
builder, the install step, the automatic queueing and both admin buttons are all
gone. What survives is the decision table below, as
`maintenance_scripts/sysadmin_tools/set_recovery_key.php` (pinned by
`tests/backups/recovery_key_push_test.php`) plus its `--report` mode, which feeds
the fleet recovery-key table read-only.

The premise no longer holds: a fleet backup mints a data key per run and seals it
to the control plane's own recipients, so a node with an empty slot is a node
taking no copies of *its own* — not a node left unprotected. And a control plane
that writes into that slot would hold the private half of a key the site believes
is solely its own, which is exactly the custody split the fleet design refuses.
See `plugins/server_manager/tests/recovery_key_fleet_test.php`: reported, never
written.

**Date:** 2026-08-03

## The problem

Every site that backs itself up on a schedule needs its own
`backup_recovery_public_key`, set and proven, or `BackupRunner` refuses to run:

> Backups are configured but the recovery key is not set up, so nothing could
> ever open them.

That is correct — a scheduled backup on a node reads that node's own setting,
because nothing is sending it a key. But it means the operator repeats the same
manual ceremony on every managed site: paste the public key, open the challenge,
paste the proof. Same key every time. The only thing being established is a fact
the control plane already knows and has already verified.

Two consequences, both bad:

- A newly provisioned node is born unable to back itself up, and stays that way
  until someone remembers to visit its admin.
- The chore scales with the fleet, so the natural response is to skip it, and
  the site that gets skipped is the one with no backups.

Meanwhile a manager-driven backup job works fine without any of this — it reads
the *manager's* recovery key and ships the public half in the step command
(`JobCommandBuilder::step_mint_envelope()`). The manager already holds the key,
already reaches every node, and already refuses to build a backup job when its
own key is unproven. It just never hands the key over.

## The shape of the fix

The manager pushes the recovery public key — and the proof that it works — to
each node it manages. The operator sets the key up once, at the manager, and
never types it again.

### What gets pushed

Both settings: `backup_recovery_public_key` and
`backup_recovery_public_key_proven_fpr`.

Pushing the proof is the decision worth stating outright. The proof marker means
*someone has demonstrated the private half of this public key* — a property of
the key, not of the site holding it. Making the operator re-prove the same key
on each node establishes nothing new.

The reason the ceremony is mandatory for a hand-entered key is that a human
typed or pasted it, and a mistyped key seals happily while producing archives
nobody can open. A pushed key is copied machine-to-machine from a value the
manager has already proven. There is no typo to catch. That difference is
exactly what makes the push safe and the hand-entry path not.

### Mechanism

A job step over SSH, running a small CLI on the node:

```
php {site}/maintenance_scripts/sysadmin_tools/set_recovery_key.php \
    --public <base64> --proven-fpr <sha256>
```

Unlike `escrow_keypair.php`, this one boots the platform, so it can call
`BackupRecoveryKey::set_public_key()` and go through the same validation,
rotation refusal, and setting writes as the admin page. There is one code path
that decides whether a recovery key may be written, and this is not a second
one.

Rejected alternative: writing the node's `stg_settings` directly from the
manager over its existing DB credentials. It reimplements the guards, and it
would happily write a value the node's own code would refuse.

### The rule: fill an empty slot, never overwrite

The push writes a recovery key onto a node **only when that node has none**. If
any value is already there — the same key, a different key, proven or not — the
push leaves it alone and reports what it found. There is no force flag and no
confirmation dialog that turns one into an overwrite.

This is what makes the push safe to run automatically. Writing into an empty
slot cannot destroy anything: a node with no key is a node making no encrypted
backups. Replacing an existing key is a rotation, which is a different
operation with different consequences (archives already on the shelf open only
with the old private key) and is deliberately out of scope here.

One clarification, since it is not an overwrite: a node holding **this exact
key** with an empty proof marker gets the proof written. Nothing is replaced —
the key it already has is completed so its backups can run.

Remaining guards:

- **Manager must be ready.** Nothing is pushed if the manager's own key is
  unconfigured or unproven, checked where
  `ensure_backup_key_if_encrypting()` already checks it.
- **Only nodes that host a Joinery site.** Nodes with no `mgn_web_root` (DNS
  servers, relays) are not applicable — skipped silently, not failed.

### Where it happens

The push is not an operator action. It runs wherever the manager learns a node's
slot is empty:

- **At install.** `build_install_node()` pushes as part of provisioning, so a
  node is never born without a recovery key.
- **When the status check finds an empty slot.** The status job already collects
  each node's fingerprint (below); an empty one triggers the push on the spot.
  This is what brings existing nodes into line without anyone doing anything, and
  what repairs a node whose key was cleared.

A manual per-node action stays available for "do it now", but nothing depends on
someone remembering to use it.

The fleet view still shows per-node state — has this key / has a different key
(left alone) / not applicable — because the operator should be able to see that
a node is holding a key the manager did not put there.

### Seeing the state without touching every node

The fleet view needs each node's recovery key fingerprint, and reaching out to
every node on page load is not acceptable. The fingerprint is collected during
the existing status check — one extra command on a job that already runs — and
stored on the node row as `mgn_backup_recovery_fpr`.

Per the schema rules this is a field on the node data class's
`$field_specifications`, applied by `update_database`. No migration.

## Relationship to browser generation

`specs/recovery_key_browser_generation.md` removes the shell from creating the
key. This removes the repetition from distributing it. Together the operator
generates once, proves once, and every managed site is covered — including ones
provisioned later, which is the case no amount of careful manual work covers
today.

Neither spec depends on the other; either can land first.

## Scope

- **New** `maintenance_scripts/sysadmin_tools/set_recovery_key.php` — platform-
  booting CLI, delegates to `BackupRecoveryKey`. Validate with `php -l` only:
  run-on-include body.
- `plugins/server_manager/includes/JobCommandBuilder.php` — `build_push_recovery_key()`,
  a step inside `build_install_node()`, and the fingerprint probe in the status
  check. Version bump.
- `plugins/server_manager/logic/node_detail_actions_logic.php` — the manual
  per-node action, and what it reports when the slot was not empty.
- `plugins/server_manager/includes/JobResultProcessor.php` — record the reported
  fingerprint onto the node row.
- `data/` node class (`mgn_backup_recovery_fpr`) — plugin data class; schema
  applied by plugin sync.
- Fleet action + state table on the server_manager backups/targets surface.
- `docs/backups.md` § Recovery key setup — managed nodes receive the key from
  the control plane; a standalone site sets it up itself.
- `plugins/server_manager/docs/overview.md` — when the manager pushes a recovery
  key, and that it only ever fills an empty slot.

## Gate

- **Unit (safe tier):** the CLI's decision table — no key present (writes both),
  same key with empty proof (writes the proof), same key proven (no-op),
  different key present whether proven or not (**leaves it untouched**, reports
  what is there, does not fail the job), malformed input (refuses).
  The different-key case is the one that must not regress: a bug there
  overwrites a key someone's archives depend on.
- **db tier:** eligibility planning — which nodes are pushable, refused, or not
  applicable — over fixture nodes, including one with no `mgn_web_root`.
- **Live:** push to a real node that currently has no key, then run that node's
  own scheduled backup and confirm it produces an archive whose envelope carries
  the expected recovery fingerprint. Then confirm a second push is a clean no-op.

## Out of scope

**Rotation.** Replacing a recovery key that is already in use is a separate
operation — the archives already on the shelf open only with the old private
key, so a rotation has to re-seal existing data keys against each site recipient
before the old key can be retired. Nothing here does that, and nothing here
should imply it exists. A node holding a key the manager did not put there is
simply left as it is and reported.

## Open

Nothing blocking. The two decisions this spec previously left open — whether the
push is automatic, and how it handles an existing key — are settled above: it is
automatic wherever the slot is empty, and it never overwrites.
