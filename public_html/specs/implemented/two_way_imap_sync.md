# Spec: Two-Way IMAP Sync

**Status:** Implemented — kept live and refined as built (see §14 As-built refinements)
**Scope:** `inbound_email` plugin — IMAP-source feeds only
**Related docs:** `plugins/inbound_email/docs/overview.md` (Setup, IMAP feeds, reference-backed storage)
**Related specs:** `outbound_reply_forward.md`, `connected_account_email.md` (Sent / APPEND-on-send)

---

## 1. Problem & Goal

The IMAP integration is **one-way (read-only pull of new mail)** today. `ImapIngestor` only calls
`login`, `status`, `search`, `fetch` (all with `BODY.PEEK`), and `logout`; it never issues
`STORE`, `EXPUNGE`, `APPEND`, `COPY`, or `MOVE`, and it never re-reads the state of mail it already
ingested. Message state — read/unread, starred, deleted, and which folder/label a message carries —
lives entirely in Joinery's `iem_` rows and never propagates to the source mailbox, nor does a change
made in the source mailbox return to Joinery.

**Goal:** keep the source mailbox and the Joinery reader in agreement across every state a mailbox
row can carry — read/star flags, **folder/label membership**, and deletion — for feeds that opt in,
in either a **read-only** (source → Joinery) or **two-way** (bidirectional) mode, without changing
behavior for feeds that don't opt in.

This is a **platform-level** capability of the IMAP provider, expressed in standard IMAP flags and
folders — not a Gmail-specific feature. Gmail is one instance, reconciled by the same model as every
other host (§6.1).

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
- **No write-without-read mode.** Sync is either off, read-only (pull), or two-way. A "push my
  changes but never show me theirs" half-sync drifts confusingly and serves only the rare
  non-QRESYNC server, so it is not offered (§4).
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

## 4. Sync Modes

Sync is configured **per feed** (`InboundImapAccount`) and is **off by default**, so existing feeds
are unaffected. Setup offers the operator a single choice — **Read-only** or **Two-way** — with Off
as the unconfigured default:

| Mode | `iia_sync_mode` | Direction | Writes to source? | What it gives |
|---|---|---|---|---|
| **Off** | `off` | — | No | Default. One-time ingest; the source is never touched again and local state stays local. |
| **Read-only** | `pull` | source → Joinery | No | Joinery *follows* the source: read/star/move/delete in the native client → reflected in Joinery. Joinery never writes back. |
| **Two-way** | `both` | bidirectional | Yes | Full reconciliation (§7): act in either place → reflected in the other. |

**Read-only and Two-way require CONDSTORE** (§6) — incremental flag/membership pull via
`CHANGEDSINCE`. Detecting messages that *left* a folder uses QRESYNC `VANISHED` when the server has
it, else a UID-set diff fallback (§7.4), so CONDSTORE alone is sufficient. A server without CONDSTORE
can only be **Off**; the setup dropdown omits the sync options with a short note. (Originally specced
as "QRESYNC required"; corrected during implementation — Gmail advertises CONDSTORE but not QRESYNC,
so a QRESYNC gate would have excluded Gmail entirely. See §14.)

`iia_sync_deletes` (bool, default `false`) gates the deletion dimension independently of mode: when
off, local soft-deletes never touch the source and remote deletions are not pulled. `iia_show_compose`
(bool, default `false`) surfaces the reader's reply/forward/compose affordances (the compose feature
itself is `outbound_reply_forward.md`); when on and the feed is IMAP-backed, sent copies reach the
source's Sent folder per §9. Flags and membership sync together — there is no per-dimension on/off
beyond the deletes gate.

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
- `iia_sync_mode` `varchar(10)` default `'off'` not null — `off|pull|both`.
- `iia_sync_deletes` `bool` default `false` not null — gate delete propagation.
- `iia_show_compose` `bool` default `false` not null — surface reader compose + Sent handling (§9).
- `iia_supports_condstore` `bool` default `false` not null — cached capability (§6); the **sync gate**
  (incremental flag/membership pull via `CHANGEDSINCE`). Required for any sync.
