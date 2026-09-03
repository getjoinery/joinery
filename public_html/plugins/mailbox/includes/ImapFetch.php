<?php
/**
 * ImapFetch - one full fetch cycle for an IMAP account, shared by every
 * fetch path: the scheduled poller (PollImapAccounts), the admin "Fetch now"
 * button, and the reader's Refresh (mailbox/check_mail).
 *
 * The cycle is what the account's sync mode prescribes: with sync on, the
 * whole two-way sequence on one connection (specs/two_way_imap_sync.md §7) —
 * Pull (flags + VANISHED) → Ingest → Push (two-way only); with sync off, a
 * plain ingest poll. One definition, so a manual fetch can never quietly do
 * less than the scheduled one.
 *
 * Two things every cycle carries out with it:
 *
 *   - Its timing. The ingestor laps each phase (connect, prepare, pull, each
 *     folder's seek / fetch / store, push) and run() writes the ledger into
 *     iia_last_status beside the counts, so the last fetch of any feed says
 *     where its clock went (specs/mailbox_refresh_budget.md).
 *   - A deadline, when the caller has one. A browser, and the proxy in front
 *     of it, waits a bounded time for a click; an interactive fetch is given
 *     INTERACTIVE_BUDGET_SECONDS and stops between folders and messages once
 *     it passes. The cursor stays below the first message not walked and the
 *     result says 'budget_exhausted', so the caller can leave the account due
 *     (leaveDue) for the scheduled poller to finish. The poller itself runs
 *     unbounded.
 *
 * Concurrency is the ingestor's: ImapIngestor::poll() holds the per-account
 * advisory fetch lock, so a second fetch on the same account fails fast with
 * ImapFetchBusyException whoever the two callers are. Callers decide what a
 * busy account means to them (skip, warn); this helper just lets it through.
 *
 * @version 1.2 - timing ledger into iia_last_status; optional deadline with
 *   budget_exhausted + leaveDue(); injectable client for tests
 * @version 1.1 - observes the outcome on the account (feed health, announced on transition)
 * @version 1.0
 */

class ImapFetch {

	/**
	 * How long an interactive fetch (Refresh, Fetch now) may run. Well inside
	 * the 100 seconds a fronting proxy waits, and short enough that a click
	 * still feels like a click; whatever is left is the scheduled poller's.
	 */
	const INTERACTIVE_BUDGET_SECONDS = 20;

	/**
	 * Run one fetch cycle. Returns the ingest result array
	 * (['status'=>..., 'stored'=>int, 'failed'=>int, 'took'=>float,
	 * 'timing'=>array, 'budget_exhausted'=>bool, ...]).
	 * Throws ImapFetchBusyException when the account's fetch lock is held,
	 * or any Throwable a connection/ingest failure raises — the ingestor is
	 * closed either way.
	 *
	 * @param float|null      $deadline absolute microtime() to stop at; null = unbounded
	 * @param ImapClient|null $client   a fake client (tests); null builds the real one
	 */
	public static function run(InboundImapAccount $account, int $maxPerRun,
			?float $deadline = null, ?ImapClient $client = null): array {
		$started = microtime(true);
		$ingestor = new ImapIngestor($account, $client);
		$ingestor->setDeadline($deadline);
		try {
			if ($account->syncEnabled()) {
				$syncer = new ImapSyncer($account, $ingestor);
				$t = microtime(true);
				$syncer->prepare();                 // capabilities + folder discovery
				$ingestor->lap('prepare', microtime(true) - $t);
				$syncer->pull();                    // flags + VANISHED (pull|both)
				$result = $ingestor->poll($maxPerRun); // ingest, seeding ilm_ labels + Trash soft-deletes
				if ($account->isTwoWay()) {
					if ($ingestor->pastDeadline()) {
						// Local changes wait for the next cycle; nothing is lost,
						// only later. Said in the status below.
						$result['push_deferred'] = true;
					} else {
						$syncer->push($maxPerRun);      // STORE / COPY / MOVE / EXPUNGE / trash
					}
				}
			} else {
				$result = $ingestor->poll($maxPerRun);
			}
		} catch (ImapFetchBusyException $e) {
			// Not an outcome: the fetch this caller wanted is already running.
			throw $e;
		} catch (Throwable $e) {
			// Every fetch path passes through here, so this is where a feed's
			// health is observed: a fault is announced once, on transition, and
			// the exception still reaches the caller that records the status.
			// The elapsed time rides on the detail — a login that failed after
			// thirty seconds and one refused at once are different faults.
			$account->observeFetchOutcome(false,
				$e->getMessage() . ' (after ' . number_format(microtime(true) - $started, 1) . 's)');
			throw $e;
		} finally {
			$ingestor->close();
		}

		$took = microtime(true) - $started;
		$result['took'] = $took;
		$result['timing'] = $ingestor->timing();
		$result['budget_exhausted'] = $ingestor->budgetExhausted() || !empty($result['push_deferred']);

		// The status the ingestor recorded, plus where the clock went. Written
		// through the same save observeFetchOutcome makes — one write, not two.
		$status = (string)($result['status'] ?? '');
		$suffix = ' · ' . ImapIngestor::describeTiming($result['timing'], $took, false)
			. (!empty($result['push_deferred']) ? '; push deferred to the next poll (time budget)' : '');
		$account->set('iia_last_status', substr($status . $suffix, 0, 500));
		$account->observeFetchOutcome(true, '', (int)($result['stored'] ?? 0));
		return $result;
	}

	/**
	 * Hand what a bounded cycle left undone to the scheduled poller: clear the
	 * account's last-poll stamp so it is due at the poller's next tick rather
	 * than one full interval later. Interactive callers do this when run()
	 * reports budget_exhausted.
	 */
	public static function leaveDue(InboundImapAccount $account): void {
		$account->set('iia_last_poll_time', null);
		$account->save();
	}
}
?>
