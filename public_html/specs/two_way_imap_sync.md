# Spec: Two-Way IMAP Sync

**Status:** Proposed (awaiting implementation)
**Scope:** `inbound_email` plugin — IMAP-source feeds only
**Related docs:** `plugins/inbound_email/docs/overview.md` (Setup, IMAP feeds, reference-backed storage)
**Related specs:** `outbound_reply_forward.md`, `connected_account_email.md` (Sent / APPEND-on-send)

---

## 1. Problem & Goal

The IMAP integration is **one-way (read-only pull)** today. `ImapIngestor` only calls
`login`, `status`, `search`, `fetch` (all with `BODY.PEEK`), and `logout`; it never issues
`STORE`, `EXPUNGE`, `APPEND`, `COPY`, or `MOVE`. Message state — read/unread, starred, deleted,
and which folder/label a message carries — lives entirely in Joinery's `iem_` rows and never
propagates to the source mailbox, nor does a change made in the source mailbox return to Joinery.

**Goal:** keep the source mailbox and the Joinery reader in agreement across every state a
mailbox row can carry — read/star flags, **folder/label membership**, and deletion — in one or
both directions, for feeds that opt in, without changing behavior for feeds that don't.

This is a **platform-level** capability of the IMAP provider, expressed in standard IMAP flags,
folders, and (where available) labels — not a Gmail-specific feature. Gmail is one instance.

### 1.1 Product framing — A is a runtime subset of B

Two product shapes exist for the reader: **"Joinery reads your mail"** (a clean INBOX reader —
flags, maybe deletes, no folder navigation, no compose) and **"Joinery is your mail client"**
(full webmail — folders, Sent, compose). These are **not two builds.** The mail-client shape is
the superset; the reader shape is that same system *configured down* — `iia_sync_mode` plus a
"show compose" flag plus a folder set of `{INBOX}`. There is one codebase and one data model; the
operator's choice of how much of it to use is configuration, never an architectural fork. This
spec designs the superset.

## 2. Non-Goals

- **No new OAuth scope / re-consent.** The granted scopes (`https://mail.google.com/`, Microsoft
  `IMAP.AccessAsUser.All offline_access`) already permit IMAP reads *and writes* — `STORE`,
  `COPY`/`MOVE`, `APPEND`, `EXPUNGE`. Password feeds (Yahoo/iCloud/Fastmail/generic) have full
  access likewise. Sync is purely additive on the existing connection.
- **No applicability to non-IMAP inbound.** Webhook (Mailgun/SendGrid/SES) and Postfix domains
  have no upstream mailbox to sync with; this feature is inert for them.
- **No real-time push.** Sync rides the existing poll cadence (`PollImapAccounts`); IMAP IDLE /
  push notifications are out of scope.
- **Compose UI and send transport are specced elsewhere.** This spec owns *ingesting* the Sent
  folder and *APPEND*-ing a sent copy back to the source; the reader compose UI is
  `outbound_reply_forward.md` and the send identity/transport is `connected_account_email.md`.
  This spec consumes `resolveOutboundTransport(mailbox)` and does not re-decide it (§9).

## 3. State Dimensions

Every state a mailbox row can carry, and its IMAP counterpart:

| Joinery state            | IMAP counterpart                              | Conflict shape | In v1 |
|--------------------------|-----------------------------------------------|----------------|-------|
| `iem_is_read`            | `\Seen` system flag                           | scalar boolean | Synced |
| `iem_is_starred`         | `\Flagged` system flag (= Gmail star)         | scalar boolean | Synced |
| folder/label membership  | label set (`X-GM-LABELS`) or folder location  | **set / scalar** (§7) | Synced |
| deletion                 | move to Trash (`\Deleted` only when no Trash) | destructive    | Synced |
| sent copy (compose)      | `APPEND` to Sent                              | one-way out    | Synced (§9) |

Flags are scalar booleans. **Membership** is the dimension that does not reduce to a boolean and
drives the reconciliation design in §7. Deletion is destructive and asymmetric (soft-delete here
vs. move-to-Trash there) and is gated by its own toggle, defaulting to move-to-Trash, never raw
`EXPUNGE` unless explicitly configured.

## 4. Sync Levels & Directions

Sync is configured **per feed** (`InboundImapAccount`) and is **off by default**, so existing
feeds are unaffected. The operator picks one level per feed:

