# Inbound Email — Labels

## Status

Active.

A custom label is decoupled from any IMAP folder, so local mail and filters can carry a
label and a label is global across mailboxes. Each fact lives in its natural shape:
standard buckets are columns on the message, custom labels are a dedicated membership
table.

## The model (the whole idea in one rule)

**A standard label is a column. A custom label is a membership row.**

A *standard* label is a per-message scalar fact the message simply *has* — and every one
already has, or naturally wants, a column on `iem_inbound_email_messages`:

| Standard label (Gmail) | Stored as |
|---|---|
| Inbox / Archive | `iem_is_archived` |
| Unread / Read | `iem_is_read` |
| Starred | `iem_is_starred` |
| Spam | `iem_spam_verdict` (+ `iem_spam_score`) |
| Trash | `iem_delete_time` |
| Sent | `iem_direction` |
| All Mail | coverage source — not a label, no storage |

A *custom* label ("Receipts", "Work") is the opposite: an arbitrary, user-named bucket
with genuine many-to-many membership. Those — and only those — live in a dedicated
membership table.

Scalar per-message state goes in columns (which IMAP flags and the data model already
use); arbitrary user collections go in one membership table. Volume falls out of the
design: the only high-cardinality bucket is INBOX, and INBOX is a standard label, hence a
column — so the membership table holds only custom labels, which are sparse by nature.

## Schema

```
  ilb_inbound_email_labels          -- a custom label (global name registry)
    ilb_name                        -- unique → one global namespace
    ilb_create_time / ilb_update_time / ilb_delete_time (soft delete)

  ilm_inbound_label_members         -- (message, custom label) truth + IMAP shadow, ONE row
    ilm_iem_inbound_email_message_id   -- FK → iem, cascade (purge cleans membership)
    ilm_ilb_inbound_email_label_id     -- FK → ilb, cascade        (unique together)
    ilm_present_local  bool         -- truth: the message carries this label
    ilm_present_base   bool         -- shadow: in the bound remote folder at last sync
    ilm_iif_inbound_imap_folder_id  -- the binding on the message's feed (NULL = local/unbound)
    ilm_imap_uid / ilm_imap_uidvalidity  -- UID in that folder, for VANISHED correlation

  iif_inbound_imap_folders          -- binding: a CUSTOM label ↔ one feed's remote folder
    iif_ilb_inbound_email_label_id  -- NULL for special-use + coverage folders (they are columns)

  iem_inbound_email_messages        -- standard labels are columns here (see table above)
```

