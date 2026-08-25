# WorkCursor — One Vetted Implementation of the Incremental Sweep

**Status:** Spec, unbuilt, deliberately parked. **Build trigger:** the next
time any new code needs a durable "process new rows once" cursor. Do not
build it speculatively, and do not retrofit the existing consumers when it
lands — they migrate opportunistically, the way `/ajax/` endpoints do.

## The problem

The platform keeps re-inventing the same mechanism: a background pass that
must act on each new row of an ever-growing table exactly once, across
crashes, restarts and failures. Each copy is hand-rolled, stores its
bookmark differently, and re-decides the same subtle rules from scratch —
and the subtle rules are exactly where the bugs live. Two shipped-quality
defects motivated writing this down:

- The managed-domain orphan sweep advanced its bookmark past an order whose
  alert email had **failed to send** — permanently silencing the one loss
  the sweep existed to catch (caught in review 2026-08-25, fixed).
- The Gmail import walked UIDs in **fixed steps** instead of following the
  IDs queries actually returned, and died on Gmail's sparse UID space
  (see memory: `reference_gmail_sparse_uid_space`).

## Existing hand-rolled consumers (inventory, 2026-08-25)

| Consumer | Bookmark | Storage |
|---|---|---|
| Mailbox FTS index (`MailboxIndex`) | `imi_fts_high_water` | bookkeeping row; reset-to-0 is its deliberate rebuild trigger |
| IMAP ingestor (`ImapIngestor`) | `isp_cursor_uid` | per-source column |
| check_mail pulls | per-source cursors | source rows |
| Managed-domain orphan sweep (`ManagedDomainWatch`) | `server_manager_domain_orphan_swept_id` | managed plugin setting |

These stay as they are. They are load-bearing and subtle (the FTS one has
vault-aware reset semantics); migrating them buys no user-visible change and
carries real risk. They are listed so the next author knows the pattern has
prior art to read, and so opportunistic migration has a checklist.

## The contract (what the helper must encode)

1. **Work is rows with an ever-increasing ID.** "New work" is exactly
   `id > bookmark`. Follow IDs the query returns — never walk in fixed
   steps; ID spaces are sparse.
2. **The bookmark is durable** (a setting or bookkeeping row), so a crash
   or restart resumes instead of repeating or skipping.
3. **Advance only after the side effect succeeds.** Examining a row is not
   acting on it. If the action fails, the bookmark holds.
4. **A single-scalar bookmark forces stop-at-first-failure.** Skipping a
   failed item while continuing would drag the scalar past it as soon as a
   later item succeeded. Stop, leave the bookmark, resume there next tick.
   (A per-row status table lifts this constraint at the cost of a table —
   out of scope for this helper; if a consumer needs per-row status, it
   needs a real queue, not a cursor.)
5. **A settle window:** never judge rows younger than N minutes, so
   multi-step work still in flight is not mistaken for a failure. If the
   arithmetic spans a grouping (per-order, per-user), state the assumption
   that a group's rows age together — partial payment broke exactly this
   assumption in review analysis.
6. **Reset semantics are part of the design.** Bookmark = 0 means
   "re-examine everything" and must be either idempotent-safe or refused.
   The consumer declares which.
7. **Read the bookmark fresh, not through a request-scoped cache.**
   `Setting::put()` does not refresh the Globalvars in-process cache; a
   second sweep in one process re-reading a stale mark re-reports
   everything (bit the orphan sweep; it reads via
   `ProvisioningSetup::readSetting()`).

## Sketch (shape, not final API)

One small core class, `includes/WorkCursor.php`, roughly:

```php
$cursor = WorkCursor::forSetting('server_manager_domain_orphan_swept_id',
    ['settle_minutes' => 15, 'batch' => 200]);
$cursor->sweep(
    fn(int $mark, int $limit): array => /* rows with id > $mark, settled,
                                           ordered by id ASC, LIMIT */,
    fn(array $row): bool => /* act; true = advance past, false = stop here */
);
```

The helper owns: fresh-read of the mark, the advance-after-success rule,
stop-at-first-failure, and the single write of the new mark per sweep. The
consumer owns: the query (including its settle window and any per-group
arithmetic) and the action. A second constructor
(`WorkCursor::forBookkeeping(...)`) covers row-stored marks when a migrating
consumer needs it.

## Testing requirement

Any test written to pin a cursor bug MUST be mutation-checked: reintroduce
the bug and confirm the test fails. The orphan-sweep regression test passed
green over the live defect on its first writing (it reset the mark to 0 and
asserted against the wrong rows) — seeding the mark just below the row under
test is the known-good shape.

## Out of scope

- Retrofitting the four existing consumers (opportunistic only).
- Per-row status / retry-queue semantics (that is a job queue, not a cursor).
- Distributed cursors (one deployment, one writer, is the platform reality).