| Level | `iia_sync_mode` | Direction | Writes to source? | Needs CONDSTORE? | What it gives |
|---|---|---|---|---|---|
| **0 — None** | `off` | — | No | No | Default. Mail is ingested once; the source is never touched again and local state stays local. |
| **1 — Mirror** | `pull` | source → Joinery | No | Yes | Joinery *follows* the source: read/star/move/delete in the native client → reflected in Joinery. |
| **2 — Push** | `push` | Joinery → source | Yes | No | The source *follows* Joinery: act in the reader → reflected in the source. |
| **3 — Two-way** | `both` | bidirectional | Yes | Yes | Full reconciliation across all tracked folders, with the §7 rules. |

`iia_sync_deletes` (bool, default `false`) gates the deletion dimension independently of mode: when
off, local soft-deletes never touch the source and remote deletions are not pulled. `iia_show_compose`
(bool, default `false`) surfaces the reader's reply/forward/compose affordances (the compose feature
itself is `outbound_reply_forward.md`); when on and the feed is IMAP-backed, sent copies are
APPEND-ed to Sent (§9).

**Pull and Both require CONDSTORE** (§6); on a non-CONDSTORE server only `off` and `push` are
selectable. Flags and membership sync together — there is no per-dimension on/off beyond the
deletes gate.

## 5. Data Model Changes

All via `$field_specifications` + `update_database` (no migrations for schema). The single-folder
assumption in the current data layer is removed: per-folder cursor state moves out of the account
row into a per-folder entity, and membership becomes a first-class many-to-many relation.

**`iia_inbound_imap_account` (feed):**
- `iia_sync_mode` `varchar(10)` default `'off'` not null — `off|push|pull|both`.
- `iia_sync_deletes` `bool` default `false` not null — gate delete propagation.
- `iia_show_compose` `bool` default `false` not null — surface reader compose + APPEND-to-Sent.
- `iia_supports_condstore` `bool` default `false` not null — cached capability (§6).
- `iia_supports_qresync` `bool` default `false` not null — cached capability (§6).
- `iia_supports_labels` `bool` default `false` not null — true for Gmail (`X-GM-EXT-1`); false for
  exclusive-folder servers. Selects the membership *observation* path (§7).
- `iia_last_sync_time` `timestamp(6)` nullable — observability.

The per-account `iia_uidvalidity` / `iia_last_seen_uid` move to the per-folder entity below; the
account row keeps them only as a legacy single-folder fallback during migration and is otherwise
unused once folders are seeded.

**`iif_inbound_imap_folder` (new — one row per (feed, folder/label)):** UIDVALIDITY and MODSEQ are
per-folder, so the cursor lives here.
- `iif_iia_inbound_imap_account_id` `int8` not null — owning feed (`cascade` delete).
- `iif_name` `varchar(255)` not null — IMAP mailbox name / Gmail label (e.g. `INBOX`, `Receipts`,
  `[Gmail]/Sent Mail`).
- `iif_role` `varchar(20)` nullable — special-use role: `inbox|sent|trash|drafts|junk|archive|all|custom`
  (from `SPECIAL-USE` / provider name mapping, §6).
- `iif_navigable` `bool` default `true` not null — reader visibility, an axis **independent of**
  `iif_role`: whether this folder appears in reader navigation. Seeded `false` only for the Gmail
  All-Mail backing source (an ingestion source, not a reader destination, §7); otherwise `true`. A
  full mail-client folder-visibility preference (e.g. hide Junk, collapse a noisy folder) drives this
  per-folder without a schema change — do not "optimize" it into a function of `iif_role`.
- `iif_uidvalidity` `int8` nullable, `iif_last_seen_uid` `int8` nullable — ingestion cursor.
- `iif_last_sync_modseq` `int8` nullable — highest CONDSTORE MODSEQ reconciled on pull.
- `iif_is_tracked` `bool` default `true` not null — whether sync polls this folder.

**`imf_inbound_message_folder` (new — membership join, the heart of §7):** one row per
(message, folder) the message belongs to. Many-to-many: a Gmail message in INBOX + Receipts + Work
has three rows. Carries the **shadow**.
- `imf_iem_inbound_email_message_id` `int8` not null (`cascade` delete).
- `imf_iif_inbound_imap_folder_id` `int8` not null (`cascade` delete).
- `imf_present_local` `bool` default `true` not null — is the message in this folder per Joinery now.
- `imf_present_base` `bool` default `true` not null — **the shadow:** was it in this folder at the
  last successful sync. A membership element is **dirty** iff `imf_present_local ≠ imf_present_base`.
