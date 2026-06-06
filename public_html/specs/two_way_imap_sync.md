# Spec: Two-Way IMAP Sync

**Status:** Proposed (awaiting implementation)
**Scope:** `inbound_email` plugin — IMAP-source feeds only
**Related docs:** `plugins/inbound_email/docs/overview.md` (Setup, IMAP feeds, reference-backed storage)

---

## 1. Problem & Goal

The IMAP integration is **one-way (read-only pull)** today. `ImapIngestor` only calls
`login`, `status`, `search`, `fetch` (all with `BODY.PEEK`), and `logout`; it never
issues `STORE`, `EXPUNGE`, `APPEND`, or `COPY`. Message state — read/unread, starred,
deleted — lives entirely in Joinery's `iem_` rows (`iem_is_read`, `iem_is_starred`,
`iem_delete_time`) via `MailboxService`, and never propagates to the source mailbox.
Likewise, a change made in the source mailbox (e.g. reading or deleting a message in
Gmail) is never reflected back into Joinery.

**Goal:** let an operator opt a feed into keeping the source mailbox and the Joinery
reader in agreement for a defined set of state dimensions, in one or both directions,
without changing behavior for feeds that don't opt in.

This is a **platform-level** capability of the IMAP provider, expressed in standard IMAP
flags — not a Gmail-specific feature. Gmail is one instance.

## 2. Non-Goals

- **No new OAuth scope / re-consent.** The granted scopes (`https://mail.google.com/`,
  Microsoft `IMAP.AccessAsUser.All offline_access`) already permit IMAP writes; password
  feeds (Yahoo/iCloud/Fastmail/generic) likewise have full access. Sync is purely
  additive on the existing connection.
- **Single folder, no folder/label sync.** Sync operates only on the feed's one configured folder
  (`iia_imap_folder`, default `INBOX`) — the same folder ingestion polls. v1 syncs *flags* (and
  optionally deletion) within that folder, not Gmail labels, IMAP folder moves, multiple folders, or
  arbitrary categorization. See the multi-folder note below.
- **No applicability to non-IMAP inbound.** Webhook (Mailgun/SendGrid/SES) and Postfix
  domains have no upstream mailbox to sync with; this feature is inert for them.
- **No real-time push.** Sync rides the existing poll cadence (`PollImapAccounts`); IMAP
  IDLE / push notifications are out of scope.

**Future — multiple folders (own spec, not small).** The single-folder model is baked into the data
layer, so multi-folder is a sizeable follow-on, not an add-on here. It would involve: (1) a per-folder
sub-entity (`iif_inbound_imap_folder`) since UIDVALIDITY and MODSEQ are per-folder — the
`iia_uidvalidity` / `iia_last_sync_modseq` / `iia_last_sync_time` state moves there; (2) a folder
dimension on `iem_` rows (UIDs are unique only within `(folder, UIDVALIDITY)`), included in the dedup
constraint; (3) **cross-folder Message-ID dedup** — Gmail folders are labels, so the same message
appears in INBOX *and* All Mail *and* each label as distinct UIDs; (4) folder discovery (`LIST`) +
selection UI with provider special-folder names in `PRESETS`; (5) ingestor/syncer iterating folders
per feed with per-folder cursors and shared per-run caps; (6) a reader folder-navigation dimension
(which also revives the spec 2 sent-copy concern once Sent is ingested). Deliberately deferred.

## 3. State Dimensions (decide once, up front)

Every state dimension a mailbox row can carry, and its IMAP counterpart:

| Joinery state            | IMAP counterpart                          | Sync risk | Default in v1 |
|--------------------------|-------------------------------------------|-----------|---------------|
| `iem_is_read`            | `\Seen` system flag                       | Safe (reversible) | Synced |
| `iem_is_starred`         | `\Flagged` system flag (= Gmail star)     | Safe (reversible) | Synced |
| `iem_delete_time` (soft) | Move to Trash, or `\Deleted` + `EXPUNGE`  | **Destructive** | **Deferred (§4.1)** |
| (none — replies/compose) | `APPEND` to Sent, drafts                  | Out of scope | n/a |

