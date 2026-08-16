# Proving no mail was lost

**Status:** implemented
**Depends on:** `specs/mail_archive_import.md`, `specs/mailbox_attachment_byte_custody.md`

## Why

Someone connects a live mailbox and then uploads an export of the same account.
Afterwards they want one thing answered honestly: **is all of it here?**

Today the system can only answer "everything I noticed, I handled." Each path
reconciles its own counters — every message seen lands in exactly one bucket, and
a shortfall is reported as `unaccounted` — but the denominator is the system's own
count of what it found. A message the reader never emitted, or a server message
the cursor never reached, is absent from every number. That is the failure this
spec closes: the difference between *self-consistent* and *complete*.

Two defects found while reading the paths are fixed here as well, because both
destroy custody silently and neither shows up in any existing counter.

## What already exists

Not re-litigated, and load-bearing for everything below:

- One `mie_` ledger row per scanned archive message, carrying state, reason and
  the resulting message id (`MailImportEntry`).
- Per-batch reconciliation with an `unaccounted` tripwire on both paths
  (`MailRunRecord::summarize`).
- Overlap guards on both cron tasks — per-account for polls, per-run for imports.
- Adoption of real bytes onto reference-backed attachments
  (`AttachmentByteCustody`), which is what makes the two orders converge —
  including on the router's own site-wide dedup path: `storeMessage()` catches
  the unique collision, resolves the colliding row, and adopts onto it before
  reporting dedup. Custody is already handled there; D2 below is about
  traceability only.

## Defects

### D1 — the IMAP store and its attachment manifest are not atomic

`ImapIngestor::ingestOne()` commits the message row via `storeExtracted()` and
then writes the attachment manifest as a **separate statement**. Between the two,
the message exists with zero attachments. Two ways that bites:

**Concurrent.** An import worker deduping against the message in that window
calls `AttachmentByteCustody::adopt()`, finds no rows to adopt, and records a
clean `dedup` on the entry — permanently. Nothing revisits it, the archive is
purged on schedule, and those attachments stay dependent on the source mailbox
forever. This is exactly the failure byte custody exists to prevent.

