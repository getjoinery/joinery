# Inbound Email — Labels (built on the Groups primitive)

## Summary

Make inbound-email **labels** a global concept decoupled from IMAP, by reusing the
platform's existing generic collection primitive — **Groups** — rather than
inventing a label/tag table. A label is a `Group` of category `inbound_label`;
applying a label to a message is a `grm_group_members` row. Any inbound message
(locally-received *or* IMAP-sourced) can be labeled. An IMAP folder is reframed as
a plugin-local **binding** that maps a remote folder to a label-group for sync,
with a small projection table holding the per-message IMAP shadow/UID. The generic
primitive (Groups) gains **no** email/IMAP columns; all IMAP machinery stays in
the inbound-email plugin.

System states (Inbox/Archive, Starred, Read, Spam) are **deliberately not** labels:
they stay as the `iem_*` columns and the spam verdict they already are. Labels are
Groups; system states are columns; the filter engine, reader, and importer handle
the two directly. No unifying "filing target" abstraction — see *Scope* below.

## Why Groups (and not a new label/tag table)

The recurring gap: a "label" today is an `iif_inbound_imap_folder`, which **must**
belong to an IMAP feed — so labels exist only for IMAP mailboxes, while filters
run only on **non-IMAP** mailboxes. The two populations are disjoint, so the
filter *Apply label* action and Gmail label import have no target. The root cause
is that "label" is conflated with "IMAP folder."

The fix is to model a label as what it generically is — a **named collection with
membership** — and the platform **already has** that primitive:

- `grp_groups` — a named group with `grp_category` (the category determines what
  kind of thing it collects) and a global-within-category unique name.