- `iia_supports_qresync` `bool` default `false` not null — cached capability (§6); the **fast
  removal-detection path** (`VANISHED`). QRESYNC implies CONDSTORE; when absent (e.g. Gmail) removals
  are found by a UID-set diff (§7.4). (Originally the single gate; split into these two during
  implementation — see §14.)
- `iia_folders_exclusive` `bool` default `true` not null — **membership cardinality**. True (the
  default) for ordinary IMAP, where a message lives in exactly one folder; false for hosts whose
  model lets a message sit in several folders at once (Gmail, which advertises `X-GM-EXT-1`, where
  the IMAP "folders" are labels). It selects only the **push strategy** — add via `MOVE` on an
  exclusive feed, `COPY` on a non-exclusive one (§6.1) — not a conflict subsystem (§7.2).

**`iif_inbound_imap_folder` (new — one row per (feed, folder)):** UIDVALIDITY and MODSEQ are
per-folder, so the cursor lives here.
- `iif_iia_inbound_imap_account_id` `int8` not null — owning feed (`cascade` delete).
- `iif_name` `varchar(255)` not null — IMAP mailbox name (e.g. `INBOX`, `Receipts`,
  `[Gmail]/Sent Mail`).
- `iif_role` `varchar(20)` nullable — special-use role: `inbox|sent|trash|drafts|junk|archive|all|custom`
  (from `SPECIAL-USE` / provider name mapping, §6). The behaviorally significant roles are `sent`
  (APPEND target, §9), `trash` (delete target, §7.5), and **`all`** (a coverage source, not a
  membership folder, §6.1 — derived from the role, no separate flag). The rest are descriptive
  metadata for pre-selection and labeling.
- `iif_uidvalidity` `int8` nullable, `iif_last_seen_uid` `int8` nullable — ingestion cursor.
- `iif_last_sync_modseq` `int8` nullable — highest MODSEQ reconciled on pull.
- `iif_is_tracked` `bool` default `true` not null — whether sync polls this folder (ingestion, flag
  pull, and — for non-`all` folders — membership reconciliation).

**`imf_inbound_message_folder` (new — membership join, the heart of §7):** one row per
(message, folder) the message belongs to. Many-to-many: a Gmail message in INBOX + Receipts + Work
has three rows. Carries the **shadow**.
- `imf_iem_inbound_email_message_id` `int8` not null (`cascade` delete).
- `imf_iif_inbound_imap_folder_id` `int8` not null (`cascade` delete).
- `imf_present_local` `bool` default `true` not null — is the message in this folder per Joinery now.
- `imf_present_base` `bool` default `true` not null — **the shadow:** was it in this folder at the
  last successful sync. A membership element is **dirty** iff `imf_present_local ≠ imf_present_base`.
- `imf_imap_uid` `int8` nullable, `imf_imap_uidvalidity` `int8` nullable — the message's UID in
  **this** folder, recorded at ingest. QRESYNC `VANISHED` (and the CONDSTORE-only UID-diff) return
  only UIDs for messages that no longer exist and so can't be re-fetched; this column is how a
  vanished UID is correlated back to its membership row to clear it incrementally (§7.4). (Added
  during implementation — see §14.)
- Unique on (`imf_iem_...`, `imf_iif_...`). A row with both bits false is deleted, not kept.

**`iem_inbound_email_message` (message):**
- `iem_local_state_modified` `timestamp(6)` nullable — set by `MailboxService` whenever a
  reference-backed row's **flags** change locally; the flag-dirtiness signal compared against
  `iem_synced_state_time` in §7.1. Membership dirtiness is **not** tracked here — it lives entirely in
  the per-element shadow (`imf_present_local ≠ imf_present_base`), which the push step queries directly.
- `iem_synced_state_time` `timestamp(6)` nullable — when push last reconciled this row's **flags**.

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

- **CONDSTORE** (RFC 7162) → `iia_supports_condstore`. Incremental flag/membership pull via
  `CHANGEDSINCE iif_last_sync_modseq`. This is the **sync gate**: any sync requires it; without it the
  feed can only be Off.
