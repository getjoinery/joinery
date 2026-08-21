<?php
/**
 * MailRunRecord - what a batch of mail ingestion did, in a form a human can act on.
 *
 * Every ingest path that runs unattended and in batches needs the same three
 * things, and gets them here rather than each inventing its own:
 *
 * FAILURES GROUPED BY REASON. Fifty messages failing the same way is one problem
 * to fix, not fifty lines to read.
 *
 * A RECONCILIATION TRIPWIRE. Every message walked must end up in exactly one
 * bucket. When the buckets do not add up to what was seen, the difference is
 * reported as `unaccounted` and the run is marked unsuccessful — a message that
 * vanishes without anything reporting it is precisely the failure a set of
 * counters alone hides.
 *
 * A DURABLE ROW, BEST EFFORT. The summary goes to evl_event_logs so the history
 * outlives the last-status field it would otherwise overwrite. Writing it can
 * never fail the run: the mail is already stored, and losing the audit row is
 * strictly better than losing the mail.
 *
 * The buckets themselves differ by path — an IMAP poll stores, dedups or fails,
 * while an archive import can also skip a message the user did not select — so
 * they are passed in rather than fixed here.
 *
 * @version 1.1
 * @changelog 1.1 - the poll dimensions gain out_of_scope, the day-window
 *   backfill guard's bucket (specs/imap_seed_scope_guard.md §3.3)
 */

require_once(PathHelper::getIncludePath('data/event_logs_class.php'));

class MailRunRecord {

	/** The buckets an IMAP poll uses: counter key => the word for it in the note.
	 *  out_of_scope is the day-window backfill guard's bucket — a message walked
	 *  but deliberately not stored because it predates the feed's window
	 *  (specs/imap_seed_scope_guard.md §3.3). A first-class bucket, never a silent
	 *  skip, so the reconciliation tripwire still balances. */
	const DIMENSIONS_POLL = array('stored' => 'stored', 'dedup' => 'duplicates',
		'out_of_scope' => 'out of scope', 'failed' => 'failed');

	/** The buckets an archive import uses — the same, plus what the user left out. */
	const DIMENSIONS_IMPORT = array('stored' => 'stored', 'dedup' => 'duplicates',
		'skipped' => 'skipped', 'failed' => 'failed');

	/**
	 * Per-message failure lines written to the error log per run. A source that
	 * fails wholesale would otherwise put one line per message into the log.
	 */
	const MAX_LOGGED_FAILURES = 100;

	/**
	 * Reduce a batch's counters to the note, the verdict and the reconciliation.
	 * Pure — no database, no logging. write() does all of that.
	 *
	 * $counts carries 'seen', one key per dimension, and optionally 'failed_detail'
	 * (a list of ['reason' => string, ...]).
	 *
	 * @return array ['note'=>string, 'success'=>bool, 'unaccounted'=>int, 'failed_reasons'=>array]
	 */
	public static function summarize(array $counts, string $subject = '', array $dimensions = self::DIMENSIONS_POLL): array {
		$seen = intval($counts['seen'] ?? 0);

		$parts = array('seen ' . $seen);
		$accounted = 0;
		foreach ($dimensions as $key => $word) {
			$value = intval($counts[$key] ?? 0);
			$accounted += $value;
			$parts[] = $word . ' ' . $value;
		}
		$unaccounted = $seen - $accounted;
		if ($unaccounted !== 0) {
			$parts[] = 'unaccounted ' . $unaccounted;
		}

		$reasons = array();
		foreach ((array)($counts['failed_detail'] ?? array()) as $f) {
			$r = (string)($f['reason'] ?? 'Unknown error.');
			$reasons[$r] = ($reasons[$r] ?? 0) + 1;
		}
		arsort($reasons);

		$note = ($subject !== '' ? $subject . ': ' : '') . implode(', ', $parts) . '.';
		foreach ($reasons as $reason => $count) {
			$note .= "\n  x{$count}: {$reason}";
		}

		return array(
			'note'           => $note,
			'success'        => (intval($counts['failed'] ?? 0) === 0 && $unaccounted === 0),
			'unaccounted'    => $unaccounted,
			'failed_reasons' => $reasons,
		);
	}

	/**
	 * Write the summary: one evl_event_logs row plus bounded error-log detail.
	 *
	 * $describe turns one failure detail entry into its log line, so each caller
	 * names its own coordinates (a UID and folder, or an archive position) without
	 * this helper knowing anything about them.
	 */
	public static function write(string $event, array $summary, array $failedDetail = array(),
			?callable $describe = null, ?int $userId = null): void {
		error_log($event . ': ' . str_replace("\n  ", ' | ', $summary['note']));

		$shown = 0;
		foreach ($failedDetail as $f) {
			if ($shown++ >= self::MAX_LOGGED_FAILURES) {
				error_log($event . ': ... and ' . (count($failedDetail) - self::MAX_LOGGED_FAILURES)
					. ' further failed message(s) not listed.');
				break;
			}
			$line = $describe !== null ? $describe($f) : (string)($f['reason'] ?? 'Unknown error.');
			error_log($event . ': ' . $line);
		}

		try {
			$log = new EventLog(NULL);
			$log->set('evl_event',       $event);
			$log->set('evl_was_success', $summary['success']);
			$log->set('evl_note',        $summary['note']);
			if ($userId !== null) {
				$log->set('evl_usr_user_id', $userId);
			}
			$log->save();
		} catch (Throwable $e) {
			// The mail is already stored; losing its audit row must not fail the run.
			error_log($event . ': could not write the run record — ' . $e->getMessage());
		}
	}
}
?>
