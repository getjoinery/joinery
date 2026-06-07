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

This is a **platform-level** capability of the IMAP provider, expressed in standard IMAP flags
and folders — not a Gmail-specific feature. Gmail is one instance, reconciled by the same model
as every other host (§6.1).

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
  access likewise. Sync is purely additive on the existing connection. (The `APPEND`-to-Sent of
  §9 is an IMAP write, covered by the IMAP scope; it needs no SMTP send scope.)
- **No applicability to non-IMAP inbound.** Webhook (Mailgun/SendGrid/SES) and Postfix domains
  have no upstream mailbox to sync with; this feature is inert for them.
- **No real-time push.** Sync rides the existing poll cadence (`PollImapAccounts`); IMAP IDLE /
  push notifications are out of scope.
- **Compose UI and send transport are specced elsewhere.** This spec owns *ingesting* the Sent
  folder and *APPEND*-ing a sent copy back to the source; the reader compose UI is
  `outbound_reply_forward.md` and the send identity/transport is `connected_account_email.md`.
  This spec consumes `resolveOutboundTransport(mailbox)` and does not re-decide it (§9).
- **No Gmail IMAP extensions.** `X-GM-LABELS` / `X-GM-MSGID` / `X-GM-THRID` are deliberately
  **not** used. The bundled IMAP client (`bytestream/horde-imap-client` v2.34.1) does not
  implement them, and they are Gmail-only. Membership is observed and written through standard
  folder operations that work identically on every host (§6.1). Gmail is reconciled by the same
  path as Outlook, Fastmail, and generic IMAP.

## 3. State Dimensions

Every state a mailbox row can carry, and its IMAP counterpart:

| Joinery state            | IMAP counterpart                              | Conflict shape | In v1 |
|--------------------------|-----------------------------------------------|----------------|-------|
| `iem_is_read`            | `\Seen` system flag                           | scalar boolean | Synced |
| `iem_is_starred`         | `\Flagged` system flag (= Gmail star)         | scalar boolean | Synced |
| folder/label membership  | the **set of folders the message appears in** | **set** (§7.2) | Synced |
| deletion                 | move to Trash (`\Deleted` only when no Trash) | destructive    | Synced |
| sent copy (compose)      | `APPEND` to Sent                              | one-way out    | Synced (§9) |

Flags are scalar booleans. **Membership** is the dimension that does not reduce to a single
boolean: it is a set, observed as *which tracked folders contain the message* (§6.1), and it
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

The platform is pre-launch (no production feeds), so this is a **clean cutover** — there is no
dual-path "legacy single-folder fallback" to maintain. The per-folder `iif_` cursors are the sole
ingestion cursor from day one; the account-level `iia_uidvalidity` / `iia_last_seen_uid` columns
are no longer read (seeded into a single `INBOX` `iif_` row on first poll of an existing feed and
otherwise inert).

**`iia_inbound_imap_account` (feed):**
- `iia_sync_mode` `varchar(10)` default `'off'` not null — `off|push|pull|both`.
- `iia_sync_deletes` `bool` default `false` not null — gate delete propagation.
- `iia_show_compose` `bool` default `false` not null — surface reader compose + APPEND-to-Sent.
- `iia_supports_condstore` `bool` default `false` not null — cached capability (§6).
- `iia_supports_qresync` `bool` default `false` not null — cached capability (§6).
- `iia_folders_exclusive` `bool` default `true` not null — **membership cardinality**, not an
  observation switch. True (the default) for ordinary IMAP, where a message lives in exactly one
  folder; false for hosts whose model lets a message sit in several folders at once (Gmail, which
  advertises `X-GM-EXT-1`, where the IMAP "folders" are labels). It selects only the §7.2 **conflict
  resolution** (an exclusive feed needs the local-wins tiebreak when the single destination differs;
  a non-exclusive feed is conflict-free per-element). Both are observed and written the same way (§6.1).
- `iia_last_sync_time` `timestamp(6)` nullable — observability.

