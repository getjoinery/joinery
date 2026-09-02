# Spec: The IMAP seed's import scope is enforced at storage time

**Status:** Built — `iif_seed_high_uid` is a live column on `iif_inbound_imap_folders`, the `out_of_scope` reconciliation is documented in `ImapIngestor`, and `plugins/mailbox/tests/imap_seed_scope_guard_test.php` covers it (header corrected 2026-09-02; it still read Draft after the build).
**Version:** 1.0
**Area:** `plugins/mailbox/includes/ImapIngestor.php`, `plugins/mailbox/data/inbound_imap_folder_class.php`, `plugins/mailbox/includes/MailRunRecord.php`
**Related:** `specs/mail_import_loss_proof.md` (§ B seed proofs — this spec keeps its guarantees), `specs/imap_source_domain_boundaries.md`, `plugins/mailbox/docs/overview.md`

---

## 1. What this fixes, in plain terms

A member connected their Gmail as an IMAP source scoped to **"Last 30 days"**. The feed stored 1,467 messages — and 973 of them (two-thirds, 40 MB of bodies) predate the 30-day window, reaching back to 2005. The member never asked for a history import, but got a partial one, and each poll can keep trickling in more until the backfill walker catches up to the present.

The mechanism, from the live seed proofs (`isp_` rows on jeremytunnell.com, account 3): the INBOX boundary seek burned its full 48-probe budget without converging — Gmail's UID space is sparse (high UID 270,948 with a fraction of that occupied), the band probes kept landing in deserts, and the bisection was driven down to cursor UID 1,536, ancient mail. That non-convergence is **by design**: the loss-proof work made every inconclusive seek fail toward importing more, so an inconclusive probe can never silently exclude in-window mail. The defect is what happens next: the windowed backfill (`ingestFolder()` → `nextOccupiedWindow()` → `ingestOne()`) stores **everything** above the cursor with no date check anywhere. The cursor is the only thing enforcing scope, so a conservative cursor converts directly into out-of-scope mail in the member's mailbox.

Downstream, the extra decades of mail are not free: they inflate the sealed search index (see `specs/mailbox_search_index_streaming_seal.md`, whose OOM this over-import accelerated), the AI panel's context, and the mailbox itself.

## 2. The rule

**The cursor decides where to look; the scope decides what to keep.** The seek stays fail-open — an inconclusive boundary must still cost extra walking, never lost mail. But during a day-scoped backfill, a message older than the window is skipped at storage time, visibly counted, with the cursor advancing past it. A bad cursor then costs poll time only, never scope.

## 3. Design

### 3.1 The storage-time guard

- `fetchWindow()` adds `$query->imapDate()` so every walked message carries its INTERNALDATE — the same clock the boundary seek probes (`probeEdgeInBand`), deliberately not the sender-controlled `Date:` header the stored row's `received_time` uses.
- In `ingestFolder()`'s walk loop, when the account's scope is `SCOPE_DAYS` **and the folder is still backfilling (§ 3.2)**: a message whose INTERNALDATE precedes `importCutoffUtc()` is skipped — not fetched further, not stored, not membership-recorded — and counted as `out_of_scope`. The cursor advances past it exactly as it does for stored mail.
- `SCOPE_FUTURE` and `SCOPE_FULL` are untouched; neither has a window to enforce.
- The cutoff is the account's rolling `importCutoffUtc()` ("N days before now"). Backfill runs at connect time, so it matches the seed cutoff to within the backfill's own duration; the seed proof's recorded `isp_cutoff_time` remains the auditable as-of-connect value.

### 3.2 The guard is confined to the backfill

`iif_inbound_imap_folders` gains `iif_seed_high_uid` (int, nullable): the folder's high UID at the moment its cursor was seeded, written in the same block that seeds `iif_last_seen_uid`. The guard applies only to UIDs at or below it; NULL (pre-existing folders) means no guard.

This confinement is what keeps later, deliberate acts working: a message the member moves into a tracked folder next month gets a fresh UID above the seed-time high, so it is ingested regardless of how old its INTERNALDATE is — moving old mail in is a request, and honored. Once the cursor passes `iif_seed_high_uid`, backfill is over and the guard never runs again for that folder.

### 3.3 The count stays reconciled

`mail_import_loss_proof`'s run reconciliation is the reason a skip must be a first-class outcome, not a silent `continue`. `MailRunRecord`'s poll dimensions gain `out_of_scope`; `summarizeRun()` reconciles `stored + dedup + failed + out_of_scope` against `seen`, and the run note names the count ("973 out of scope for the 30-day window"). A skipped message is provable-on-purpose, never `unaccounted`.

### 3.4 Seek improvement: ask the server first

Before bisecting, `seekCursorForCutoff()` attempts one server-side `UID SEARCH SINCE <cutoff-date>` on the folder. A successful result gives the exact in-window UID set: cursor = (min UID − 1), one round trip, proof recorded as converged with a `search` method marker (`isp_` gains a method column or encodes it in `isp_probes = 0`). On failure — the server rejects it, times out, or returns nonsense (the existing fetch path already avoids one ESEARCH form Gmail rejects; plain `SEARCH SINCE` must be verified live against Gmail before this ships) — fall through to the existing band-probe bisection unchanged. With § 3.1 in place, either path's worst case is wasted walking, so this is an efficiency fix, not a correctness dependency.

## 4. Remediation on jeremytunnell.com (operator-gated)

The 973 out-of-scope rows on alias 12 already exist; this spec does not delete them on its own — the owner may want some of that mail now that it is there. If removal is chosen:

1. Delete the pre-cutoff message rows (`iem_received_time < '2026-07-18'` on alias 12) with their attachment manifests and folder/label memberships, through the deletion system, not raw SQL.
2. `MailboxIndex::purgePersisted(1)` so the next unlock rebuilds the search index without them.
3. Set the INBOX folder's `iif_seed_high_uid` from the recorded seed proof and leave the cursor where it stands — with the guard deployed, the remaining walk skips pre-cutoff mail instead of storing it.

Either way, deploying the guard stops further out-of-scope trickle immediately.

## 5. Tests

`plugins/mailbox/tests/`, against the mock IMAP client (safe tier):

1. Sparse-UID fixture (occupied bands separated by deserts wider than the probe budget resolves): seek fails to converge, cursor lands deep, and the backfill stores **only** in-window messages, with `out_of_scope` carrying the rest and `unaccounted = 0`.
2. Converged-seek fixture: zero `out_of_scope` (the guard never fires when the cursor is right).
3. Post-backfill move: a message appearing above `iif_seed_high_uid` with an ancient INTERNALDATE is stored.
4. `SCOPE_FULL` and `SCOPE_FUTURE` accounts: guard never engages.
5. `UID SEARCH SINCE` path: mock returns a UID set → cursor exact, proof converged; mock throws → bisection fallback taken.
