# Mailbox search: incremental, checkpointed index folding

## The defect (found live, 2026-08-25)

Searching jeremy.tunnell@gmail.com on jeremytunnell.com returned "Could not
load this mailbox (524)" after ~30s. Diagnosis on the node:

- A sealed mailbox searches through `MailboxIndex` (SQLite FTS5 in /dev/shm).
  Before searching, `fold()` indexes every message above the high-water mark —
  decrypting and HTML-sanitizing each — synchronously inside the web request.
- The high-water mark sat at 3,981 (written when the index was created); the
  Gmail takeout import then added ~97k messages. Every search attempted to fold
  ~97k messages in one request and was killed by `max_execution_time = 30`.
- The mark is saved only after a **complete** fold, so no attempt ever advanced
  it — every retry restarted from 3,981. The fold can never catch up through
  this path: the design is wrong for large backlogs, not merely slow.
- Partial folds are not idempotent: each dead attempt leaves its inserts behind
  and the next re-inserts them. The live working copy held 33,822 rows for
  19,688 distinct ids (~2× duplication).
- No per-user lock and no SQLite busy timeout: concurrent searches folded the
  same file simultaneously, producing a "database is locked" warning storm.
  Failed inserts are swallowed — a fold that completed *despite* lock failures
  would advance the mark past messages that never made it in (permanent, silent
  search gaps).

Two adjacent latent defects, same subsystem:

- A Fortress pending-parse row has no content fields yet; folding it indexes
  empty text, and `parsePendingMessage()` never enqueues a refold when the
  content lands — the message stays unfindable forever.
- With the mark advancing per batch (below), the persisted blob can lag the
  mark. A restore of an older blob under a newer mark would open a silent
  coverage gap unless the blob records what it covers.

## Design

### 1. The fold is batched, checkpointed, and idempotent

`foldSince()` processes the backlog in batches (`FOLD_BATCH = 200`). Every id
is **delete-then-insert**, so re-folding an id is harmless — this also retires
the duplicate rows existing damaged working copies already hold, as the backlog
drains through them. After each fully successful batch the high-water mark is
checkpointed to the bookkeeping row; a fold interrupted by deadline, fatal, or
kill resumes where the last batch ended instead of restarting.

A failed insert aborts the pass with the mark at the last contiguous success —
the mark never advances past a message that is not actually in the index.

Checkpoint saves re-read the bookkeeping row and write only the fold's own
columns, so a concurrent `enqueueRefold()` (purge, send) is not clobbered. The
refold queue is likewise consumed incrementally: processed ids are subtracted
from the freshly re-read queue at each checkpoint.

### 2. The fold takes a deadline and reports what remains

`fold(int $user_id, string $secret_key, ?float $deadline = null): array`
returns `{complete: bool, folded: int, remaining: int, total: int}`. The
deadline is a `microtime(true)` value checked between messages — the same
contract every `VaultDeferredWork` consumer has. Null keeps the old
run-to-completion behavior (tests, small mailboxes via the drain).

### 3. One fold at a time per user

`fold()` takes a non-blocking `flock` on `/dev/shm/mailfts_{uid}.lock` around
the whole open-restore-rebuild-fold-persist sequence. A second request finding
the lock held does **not** wait and does not touch the working copy: it reports
`complete = false` with the backlog count and searches whatever is already
indexed. All SQLite handles set `busyTimeout(5000)` as a second belt. The lock
file is never deleted (unlinking a lock file another process holds breaks
`flock` mutual exclusion); it is zero bytes.

### 4. Search folds a slice, not the backlog

`MailboxService` gives the in-request fold a fixed budget
(`SEARCH_FOLD_BUDGET_SECONDS = 5.0` — well under `max_execution_time` anywhere)
and searches the partial index. When the fold reports incomplete, the response
carries `search_indexing: {remaining, total}` and the reader shows a
non-blocking banner: results may be incomplete, N messages still indexing.

### 5. The backlog drains in the background, in-window

The plugin registers a `mailbox_fts_fold` consumer with `VaultDeferredWork` —
the fold needs the vault window (it reads sealed content), which is exactly
what that system exists for. `hasWork` is `MailboxIndex::hasBacklog()`: cheap,
indexed, no decrypt, and **false when no bookkeeping row exists** — a user who
never searches never pays for index building. After the first search creates
the row, the presence-beacon drain finishes the backlog wherever the owner is
on the site, budgeted per slice, checkpointed per batch.

Registration order: after `mailbox_parse` and `mailbox_promoted_reseal`
(parsed, settled rows are what the index reads), before
`mailbox_inline_backfill` (cosmetic catch-up stays last).

### 6. The persisted blob records its own coverage

New bookkeeping column `imi_blob_high_water`: the mark at the moment the blob
was sealed. `restoreFromBlob()` resets `imi_fts_high_water` to it, so a
restored copy and the mark are consistent by construction and the gap between
blob-time and now is simply re-folded. A legacy blob (column null) was written
under the old complete-fold invariant — mark and blob already agree, so the
mark stands. `purgePersisted()` clears the column with the rest.

### 7. Pending-parse rows fold as no-ops and refold after parse

`rowContent()` returns null for a pending-parse row (its content fields do not
exist yet), so the mark advances past it without indexing garbage.
`parsePendingMessage()` enqueues a refold when it clears the pending state —
the parsed content enters the index at the next fold, closing the loop.

## Not doing

- No new settings (zero-config: constants with documented rationale).
- No progress persistence beyond the high-water mark — the mark IS the cursor.
- No automatic client re-search polling while indexing; the banner informs, the
  background drain catches up, the next search sees more. (Revisit only if the
  banner proves confusing in real use.)