An element `(message, custom label)` is **dirty** when `ilm_present_local <>
ilm_present_base` — a plain column comparison, so a **partial index**
`WHERE ilm_present_local <> ilm_present_base` makes the push scan O(dirty), not O(total).
For an **unbound** membership (local mail, or a label not bound on the message's feed) the
writer keeps `present_base = present_local`, so it never enters the dirty index and is
never pushed.

### Why the membership lives in one row

Truth and the IMAP shadow are in the **same row**, which is what makes dirty a column
predicate (and gives a real `…_iem_…` cascade FK so a purged message cleans its
membership, an isolated table, and a one-statement upsert).

One row works because **an inbound message lives on exactly one IMAP feed**, so each
`(message, label)` maps to at most one binding — the message's own feed's folder for that
label — and the shadow (`present_base`, `imap_uid`) folds inline even though membership is
keyed by label, not folder.

## Layers

### Layer 1 — custom labels are `ilb_` rows
Create happens inline (the reader's "New label…" and the filter "Create new label…").
Rename/delete use a small dedicated label admin. Reading a message's labels / the label
list are plain queries on `ilm_`/`ilb_`.

### Layer 2 — inbound email uses labels + columns
- **Reader.** The rail lists `ilb_` custom labels alongside the standard views
  (Inbox/Starred/Spam), which render from their columns. The open-thread "Labels ▾"
  control toggles `ilm_` rows; `listThreads`' label dimension filters by an `ilm_`
  subquery; the standard views filter by their columns. A label click filters within the
  active mailbox.
- **Filters.** The *Apply label* action (`fil_action_ilb_inbound_email_label_id`) lists
  `ilb_` labels (+ "Create new label…") and is valid for every scope, domain-wide
  included. The standard-state actions (star, mark read, archive, spam, delete) write
  their columns.
- **Gmail import.** A custom Gmail label find-or-creates an `ilb_` label and wires the
  filter's label action. A Gmail *standard* label (`INBOX`/`STARRED`/`UNREAD`/`SPAM`/
  `TRASH`/`SENT`) maps via a small inline table to the matching **column** action, never
  to a label.

### Layer 3 — IMAP bindings + the in-row shadow (custom labels only)
- `iif_` is a `(feed, remote folder)` binding; `iif_ilb_inbound_email_label_id` is the
  custom label it mirrors, **NULL** for special-use and coverage folders.
- **Sync — custom labels** are a per-element CRDT (boolean three-way merge; COPY
  non-exclusive / MOVE exclusive / EXPUNGE; VANISHED by `(binding, uid)`) on the single
  `ilm_` row, selected through the partial dirty index.
- **Sync — standard state is column-driven**, the way flags are. Read/star push/pull via
  `\Seen`/`\Flagged` + the `iem_local_state_modified` dirty signal. **Trash** is the
  `iem_delete_time` column: a local soft-delete pushes a MOVE/COPY-to-Trash (the locator
  follows, doubling as the "already trashed" shadow so it is never re-pushed); a message
  ingested from the remote Trash folder sets `iem_delete_time` at ingest. **Archive stays
  local** (`iem_is_archived`); two-way archive ↔ remote-INBOX is a separate future
  enhancement.
- **Ingest records a membership row only for a *custom* tracked folder.** A message seen
  in a special-use folder sets the matching column instead (Junk → spam verdict, Sent →
  outbound direction, Trash → `iem_delete_time`); INBOX and coverage record nothing.

## Standard folders without a column (the "Important" wrinkle)

A few Gmail *system* folders have no existing column — chiefly **`[Gmail]/Important`**
(Starred already maps to `iem_is_starred`; All Mail is coverage). By the rule they are
**not** custom labels and must not get membership rows. Decision: **they are not modeled.**
If a real need appears later, the answer is a column (`iem_is_important`), never
membership.

## Testing

- **Custom labels:** create/find an `ilb_` label; add/remove a message; name unique;
  hard-deleting the **message** cascades its `ilm_` rows.
- **Standard state is columns:** archiving sets only `iem_is_archived` and writes no
  membership; star/read flip their columns; a remote Trash arrival sets `iem_delete_time`
  at ingest; INBOX membership is never written.
- **Inbound (local):** label/unlabel a local message; assert the `ilm_` row is clean-local
  (`present_local = present_base`, no binding) and never enters the dirty index or a sync
  query.
- **Inbound (IMAP):** a **bound** label pushes COPY/MOVE; an **unbound** label does not
  touch the remote; binding then labeling creates the folder (pending-create) and files
  into it.
- **Push is O(dirty):** with a large clean membership population, the push scan touches
  only the dirty rows (the partial index).
- **Trash round-trip:** local soft-delete pushes a MOVE/COPY-to-Trash; a remote Trash
  arrival soft-deletes locally — both via the column, no membership.

## Docs

Document the inbound **Mailbox Reader** / **Filters** / IMAP-sync sections of
`plugins/inbound_email/docs/overview.md` (current-state voice): a custom label is an
`ilb_` row applied to any inbound message; standard labels (Inbox/Archive, Read, Starred,
Spam, Trash, Sent) are the `iem_*` columns; an IMAP folder is a binding that mirrors a
custom label to a feed.

## Decisions (resolved)

- **Standard labels are columns; custom labels are a dedicated membership table.** Each
  fact in its natural shape; volume falls out because the one big bucket (INBOX) is a
  standard label, hence a column.
- **Custom-label membership + IMAP shadow live in one `ilm_` row**, giving an O(dirty)
  partial-indexed push, a cascade FK, and an isolated table — sound because a message
  lives on exactly one feed.
- **Standard state syncs as columns** (read/star via flags; Trash via the
  `iem_delete_time` column; archive stays local).
- **Important and Gmail category tabs are not modeled** — not labels, no column for now; a
  column later if ever needed, never membership.
- **No colour; reader label scope = active mailbox; create inline; housekeeping via a
  small dedicated label admin.**
