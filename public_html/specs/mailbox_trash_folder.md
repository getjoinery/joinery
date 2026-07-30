# Mailbox Trash Folder

**Status:** Not started

## Problem

Trashing mail in the reader calls `MailboxService::softDelete()`, which stamps
`iem_delete_time` and nothing else. Every read pins `iem_delete_time IS NULL`,
so the message vanishes from the list, the thread, the folder counts and every
search result — and `mutationScopeSql()` pins the same predicate, so no action
in the product can ever touch it again. There is no Trash view, no restore, and
no admin listing of trashed mail. Recovery means clearing a column by hand in
the database.

Nothing purges it either. `PurgeOldInboundEmailLogs` trims the `iel_` delivery
log, not messages, so trashed mail accumulates forever — still on disk, still
in every backup. An inbound filter with a delete action
(`inbound_email_filter_class.php:391`) stamps the same column, so mail the owner
never saw lands in the same invisible, unrecoverable state.

So "Trash" is a permanent hide. People expect a trash can: it holds what you
threw away, you can take it back out, and it empties itself after 30 days.

## Principles

- **Mirror the Drive trash.** The member Drive already implements this exact
  contract — soft-delete, restore, and a `DrivePurgeTrash` task on a 30-day
  window (docs/deletion_system.md § Worked example). Mail adopts the same
  vocabulary, the same default, and the same "the destructive path goes through
  the trash logic" rule. Mail is simpler: a message has no descendants, so
  there is no selective-restore rule to reproduce.
- **Trash is a view, not a label.** Deletion stays column-driven
  (`iem_delete_time`). No membership row, no folder record. The Spam view is the
  existing precedent for a column-driven pseudo-folder and Trash copies it
  exactly.
- **Nothing else loosens.** `mutationScopeSql()` keeps its
  `iem_delete_time IS NULL` pin for every existing action. Restore and delete-
  forever get their own narrow scope; nothing else may use it.
- **Purge reclaims everything or it is not a purge.** Permanent delete goes
  through `InboundEmailMessage::permanent_delete()`, which already reclaims
  file-backed attachment bytes and the stored raw object (local file or cloud
  object). A raw SQL delete would leak both.
- **One window, no exceptions.** Every trashed message purges on the same
  clock, including mail imported from another provider's Trash folder (the
  importer stamps the delete time at import, so an import starts a fresh
  window). A message's purge date is shown in the Trash view, so nothing
  disappears that the owner was not told about.

## Change 1 — Trash in the folder rail

`renderFolderRail()` in `plugins/mailbox/assets/mailbox_reader.js` appends one
more pseudo-folder after Spam: `folderItem('trash', 'Trash')`. `state.trashView`
joins `inboxView` / `spamView` in the state block and in `selectFolder()`'s
reset-and-set sequence, and `highlightFolder()` learns the fourth case.

Server side, `MailboxService` gains `trashScopeSql(?int $aliasId)` — the read
scope with the pin inverted (`iem_delete_time IS NOT NULL`), same alias
restriction, same `NO_DRAFTS` exclusion. It reproduces all three of
`readScopeSql()`'s branches, including the `UNMATCHED` sentinel (all-access only,
`1=0` for anyone who crafts the parameter) and the unconstrained all-access
`null` case — a Trash view that quietly dropped the unmatched branch would show
an all-access viewer a Trash that disagrees with their inbox.

Because the rail renders under whichever mailbox row is active, the superadmin
"All mail" and "Unmatched" rows get a Trash entry for free, scoped the same way
their inbox is. All-access can therefore restore or purge another user's trashed
mail — consistent with all-access being able to read and trash it already, and
the tests pin that a viewer *without* all-access cannot. `listThreads()` and `getThread()` both
select it when the new `trash` filter is set, so a trashed conversation opens
from the Trash view instead of 404-ing on the read scope.

`thread_list_logic` passes `'trash' => !empty($input['trash'])` through, exactly
as it does `drafts`. The reader sets `p.set('trash', '1')` alongside the
existing `unread_only` / `starred_only` handling.

No count badge — Spam has none either, and the rail entry reads the same way.
Unread badges need no change: the switcher's count query already excludes
deleted rows.

Each row in the Trash list carries its **purge date** in place of the received
time: `iem_delete_time` plus the retention window, rendered through
`LibraryFunctions::convert_time()` in the viewer's timezone. The window is a
setting an operator can change, so the date is computed for display, never
stored. When retention is 0 the column reads "kept indefinitely".

Search works in the Trash view exactly as it does elsewhere — the search box
stays, and `q` combines with the trash scope. On the Postgres path this needs
nothing: the full-text predicate is orthogonal to the scope. On the sealed path
it needs Change 2a.

## Change 2a — The index holds everything; the scope decides what shows