- `grm_group_members` — membership; `grm_grp_group_id` → the group,
  `grm_foreign_key_id` → the member (polymorphic, "user, event, etc., depending on
  the group category").

A label is exactly a category of group. Reusing Groups is the most general and the
simplest option: zero new generic tables, and a ready-made helper surface
(`get_by_name`, `add_group`, `get_groups_in_category`, `get_groups_for_member`,
`AddMemberBulkByName`, `add_member`/`remove_member`/`is_member_in_group`). Building
a parallel `tag_*`/`lbl_*` system would duplicate Groups.

## Scope — labels generalize onto Groups; system states do not

This spec generalizes exactly one thing: the **custom label**, which becomes a
Group. It deliberately stops there. The message's system states —
`iem_is_archived` (Inbox), `iem_is_starred`, `iem_is_read`, and the
`iem_spam_verdict` — stay as the columns and verdict they already are, and the
three call sites that file mail (filter engine, reader rail, Gmail import) handle
labels and system states as two separate, directly-coded mechanisms.

**Why not unify them behind one "filing target" interface.** It is tempting,
because Gmail models `INBOX`/`STARRED`/`UNREAD`/`SPAM` as labels too, to wrap
columns-and-Groups behind a single `add/remove/contains/members` interface so every
call site dispatches uniformly. We are **not** doing that. The reasons:

- **Groups already does the whole job for labels.** The interface would not make
  labels work — `add_member`/`remove_member`/`get_groups_for_member` already do.
  Its only purpose would be to also wrap the system-state columns, i.e. it is an
  abstraction over a decision (keep states as columns) we are making precisely to
  *avoid* treating them like labels.
- **The states must not become Groups anyway.** `iem_is_read` is the most-written
  per-message bool in the system; a membership row per unread message is
  pathological. `iem_spam_verdict` is a classifier output with a companion score,
  not a user-applied bucket. So the columns stay — and an interface that makes a
  column "look like" a group buys uniformity nobody downstream needs.
- **The duplication it removes is small.** Across the three call sites it collapses
  a handful of `if` branches and one extra render loop. That is not enough
  duplication to justify a new interface, two implementing classes, and a resolver.

The one place the two populations genuinely meet is the Gmail importer, whose
`label` field mixes custom and system label *names*. That is solved with a small
inline mapping (system-label name → the matching column/action; anything else →
find-or-create an `inbound_label` Group), not a platform abstraction. If a second
consumer ever wants "file an entity into a column-state or a Group uniformly,"
extract the interface then, shaped by two real call sites instead of one.

## Architecture — three layers

```
  EXISTING CORE   grp_groups (category='inbound_label')   ← the label
                  grm_group_members (group ↔ message)     ← the membership = truth
        ▲
        │ message = grm_foreign_key_id, category scopes it to inbound labels
        │
  PLUGIN          iif_ binding   (feed, remote folder) ↔ label-group   ← IMAP-only
                  imap projection (message, binding) present_base+UID   ← IMAP-only sync state
```

Membership in Groups is the **truth** ("what labels does this message have"),
identical for local and IMAP mail. IMAP sync is a projection of that truth onto
remote folders, with its own shadow, isolated in the plugin.

### Layer 1 — labels are Groups (`category = 'inbound_label'`)

- A label = a `grp_groups` row in the `inbound_label` category. `grp_name` is the
  label name; uniqueness is already per `(name, category)`, giving a **global label
  namespace** for free.
- Applying/removing a label = `$group->add_member($messageId)` /
  `remove_member($messageId)`; the message is `grm_foreign_key_id`.
- Reading a message's labels = `Group::get_groups_for_member($messageId,
  'inbound_label')`. The label list for the reader =
  `Group::get_groups_in_category('inbound_label')`.
- **No schema change to the Groups model.** Labels use `grp_groups` /
  `grm_group_members` exactly as they exist; the reader renders label chips without
  a stored colour.
- A `Group::CATEGORY_INBOUND_LABEL` constant pins the category string in one place.

No new label-management UI is built. The label surfaces already exist — the
reader's folder/label rail, the reader's apply-label control, and the filter
*Apply label* dropdown — and this spec only repoints them from `iif_` folders to
`inbound_label` Groups, so they keep working and now also populate for local
(non-IMAP) mailboxes. Create/apply happen inline in those surfaces; the rare
rename/delete housekeeping uses the existing core Groups admin.

### Layer 2 — inbound email uses label-groups

Labels go through Groups directly; system states stay on their existing columns and
keep their existing handling. No shared abstraction between the two.

- **Reader.** The folder/label rail lists the `inbound_label` groups alongside the
  existing system views (Inbox/Starred/Spam, rendered as today from their columns).
  The open-thread "Labels ▾" multi-select toggles `grm_group_members` rows via
  `add_member`/`remove_member`; `listThreads`' label dimension filters by a
  `grm_group_members` subquery (mechanically the same as today's `imf_` subquery).
  Uniform for local + IMAP mail. **A label click filters within the active
  mailbox** — the label is global, but the reader views it through the current
  mailbox's window (the thread list stays mailbox-scoped, no new access surface). A
  cross-mailbox label view is a separate future feature, not this rail.
- **Filters.** `fil_action_label_id` → `fil_action_grp_group_id`. The *Apply label*
  dropdown lists `inbound_label` groups (+ "Create new label…"); the label branch of
  `applyActionSet` calls `add_member($messageId)` instead of `setPresence`. The
  archive/star/read/spam action branches are unchanged. The label action is now
  valid for the non-IMAP mailboxes filters run on, **and** for domain-wide filters
  (a label-group is not mailbox-scoped — this also closes the filters spec's open
  decision (a)).
- **Gmail import.** A custom label (`label='deals'`) find-or-creates an
  `inbound_label` Group (`Group::AddMemberBulkByName` /
  `get_by_name(..., 'inbound_label')`) and wires the filter's label action. A Gmail
  *system* label name (`INBOX`/`STARRED`/`UNREAD`/`SPAM`/`TRASH`) maps via a small
  inline table to the matching column action instead. (The "skipped: label" path in
  `specs/inbound_email_filter_import.md` is removed once this ships.)

### Layer 3 — IMAP folders as bindings + a sync projection

IMAP folders/labels mirror a label to a remote account — genuinely IMAP-specific,
so it stays in the plugin and is split from the label:

```
iif_inbound_imap_folders            -- REFRAMED: a (feed, remote folder) binding
  iif_iia_inbound_imap_account_id   -- the feed (NOT NULL — a binding needs a feed)
  iif_name                          -- remote folder name on this feed (unique per feed)
  iif_grp_group_id      NEW nullable -- the label-group this folder maps to;
                                     --   NULL for a role-only folder (Sent/Trash/All)
  iif_role / iif_uidvalidity / iif_last_seen_uid / iif_last_sync_modseq
  iif_is_tracked / iif_pending_remote_create

imap_folder_membership              -- NEW (plugin): IMAP shadow, per (message, binding)
  (iem message, iif binding)  unique
  present_base   bool               -- in this remote folder at last sync (the shadow)
  imap_uid / imap_uidvalidity       -- UID-in-folder, for VANISHED correlation