- Unique on (`imf_iem_...`, `imf_iif_...`). A row with both bits false is deleted, not kept.

**`iem_inbound_email_message` (message):**
- `iem_local_state_modified` `timestamp(6)` nullable — set by `MailboxService` whenever a
  reference-backed row's **flags** change locally; the flag-dirtiness signal compared against
  `iem_synced_state_time` in §7.1. Membership dirtiness is **not** tracked here — it lives entirely in
  the per-element shadow (`imf_present_local ≠ imf_present_base`), which the push step queries directly,
  so there is no `iem_`-level membership index to keep in sync.
- `iem_synced_state_time` `timestamp(6)` nullable — when push last reconciled this row's **flags**
  (membership dirtiness is tracked per-element in `imf_`, not by this timestamp).
- `iem_gm_msgid` `varchar(32)` nullable — Gmail `X-GM-MSGID` (stable, account-unique). The dedup
  key that collapses the same message seen under N labels into one row (§7). Null for non-label feeds.

Existing `iem_imap_uid` / `iem_imap_uidvalidity` / `iem_imap_folder` /
`iem_iia_inbound_imap_account_id` remain the *current-locator* for body/attachment fetch. On a move
(classic IMAP, §7) the UID changes; the locator is re-pointed to the destination folder's new UID.
Only reference-backed rows (non-null `iem_iia_inbound_imap_account_id`) are ever synced.

## 6. Provider Capability & Mechanics

Behavior is data-driven from a capability surface on the provider/preset layer, not hard-coded per
host. Detected from the server `CAPABILITY` response and cached on the feed:

- **CONDSTORE / QRESYNC** (RFC 7162) → `iia_supports_condstore` / `iia_supports_qresync`. CONDSTORE
  enables incremental flag/label pull (`CHANGEDSINCE iif_last_sync_modseq`); QRESYNC adds `VANISHED`
  for detecting messages that left a folder (move or delete). **Pull and Both require CONDSTORE.**
- **Labels** (`X-GM-EXT-1`) → `iia_supports_labels`. When true, membership is **observed directly**
  via `X-GM-LABELS` and written via `STORE ±X-GM-LABELS`; ingestion reads All Mail once. When false,
  membership is a single exclusive folder, observed by which folder a UID lives in and written via
  `MOVE` (or `COPY`+`EXPUNGE`).
- **Special-use folders** — `SELECT`/`LIST (SPECIAL-USE)` and provider name maps populate `iif_role`
  (`\Sent`, `\Trash`, `\Drafts`, `\Junk`, `\Archive`, `\All`; `[Gmail]/Sent Mail`, `[Gmail]/Trash`,
  `[Gmail]/All Mail` for Gmail). Trash is the delete target; All is the Gmail ingestion source; Sent
  is the APPEND target (§9).

**Operations in a new `ImapSyncer` (sibling of `ImapIngestor`, sharing the open connection):**
- Push flags: `STORE +FLAGS/-FLAGS (\Seen \Flagged)` over a UID set, batched (windowed-UID pattern,
  per-run cap).
- Push membership: Gmail `STORE ±X-GM-LABELS (<label>)`; classic `MOVE`/`COPY`+`EXPUNGE` to the
  destination folder.
- Push delete (when `iia_sync_deletes`): `MOVE` to the Trash folder; `\Deleted` + `EXPUNGE` only on a
  generic feed with no resolvable Trash and explicit configuration.
- Pull flags/labels: `FETCH (FLAGS` + on Gmail `X-GM-LABELS`) `CHANGEDSINCE iif_last_sync_modseq`.
- Pull vanished (deletes / classic moves): QRESYNC `UID FETCH … (CHANGEDSINCE <modseq> VANISHED)`.

## 7. Sync Cycle, Reconciliation, Conflicts & Loop Avoidance

`ImapSyncer` runs inside `PollImapAccounts`, on the already-open connection, once per feed (skipped
when `iia_sync_mode = off`). Order per feed: **Pull → Ingest → Push.**

### 7.1 Flags — scalar, unchanged from the proven design