Flags are the safe core and the scope of this spec. Deletion is destructive and asymmetric
(soft-delete here vs. permanent removal there) and is **deferred** (§4.1); when built it is a distinct
opt-in defaulting to move-to-Trash, never raw `EXPUNGE`, unless explicitly configured.

## 4. Sync Levels & Directions

Sync is configured **per feed** (`InboundImapAccount`) and is **off by default**, so existing feeds
are unaffected. The capability is an escalating ladder; the operator picks one level per feed:

| Level | `iia_sync_mode` | Direction | Writes to source? | Needs CONDSTORE? | What it gives |
|---|---|---|---|---|---|
| **0 — None** | `off` | — | No | No | Default. Mail is ingested once; the source is never touched again and local read/star/delete stay local. |
| **1 — Read-only mirror** | `pull` | source → Joinery | No (source untouched) | Yes | Joinery *follows* the source: read/star in Gmail → shown in Joinery. Joinery actions do not propagate. |
| **2 — Push** | `push` | Joinery → source | Yes | No | The source *follows* Joinery: act in the reader → reflected in the source. Source-side changes do not return. |
| **3 — Two-way (INBOX)** | `both` | bidirectional | Yes | Yes | Full reconciliation on the INBOX with the local-wins conflict rule (§7). |

Flags sync as a pair — read (`\Seen`) and star (`\Flagged`) move together; there is no separate
"read but not star" switch. **Pull and Both require CONDSTORE** (§6); on a non-CONDSTORE server only
`off` and `push` are selectable. Setting: `iia_sync_mode` ∈ `{off, push, pull, both}` (default `off`).

**Scope of this spec: Levels 0–3, flags only.** The destructive **deletions** dimension
(`iia_sync_deletes`) and **Level 4 (all folders)** are deferred — see §4.1.

**Directions in detail:**
- **Push (local → remote):** a reader flag action (`markRead`, `setStarred`) propagates to the source
  mailbox's flags on the next sync pass.
- **Pull (remote → local):** a flag change made in the source mailbox is reflected into the `iem_` row
  on the next poll.
- **Both:** push and pull, with the conflict rule in §7.

### 4.1 Deferred Scope & Rationale (read this before extending)

Two capabilities are intentionally *out* of this spec. The reasoning is recorded here so a future
reader doesn't assume they were forgotten or re-litigate the decision:

- **Deletion sync (`iia_sync_deletes`).** Deferred — it is a step-change in cost *and* risk for
  marginal benefit. It is destructive and asymmetric (soft-delete here vs. move-to-Trash / permanent
  removal there); pull-side deletion needs the separate QRESYNC/`VANISHED` machinery; and on Gmail
  "archive" (remove from INBOX) is indistinguishable from delete at the IMAP layer, so it would mark
  archived mail as deleted. Pre-launch that is a poor trade. It is cleanly additive later — the toggle,
  the Trash-target columns, and the delete code drop onto the finished flags path without reworking it.
  (Its mechanics are still described in §5–§11 as reference for when it is built.)
- **Level 4 — all folders.** Deferred — the single-folder assumption is baked into the data layer, so
  multi-folder is a sizeable separate effort (per-folder UIDVALIDITY/MODSEQ entity, a folder dimension
  on `iem_` rows, cross-folder Message-ID dedup for Gmail labels, folder discovery + selection UI,
  per-folder loop cursors, and a reader folder-navigation UX). See the multi-folder note in §2.

**Why the line sits exactly here (cost/benefit).** Most of the cost is standing up `ImapSyncer` at all
(columns, `MailboxService` hooks, poll integration, UI); once that exists, two-way flags is the
coherent payoff, because one-way leaves the other view stale — push-only would show reads done in the
native client as unread, which is more confusing than no sync. The hard correctness design (conflict
carve-out, loop avoidance, §7) is already done, so Levels 1–3 bank that work. Deletions and all-folders
are where cost and risk jump disproportionately, so they are the natural deferral. Note the whole
feature's value is **conditional on dual-client use** (the operator also using their native mail app
on the same mailbox); if Joinery is the sole client even Level 3 adds little — which is why sync is
off by default and low priority.

