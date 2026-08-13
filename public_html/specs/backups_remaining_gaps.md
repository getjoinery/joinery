# Backups — Remaining Gaps

**Status:** Unbuilt.
**Date:** 2026-08-13 (split out of
`specs/implemented/backups_core_and_incremental.md`, whose Phases 1–3 are built,
live-verified and committed as `b0bd8e16`).

## What this is

Three gaps the core backup build left open deliberately. **None is a regression
and none blocks anything shipped** — each is a feature that was scoped out, and
they are recorded together here so a review does not re-discover them one at a
time.

The engine, the envelope key model, retention, and incremental chains are all
built and proven against a real bucket. Everything below is additive.

## 1. Remote core-history display

The fleet Backups tab cannot show a managed node's own backup history — it needs
a new management API endpoint to read it. The v1 decision was observe-only, so
this is the natural completion of that decision rather than a defect.

**Carries its own open decision:** the backup history table naming/prefix, and
whether the Server Manager Backups tab should *write* core settings on remote
nodes or stay observe-only. Observe-only is the shipped behaviour; changing it is
a deliberate act, not a default.

## 2. Install-from-backup cannot open an envelope minted by another site

A restore onto a *different* node has no recipient it holds a private half for.

This is **pre-existing and not introduced by the envelope model** — the old
per-node escrow had the same wall. The clean fix is the source node re-sealing
the data key to the destination's site key at provisioning time, which makes it a
**provisioning change, not a backup-format one**. It should be specced against
the provisioning path rather than bolted onto the backup engine.

## 3. Multipart upload

`S3Signer` does a single PUT. The 5 GB single-PUT ceiling on S3/B2 is the first
thing a large full backup hits, and incrementals postpone that rather than remove
it — a chain still starts with a level-0 full.

This item sits on both this list and the parent spec's *Later / Out of Scope*
list, deliberately: it is out of scope for the engine as built, and it is the
first thing that will force itself back in.

## Related, still out of scope

Recorded so they are not mistaken for gaps:

- **PG 17+ incremental database backups** (`pg_basebackup --incremental` +
  `pg_combinebackup`) once the fleet's PostgreSQL allows. Gated on the OS
  campaign — see `specs/fleet_ubuntu_2604_postgres_upgrade.md`.
- **Per-table logical incrementals** — rejected. Change detection via stats
  counters is not crash-safe; audit-trigger approaches cost more than they save.
- **restic/borg** — rejected for now. Replaces the archive format, key custody,
  and restore/browse model wholesale and adds a fleet-wide binary dependency;
  revisit only if cross-backup dedup becomes a measured cost problem.
- **Client-custody / Sealed Vault integration** — backups here are
  operator-level infrastructure; user-level encrypted content (Drive E2E) is
  already opaque in the files being archived.

## Verification debt inherited from the parent

Retention **deletion** has never been live-tested — retention keeping exactly N
is covered, but a run that actually deletes an aged chain from a real bucket has
not been observed. Whichever gap above is built first should carry that
observation with it.