- **QRESYNC** (RFC 7162) → `iia_supports_qresync`. Adds `VANISHED` for detecting messages that left a
  folder. The **fast** removal path; when absent (Gmail has CONDSTORE but not QRESYNC) removals are
  found by a per-folder UID-set diff instead (§7.4). QRESYNC implies CONDSTORE, so detection sets
  `condstore = qresync || CONDSTORE`. (Horde exposes the pieces: `Fetch_Query::modseq()`, the
  `changedsince` store option, fetch `VANISHED`, and a plain UID fetch for the diff.)
- **Cardinality** (`X-GM-EXT-1`) → `iia_folders_exclusive`. A host advertising `X-GM-EXT-1` (Gmail)
  is **non-exclusive** (`false`): a message can be in several folders at once. Everything else is
  exclusive. This flag drives only the push strategy (§6.1), not how membership is observed.
- **Special-use folders** — `LIST (SPECIAL-USE)` (RFC 6154, supported by Horde) and provider name
  maps populate `iif_role` (`\Sent`, `\Trash`, `\Drafts`, `\Junk`, `\Archive`, `\All`;
  `[Gmail]/Sent Mail`, `[Gmail]/Trash`, `[Gmail]/All Mail` for Gmail).

### 6.1 One observation model: per-folder presence

**Membership is observed uniformly as which tracked folders contain a message**, on every host.
There is no second "label" observation path. This is the design consequence of not using Gmail
extensions, and it is *more* general, not a compromise:

- **Add** a message to a folder = `COPY` it there (non-exclusive feed) or `MOVE` it there (exclusive
  feed — the message can only be in one folder, so adding a destination *is* the move). On Gmail
  (non-exclusive) the `COPY` adds the label; on a classic host the `MOVE` relocates it.
- **Remove** a message from a folder = on a non-exclusive feed, `STORE +FLAGS (\Deleted)` + `EXPUNGE`
  scoped to that folder's UID (Gmail: removes the label); on an exclusive feed a removal is part of a
  move (handled by the add-as-`MOVE` above) or, if nothing remains, a delete (§7.5).
- **Delete** = `MOVE` to the Trash folder. On Gmail, the message landing in `[Gmail]/Trash` is what
  removes it from every other folder/label.

Gmail maps these standard folder operations onto label operations faithfully, so the same code
reconciles a labelled Gmail account and a foldered Outlook account. The only difference between them
is cardinality (the push strategy above), not mechanism.