## 5. Data Model Changes

All via `$field_specifications` + `update_database` (no migrations for schema).

**`iia_inbound_imap_account` (feed):**
- `iia_sync_mode` `varchar(10)` default `'off'` not null — `off|push|pull|both`.
- `iia_last_sync_modseq` `int8` nullable — highest CONDSTORE MODSEQ reconciled on pull.
- `iia_supports_condstore` `bool` default `false` not null — cached capability (see §6).
- `iia_last_sync_time` `timestamp(6)` nullable — observability.

*Deferred (§4.1) — added with the delete path, not in the flags-only v1:*
- `iia_sync_deletes` `bool` default `false` not null — gate delete propagation.
- `iia_trash_folder` `varchar(255)` nullable — target for delete-push (e.g. `[Gmail]/Trash`;
  null ⇒ provider default, see §6).
- `iia_supports_qresync` `bool` default `false` not null — cached capability; gates remote→local
  delete-pull via `VANISHED` (see §6).

**`iem_inbound_email_message` (message):**
- `iem_local_state_modified` `timestamp(6)` nullable — set by `MailboxService` whenever a
  *reference-backed* row's read/star/delete changes locally; the "needs push" marker and
  the conflict timestamp.
- `iem_synced_state_time` `timestamp(6)` nullable — when push last reconciled this row;
  a row needs push ("dirty") iff `iem_local_state_modified > iem_synced_state_time` (or the
  latter is null). Together these two columns fully drive sync; loop avoidance is handled by
  value comparison, so no per-row MODSEQ column is needed (§7).

Existing `iem_imap_uid` / `iem_imap_uidvalidity` / `iem_iia_inbound_imap_account_id`
already address a message on the server; reuse them. Only reference-backed rows
(non-null `iem_iia_inbound_imap_account_id`) are ever synced.

## 6. Provider Capability & Mechanics

Add a capability surface to the IMAP provider/preset layer so behavior is data-driven,
not hard-coded per host:

- **CONDSTORE / QRESYNC** (RFC 7162): both detected from the server `CAPABILITY` response and
  cached in `iia_supports_condstore` / `iia_supports_qresync`. CONDSTORE enables efficient flag pull
  (fetch only flags changed since `iia_last_sync_modseq`); QRESYNC adds `VANISHED` for delete-pull.
  Gmail and Microsoft 365 advertise both, as do Fastmail/iCloud. **Pull and Both modes require
  CONDSTORE**; a server without it is **push-only** (the §8 control omits Pull/Both with a short note).
  This is the guard that lets pull stay incremental with no remote-flag baseline cache.
- **Trash target:** per-provider default for delete-push (`[Gmail]/Trash` for Gmail;
  generic IMAP falls back to `\Deleted` + `EXPUNGE` only when `iia_sync_deletes` is on and
  no Trash folder is resolvable). Configurable via `iia_trash_folder`.

**Operations introduced in a new `ImapSyncer` (sibling of `ImapIngestor`, sharing the open
connection):**
- Push flags: `STORE` `+FLAGS`/`-FLAGS (\Seen \Flagged)` over a UID set, batched (reuse the
  windowed-UID pattern and a per-run cap).
- Push delete: `COPY`/`MOVE` to the Trash folder (or `\Deleted` + `EXPUNGE` for generic
  when explicitly configured).
- Pull (flags): **requires CONDSTORE** — `FETCH … (FLAGS) CHANGEDSINCE <iia_last_sync_modseq>`. No
  full-flag-diff fallback: without CONDSTORE there is no stored remote-flag baseline to diff against,
  so pull is simply unavailable (see below). This keeps `§5` free of a remote-flag cache column.
- Pull (deletes): **requires QRESYNC** (`iia_supports_qresync`). Plain `CHANGEDSINCE` reports
  *modified* messages, not *vanished* ones, so deletions are read via QRESYNC's
  `UID FETCH … (CHANGEDSINCE <iia_last_sync_modseq> VANISHED)` (or a QRESYNC `SELECT`) — the
  returned `VANISHED (EARLIER)` UIDs map to local rows, which are soft-deleted. Runs in the §7 pull
  step on the same modseq cursor as flag-pull, and only when `iia_sync_deletes` is on. No full-UID-scan
  fallback: a CONDSTORE-but-not-QRESYNC feed syncs flags and can push-delete, but remote deletions are
  not reflected locally (guard surfaced at the §8 checkbox).

