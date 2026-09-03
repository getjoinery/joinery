# A fetch that says where its time went, and a click that stops on time

**Status:** implemented 2026-09-03

## Problem

On jeremytunnell.com, one Refresh click in the mailbox reader (the
`mailbox/check_mail` action) ran for 145 seconds on the server. The proxy in
front of the node cuts a request at 100 seconds, so the browser saw a failed
request; the reader swallowed it, re-read the list, and the message did land.
The scheduled poll of the same account takes about three seconds, and earlier
clicks took six to eight. Nothing on the node could say which part of the
cycle took the two minutes:

- the ingest run record carried counts only;
- `iia_last_status` carried counts only;
- the cron log stamped every line with the runner's start time, so a
  two-minute task and a one-second task looked identical;
- no slow-request log, and lock-wait logging off.

Two defects, then. The click had no time budget, so anything over the proxy's
limit was invisible whatever it was; and the cycle recorded no timing, so a
slow one could not explain itself.

## Change

### Every cycle is timed

`ImapIngestor` keeps a ledger: `connect`, `prepare`, `pull`, each folder's
`seek` (STATUS + the window walk), `fetch` (bodies and inline images) and
`store` (the transaction), and `push`. `ImapIngestor::describeTiming()` turns
it into one line — `took 4.2s: connect 0.5s, pull 1.1s, INBOX 2.1s (seek
0.3s, fetch 1.2s, store 0.6s), push 0.1s`.

- `ImapFetch::run` appends the line (folder totals only; the column is 500
  characters) to `iia_last_status`, through the same save
  `observeFetchOutcome` makes.
- The run record's note carries the full line with folder detail.
- A fetch that fails reports how long it ran before it did, on the health
  detail.
- `process_scheduled_tasks.php` stamps each line when it is written and puts
  the task's elapsed seconds on the `Result:` line.

### An interactive fetch has a deadline

`ImapFetch::run(account, max, ?deadline, ?client)`. The ingestor's
`setDeadline()` bounds the cycle: work stops between folders (tracked-folder
ingest, the sync pull) and between messages once the deadline passes. Nothing
already started is cut. The folder cursor is held below the first message not
walked; the leftovers are counted as `deferred`, never `seen`, so the run
record's reconciliation balances. Push is skipped when the deadline has passed
and the status says so.

`ImapFetch::INTERACTIVE_BUDGET_SECONDS` (20) is the budget for the reader's
Refresh and the admin's Fetch now. `check_mail` hands every account the same
absolute deadline, reports `deferred` and each lane's `took_ms`, and calls
`ImapFetch::leaveDue()` on an account the deadline stopped, which clears its
last-poll stamp so the scheduled poller takes it at its next tick. The poller
itself runs unbounded.

## Not changed

The reader's JavaScript. The re-read after the click shows whatever landed;
the rest arrives with the poller. The response's `deferred` count is there for
a later banner if one is wanted.

## Files

- `plugins/mailbox/includes/ImapIngestor.php` 1.18 — ledger, deadline,
  `describeTiming`, deferred counts, run-record timing line
- `plugins/mailbox/includes/ImapSyncer.php` 2.2 — pull honours the deadline
  and laps; push laps
- `plugins/mailbox/includes/ImapFetch.php` 1.2 — budget constant, deadline
  and client parameters, status suffix, `leaveDue`
- `plugins/mailbox/logic/check_mail_logic.php` 1.2.0 — deadline per click,
  `deferred`, `took_ms`
- `plugins/mailbox/logic/admin_mailbox_imap_logic.php` 1.6 — Fetch now under
  the budget
- `utils/process_scheduled_tasks.php` 2.2 — per-line stamps, elapsed on Result
- `plugins/mailbox/tests/imap_fetch_budget_test.php` — new
- `plugins/mailbox/docs/overview.md`, `docs/scheduled_tasks.md`

## Still open

The cause of the one 145-second cycle is unmeasured; the ledger exists so the
next one is not.