```

`present_local` is gone: current truth is "is the message a member of the
binding's label-group?" The projection holds only the shadow + UID. An element is
**dirty** when group membership ≠ `present_base`.

**Sync (`ImapSyncer`) keeps its algorithm; only lookups change:**

- **Pull.** A message in remote folder F on feed A maps to `binding(A,F).group`;
  ensure membership in that group and a projection row (seen UID, `present_base =
  true`). An untracked remote folder find-or-creates a label-group + binding — the
  natural way a remote Gmail label joins the global set. **Special-use folders
  (`iif_role != custom`) never become labels** (their binding has
  `iif_grp_group_id = NULL`); Sent/Trash/All keep today's role behavior.
- **Push.** A dirty element resolves `binding(message.feed, group)` → remote
  folder, then COPY/MOVE/EXPUNGE. A label with **no binding on that feed is not
  pushed** — labeling never silently creates a folder in someone's real account.
  Materializing a label onto a feed (IMAP CREATE via `pending_remote_create`) is an
  **explicit** bind action, not a side effect of labeling.
- **VANISHED.** A vanished `(feed, folder, uid)` resolves folder→binding and
  `(binding, uid)` → projection row → message; unambiguous because a feed has at
  most one binding per remote folder and the projection is unique per
  `(message, binding)`.

## What this resolves

The design holes flagged earlier each land in the layer that owns them:

- **Special-use as labels** — role-folder bindings carry `iif_grp_group_id = NULL`;
  only `custom` folders map to label-groups.
- **Name collisions / hierarchy** — the binding maps one feed's folder name to a
  chosen label-group; two feeds' "Work" map to the same group *or* different groups
  by the binding, not by a forced global flatten.
- **Push creates a remote folder** — only an explicit binding pushes; local-origin
  labels never fan out to remote accounts.
- **UID correlation** — the projection is keyed `(message, binding)`, so the shadow
  + UID stay per-folder even though truth is per-label.

## One-time conversion

Not distributed — a single local install with no production users — so this is a
one-time conversion run once against the one database, not a per-install migration:

1. For each existing **custom** `iif_` folder, find-or-create a `Group` in the
   `inbound_label` category by name (`Group::get_by_name(name, 'inbound_label')`)
   and set `iif_grp_group_id`; leave role folders' group NULL. Find-or-create *is*
   the merge — two feeds' same-named folders resolve to one group for free (names
   are unique per category), so no separate merge/split policy is needed.
2. Convert each `imf_` membership row into: a `grm_group_members` row (message →
   the folder's label-group) for current presence, and an `imap_folder_membership`
   projection row carrying the old `imf_present_base` + UID. Retire `imf_`.
3. Repoint `fil_action_label_id` data → `fil_action_grp_group_id`.
4. Add the non-unique index on `grm_group_members (grm_foreign_key_id)` for the
   "labels of a message" lookup, via a `/migrations/` index file, if not already
   present.

## Testing

- **Labels via Groups:** create/find an `inbound_label` group; add/remove a message;
  `get_groups_for_member` returns it; name unique within category; soft delete
  cascades membership.
- **Inbound (local):** label/unlabel a locally-stored message; assert no IMAP state
  is written and it never enters a sync query.
- **Inbound (IMAP):** labeling an IMAP message with a **bound** label pushes a
  COPY/MOVE to the right folder; an **unbound** label does not touch the remote;
  binding then labeling creates the folder (pending-create) and files into it.
- **Pull/VANISHED:** new remote folder → group+binding+membership+projection; a
  vanished UID clears exactly the right membership; special-use never makes a label.
- **Conversion:** every prior `imf_` membership resolves to the right label-group;
  role folders stay role-only; filter label actions still apply.
- **Filters/import:** filter *Apply label* files an ingested local message via
  `add_member`; a filter combining *Apply label* + *Skip the Inbox* writes both the
  `grm_group_members` row and `iem_is_archived` through their own action branches.
  Gmail `label='deals'` import creates/reuses the group and wires the filter; a
  Gmail system-label name (`STARRED`, `INBOX`) maps to the column action, not a
  group.

## Docs

When this ships: note in `/docs/` that Groups now backs inbound-email labels (the
`inbound_label` category) and rewrite the inbound **Mailbox Reader** / **Filters** /
IMAP-sync sections of `plugins/inbound_email/docs/overview.md` (current-state
voice): labels are `inbound_label` groups applied to any inbound message; an IMAP
folder is a binding that mirrors a label to a feed. No doc changes land with the
spec itself.

## Decisions (resolved)

No open decisions remain.

- **No filing-target abstraction.** Labels generalize onto Groups; system states
  (Inbox/Archive, Starred, Read, Spam) stay as their `iem_*` columns/verdict,
  handled directly. (See *Scope*.)
- **IMAP folders and labels are the same object.** Layer 3 stays; the syncer's
  remote-facing behavior is unchanged, only its internal truth/shadow storage moves
  to Groups + the projection table.
- **No colour.** Labels use `grp_groups`/`grm_group_members` as-is; no `grp_color`.
- **No merge/split policy.** The one-time conversion uses find-or-create-by-name,
  which *is* the merge; the single local feed has no name collisions anyway.
- **Reader label scope = active mailbox.** A label click filters within the current
  mailbox; cross-mailbox label views are a separate future feature.
- **No new label-management UI.** Existing reader rail / apply control / filter
  dropdown repoint to Groups; core Groups admin covers housekeeping.