**`iif_inbound_imap_folder` (new — one row per (feed, folder)):** UIDVALIDITY and MODSEQ are
per-folder, so the cursor lives here.
- `iif_iia_inbound_imap_account_id` `int8` not null — owning feed (`cascade` delete).
- `iif_name` `varchar(255)` not null — IMAP mailbox name (e.g. `INBOX`, `Receipts`,
  `[Gmail]/Sent Mail`).
- `iif_role` `varchar(20)` nullable — special-use role: `inbox|sent|trash|drafts|junk|archive|all|custom`
  (from `SPECIAL-USE` / provider name mapping, §6).
- `iif_navigable` `bool` default `true` not null — reader visibility, an axis **independent of**
  `iif_role`: whether this folder appears in reader navigation. A full mail-client folder-visibility
  preference (e.g. hide Junk, collapse a noisy folder) drives this per-folder without a schema change
  — do not "optimize" it into a function of `iif_role`.
- `iif_is_membership` `bool` default `true` not null — whether *presence in this folder* counts as a
  membership element (an `imf_` bit, §7.2). A folder with `iif_role = all` (Gmail's `[Gmail]/All Mail`,
  or any host's `\All` view) is seeded `false`: it is a **coverage source**, not a membership folder
  (§6.1). Independent of `iif_is_tracked` — a coverage folder is still polled.
- `iif_uidvalidity` `int8` nullable, `iif_last_seen_uid` `int8` nullable — ingestion cursor.
- `iif_last_sync_modseq` `int8` nullable — highest CONDSTORE MODSEQ reconciled on pull.
- `iif_is_tracked` `bool` default `true` not null — whether sync polls this folder (for ingestion,
  flag pull, and — when `iif_is_membership` — membership reconciliation).

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

The message is identified across folders by its **`iem_message_id_header`** — the same identity the
system already relies on (the `(iem_message_id_header, iem_recipient)` dedup key, and the
`resolveUid` Message-ID fallback). The same RFC822 message keeps its Message-ID in every folder it
is copied/labelled into, so presence across folders correlates on it. No Gmail-specific stable id
(`X-GM-MSGID`) is stored.

Existing `iem_imap_uid` / `iem_imap_uidvalidity` / `iem_imap_folder` /
`iem_iia_inbound_imap_account_id` remain the *current-locator* for body/attachment fetch — they point
at **one** folder/UID where the message bytes can be re-fetched. When that folder loses the message
(a `VANISHED`, §7.4), the locator is re-pointed to another tracked folder that still holds it (by
Message-ID), so the body stays fetchable even though the message moved. Only reference-backed rows
(non-null `iem_iia_inbound_imap_account_id`) are ever synced.

**Local mutation stamping (wiring, not schema):** `MailboxService::markRead()` / `setStarred()` must
set `iem_local_state_modified = now()` on the affected reference-backed rows (the flag-dirty signal).
`softDelete()` must additionally translate the delete into membership dirtiness (§7.5) so the push
step can act on it; soft-delete and membership are two representations of one fact and are bridged here.

## 6. Provider Capability & Mechanics

Behavior is data-driven from a capability surface on the provider/preset layer, not hard-coded per
host. Detected from the server `CAPABILITY` response and cached on the feed:

- **CONDSTORE / QRESYNC** (RFC 7162) → `iia_supports_condstore` / `iia_supports_qresync`. CONDSTORE
  enables incremental flag/membership pull (`CHANGEDSINCE iif_last_sync_modseq`); QRESYNC adds
  `VANISHED` for detecting messages that left a folder (move or delete). **Pull and Both require
  CONDSTORE.** (Horde exposes both: `Fetch_Query::modseq()`, the `changedsince`/`unchangedsince`
  store options, and `sync()`/fetch `VANISHED`.)
- **Cardinality** (`X-GM-EXT-1`) → `iia_folders_exclusive`. A host advertising `X-GM-EXT-1` (Gmail)
  is **non-exclusive** (`iia_folders_exclusive = false`): a message can be in several folders at
  once. Everything else is exclusive. This flag drives only conflict resolution (§7.2), not how
  membership is observed.
- **Special-use folders** — `LIST (SPECIAL-USE)` (RFC 6154, supported by Horde) and provider name
  maps populate `iif_role` (`\Sent`, `\Trash`, `\Drafts`, `\Junk`, `\Archive`, `\All`;
  `[Gmail]/Sent Mail`, `[Gmail]/Trash`, `[Gmail]/All Mail` for Gmail). Trash is the delete target;
  Sent is the APPEND target (§9).

### 6.1 One observation model: per-folder presence

**Membership is observed uniformly as which tracked folders contain a message**, on every host.
There is no second "label" observation path. This is the design consequence of not using Gmail
extensions, and it is *more* general, not a compromise:

- **Adding** a message to a folder = `COPY` it there. On Gmail this adds the label; on classic
  hosts it places the message in the folder.
- **Removing** a message from a folder = `MOVE` it out, or `STORE +FLAGS (\Deleted)` + `EXPUNGE`
  scoped to that folder's UID. On Gmail this removes the label.
- **Deleting** = `MOVE` to the Trash folder. On Gmail, the message landing in `[Gmail]/Trash` is
  what removes it from every other folder/label.

Gmail maps these standard folder operations onto label operations faithfully, so the same code
reconciles a labelled Gmail account and a foldered Outlook account. The only difference between them
is cardinality (§7.2 conflict handling), not mechanism.

**The `\All` folder (Gmail `[Gmail]/All Mail`) is a coverage source, not a membership folder.**
Because every non-trashed Gmail message is always in All Mail, an All-Mail presence *bit* is true for
everything and carries no information — so it is never a membership element (`iif_is_membership =
false`, §5). But All Mail is the only folder guaranteed to contain *every* message, so it **is**
tracked (`iif_is_tracked = true`) for two jobs: (1) **coverage ingestion** — a message that lives in
no membership folder (archived with no label, or filed straight to All Mail by a "skip inbox, no
label" filter) still gets an `iem_` row, so it is stored, searchable, and visible; and (2) **flag
pull** — read/star changes seen on All Mail reconcile per §7.1. Membership itself is observed across
the membership folders (`INBOX`, the user's label folders, `Sent`, `Trash`); All Mail never creates,
reconciles, or is written with an `imf_` row. The reader's **"All Mail" view is the folder-unfiltered
alias view** — every `iem_` row for the mailbox regardless of membership — so coverage-only messages
(zero `imf_` rows) appear there and nowhere else in folder navigation. This rule is generic by role:
any host exposing a `\All` view is treated the same; classic IMAP has none, so it is inert there.

**Operations in a new `ImapSyncer` (sibling of `ImapIngestor`, sharing the open connection):**
- Push flags: `STORE +FLAGS/-FLAGS (\Seen \Flagged)` over a UID set, batched (windowed-UID pattern,
  per-run cap).
- Push membership add: `COPY` into the destination folder (re-point the locator if needed).
- Push membership remove: `MOVE` out / `STORE (\Deleted)` + `EXPUNGE` scoped to the folder.
- Push delete (when `iia_sync_deletes`): `MOVE` to the Trash folder; `\Deleted` + `EXPUNGE` only on a
  generic feed with no resolvable Trash and explicit configuration.
- Pull flags: `FETCH (FLAGS) CHANGEDSINCE iif_last_sync_modseq` per tracked folder.
- Pull presence: new arrivals per tracked folder (ingest), plus QRESYNC `VANISHED`
  (`UID FETCH … (CHANGEDSINCE <modseq> VANISHED)`) for messages that left a folder.

### 6.2 Connection lifecycle & test seam (refactor)

Two refactors of the existing ingestor are prerequisites and land with the data layer (§12 step 2):

- **Shared connection.** `ImapIngestor::poll()` currently opens *and closes* the Horde client
  internally (multiple `close()` returns). For the `Pull → Ingest → Push` cycle to run on one
  connection, the connection lifecycle moves up to the caller (the poller): open once, run pull +
  ingest + push, close once. `poll()` stops self-closing.
- **Client seam for testing.** The ingestor type-hints the concrete `Horde_Imap_Client_Socket`. §11
  mandates a mock IMAP client, so the client is reached through a narrow internal interface (the
  handful of methods used: `status`, `fetch`, `search`, `store`, `copy`, `expunge`, `append`,
  `sync`) that a fake implements. Horde stays wrapped in `ImapIngestor`/`ImapSyncer` and nowhere else.

## 7. Sync Cycle, Reconciliation, Conflicts & Loop Avoidance

`ImapSyncer` runs inside `PollImapAccounts`, on the already-open connection, once per feed (skipped
when `iia_sync_mode = off`). Order per feed: **Pull → Ingest → Push.**

### 7.1 Flags — scalar three-way merge

A flag row is **dirty** iff `iem_local_state_modified > iem_synced_state_time` (or the latter is
null). Pull applies remote flag changes to **clean** rows and **skips dirty** ones (local-wins);
Push `STORE`s each dirty row whose local value still differs, then sets `iem_synced_state_time = now`.
Loop avoidance is by value comparison: a pushed flag bumps MODSEQ and is re-read next cycle, but the
row is clean and value-equal, so pull no-ops. The modseq cursor is advanced to the **pre-pull**
`HIGHESTMODSEQ`. (This is net-new work — there is no flag sync today — but it is the simplest
dimension and the proving ground for the cursor/dirty/loop machinery membership reuses.)

### 7.2 Membership — the shadow makes it a conflict-free set merge

Membership cannot be reconciled as a scalar: a message in `{INBOX, Work}` now showing `{Work}`
locally and `{INBOX, Work}` remotely is ambiguous — *local removed INBOX* or *remote added INBOX*?
— unless you know the set as of last agreement. That is the **shadow** (`imf_present_base`).

Decompose per folder into one boolean three-way merge (base `b` / local `l` / remote `r`), where
"remote present" = the message currently appears in that folder on the server:

| b | l | r | meaning | action |
|---|---|---|---------|--------|
| 0 | 1 | 0 | local added | **push add** (`COPY`) |
| 0 | 0 | 1 | remote added | **apply add** (set local & base) |
| 1 | 0 | 1 | local removed | **push remove** (`MOVE`/`EXPUNGE`) |
| 1 | 1 | 0 | remote removed | **apply remove** (clear local & base → drop row) |
| 0 | 1 | 1 / 1 | 0 | 0 | both moved same way | agree → advance base |
| 1 | 1 | 1 / 0 | 0 | 0 | unchanged | no-op |

**Per-element, membership is a boolean, and a boolean three-way merge cannot conflict** — a genuine
conflict needs one item driven to two *different* targets, and a boolean has only one other state, so
if both sides change it they necessarily agree. On a **non-exclusive feed** (`iia_folders_exclusive =
false`, e.g. Gmail) where every folder is an independent membership bit, **membership sync is
conflict-free.** The dirty carve-out (skip a dirty element in pull, push it in step 3) is retained
purely as the mechanical guard against pull clobbering an unpushed local edit — not as a tiebreak.

**The one genuine conflict lives on an *exclusive* feed** (`iia_folders_exclusive = true`), where
"which one folder" is a multi-valued scalar: base `INBOX`, local → `Archive`, remote → `Receipts`
(three distinct values). There the §7.1 **local-wins** rule is the resolution — keep the local
destination, push it. So the non-exclusive host is the easy one; the exclusive host is where the
tiebreak is needed, and it reuses the rule already established for flags.

### 7.3 Cycle

1. **Pull (mode ∈ `pull|both`).** Capture each tracked folder's current `HIGHESTMODSEQ` first. For
   each tracked folder:
   - `FETCH (FLAGS) CHANGEDSINCE iif_last_sync_modseq` → reconcile flags per §7.1.
   - QRESYNC `VANISHED` → UIDs that left this folder (the message's `iem_message_id_header` is known
     from the local row).
   - New arrivals in the folder are staged keyed by `iem_message_id_header`.

   A **coverage-only** folder (`iif_is_membership = false`, the `\All` view) runs the flag pull and
   stages its arrivals for ingestion, but contributes **no** presence add/remove.

   Then reconcile **presence** per §7.2 across all **membership** folders: a staged arrival in folder
   G is a remote-add of G; a `VANISHED` from folder F is a remote-remove of F. For a **non-exclusive
   feed**, those are independent boolean edits — a message that left F and appeared in G simply has
   membership F cleared and G set, no correlation needed. For an **exclusive feed**, correlate
   vanish-in-F with arrive-in-G to classify a move (and apply the local-wins scalar conflict, §7.4).
   Clean elements/flags apply remote; dirty ones are skipped (pushed in step 3). Re-point the `iem_`
   locator if the folder it referenced is the one that vanished (§7.4) — including a vanish from the
   coverage folder. Advance each folder's `iif_last_sync_modseq` to the **pre-pull** `HIGHESTMODSEQ`.
2. **Ingest** new mail (`ImapIngestor::poll`, extended so that ingesting a message — whether it is a
   brand-new row or a dedup hit on a message already stored from another folder — **adds an `imf_`
   row** with `local = base =` observed for the **membership** folder it arrived in). The dedup path,
   which is a no-op today, is where a second-folder membership is attached. An arrival from a
   **coverage-only** folder (the `\All` view) creates or retains the `iem_` row but adds **no** `imf_`
   row — coverage guarantees the message is stored and searchable without giving it a meaningless
   All-Mail membership.
3. **Push (mode ∈ `push|both`).** For each **dirty** flag row and each **dirty** `imf_` element whose
   local value still differs from remote, issue the §6.1 op (`STORE`, `COPY`, `MOVE`/`EXPUNGE`,
   move-to-Trash). On confirmed write set the shadow to match local (`imf_present_base =
   imf_present_local`; flags → `iem_synced_state_time = now`), clearing dirty. Drop `imf_` rows now
   `(0,0)`.

**Loop avoidance** is the shadow doing the same job at element granularity: a pushed `COPY`/`MOVE`
bumps MODSEQ and is re-read next cycle, but `imf_present_base` already equals remote, so §7.2 yields a
value-equal no-op. No per-element MODSEQ column.

### 7.4 Vanish handling & locator maintenance

A QRESYNC `VANISHED` UID in folder F means the message left F. Under the per-folder presence model
this is, first and simply, **a remote-remove of membership F** (§7.2) — no move *classification* is
required to record the membership change. Two concerns remain:

- **Locator re-point (all feeds).** If the `iem_` locator (`iem_imap_folder`/`iem_imap_uid`/
  `iem_imap_uidvalidity`) pointed at F, the body/attachment bytes must stay fetchable. Look up the
  message's `iem_message_id_header` and re-point the locator to any other tracked folder that still
  holds it (the staged arrivals + existing memberships answer this; the `resolveUid` Message-ID
  fallback covers any cursor gap). If no tracked folder holds it, the message has left the reader's
  view entirely.
- **Move vs delete (exclusive feeds only).** On an exclusive feed a vanish is the message leaving its
  single folder. To decide whether that is a *move* (→ set the single membership to the destination
  folder G found among staged arrivals) or a *delete* (found in Trash, or found nowhere tracked),
  correlate by Message-ID, as above. A delete soft-deletes the local row when `iia_sync_deletes`.
  On a non-exclusive feed (Gmail) this disambiguation is unnecessary: a Trash arrival is just the
  Trash membership appearing, and archive is the INBOX membership disappearing while other folders
  persist — the membership set says everything.

### 7.5 Deletion

`iia_sync_deletes` gates both directions, and the local soft-delete is **bridged into membership** so
the one push path handles it:

- **Push.** A local soft-delete (`iem_delete_time` set in the reader) is translated by
  `MailboxService::softDelete()` into membership dirtiness: mark every non-Trash `imf_` element
  `present_local = false` and (when a Trash folder is resolvable) a Trash element `present_local =
  true`. The push step then `MOVE`s the source message to Trash (Gmail: the Trash membership add,
  which Gmail treats as removal from all other labels). With `iia_sync_deletes` off, the soft-delete
  leaves `imf_` untouched and never reaches the source.
- **Pull.** A Trash arrival (or, on an exclusive feed, a `VANISHED`-classified-as-delete per §7.4)
  soft-deletes the local row. On Gmail, **archive** (the message loses its `INBOX` membership but
  remains in other folders / All Mail) is cleanly distinct from delete (gains Trash membership) —
  the membership model removes the old archive/delete ambiguity.

### 7.6 UIDVALIDITY change

If a folder's `UIDVALIDITY` differs from `iif_uidvalidity`, all UID→row mappings for that folder are
stale: clear `iif_last_sync_modseq`, do not push into it (UIDs are meaningless), and let ingestion
re-seed (correlating by `iem_message_id_header`) before sync resumes for that folder.

## 8. UI

- **Accounts → combined IMAP mailbox editor.** A "Sync" dropdown (`Off` / `Pull (read-only)` /
  `Push` / `Two-way`) via FormWriter `dropinput` with `visibility_rules` (no hand-rolled JS). On a
  non-CONDSTORE feed (`iia_supports_condstore` false) omit `Pull`/`Two-way` with a short note. When
  sync ≠ Off, reveal **"Also sync deletions"** (`iia_sync_deletes`) — warns deletions move the source
  message to Trash — and **"Enable compose / Sent sync"** (`iia_show_compose`, §9). Guided controls
  only, no explainer prose (per the self-documenting-pages rule).
- **Folder selection.** From `LIST`, the operator picks which folders are tracked (`iif_is_tracked`);
  special-use folders pre-selected. The `\All` view (Gmail `[Gmail]/All Mail`) is tracked as a
  coverage source, not a togglable membership folder (`iif_is_membership = false`, §6.1).
- **Reader folder navigation.** The reader gains a folder dimension alongside the alias scope, driven
  by `imf_` membership; INBOX-only feeds simply show one folder (the Product-A subset, §1.1). A
  message in several folders appears under each without being double-counted in thread aggregation
  (the `MailboxService` thread queries join `imf_` and de-duplicate). An **"All Mail" entry** shows
  the folder-unfiltered alias view, so coverage-only messages (no `imf_` membership) are reachable
  there.
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
  transport does not** (`smtp_files_sent = false`, e.g. self-hosted Postfix+Dovecot, or a generic
  IMAP feed whose outbound goes through the platform relay). APPEND-ing when the provider already
  filed would duplicate. Transport/identity come from `resolveOutboundTransport(mailbox)`
  (`connected_account_email.md`), which exposes `filesSent`; this spec only performs the conditional
  APPEND (with `\Seen`).
- **Dedup / reconciliation.** A Joinery-composed message is stored as a local outbound row immediately
  (for instant reader display) *and* later observed in Sent — whether the provider filed it or Joinery
  APPEND-ed it. Ingestion **reconciles to the existing outbound row** instead of creating a duplicate,
  then backfills the `iem_` IMAP locator and adds an `imf_` Sent membership so the outbound row becomes
  reference-backed and appears under Sent. Matching:
  - **APPEND path** (`smtp_files_sent = false`): Joinery sets the `Message-ID` and APPENDs the exact
    MIME, so the Sent copy matches the stored `iem_message_id_header` reliably.
  - **Provider-filed path** (`smtp_files_sent = true`): most providers preserve the `Message-ID` and
    match the same way. **Gmail rewrites the `Message-ID` on send**, so the filed copy will not match
    by header. For Gmail-composed mail, match the Sent copy heuristically by
    `(normalized subject, recipient set, send-time window)`; if no confident match is found, the Sent
    copy ingests as its own row rather than risk a wrong merge. This Gmail-only edge is the single
    cost of not storing `X-GM-MSGID`, it affects only the cosmetic de-duplication of *Joinery-composed*
    Sent mail, and it lands with §12 step 6 (lowest risk/value).

## 10. Failure Handling

- Reuse `iia_needs_reauth` / `markNeedsReauth()` — a sync write failing on auth flags the feed exactly
  as ingestion does; the Accounts "Reconnect" affordance covers it.
- Partial progress is safe: pushes are idempotent (`COPY`/`STORE` of an already-applied state is a
  no-op; a re-`MOVE` of an already-moved message finds nothing); the shadow and `iem_synced_state_time`
  only advance on confirmed writes, per element, so an interrupted run retries the remainder next cycle.
- Per-run caps (mirroring `maxPerRun`) bound work and rate exposure across the now-multiple tracked
  folders; `iia_last_status` records counts (pushed/pulled/moved/deleted/conflicts).

## 11. Testing

Extend `plugins/inbound_email/tests/imap_poller_test.php` (or a new `imap_syncer_test.php`) with a
mock IMAP client (the §6.2 seam) covering the unified model:
- **Flags** push/pull/conflict (local-wins) and loop avoidance.
- **Membership, non-exclusive feed:** remote presence add/remove since MODSEQ → `imf_` reconciled;
  local membership add/remove → `COPY` / `MOVE`; both-add-same-folder → agree, no double op;
  conflict-free claim — concurrent disjoint membership edits both land (no clobber).
- **Membership, exclusive feed:** local move → `MOVE` + locator re-point; remote move detected by
  vanish-in-F/appear-in-G correlation (§7.4); move-vs-delete disambiguation via Trash; genuine scalar
  conflict (local→A, remote→B) → local-wins.
- **Ingest adds membership:** a dedup hit (same Message-ID, different folder) adds an `imf_` row
  rather than no-op'ing.
- **Coverage source:** a message present only in the `\All` view (archived, no label) ingests, is
  visible in the All-Mail (folder-unfiltered) view, and has **zero** `imf_` membership; All-Mail flag
  changes still reconcile, and All-Mail arrivals/`VANISHED` create no membership.
- **Deletion:** `iia_sync_deletes` on → soft-delete bridges to membership → move-to-Trash (push); a
  Trash arrival / VANISHED → soft-delete (pull); off → local soft-delete does not touch the source and
  remote deletions are not pulled; Gmail archive (lose INBOX membership, keep others) is *not* a delete.
- **Sent:** native-client Sent message ingests; Joinery-composed message APPEND-ed and then ingested
  from Sent dedups to one row by Message-ID; a Gmail-composed message matches by the §9 heuristic.
- **UIDVALIDITY change:** sync suspends for that folder and re-seeds without corrupting `imf_`.

## 12. Delivery Order (not a timeline)

Inventoried up front; shipped in risk order, each step independently shippable.
1. **Flags two-way** (read/star) — push, then CONDSTORE pull, then both + loop guard. Includes the
   §6.2 connection-lifecycle refactor, the client seam, and `MailboxService` flag-dirty stamping. The
   proving ground for the cursor/dirty/loop machinery.
2. **Membership data layer** — `iif_` folder entity (incl. `iif_is_membership`), `imf_` membership
   join + shadow, capability detection (`iia_folders_exclusive/condstore/qresync`), special-use +
   folder discovery/selection UI (seeding the `\All` view as a coverage source), ingest extended to
   seed/add `imf_` rows on membership folders (including the dedup path) and to store coverage-only
   arrivals without membership.
3. **Membership sync (unified presence)** — per-folder cursors, `COPY`/`MOVE`/`EXPUNGE` push,
   per-folder pull + `VANISHED`, the §7.2 shadow merge, and the coverage-folder carve-out (flag pull +
   ingest, no presence reconciliation). Covers Gmail and classic hosts together, since both use the
   same folder-presence mechanism.
4. **Exclusive-feed conflict + locator detail** — vanish/appear move correlation (§7.4), local-wins
   scalar conflict, locator re-point.
5. **Deletion** (`iia_sync_deletes`) — soft-delete→membership bridge, move-to-Trash push;
   Trash/VANISHED pull.
6. **Sent / compose interop** (§9) — Sent ingestion + APPEND-on-send + dedup (Message-ID, with the
   Gmail heuristic), landing with `outbound_reply_forward.md`.

## 13. Docs to Update at Implementation

Update `plugins/inbound_email/docs/overview.md` to describe sync as the current state (per the docs
rule — no "previously one-way" narration): a "Sync" subsection covering the per-feed mode, the
flag↔state and membership↔folder mappings, the per-folder-presence observation model, the `\All`
coverage view (All-Mail ingestion without membership), the shadow-based reconciliation, deletion and
Sent handling, the pull/ingest/push cycle, and the conflict rules. Note that no OAuth re-consent is
required and that Gmail is reconciled by the same folder model as every other host.