**The `\All` folder (Gmail `[Gmail]/All Mail`) is a coverage source, not a membership folder.**
Because every non-trashed Gmail message is always in All Mail, an All-Mail presence *bit* is true for
everything and carries no information — so a `\All`-role folder is never a membership element. But
All Mail is the only folder guaranteed to contain *every* message, so it **is** tracked
(`iif_is_tracked = true`) for two jobs: (1) **coverage ingestion** — a message that lives in no
membership folder (archived with no label, or filed straight to All Mail by a "skip inbox, no label"
filter) still gets an `iem_` row, so it is stored, searchable, and visible; and (2) **flag pull** —
read/star changes seen on All Mail reconcile per §7.1. Membership itself is observed across the
membership folders (`INBOX`, the user's label folders, `Sent`, `Trash`); a `\All` folder never
creates, reconciles, or is written with an `imf_` row. The reader's **"All Mail" view is the
folder-unfiltered alias view** — every `iem_` row for the mailbox regardless of membership — so
coverage-only messages (zero `imf_` rows) appear there and nowhere else in folder navigation. This
rule is generic by role: any host exposing a `\All` view is treated the same; classic IMAP has none,
so it is inert there.

**Operations in a new `ImapSyncer` (sibling of `ImapIngestor`, sharing the open connection):**
- Push flags: `STORE +FLAGS/-FLAGS (\Seen \Flagged)` over a UID set, batched (windowed-UID pattern,
  per-run cap).
- Push membership: `COPY` (non-exclusive add) / `MOVE` (exclusive add or move) / `STORE (\Deleted)` +
  `EXPUNGE` (non-exclusive remove); re-point the locator if needed.
- Push delete (when `iia_sync_deletes`): `MOVE` to the Trash folder; `\Deleted` + `EXPUNGE` only on a
  generic feed with no resolvable Trash and explicit configuration.
- Pull flags: `FETCH (FLAGS) CHANGEDSINCE iif_last_sync_modseq` per tracked folder.
- Pull presence: new arrivals per tracked folder (ingest), plus `VANISHED`
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
| 0 | 1 | 0 | local added | **push add** (`COPY`/`MOVE`) |
| 0 | 0 | 1 | remote added | **apply add** (set local & base) |
| 1 | 0 | 1 | local removed | **push remove** (`MOVE`/`EXPUNGE`) |
| 1 | 1 | 0 | remote removed | **apply remove** (clear local & base → drop row) |
| 0 | 1 | 1 / 1 | 0 | 0 | both moved same way | agree → advance base |
| 1 | 1 | 1 / 0 | 0 | 0 | unchanged | no-op |

**Per-element, membership is a boolean, and a boolean three-way merge cannot conflict** — a genuine
conflict needs one item driven to two *different* targets, and a boolean has only one other state, so
if both sides change it they necessarily agree. On a **non-exclusive feed** every folder is an
independent membership bit, so membership sync is conflict-free outright. The dirty carve-out (skip a
dirty element in pull, push it in step 3) guards against pull clobbering an unpushed local edit.

**No explicit conflict rule is needed even on an exclusive feed.** The one apparent conflict there is
a divergent move (base `INBOX`, local → `Archive`, remote → `Receipts`). It resolves *emergently*,
with no tiebreak code: pull applies the clean remote-add (`Receipts`) and **skips** the dirty local
elements; push then issues the local edit as a `MOVE` (the exclusive add strategy), relocating the
message to `Archive`; the next cycle observes `Receipts` vanished, the shadow reconciles it away, and
the set **converges to the local destination (`Archive`) within two cycles** — local-wins, for free,
from `MOVE`-based push + the dirty-skip carve-out + the shadow. The only visible artifact is a
one-poll-interval window where the message shows under two folders.

### 7.3 Cycle

1. **Pull (mode ∈ `pull|both`).** Capture each tracked folder's current `HIGHESTMODSEQ` first. For
   each tracked folder:
   - `FETCH (FLAGS) CHANGEDSINCE iif_last_sync_modseq` → reconcile flags per §7.1.
   - `VANISHED` → UIDs that left this folder (the message's `iem_message_id_header` is known from the
     local row).
   - New arrivals in the folder are staged keyed by `iem_message_id_header`.

   A **coverage folder** (`iif_role = all`, the `\All` view) runs the flag pull and stages its
   arrivals for ingestion, but contributes **no** presence add/remove.

   Then reconcile **presence** per §7.2 across all **membership** folders: a staged arrival in folder
   G is a remote-add of G; a `VANISHED` from folder F is a remote-remove of F. These are independent
   boolean edits — a message that left F and appeared in G is simply membership F cleared and G set;
   no move *classification* is performed (a move is remove-here + add-there, §7.4). Clean
   elements/flags apply remote; dirty ones are skipped (pushed in step 3). Re-point the `iem_` locator
   if the folder it referenced is the one that vanished (§7.4). Advance each folder's
   `iif_last_sync_modseq` to the **pre-pull** `HIGHESTMODSEQ`.
2. **Ingest** new mail (`ImapIngestor::poll`, extended so that ingesting a message — whether a
   brand-new row or a dedup hit on a message already stored from another folder — **adds an `imf_`
   row** with `local = base =` observed for the **membership** folder it arrived in). The dedup path,
   which is a no-op today, is where a second-folder membership is attached. An arrival from a
   **coverage folder** creates or retains the `iem_` row but adds **no** `imf_` row.
3. **Push (mode = `both`).** For each **dirty** flag row and each **dirty** `imf_` element whose local
   value still differs from remote, issue the §6.1 op (`STORE`, `COPY`/`MOVE`, `EXPUNGE`,
   move-to-Trash). On confirmed write set the shadow to match local (`imf_present_base =
   imf_present_local`; flags → `iem_synced_state_time = now`), clearing dirty. Drop `imf_` rows now
   `(0,0)`.

**Loop avoidance** is the shadow doing the same job at element granularity: a pushed `COPY`/`MOVE`
bumps MODSEQ and is re-read next cycle, but `imf_present_base` already equals remote, so §7.2 yields a
value-equal no-op. No per-element MODSEQ column.

### 7.4 Vanish handling & locator maintenance

A UID that left folder F is **a remote-remove of membership F** (§7.2) — there is no move
*classification* to perform; a move is just a remove of F plus an add of G, reconciled as two
independent elements. **How the departed UIDs are found depends on capability:** on a QRESYNC feed,
`VANISHED` since the modseq cursor; on a CONDSTORE-only feed (Gmail), a **UID-set diff** — fetch F's
current UIDs and treat any stored membership UID (`imf_imap_uid`) no longer present as departed. Both
feed the same removal logic. Two concerns remain:

- **Locator re-point (all feeds).** If the `iem_` locator (`iem_imap_folder`/`iem_imap_uid`/
  `iem_imap_uidvalidity`) pointed at F, the body/attachment bytes must stay fetchable. Look up the
  message's `iem_message_id_header` and re-point the locator to any other tracked folder that still
  holds it (the staged arrivals + existing memberships answer this; the `resolveUid` Message-ID
  fallback covers any cursor gap). If no tracked folder holds it, the message has left the reader's
  view entirely.
- **Delete detection (when `iia_sync_deletes`).** A message that arrives in the Trash folder — or that
  vanished from its folder and is found in no tracked folder — is a delete: soft-delete the local row
  (§7.5). On a non-exclusive feed (Gmail) this needs no special handling: a Trash arrival is just the
  Trash membership appearing, and archive is the INBOX membership disappearing while others persist.

### 7.5 Deletion

`iia_sync_deletes` gates both directions, and the local soft-delete is **bridged into membership** so
the one push path handles it:

- **Push.** A local soft-delete (`iem_delete_time` set in the reader) is translated by
  `MailboxService::softDelete()` into membership dirtiness: mark every membership `imf_` element
  `present_local = false` and (when a Trash folder is resolvable) a Trash element `present_local =
  true`. The push step then `MOVE`s the source message to Trash (Gmail: the Trash membership add,
  which Gmail treats as removal from all other labels). With `iia_sync_deletes` off, the soft-delete
  leaves `imf_` untouched and never reaches the source.
- **Pull.** A Trash arrival (§7.4) soft-deletes the local row. On Gmail, **archive** (the message
  loses its `INBOX` membership but remains in other folders / All Mail) is cleanly distinct from
  delete (gains Trash membership) — the membership model removes the old archive/delete ambiguity.

### 7.6 UIDVALIDITY change

If a folder's `UIDVALIDITY` differs from `iif_uidvalidity`, all UID→row mappings for that folder are
stale: clear `iif_last_sync_modseq`, do not push into it (UIDs are meaningless), and let ingestion
re-seed (correlating by `iem_message_id_header`) before sync resumes for that folder.

## 8. UI

- **Accounts → combined IMAP mailbox editor.** A "Sync" dropdown — **Off / Read-only / Two-way** —
  via FormWriter `dropinput` with `visibility_rules` (no hand-rolled JS). On a non-QRESYNC feed
  (`iia_supports_qresync` false) only **Off** is offered, with a short note. When sync ≠ Off, reveal
  **"Also sync deletions"** (`iia_sync_deletes`) — warns deletions move the source message to Trash —
  and **"Enable compose / Sent sync"** (`iia_show_compose`, §9). Guided controls only, no explainer
  prose (per the self-documenting-pages rule).
- **Folder selection.** From `LIST`, the operator picks which folders are tracked (`iif_is_tracked`);
  special-use folders pre-selected. The `\All` view (Gmail `[Gmail]/All Mail`) is tracked as a
  coverage source, not a togglable membership folder (§6.1).
- **Reader folder navigation.** The reader gains a folder dimension alongside the alias scope. The
  left rail lists the mailbox's tracked folders **indented under the selected mailbox**, rendered from
  the discovered `iif_` folders (so the structure is visible as soon as folders are discovered,
  independent of sync mode — they fill with mail as sync populates `imf_` membership; see §14). The
  mailbox root is the folder-unfiltered **"All Mail"** view, so coverage-only messages (no `imf_`
  membership) are reachable there; a message in several folders appears under each without being
  double-counted in thread aggregation (the `MailboxService` thread queries filter by `imf_` and each
  message row is unique). INBOX-only feeds simply show one folder (the Product-A subset, §1.1). The
  open-thread toolbar also carries a **Move ▾ / Labels ▾** control that edits the thread's membership
  (`set_membership` → `MailboxService::setMembership`), which two-way push then reconciles to the
  source — see §14.
- **Setup → Receiving (IMAP mailbox).** Synthetic rows report sync mode and per-folder last-sync
  time/status (reuses `_setup_imap_receiving_rows`).

## 9. Sent / Compose Interop

Two distinct mechanics, both owned here. Dedup is **Message-ID only** — no fuzzy matching.

- **Ingesting Sent.** The Sent folder (`iif_role = sent`) is a tracked membership folder like any
  other; its messages ingest and show in the reader, so mail sent from the *native* client appears in
  Joinery.
- **APPEND-on-send (conditional).** When the reader's reply/forward feature
  (`outbound_reply_forward.md`) sends as an IMAP-backed mailbox with `iia_show_compose` on, the sent
  copy must reach the source's Sent folder so the native client sees Joinery-sent mail. *Most providers
  file it themselves* — Gmail/M365 SMTP auto-save to Sent — so Joinery `APPEND`s **only when the send
  transport does not** (`filesSent = false`, e.g. self-hosted Postfix+Dovecot, or a generic IMAP feed
  whose outbound goes through the platform relay). Transport/identity come from
  `resolveOutboundTransport(mailbox)` (`connected_account_email.md`), which exposes `filesSent`; this
  spec only performs the conditional `APPEND` (with `\Seen`).
- **Dedup, by send class.** A Joinery-composed message is stored as a local outbound `iem_` row for
  instant reader display *and* later observed in Sent. To avoid a duplicate without any heuristic, the
  rule keys off two declarative `PRESETS` capabilities — `filesSent` and a new
  `smtp_rewrites_message_id` (true for Gmail, false elsewhere):
  - **`filesSent = false` (APPEND path):** Joinery sets the `Message-ID` and `APPEND`s the exact MIME.
    Store the local row instantly; the APPENDed copy ingests and dedups by `Message-ID`. Instant, no
    duplicate.
  - **`filesSent = true`, `smtp_rewrites_message_id = false` (M365/Yahoo/iCloud/Fastmail):** the
    provider files the copy and preserves the `Message-ID`. Store the local row instantly; the filed
    copy ingests and dedups by `Message-ID`. Instant, no duplicate.
  - **`filesSent = true`, `smtp_rewrites_message_id = true` (Gmail):** Gmail rewrites the `Message-ID`
    on send, so a stored row could never match the filed copy. **Do not store a local outbound row**;
    the sent message appears on the next Sent ingest (one poll-interval latency). No mismatch is
    possible, and there is no fuzzy matching — the only cost of not storing `X-GM-MSGID` is this small,
    Gmail-only display latency, isolated to the §12 step-5 work.

## 10. Failure Handling

- Reuse `iia_needs_reauth` / `markNeedsReauth()` — a sync write failing on auth flags the feed exactly
  as ingestion does; the Accounts "Reconnect" affordance covers it.
- Partial progress is safe: pushes are idempotent (`COPY`/`STORE` of an already-applied state is a
  no-op; a re-`MOVE` of an already-moved message finds nothing); the shadow and `iem_synced_state_time`
  only advance on confirmed writes, per element, so an interrupted run retries the remainder next cycle.
- Per-run caps (mirroring `maxPerRun`) bound work and rate exposure across the now-multiple tracked
  folders; `iia_last_status` records counts (pushed/pulled/moved/deleted).

## 11. Testing

Extend `plugins/inbound_email/tests/imap_poller_test.php` (or a new `imap_syncer_test.php`) with a
mock IMAP client (the §6.2 seam) covering the unified model:
- **Flags** push/pull/dirty-skip (local-wins) and loop avoidance.
- **Membership, non-exclusive feed:** remote presence add/remove since MODSEQ → `imf_` reconciled;
  local add/remove → `COPY` / `EXPUNGE`; both-add-same-folder → agree, no double op; conflict-free
  claim — concurrent disjoint membership edits both land (no clobber).
- **Membership, exclusive feed:** local move → `MOVE` + locator re-point; remote move observed as
  vanish-F + arrive-G reconciled as remove + add; divergent move (local→A, remote→B) **converges to A
  within two cycles with no explicit tiebreak**.
- **Ingest adds membership:** a dedup hit (same Message-ID, different folder) adds an `imf_` row
  rather than no-op'ing.
- **Coverage source:** a message present only in the `\All` view (archived, no label) ingests, is
  visible in the All-Mail (folder-unfiltered) view, and has **zero** `imf_` membership; All-Mail flag
  changes still reconcile, and All-Mail arrivals/`VANISHED` create no membership.
- **Deletion:** `iia_sync_deletes` on → soft-delete bridges to membership → move-to-Trash (push); a
  Trash arrival → soft-delete (pull); off → local soft-delete does not touch the source and remote
  deletions are not pulled; Gmail archive (lose INBOX membership, keep others) is *not* a delete.
- **Sent:** native-client Sent message ingests; an APPEND-path message dedups to one row by
  Message-ID; a Gmail (`smtp_rewrites_message_id`) composed message stores no local row and appears
  via Sent ingest.
- **UIDVALIDITY change:** sync suspends for that folder and re-seeds without corrupting `imf_`.

## 12. Delivery Order (not a timeline)

Inventoried up front; shipped in risk order, each step independently shippable.
1. **Flags two-way** (read/star) — push, QRESYNC pull, loop guard. Includes the §6.2
   connection-lifecycle refactor, the client seam, and `MailboxService` flag-dirty stamping. The
   proving ground for the cursor/dirty/loop machinery. (Read-only mode exercises pull only; Two-way
   adds push.)
2. **Membership data layer** — `iif_` folder entity, `imf_` membership join + shadow, capability
   detection (`iia_supports_qresync`, `iia_folders_exclusive`), special-use + folder
   discovery/selection UI (the `\All` view seeded as a coverage source), ingest extended to seed/add
   `imf_` rows on membership folders (including the dedup path) and to store coverage-only arrivals
   without membership.
3. **Membership sync (unified presence)** — per-folder cursors, `COPY`/`MOVE`/`EXPUNGE` push (`MOVE`
   for exclusive feeds), per-folder pull + `VANISHED`, the §7.2 shadow merge, locator re-point, and
   the coverage-folder carve-out. Covers Gmail and classic hosts together; the exclusive-feed
   divergent-move case converges emergently (no separate conflict step).
4. **Deletion** (`iia_sync_deletes`) — soft-delete→membership bridge, move-to-Trash push;
   Trash/`VANISHED` pull.
5. **Sent / compose interop** (§9) — Sent ingestion + conditional APPEND + Message-ID dedup (with the
   Gmail no-local-row rule), landing with `outbound_reply_forward.md`.

## 13. Docs to Update at Implementation

Update `plugins/inbound_email/docs/overview.md` to describe sync as the current state (per the docs
rule — no "previously one-way" narration): a "Sync" subsection covering the per-feed Off/Read-only/
Two-way modes, the flag↔state and membership↔folder mappings, the per-folder-presence observation
model, the `\All` coverage view (All-Mail ingestion without membership), the shadow-based
reconciliation, deletion and Sent handling, the pull/ingest/push cycle, and the CONDSTORE requirement.
Note that no OAuth re-consent is required and that Gmail is reconciled by the same folder model as
every other host.

## 14. As-Built Refinements

Deviations from the original proposal, made while implementing and verified against a live Gmail feed.
This section is the authoritative record of *why* the as-built design differs from the prose above.

1. **Sync gate is CONDSTORE, not QRESYNC.** The proposal made QRESYNC the single gate. A live
   capability check showed **Gmail advertises CONDSTORE but not QRESYNC** — so a QRESYNC gate would
   have locked Gmail (the primary target) to Off, contradicting §1. Fixed by gating on CONDSTORE
   (incremental flags) and treating QRESYNC as an optional *fast path* for removal detection.
   `iia_supports_condstore` was added alongside `iia_supports_qresync`; `detectCapabilities()` sets
   `condstore = qresync || CONDSTORE`. A migration backfills `condstore` from a prior `qresync` flag.

2. **Removal detection has a CONDSTORE-only fallback (§7.4).** When QRESYNC is present, `VANISHED`
   since the modseq cursor. When absent, a per-folder **UID-set diff**: fetch the folder's current
   UIDs and treat any stored membership UID no longer present as departed. Same downstream logic.

3. **`imf_` carries the per-folder UID (`imf_imap_uid` / `imf_imap_uidvalidity`).** Required by both
   removal paths: a vanished/absent UID belongs to a message that can no longer be fetched, so the
   UID→membership-row correlation must have been recorded at ingest. The original `imf_` shape (just
   the shadow bits) could not support incremental removal.

4. **Folder rail is shown whenever folders are discovered, independent of sync mode.** `§8` originally
   implied folders surface only for syncing feeds. `MailboxService::foldersForAlias()` now returns the
   feed's tracked (non-`\All`) folders whenever they exist, so the structure is visible before sync is
   switched on. Folder *contents* still depend on `imf_` membership, which only populates once sync
   runs — so an Off feed shows the folders but they read empty until sync is enabled.

5. **Hot-path indexes via migration.** The declarative schema builder makes no non-unique indexes, so
   a migration adds: `imf_(folder_id, imap_uid)` (removal correlation), `imf_(message_id)`, and
   `iem_(account_id, imap_folder, imap_uid)` (the flag-pull locator lookup).

6. **Pull fetches use a numeric UID range, never `1:*`.** Found against live Gmail: a `1:*` UID fetch
   can return zero rows (the same `*`-form caveat the ingest path already avoids). The flag-pull and
   the UID-diff removal fetch both compute `1:<UIDNEXT-1>` from `STATUS`. Without this, flag pull was
   silently inert and the UID-diff saw an empty folder and wrongly cleared every membership. An empty
   folder (`UIDNEXT-1 < 1`) is treated as "unknown" and skips removal detection rather than clearing.

7. **Reader move/labels control (membership mutation from the UI).** §8 originally scoped the reader
   to *navigating* folders. A **Move ▾** (exclusive feeds) / **Labels ▾** (non-exclusive, e.g. Gmail)
   control was added to the open-thread toolbar so a user can change a thread's membership from
   Joinery: `MailboxService::setMembership()` sets `imf_present_local` (keeping the shadow base, so the
   element goes dirty) and the `set_membership` AJAX action drives it; two-way push then issues the
   COPY/MOVE/EXPUNGE to the source. `listMailboxes` now also returns `folders_exclusive` per mailbox so
   the reader picks the single-pick Move vs. multi-toggle Labels affordance, and the thread fetch
   returns the thread's current folder ids to pre-check it. Verified end-to-end against live Gmail.

8. **Create a label/folder locally → created on the source during sync.** The Move/Labels control has
   a "New label… / New folder…" field. `MailboxService::createFolder()` makes a tracked `iif_` row
   flagged `iif_pending_remote_create` and files the thread into it; the `ImapClient` seam gained
   `createMailbox`, and `ImapSyncer::push()` runs `createPendingFolders()` first — issuing the IMAP
   `CREATE` (idempotent: an already-existing folder is adopted), clearing the flag, then the membership
   `COPY` lands. Pull and ingest skip pending folders until they exist on the server. Verified
   end-to-end against live Gmail (create "Receipts" in the reader → folder + message appear on Gmail).

9. **The implemented spec stays live.** Per the docs/spec workflow this file was first moved to
   `specs/implemented/`, then moved back to `specs/` so it continues to track refinements as the
   feature is exercised. Treat this §14 as the diff against the proposal body above.