A flag row is **dirty** iff `iem_local_state_modified > iem_synced_state_time` (or the latter is
null). Pull applies remote flag changes to **clean** rows and **skips dirty** ones (local-wins);
Push `STORE`s each dirty row whose local value still differs, then sets `iem_synced_state_time = now`.
Loop avoidance is by value comparison: a pushed flag bumps MODSEQ and is re-read next cycle, but the
row is clean and value-equal, so pull no-ops. The modseq cursor is advanced to the **pre-pull**
`HIGHESTMODSEQ`. (This is the existing flag mechanic; membership generalizes it.)

### 7.2 Membership — the shadow makes it a conflict-free set merge

Membership cannot be reconciled as a scalar: a message in `{INBOX, Work}` now showing `{Work}`
locally and `{INBOX, Work}` remotely is ambiguous — *local removed INBOX* or *remote added INBOX*?
— unless you know the set as of last agreement. That is the **shadow** (`imf_present_base`).

Decompose per folder/label into one boolean three-way merge (base `b` / local `l` / remote `r`):

| b | l | r | meaning | action |
|---|---|---|---------|--------|
| 0 | 1 | 0 | local added | **push add** |
| 0 | 0 | 1 | remote added | **apply add** (set local & base) |
| 1 | 0 | 1 | local removed | **push remove** |
| 1 | 1 | 0 | remote removed | **apply remove** (clear local & base → drop row) |
| 0 | 1 | 1 / 1 | 0 | 0 | both moved same way | agree → advance base |
| 1 | 1 | 1 / 0 | 0 | 0 | unchanged | no-op |

**Per-element, membership is a boolean, and a boolean three-way merge cannot conflict** — a genuine
conflict needs one item driven to two *different* targets, and a boolean has only one other state, so
if both sides change it they necessarily agree. On a label provider (Gmail) where every folder is an
independent membership bit, **membership sync is conflict-free.** The dirty carve-out (skip a dirty
element in pull, push it in step 3) is retained purely as the mechanical guard against pull clobbering
an unpushed local edit — not as a tiebreak.

**The one genuine conflict lives in the *exclusive-folder* (classic) case,** where "which one folder"
is a multi-valued scalar: base `INBOX`, local → `Archive`, remote → `Receipts` (three distinct
values). There the §7.1 **local-wins** rule is the resolution — keep the local destination, push it.
So the label provider is the easy one; the constrained provider is where the tiebreak is needed, and
it reuses the rule flags already established.

### 7.3 Cycle, by provider observation path

1. **Pull (mode ∈ `pull|both`).** Capture each tracked folder's current `HIGHESTMODSEQ` first.
   - **Label provider (Gmail):** `FETCH (FLAGS X-GM-LABELS) CHANGEDSINCE` over **All Mail** (the one
     pass that sees every message). For each changed message, `X-GM-LABELS` is the *authoritative full
     set* → reconcile every membership element by §7.2 (adds = new labels; removes = any base label
     absent from the returned set — **no `VANISHED` needed for labels**). Flags reconcile per §7.1.
   - **Exclusive-folder provider (classic):** for each tracked folder, `FETCH (FLAGS) CHANGEDSINCE`
     (flags) and QRESYNC `VANISHED` (UIDs that left the folder). Stage new arrivals across all tracked
     folders keyed by `iem_message_id_header`. Then **correlate vanished-vs-arrived to classify moves**
     (§7.4). Apply membership changes (single-element set) by §7.2 with the local-wins tiebreak.

   For clean elements/flags apply remote; for dirty, skip. Then advance each folder's
   `iif_last_sync_modseq` to the **pre-pull** `HIGHESTMODSEQ`.
2. **Ingest** new mail (`ImapIngestor::poll`, extended to seed `imf_` rows with `local = base =`
   observed set, and `iem_gm_msgid` on Gmail).
3. **Push (mode ∈ `push|both`).** For each **dirty** flag row and each **dirty** `imf_` element whose
   local value still differs from remote, issue the §6 op (`STORE`, `±X-GM-LABELS`, `MOVE`,
   move-to-Trash). On confirmed write set the shadow to match local (`imf_present_base =
   imf_present_local`; flags → `iem_synced_state_time = now`), clearing dirty. Drop `imf_` rows now
   `(0,0)`.

**Loop avoidance** is the shadow doing the same job at element granularity: a pushed label/move bumps
MODSEQ and is re-read next cycle, but `imf_present_base` already equals remote, so §7.2 yields a
value-equal no-op. No per-element MODSEQ column.