## 7. Sync Cycle, Conflicts & Loop Avoidance

`ImapSyncer` runs inside `PollImapAccounts`, on the already-open connection, once per feed
(skipped entirely when `iia_sync_mode = off`).

**"Dirty" row.** A row has a pending local change iff `iem_local_state_modified >
iem_synced_state_time` (or `iem_synced_state_time` is null). Dirty rows are what push owns — and what
protects a local edit from being clobbered by pull.

Order per feed:

1. **Pull** (mode ∈ `pull|both`): capture the folder's current `HIGHESTMODSEQ` *first*, then
   `FETCH (FLAGS) CHANGEDSINCE iia_last_sync_modseq` (plus `VANISHED` when QRESYNC + deletes). For each
   changed row:
   - **dirty → skip** — do *not* apply the remote value; the pending local edit wins and is pushed in
     step 3 (this is the conflict resolution, below).
   - **clean → apply** the remote flag/deletion to the local row.

   Then advance `iia_last_sync_modseq` to the `HIGHESTMODSEQ` captured at the *start* of this step
   (not after push — see loop avoidance).
2. **Ingest** new mail (existing `ImapIngestor::poll`).
3. **Push** (mode ∈ `push|both`): for each **dirty** row whose local value still differs from the
   current remote value, `STORE` the change (move-to-Trash for deletes), then set
   `iem_synced_state_time = now` (clearing dirty).

**Conflict rule — local-wins, enforced by the step-1 dirty carve-out.** The carve-out is the crux:
if pull overwrote a dirty row it would set local = remote, and step 3 would then see "no difference"
and skip the push — silently making *remote* win. By skipping dirty rows in pull and pushing them in
step 3, a local edit made since the last sync always prevails ("I changed it here, make it so").
Remote-wins (server-authoritative) is the viable alternative but surprises a user who just acted in
the reader; local-wins is the chosen rule.

**Loop avoidance — value comparison is the correctness guarantee; the modseq cursor is only
efficiency.** A flag we push bumps the server MODSEQ, so the next cycle's `CHANGEDSINCE` re-reads that
row. That is harmless: the row is no longer dirty (step 3 cleared it) and its remote value now equals
the local value, so pull's "clean → apply" is a value-equal no-op. The read-back self-cancels — **no
`iem_remote_modseq` bookkeeping is needed.** The cursor is advanced to the **pre-push**
`HIGHESTMODSEQ` deliberately: advancing past our own pushes would risk skipping a genuine remote
change that landed concurrently during the cycle. Re-reading our own pushed rows once is the bounded
price of never missing a real change.

**Worked example (both-mode), cursor = 100:**
- A reader marks message X read (X now dirty); concurrently someone stars message Y in Gmail.
- *Step 1:* capture `HIGHESTMODSEQ` = 105; `CHANGEDSINCE 100` returns Y (`\Flagged`, modseq 104). Y is
  clean → apply the star locally. X is *not* in the remote-changed set (only its local state changed),
  so pull never touches it. Advance cursor → 105.
- *Step 3:* X is dirty and remote ≠ local → `STORE +FLAGS (\Seen)`; server assigns X modseq 106; set X
  `iem_synced_state_time = now` (X no longer dirty).
- *Next cycle:* capture `HIGHESTMODSEQ` = 106; `CHANGEDSINCE 105` returns X (`\Seen`, modseq 106). X is
  clean and remote (read) == local (read) → no-op. Advance cursor → 106. Loop closed, nothing
  re-applied.
- *Had Y also been edited locally (dirty),* step 1 would skip it and step 3 would push the local value
  — local-wins.

**UIDVALIDITY change:** if the folder's `UIDVALIDITY` differs from `iia_uidvalidity`, all
UID→row mappings are stale — clear `iia_last_sync_modseq`, do not push (UIDs are
meaningless), and let ingestion re-seed before sync resumes.

