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
 * Concurrency is the ingestor's: ImapIngestor::poll() holds the per-account
 * advisory fetch lock, so a second fetch on the same account fails fast with
 * ImapFetchBusyException whoever the two callers are. Callers decide what a
 * busy account means to them (skip, warn); this helper just lets it through.
 *
 * @version 1.1 - observes the outcome on the account (feed health, announced on transition)
 * @version 1.0
 */

class ImapFetch {

	/**
	 * Run one fetch cycle. Returns the ingest result array
	 * (['status'=>..., 'stored'=>int, 'failed'=>int, ...]).
	 * Throws ImapFetchBusyException when the account's fetch lock is held,
	 * or any Throwable a connection/ingest failure raises — the ingestor is
	 * closed either way.
	 */
	public static function run(InboundImapAccount $account, int $maxPerRun): array {
		$ingestor = new ImapIngestor($account);
		try {
			if ($account->syncEnabled()) {
				$syncer = new ImapSyncer($account, $ingestor);
				$syncer->prepare();                 // capabilities + folder discovery
				$syncer->pull();                    // flags + VANISHED (pull|both)
				$result = $ingestor->poll($maxPerRun); // ingest, seeding ilm_ labels + Trash soft-deletes
				if ($account->isTwoWay()) {
					$syncer->push($maxPerRun);      // STORE / COPY / MOVE / EXPUNGE / trash
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
			$account->observeFetchOutcome(false, $e->getMessage());
			throw $e;
		} finally {
			$ingestor->close();
		}
		$account->observeFetchOutcome(true, '', (int)($result['stored'] ?? 0));
		return $result;
	}
}
?>
