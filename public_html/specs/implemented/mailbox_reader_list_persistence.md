# Mailbox Reader: Persistent Conversation List

**Status:** Implemented — built and browser-verified on dev

## Problem

Every mutation in the reader — delete, archive, spam, read/unread, restore,
folder moves, bulk actions — ends with `loadThreads(true)`, which blanks the
list to a "Loading…" row, refetches, and rebuilds from scratch. The user sees
a flash, loses their scroll position, and loses every ticked checkbox, even
when the action's effect on the list was one row disappearing. The list feels
like a page reload after every click.

## Design

Two client-side mechanisms replace blank-and-rebuild for mutations. The list
endpoint and every server contract are untouched; the list stays a
server-rendered-per-fetch view exactly as it is.

### 1. Soft refresh — `refreshThreads()`

Refetch page 1 of the current query, but leave the existing rows on screen
until the response arrives, then clear and refill in one synchronous pass —
no intermediate paint, so no flash and no "Loading…" row. Scroll position is
preserved (clamped if the new list is shorter).

- **Selection survives.** Ticked keys still present in the new payload stay
  ticked (rows re-tick themselves from `state.selected`); keys the new list no
  longer shows are pruned before render, so the bulk toolbar never acts on
  invisible rows.
- **Stale responses never paint.** One monotonic sequence token covers every
  list load, hard or soft; a response that started before a newer load began
  is discarded.
- **Failure keeps the mail visible.** A failed soft refresh leaves the rows
  as they were and prepends a retriable error row — a list that has mail is
  never blanked because a refresh failed.

### 2. Surgical removal — `removeThreadRows(keys)`

For actions whose only effect on the current view is that rows leave it:
remove those rows from the DOM directly, no refetch at all.

- Drop any section header left with no rows under it.
- Recompute the Load-more append cursor (`state.lastSection`) from the last
  remaining header, so pagination keeps emitting headers correctly. Section
  headers carry their section key in `data-section` for this.
- Prune the removed keys from the selection and resync the toolbar.
- If the list empties: soft-refresh when the server has more pages
  (`state.hasMore`), otherwise show the empty-state row. The Trash retention
  note and setup banner sit above the rows and are unaffected.

## Disposition per action

| Action | Where offered | Treatment |
|---|---|---|
| Delete | any non-Trash view | remove rows |
| Delete forever | Trash | remove rows |
| Restore | Trash | remove rows |
| Report spam | non-Spam views | remove rows |
| Not spam | Spam view | remove rows |
| Delete drafts (bulk) | Drafts | remove rows |
| Archive | Inbox view | remove rows |
| Archive / Move to Inbox | All Mail, folder views, search | soft refresh — the rows stay but their payload flags (`any_archived`) must be current for the toolbar's direction logic |
| Mark read / unread | anywhere | soft refresh — rows move between the Unread and Everything else sections |
| Star / unstar (thread pane) | anywhere | soft refresh — rows move in and out of the Starred section |
| Star (row star, in the list) | anywhere | unchanged: in-place class toggle; the row keeps its section until the next load — the lightest touch, kept deliberately |
| Move / Labels | thread pane + bulk | soft refresh — a folder-filtered view may change |
| Compose send, draft autosave list effects | Drafts view / new conversation | soft refresh |
| Vault unlock / lock events | reader page | soft refresh — sealed placeholders and readable rows swap without a blank |

Bulk actions follow the same table; a bulk removal removes exactly the acted
keys, and a bulk soft refresh (read/unread, archive in All Mail) keeps the
tick marks through the refresh since the acted rows remain in view.

## What stays a full reload

Mailbox switch, view switch (Inbox / All Mail / Spam / Trash / Drafts /
folder), and search changes — the old rows are genuinely different mail
there. Blank-and-rebuild remains, and clearing the selection on those
transitions stays by design. "Load more" pagination is untouched (appends).

## Non-goals

- No list virtualization, client-side store, or caching layer.
- No optimistic pre-response updates: every list change applies after the API
  confirms. Removal-on-response already reads as instant.
- No server or endpoint changes.

## Files

- `plugins/mailbox/assets/mailbox_reader.js` — all changes (version bump).
- `plugins/mailbox/docs/overview.md` — reader description gains the
  in-place-update behavior.

## Acceptance

1. Deleting a conversation from the Inbox removes its row with no list flash,
   no scroll jump, and other ticked rows keep their ticks.
2. Marking a thread read moves it out of the Unread section without blanking
   the list.
3. Bulk archive in All Mail completes with the selection still ticked and
   payload-fresh toolbar directions.
4. Restoring the last Trash conversation leaves the retention note and shows
   the empty-state row (or refills from the next page when one exists).
5. A soft refresh that fails leaves the existing rows visible with a
   retriable error row on top.
6. Removing every row of a section removes its header; "Load more" after
   removals still sections correctly.
7. Mailbox/view switches and searches still clear selection and rebuild.