`MailboxIndex::fold()` currently indexes only rows with
`iem_delete_time IS NULL`, while the watermark (`imi_fts_high_water`) advances to
the highest id the pass *saw*. A message that arrives and is trashed before the
next fold is therefore skipped permanently, and restoring it cannot bring it
back — the watermark is already past it, and a full rebuild runs the same
filtered query. **Restore-then-search is broken for every vault-holding mailbox
today**, independent of this feature.

The fix is to stop filtering the index by delete state: drop
`AND iem_delete_time IS NULL` from both the main fold query and the refold
validity query, keeping the draft exclusion untouched. The index then holds
everything the owner has and the *read scope* decides what a search returns —
one rule instead of two.

Consequences, each of which the tests pin:

- Searching from Inbox or All Mail still never returns trashed mail: index hits
  are intersected with `readScopeSql()`, which keeps its pin.
- Searching inside Trash finds trashed mail, because that view's scope admits it.
- Restore needs no index bookkeeping at all — the row was never removed.
- Purge still prunes, and for the right reason: `permanent_delete()` removes the
  row, so the refold pass finds nothing to re-insert and the FTS entry is
  dropped. Pruning follows the row's existence rather than a delete flag.
- While a message sits in Trash its text remains in the index. For a sealed
  mailbox that index is the same per-owner sealed artefact under the same vault,
  so this widens what the index covers, not who can read it.

## Change 2 — Restore and Delete forever

Two new actions in `thread_action_logic` and `MailboxService`:

- `restore` → `restoreFromTrash(array $ids): int` — sets
  `iem_delete_time = NULL` on in-scope trashed rows.
- `purge` → `purgeFromTrash(array $ids): int` — loads each in-scope trashed row
  and calls `permanent_delete()` on it.

Both resolve targets through a new `trashMutationScopeSql()`: identical to
`mutationScopeSql()` but with `iem_delete_time IS NOT NULL`. These two actions
are its only callers, and a comment on the method says so — the pin on every
other mutation is what keeps trashed mail out of the read/star/archive/spam
paths, and it must not become a parameter.

`messageIdsInThread()` resolves a `thread_key` against the read scope, so it
cannot expand a trashed thread. It takes an optional `bool $trashed = false`
that swaps in `trashScopeSql()`, and `thread_action_logic` passes true for these
two actions.

In the open-thread toolbar, the reader swaps the action set while
`state.trashView` is on: **Restore** and **Delete forever** replace Archive /
Trash / Mark spam (a trashed message has no useful archive or spam state).
Delete forever confirms first, through the kit's existing `<dialog>` confirm —
never `window.confirm`.

## Change 3 — The purge task

New plugin task `plugins/mailbox/tasks/PurgeMailboxTrash.php` + `.json`, modelled
on `tasks/DrivePurgeTrash.php`:

```
SELECT iem_inbound_email_message_id, iem_iea_inbound_email_alias_id
  FROM iem_inbound_email_messages
 WHERE iem_delete_time IS NOT NULL
   AND iem_delete_time < now() - (INTERVAL '1 day' * :days)
 ORDER BY iem_delete_time ASC
 LIMIT :cap
```

then `new InboundEmailMessage($id, TRUE)` → `permanent_delete()` per row, which
reclaims the attachment Files and the raw object. Returns a message naming the
count and the window, in the house format.

`config_fields`:

- `days_to_keep` (number, default 30) — the retention window.
- `max_per_run` (number, default 500) — the backlog cap. A purge that hits the
  cap says so in its result message, so a long backlog drains over several runs
  instead of one enormous transaction.
- `report_only` (checkbox, default off) — counts what it would purge and deletes
  nothing. A diagnostic for an operator who wants to see the effect of a window
  change before it bites, not a gate: no deployment carries a trash backlog (at
  the time of writing the only deployment running mail holds 98 live messages,
  zero trashed, and no filters), so a fresh Trash only ever contains what its
  owner put there after this shipped.

`default_frequency` daily, `default_time` 03:45:00 (Drive purges at 03:30).

**Index hygiene.** Before deleting a row, the task enqueues its id onto
`imi_refold_ids` for the owning grantee(s) — the same queue `MailboxSender`
writes. The refold pass deletes the FTS row and re-inserts only if the message
still exists, and a purged one does not, so the stale entry is dropped at the
owner's next fold. Enqueueing needs no vault; the fold that consumes it happens
whenever the owner next unlocks. Without this the sealed index accumulates
entries pointing at rows that are gone.

**Sealed mailboxes purge locked.** `permanent_delete()` works on columns and
storage keys, never on plaintext, so a Fortress mailbox purges with the vault
shut. This is asserted by a test rather than assumed.

## Change 4 — Retention setting

One declared setting in `plugins/mailbox/plugin.json`, group `retention`
(alongside `mailbox_log_retention_days`):