### 7.4 Classic move detection (solved, not deferred)

A QRESYNC `VANISHED` UID in folder F means the message left F — *moved or deleted*, indistinguishable
from F alone. Resolution, within the same poll, after all tracked folders' pulls have staged arrivals:

- Look up the local message for the vanished UID (its `iem_message_id_header` is known).
- Search the staged new-arrival sets of the **other** tracked folders for that Message-ID.
  - **Found in folder G** → **move F→G**: update the single-membership scalar to G; re-point the
    `iem_` locator (`iem_imap_folder`/`iem_imap_uid`/`iem_imap_uidvalidity`) to G's new UID.
  - **Found in Trash** (if Trash is tracked/resolvable) → **delete**: soft-delete locally (when
    `iia_sync_deletes`).
  - **Found nowhere we track** → moved to an untracked folder → treat as removed from all tracked
    folders (out of the reader's view); not a delete.

Because a move assigns a new UID, the destination arrival is also what re-seeds the locator — the
existing `resolveUid` Message-ID fallback covers any cursor gap. Gmail never enters this path: labels
are observed directly, and a Trash move is just `\Trash` appearing / `\Inbox` etc. disappearing in
`X-GM-LABELS`.

### 7.5 Deletion

`iia_sync_deletes` gates both directions. **Push:** a local soft-delete `MOVE`s the source message to
Trash (Gmail: add `\Trash`, which Gmail treats as removal from all other labels). **Pull:** a Trash
arrival (Gmail) or a `VANISHED`-classified-as-delete (classic, §7.4) soft-deletes the local row. On
Gmail, **archive** (`X-GM-LABELS` loses `\Inbox` but the message persists in All Mail) is cleanly
distinct from delete (gains `\Trash`) — the membership model removes the old archive/delete ambiguity.

### 7.6 UIDVALIDITY change

If a folder's `UIDVALIDITY` differs from `iif_uidvalidity`, all UID→row mappings for that folder are
stale: clear `iif_last_sync_modseq`, do not push into it (UIDs are meaningless), and let ingestion
re-seed (correlating by Message-ID / `iem_gm_msgid`) before sync resumes for that folder.

## 8. UI

- **Accounts → combined IMAP mailbox editor.** A "Sync" dropdown (`Off` / `Pull (read-only)` /
  `Push` / `Two-way`) via FormWriter `dropinput` with `visibility_rules` (no hand-rolled JS). On a
  non-CONDSTORE feed (`iia_supports_condstore` false) omit `Pull`/`Two-way` with a short note. When
  sync ≠ Off, reveal **"Also sync deletions"** (`iia_sync_deletes`) — warns deletions move the source
  message to Trash — and **"Enable compose / Sent sync"** (`iia_show_compose`, §9). Guided controls
  only, no explainer prose (per the self-documenting-pages rule).
- **Folder selection.** From `LIST`, the operator picks which folders are tracked (`iif_is_tracked`);
  special-use folders pre-selected, All Mail hidden as a non-navigable backing source on Gmail.
- **Reader folder navigation.** The reader gains a folder/label dimension alongside the alias scope,
  driven by `imf_` membership; INBOX-only feeds simply show one folder (the Product-A subset, §1.1).
- **Setup → Receiving (IMAP mailbox).** Synthetic rows report sync mode, per-folder last-sync
  time/status (reuses `_setup_imap_receiving_rows`).

## 9. Sent / Compose Interop

Two distinct mechanics, both owned here:

- **Ingesting Sent.** The Sent folder (`iif_role = sent`) is a tracked folder like any other; its
  messages ingest and show in the reader, so mail sent from the *native* client appears in Joinery.
- **APPEND-on-send (conditional).** When the reader's reply/forward feature
  (`outbound_reply_forward.md`) sends as an IMAP-backed mailbox with `iia_show_compose` on, the sent
  copy must reach the source's Sent folder so the native client sees Joinery-sent mail. *Most providers
  file it themselves* — Gmail/M365 SMTP auto-save to Sent — so Joinery `APPEND`s **only when the send
  transport does not** (`PRESETS` capability `smtp_files_sent = false`, e.g. self-hosted Postfix+Dovecot
  where SMTP submission does not save Sent). APPEND-ing when the provider already filed would duplicate.
  Transport/identity come from `resolveOutboundTransport(mailbox)` (`connected_account_email.md`), which
  also exposes `smtp_files_sent`; this spec only performs the conditional APPEND (with `\Seen`).
- **Dedup / reconciliation.** A Joinery-composed message is stored as a local outbound row immediately
  (for instant reader display) *and* later observed in Sent — whether the provider filed it or Joinery
  APPEND-ed it. Ingestion **reconciles to the existing outbound row** instead of creating a duplicate:
  match by `iem_gm_msgid` (Gmail, server-stable) or `iem_message_id_header` (elsewhere; reliable for the
  APPEND path since Joinery sets the Message-ID), then backfill the `iem_` IMAP locator and add an
  `imf_` membership in the Sent folder so the outbound row becomes reference-backed and appears under
  Sent in reader navigation. `iem_gm_msgid` closes the old gap where Gmail rewrites `Message-ID` on
  send.

## 10. Failure Handling

- Reuse `iia_needs_reauth` / `markNeedsReauth()` — a sync write failing on auth flags the feed exactly
  as ingestion does; the Accounts "Reconnect" affordance covers it.
- Partial progress is safe: pushes are idempotent (`STORE`/`±X-GM-LABELS` of an already-set value is a
  no-op); the shadow and `iem_synced_state_time` only advance on confirmed writes, per element, so an
  interrupted run retries the remainder next cycle.
- Per-run caps (mirroring `maxPerRun`) bound work and rate exposure; `iia_last_status` records counts
  (pushed/pulled/moved/deleted/conflicts).

## 11. Testing

Extend `plugins/inbound_email/tests/imap_poller_test.php` (or a new `imap_syncer_test.php`) with a
mock IMAP client covering both observation paths:
- **Flags** push/pull/conflict (local-wins) and loop avoidance, as today.
- **Membership, label provider:** remote `X-GM-LABELS` add/remove since MODSEQ → `imf_` reconciled;
  local label add/remove → `STORE ±X-GM-LABELS`; both-add-same-label → agree, no double op;
  conflict-free claim — concurrent disjoint label edits both land (no clobber).
- **Membership, classic:** local move → `MOVE` + locator re-point; remote move detected by
  vanish-in-F/appear-in-G correlation (§7.4); move-vs-delete disambiguation via Trash; genuine scalar
  conflict (local→A, remote→B) → local-wins.
- **Deletion:** `iia_sync_deletes` on → move-to-Trash (push) and Trash-arrival/VANISHED → soft-delete
  (pull); off → local soft-delete does not touch the source and remote deletions are not pulled;
  Gmail archive (lose `\Inbox`, keep All Mail) is *not* treated as delete.
- **Sent:** native-client Sent message ingests; Joinery-composed message APPEND-ed and then ingested
  from Sent dedups to one row by Message-ID.
- **UIDVALIDITY change:** sync suspends for that folder and re-seeds without corrupting `imf_`.

## 12. Delivery Order (not a timeline)

Inventoried up front; shipped in risk order, each step independently shippable.
1. **Flags two-way** (read/star) — push, then CONDSTORE pull, then both + loop guard. The proven core.
2. **Membership data layer** — `iif_` folder entity, `imf_` membership join + shadow, capability
   detection (`iia_supports_labels/condstore/qresync`), folder discovery/selection UI.
3. **Membership sync, label provider (Gmail)** — All-Mail pull, `X-GM-LABELS` reconcile, `STORE
   ±X-GM-LABELS` push; conflict-free, highest value, simplest of the membership work.
4. **Membership sync, exclusive-folder provider (classic)** — per-folder cursors, `MOVE` push,
   vanish/appear move detection (§7.4), local-wins scalar conflict.
5. **Deletion** (`iia_sync_deletes`) — move-to-Trash push; Trash/VANISHED pull.
6. **Sent / compose interop** (§9) — Sent ingestion + APPEND-on-send + dedup, landing with
   `outbound_reply_forward.md`.

## 13. Docs to Update at Implementation

Update `plugins/inbound_email/docs/overview.md` to describe sync as the current state (per the docs
rule — no "previously one-way" narration): a "Sync" subsection covering the per-feed mode, the
flag↔state and membership↔folder/label mappings, the shadow-based reconciliation, deletion and Sent
handling, the pull/ingest/push cycle, and the conflict rules. Note that no OAuth re-consent is
required.
