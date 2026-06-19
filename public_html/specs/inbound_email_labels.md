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
- **One additive schema change, generally useful:** a nullable `grp_color`
  (varchar) for the reader's chip colour. (Optional — could ship colourless.) No
  other change to the Groups model.
- A `Group::CATEGORY_INBOUND_LABEL` constant pins the category string in one place.

The existing Groups admin/CRUD can manage labels; inbound email may ship a thin
label management UI in its own tab and adopt any core Groups surface later.

### Layer 2 — inbound email uses label-groups

- **Reader.** The folder/label rail lists the `inbound_label` groups; the
  open-thread "Labels ▾" multi-select toggles `grm_group_members` rows;
  `listThreads`' membership dimension filters by a `grm_group_members` subquery
  (mechanically the same as today's `imf_` subquery). Uniform for local + IMAP mail.
- **Filters.** `fil_action_label_id` → `fil_action_grp_group_id`. The *Apply label*
  dropdown lists `inbound_label` groups (+ "Create new label…"); `applyActionSet`
  calls `add_member($messageId)` instead of `setPresence`. Now valid for the
  non-IMAP mailboxes filters run on, **and** for domain-wide filters (a label-group
  is not mailbox-scoped — this also closes the filters spec's open decision (a)).
- **Gmail import.** `label='deals'` → `Group::AddMemberBulkByName` /
  `get_by_name(..., 'inbound_label')` find-or-create, then set the filter's label
  action. (The "skipped: label" path in `specs/inbound_email_filter_import.md` is
  removed once this ships.)

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

## Migration

No production users yet, so a one-time, simple migration:

1. For each existing **custom** `iif_` folder, find-or-create a `Group` in the
   `inbound_label` category (same-name → same group, flagged for operator review —
   open decision) and set `iif_grp_group_id`; leave role folders' group NULL.
2. Convert each `imf_` membership row into: a `grm_group_members` row (message →
   the folder's label-group) for current presence, and an `imap_folder_membership`
   projection row carrying the old `imf_present_base` + UID. Retire `imf_`.
3. Repoint `fil_action_label_id` data → `fil_action_grp_group_id`.
4. Add the non-unique index on `grm_group_members (grm_foreign_key_id)` for the
   "labels of a message" lookup, via migration, if not already present.

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
- **Migration:** every prior `imf_` membership resolves to the right label-group;
  role folders stay role-only; filter label actions still apply.
- **Filters/import:** filter *Apply label* files an ingested local message; Gmail
  `label='deals'` import creates/reuses the group and wires the filter.

## Docs

When this ships: note in `/docs/` that Groups now backs inbound-email labels (the
`inbound_label` category) and rewrite the inbound **Mailbox Reader** / **Filters** /
IMAP-sync sections of `plugins/inbound_email/docs/overview.md` (current-state
voice): labels are `inbound_label` groups applied to any inbound message; an IMAP
folder is a binding that mirrors a label to a feed. No doc changes land with the
spec itself.

## Open decisions (resolve at implementation, not now)

- **`grp_color`** addition vs. colourless labels for v1.
- **Same-name merge on migration** — auto-merge same-named folders across feeds into
  one label-group (proposed) vs. keep distinct, operator-merge.
- **Reader label scope** — a label view scoped to the active mailbox vs. spanning
  all accessible mailboxes (labels are global; the reader is mailbox-oriented).
- **Label management home** — inbound-email tab now vs. the core Groups admin.
- **System views** — Inbox/Spam/Sent stay verdict/role pseudo-views (lean), not
  label-groups.