**Not concurrent, and worse.** If the poll dies between the two writes, the
folder cursor has not advanced, so the UID is retried — `storeExtracted()` now
reports dedup, and `writeManifest()` is skipped by design ("dedup ⇒ already has
one"). That message's manifest is empty permanently. One interrupted poll is
enough; no second process is required.

**Fix.** Wrap the store, the manifest write and the direction/spam/trash column
updates for one message in a single transaction. The router already uses the
`$owns_tx = !$db->inTransaction()` idiom throughout, so it joins an outer
transaction — this is the existing pattern, not a new one. A crash now rolls the
message row back, and the retried UID stores fresh *and* writes its manifest, so
one change closes both cases.

One Postgres consequence to honour: a unique violation inside the outer
transaction **aborts the whole transaction**, and `storeExtracted()`'s
concurrent-duplicate handling catches that violation and keeps executing — those
follow-up statements would now fail with "current transaction is aborted". Do
not add a savepoint to preserve the catch-and-continue; let the collision roll
the whole message unit back. The cursor has not advanced past an uncommitted
message, so the UID retries, and the retry's pre-validate duplicate SELECT — a
plain read before any insert, no error, no abort — resolves it as a clean dedup.
This is the same crash-and-retry convergence the fix already relies on; the
implementation just has to treat the collision as "this message rolls back and
retries", not recover inline.

**Not** mutual exclusion between polling and importing. A full-archive import
runs for hours or days in cron batches; blocking the feed for that whole time
means mail stops arriving, which is a worse and more visible failure than the one
being avoided. The two features are not incompatible — one write pair was
unsynchronised.

### D2 — the one dedup outcome that can mean "not in your mailbox" records nothing

`MailArchiveImporter::importEntry()` records `'Already stored on this site.'` with
**no message id**. That branch fires on a site-wide unique collision, which can be
against a copy in another mailbox entirely — so it is the single outcome that can
legitimately mean *this mailbox does not have it*, and it is the one the ledger
cannot trace.

**Fix.** Resolve the colliding message id with the same
(message-id, recipient, direction) lookup the router's own dedup path uses, and
record it on the entry. Three outcomes, each with its own reason:

- the row belongs to this run's alias — a plain traceable dedup (the race case:
  the poll stored it between this run's alias check and its insert);
- the row belongs elsewhere — a distinct reason naming the colliding id, so the
  reconciliation below can list it;
- the lookup finds nothing — **collided, unresolvable**, its own named reason.
  This is a known shape, not a bug path: a sealed-recipient row cannot be
  matched by lookup, which is why the router's adopt call on this path is
  guarded the same way.

The entry state stays `dedup` in all three; what changes is that every entry
becomes traceable or names why it cannot be. No adoption is added here — the
router already handed bytes over whenever it could resolve the row.

### D3 — an adoption mismatch is treated as a clean dedup

Defence in depth behind D1. When the archive copy carries attachment parts and
the stored copy has no manifest rows at all, that is a discrepancy, not a
successful dedup.

With D1's transaction in place the shape cannot be transient — a row the
importer can see committed together with its manifest — so there is nothing to
retry. An empty manifest under an archive copy that carries parts is a
persistent condition: a legacy row from before the fix, or a path that never
writes manifests.

**Fix.** Detect the shape and record `dedup` immediately, with a reason naming
the unresolved mismatch and the colliding message id, so the reconciliation
below can list it. Gate the check on a cheap
`AttachmentByteCustody::manifestRowCount()` (new method) so the part enumeration
only runs on the rare mismatch path.

## Oracles and reconciliation

The principle for both sides: **the thing that produced the data cannot be the
thing that certifies it.** Each path gets an independent inventory and a
reconciliation against it.

### A — the archive side

**`maintenance_scripts/dev_tools/mail_archive_inventory.py`** walks an mbox with
Python's stdlib `mailbox` and `email` modules — a genuinely different
implementation from `MboxSplitter`, which is the point — and writes JSONL, one
object per message: `message_id` (empty when absent), `folder`, `labels`,
`byte_offset`, `attachments` (filename and size per part). Read-only. A
half-million-message archive produces a file in the low hundreds of MB, which is
fine.

**`maintenance_scripts/dev_tools/reconcile_mail_import.php`** takes a run id and
an inventory file and reports:

- source totals: messages, distinct Message-IDs, messages with none
- **the shortfall list** — source Message-IDs with no `iem_` row in the target
  alias, **counting soft-deleted rows as present**: Trash is modelled as a soft
  delete, so a message the source had in its bin is correctly stored as a
  deleted row (the same rule the importer's own dedup follows — deleted rows
  count, or a re-import would resurrect binned mail). This is the answer to the
  question; it prints identifiers, not a count, capped on screen with the full
  set written to a file.
- **the no-Message-ID reconciliation** — a source message with no Message-ID is
  invisible to the list above (the system stores it under a synthesized
  `<sha256@import.invalid>` id the oracle cannot cheaply reproduce), so those
  are matched **by byte offset** instead: the inventory records each message's
  offset, and the ledger's mbox locators are `offset:length`. An inventory
  offset with no ledger entry is a shortfall finding named by folder and
  offset; an offset on one side only also localizes exactly where the two
  splitters diverged, which no count comparison can do.
- entries by state, plus the suspicious buckets by name: D2's
  collided-elsewhere and collided-unresolvable entries, and D3's unresolved
  mismatches
- attachment custody: how many attachments on this run's messages are still
  reference-only
- **attachment presence** — per message matched by Message-ID, compare the
  source's attachment count against the stored manifest's row count, and list
  every message where they differ (with the source filenames, which the
  inventory already carries). This catches a dropped part on a message that is
  otherwise present — invisible to Message-ID matching. Count, not filename,
  is the comparison: the two sides name and de-duplicate parts differently,
  and a name match would drown the report in false mismatches.

A count-only comparison is explicitly not sufficient. Two errors that cancel
would pass it.

The finished-run panel gains one compact reconciliation line showing
`unaccounted` and the suspicious bucket counts, because those are the numbers
that mean *look here*. The detail stays in the CLI report.

### B — the IMAP side

**Seed proof.** `seekCursorForCutoff()` bisects to a start UID and fails soft, but
records nothing. Write one durable row per folder seed carrying the cutoff, the
chosen cursor, the probe count, and whether it converged or exhausted its budget —
plus two extra probes that make the result checkable:

- the INTERNALDATE of the **highest UID at or below** the cursor, which must be
  older than the cutoff. This checks the boundary the bisection chose — it is
  not a proof over everything below it, because INTERNALDATE is not guaranteed
  monotonic in UID (a message copied or imported into the account gets a fresh
  high UID with whatever date it carries). The proof over the region belongs to
  the audit tool's below-cursor sweep.
- the INTERNALDATE of the **lowest UID above** the cursor, which should be inside
  the window. This measures tightness, not safety — over-import is the documented
  fail-soft direction.

Both are single narrow FETCHes on the existing numeric-UID path, run once per
folder seed. `probeOldestInBand()` gains a descending sibling for the first.

**`maintenance_scripts/dev_tools/imap_window_audit.php`** is the IMAP-side
inventory: for one account and folder, fetch INTERNALDATE and Message-ID for
every UID above the seed cursor and compare that set against stored rows
(soft-deleted rows count as present, as in A — Trash arrivals are stored as
deleted rows), printing the shortfall. It also runs the loss proof for the region below the
cursor: one `FETCH 1:cursor (INTERNALDATE)` — dates only, no headers, a single
command even on a large folder — reporting every below-cursor UID whose date
falls inside the window. Those are messages the seed skipped that the window
claims; each is a named finding, not a count. Deliberately expensive and run on
demand — this is the verification instrument, not something a poll does. Note that no SEARCH-based
shortcut is available: Gmail advertises ESEARCH but rejects the form Horde emits,
which is why nothing in that class searches.

## C — the multi-part Takeout question

An export split into parts may cut a single oversized mbox **across** parts. If
so, the member inside part two begins mid-message and cannot be read standalone,
and identically-named members across parts collide on extraction. The one real
export in hand (2 GB parts requested) took a different shape entirely: a small
zip plus the oversized mbox shipped **whole and standalone** beside it — no
cross-part cut at all. That is one observation, not a guarantee about every
export Takeout produces, so this spec still **measures the shape and stops
there**. The remedy for a genuinely cut mbox, if one ever appears, is
deliberately out of scope.

**`maintenance_scripts/dev_tools/takeout_parts_probe.php`** takes a directory
holding an export and probes every shape production accepts — `.zip` parts,
`.tgz` parts, and bare mbox files sitting beside them. It reports, per part:
every member with its uncompressed size, which member names appear in more than
one part, and — for each mbox member or bare mbox file — whether its first
bytes are a `From ` separator at offset 0 (a complete mbox) or mid-message (a
fragment). It also totals uncompressed bytes, which sizes the disk question.
Zip parts are read from the central directory plus a 4 KB prefix per mbox
member; a tgz has no index, so it gets one streaming decompression pass that
walks the tar headers without extracting anything to disk. Either way the probe
runs against parts wherever they already are.

**`plugins/mailbox/tests/takeout_split_parts_test.php`** pins current behaviour in
the harness: build a small mbox, split it mid-message across two zip parts, and
assert what the reader stack actually does with each — whether the registry
sniffs a fragment as an mbox, what `MboxSplitter` emits for a truncated leading
message, and whether anything reports the truncation. The test's job is to record
reality, including if reality is "imports a corrupt message and says nothing" —
that finding is the input to the remedy.

## Out of scope

- **Repairing an empty manifest from archive bytes.** When the stored copy has no
  manifest rows at all, the importer is holding bytes that could populate one.
  That means minting manifest rows outside the router, and the reconciliation
  above will first tell us whether the shape ever occurs in practice.
- **Changing archive retention.** Seven days is enough to import and reconcile,
  and the source archives exist off-platform.
- **Making polling and importing mutually exclusive.** See D1.
- **A remedy for split archives.** See C.

## Data model

No schema changes. (The seed-proof rows in B use their own small table, declared
through `$field_specifications` as usual.)

## Tests

- `plugins/mailbox/tests/imap_store_atomicity_test.php` (`db`) — a message whose
  manifest write fails leaves no message row; the retried UID stores the row and
  its manifest together. Covers D1's crash case directly, which is the one that
  needs no second process.
- Extend `plugins/mailbox/tests/attachment_byte_custody_test.php` — a dedup
  against a message with an empty manifest records `dedup` with a reason naming
  the mismatch and the colliding id (D3).
- Extend the import suite — a site-wide collision against a message in another
  alias records the colliding id and the distinct reason; a collision the lookup
  cannot resolve records the unresolvable reason (D2).
- `plugins/mailbox/tests/takeout_split_parts_test.php` (see C).
- Seed proof: assert the recorded row's boundary probes for a known synthetic
  mailbox, including the exhausted-budget path.

## Documentation

- `plugins/mailbox/docs/overview.md` — the import and IMAP sections gain the
  reconciliation instruments and the seed proof, written as current state.
- `docs/testing.md` — only if the new suites introduce a tier or `needs` value
  not already described.

## Acceptance

1. Poll and import run concurrently against one alias for a full archive, and no
   attachment on a message present in both ends up reference-only.
2. A poll killed between its two writes leaves no message row, and the retry
   stores the message with a complete manifest.
3. The reconcile report on a real run prints an empty shortfall list — or a
   populated one whose every entry has a named reason.
4. Every source message is accounted for by exactly one of: a stored row (live
   or soft-deleted), a named skip, a named failure, or a traceable dedup —
   matched by Message-ID, or by byte offset for messages that have none.
5. The parts probe reports the shape of a real export — zip, tgz, or bare mbox —
   and the harness test pins what the reader stack currently does with a
   fragment.