## 8. UI

- **Accounts → combined IMAP mailbox editor:** add a "Sync" control — a single dropdown
  (`Off` / `Pull (read-only)` / `Push` / `Two-way`). Use FormWriter `dropinput` with
  `visibility_rules` (no hand-rolled JS). On a non-CONDSTORE feed (`iia_supports_condstore`
  false), omit the `Pull`/`Two-way` options and show a short note that two-way sync requires a
  CONDSTORE-capable server.
- **Deferred (§4.1):** the "Also sync deletions" checkbox is **not** shown in this spec's scope.
  When the delete path is built, it appears when sync ≠ Off, warns that deletions move the source
  message to Trash, and notes on a non-QRESYNC pull/both feed (`iia_supports_qresync` false) that
  remote deletions will not be reflected locally (push-delete still works).
- **Setup → Receiving (IMAP mailbox):** surface a synthetic row reporting sync mode and
  last sync time/status (reuses `_setup_imap_receiving_rows`).
- Default presentation reflects `off`; no behavior change unless the operator opts in.

## 9. Failure Handling

- Reuse `iia_needs_reauth` / `markNeedsReauth()` — a sync write failing on auth flags the
  feed exactly as ingestion does; the Accounts "Reconnect" affordance already covers it.
- Partial progress is safe: pushes are idempotent (`STORE` of an already-set flag is a
  no-op); `iem_synced_state_time` only advances on confirmed writes, so an interrupted run
  retries the remainder next cycle.
- Per-run caps (mirroring `maxPerRun`) bound work and API/rate exposure; `iia_last_status`
  records counts (pushed/pulled/conflicts).

## 10. Testing

Extend `plugins/inbound_email/tests/imap_poller_test.php` (or a new `imap_syncer_test.php`)
with a mock IMAP client:
- Push: local read/star change → `STORE +FLAGS` issued with correct UID set; no-op when
  already in sync.
- Pull: remote `\Seen`/`\Flagged` change since MODSEQ → local row updated (CONDSTORE path).
  Non-CONDSTORE feed → Pull/Both unavailable; only push runs.
- Conflict (both changed) → local-wins per §7.
- Loop avoidance → after a push, the next pull re-reads the row but applies nothing (remote == local
  value-equal no-op); the cursor advances without re-applying.
- Conflict carve-out → a row dirty *and* remotely changed in the same cycle is skipped by pull and
  pushed in step 3 (local-wins), not overwritten.
- UIDVALIDITY change → sync suspends and re-seeds without corrupting state.
- Delete: with `iia_sync_deletes` on → move-to-Trash (Gmail) / `\Deleted`+`EXPUNGE`
  (generic); with it off → local soft-delete does not touch the server.
- Delete-pull: QRESYNC `VANISHED` UIDs → matching local rows soft-deleted; a CONDSTORE-only
  (no QRESYNC) feed → remote deletions not reflected locally, flags still sync.

## 11. Delivery Order (not a timeline)

Inventoried up front; shipped in risk order. **This spec covers steps 1–3 (Levels 0–3, flags only);
step 4 and all-folders are deferred (§4.1).**
1. Push flags (read/star) — non-destructive, highest value, simplest.
2. Pull flags via CONDSTORE (CONDSTORE-capable feeds only; others stay push-only).
3. Both-mode + conflict rule + loop guard.
4. **Deferred (§4.1)** — Delete sync, gated behind `iia_sync_deletes`: push (move-to-Trash) on any
   feed; pull (QRESYNC `VANISHED` → local soft-delete) on QRESYNC-capable feeds only. Built later,
   additively, onto the finished flags path.

## 12. Docs to Update at Implementation

Update `plugins/inbound_email/docs/overview.md` to describe sync as the current state
(per the docs rule — no "previously one-way" narration): the IMAP section gains a "Sync"
subsection covering the per-feed mode, the flag↔state mapping, deletion handling, the
pull/ingest/push cycle, and the conflict rule. Note that no OAuth re-consent is required.