```json
{
  "name": "mailbox_trash_retention_days",
  "default": "30",
  "group": "retention",
  "label": "Trash retention (days)",
  "type": "number",
  "helptext": "Mail in Trash is permanently deleted after this many days. 0 keeps it indefinitely.",
  "validation": {"number": true, "min": 0, "messages": {"min": "Must be 0 or more."}}
}
```

The task reads `days_to_keep` from its own config when set, otherwise this
setting — task config wins, so a deployment can run a different window without
editing the setting. `0` in either place means never purge, and the task
reports `skipped`, matching `PurgeOldInboundEmailLogs`.

No migration: declared settings seed themselves.

## Change 5 — IMAP-backed mailboxes (second pass)

Deletion is one-way today. `ImapSyncer::pushTrash()` MOVEs the source copy into
the account's Trash folder and re-points the locator, and that relocated locator
doubles as the "already trashed" marker that stops a re-push.

This pass leaves that untouched. Restore and purge act **locally only**: a
restored message returns to the local view while the source copy stays in the
provider's Trash, and a purge deletes our row and our bytes without expunging
anything remote. That divergence is bounded and honest — providers run their own
30-day Trash purge, so the remote copy goes on its own schedule.

The round-trip is a follow-on, and both primitives already exist
(`moveMessage`, `expungeMessage`), so what it needs is decided semantics, not
new machinery:

- Restore → MOVE out of the remote Trash back to INBOX (or the \All folder for
  Gmail-style accounts), then repoint the locator. Undefined today: what to do
  when the provider already expunged it, and where to land when the original
  folder is gone.
- Purge → `expungeMessage()` on the locator's folder, gated by the existing
  `iia_sync_deletes` flag.
- Whose clock wins when both sides purge on a 30-day window.

Until that lands, the reader's Trash view on an IMAP-backed mailbox says in one
line that restoring returns the message here and leaves the copy on the source
server alone.

## Tests

New `plugins/mailbox/tests/mailbox_trash_test.php` (tier `db`, harness
declared):

- Trashing hides a message from the inbox, All Mail and Spam scopes, and shows it
  in the trash scope.
- Search: a trashed message is findable searching inside Trash and not findable
  searching Inbox or All Mail — on both the Postgres path and the sealed-index
  path.
- **The restore-then-search regression** (Change 2a): a message trashed before
  its first fold, then restored, is searchable. This fails on today's code and is
  the reason that change exists.
- Every existing action (read/star/archive/spam/membership) refuses a trashed
  row — the `mutationScopeSql()` pin, asserted directly so a future refactor
  cannot quietly parameterise it away.
- Restore returns the message to All Mail and clears nothing else (read, star,
  archive and folder membership survive the round trip).
- `getThread()` opens a trashed conversation under the trash scope and refuses
  it under the read scope.
- Cross-mailbox scope: a viewer cannot restore or purge a trashed message in a
  mailbox they hold no grant on.
- Purge deletes the row, the attachment `fil_` rows and the raw object, and
  enqueues the id for refold.
- Purge of a Fortress-level mailbox's message succeeds with the vault locked.
- Task: `report_only` deletes nothing and reports a count; `max_per_run` caps a
  run and says so; `days_to_keep = 0` returns `skipped`.

## Documentation

- `plugins/mailbox/docs/overview.md` — a **Trash and retention** section:
  deletion is the `iem_delete_time` column, Trash is a column-driven view beside
  Spam, restore and delete-forever are the only mutations that reach a trashed
  row, the purge task and its window, what the purge reclaims, and the local-only
  behaviour on IMAP-backed mailboxes. Its search section states the index rule —
  the index covers every stored message and the read scope decides what a search
  returns.
- `docs/deletion_system.md` — one bullet beside the Drive worked example noting
  mail follows the same soft-delete/restore/timed-purge shape, with the
  attachment-and-raw reclaim happening in the model's `permanent_delete()`
  override.
- `docs/scheduled_tasks.md` — the new task in the task list.

Written as current state: no "previously", no migration narrative.

## Open decisions

1. ~~Where the first purge starts.~~ **Resolved:** there is no backlog to
   handle — no deployment has a trashed message or a trash-action filter, so
   Trash starts empty everywhere. Imported trash purges on the same window as
   everything else, with its purge date shown. `max_per_run` stays as a cap for
   a future deployment that trashes in bulk.
2. ~~Whether Trash is searchable.~~ **Resolved:** yes. The fold stops filtering
   by delete state (Change 2a) and the read scope decides visibility, which also
   fixes the pre-existing restore-then-search break for sealed mailboxes.
3. ~~Superadmin "All mail" Trash.~~ **Resolved:** it falls out of the existing
   rail. The folder rail renders under whichever mailbox row is active, so the
   all-access "All mail" and "Unmatched" rows get Trash the same way they already
   get Spam. Suppressing it would be the special case.

All three decisions are closed; nothing blocks implementation.
