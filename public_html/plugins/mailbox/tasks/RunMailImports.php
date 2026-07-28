<?php
/**
 * RunMailImports - Scheduled Task
 *
 * Grinds through mail archive imports. Both phases live here because neither can
 * happen inside a web request: walking a 50GB mbox takes minutes, and storing a
 * hundred thousand messages takes hours.
 *
 * Each pass does ONE bounded batch of ONE run and returns. That is what makes the
 * feature safe to leave switched on: an import is always progressing and never
 * monopolising, and the cron runner is never held open. A run advances a little
 * every pass until it is finished.
 *
 * Overlap guard: a run is claimed with an atomic conditional UPDATE that stamps
 * mir_claim_time on pickup, so two concurrent cron passes can never work the same
 * run and double-count its entries. A claim goes stale after STALE_CLAIM_SECONDS,
 * which is how a run whose pass was killed mid-batch gets picked up again rather
 * than sitting claimed forever.
 *
 * Resume is implicit and needs no bookkeeping: the import's work query is "the
 * pending entries of this run", so a crash mid-batch costs at most that batch, and
 * even re-running it is safe because storing an already-stored message dedups.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mail_import_run_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/import/MailArchiveImporter.php'));

class RunMailImports implements ScheduledTaskInterface {

	/** Entries stored per pass when no batch size is configured. */
	const DEFAULT_BATCH_SIZE = 200;

	/** Runs importing at once, deployment-wide, when nothing is configured. */
	const DEFAULT_MAX_CONCURRENT = 2;

	/**
	 * Wall-clock seconds one pass will spend scanning. Scanning is the cheap phase
	 * — it reads sequentially and writes narrow rows — so it gets a time budget
	 * rather than a message count, and picks up where it left off next pass.
	 */
	const DEFAULT_SCAN_SECONDS = 120;

	/** Entries one scan pass will write, whatever the clock says. */
	const SCAN_ENTRY_LIMIT = 20000;

	/**
	 * A claim older than this is treated as abandoned. Long enough that a slow but
	 * healthy batch is never stolen mid-flight, short enough that a killed pass
	 * does not strand its run for the rest of the day.
	 */
	const STALE_CLAIM_SECONDS = 1800;

	public function run(array $config) {
		$settings = Globalvars::get_instance();

		if (!$this->truthy($settings->get_setting('mailbox_import_enabled'))) {
			return array('status' => 'skipped', 'message' => 'Mail archive import is switched off.');
		}

		$batchSize = $this->positive($config['batch_size'] ?? null)
			?: $this->positive($settings->get_setting('mailbox_import_batch_size'))
			?: self::DEFAULT_BATCH_SIZE;
		$maxConcurrent = $this->positive($config['max_concurrent'] ?? null)
			?: $this->positive($settings->get_setting('mailbox_import_max_concurrent'))
			?: self::DEFAULT_MAX_CONCURRENT;
		$scanSeconds = $this->positive($config['scan_seconds'] ?? null) ?: self::DEFAULT_SCAN_SECONDS;

		// The cap counts runs already UNDERWAY, not runs waiting their turn: a
		// deployment with two large imports in flight leaves a third queued, and
		// picks it up as soon as one of the two finishes.
		$inFlight = MailImportRun::inFlightCount();
		$claimed = $this->claim(($inFlight >= $maxConcurrent) ? 0 : 1);
		if ($claimed === null) {
			return array('status' => 'success', 'message' => $inFlight > 0
				? 'No import run is ready for another batch right now.'
				: 'No mail imports are waiting.');
		}

		$importer = new MailArchiveImporter($claimed);
		try {
			$state = (string)$claimed->get('mir_state');
			if ($state === MailImportRun::STATE_QUEUED || $state === MailImportRun::STATE_SCANNING) {
				return $this->scanPass($claimed, $importer, $scanSeconds);
			}
			return $this->importPass($claimed, $importer, $batchSize);
		} catch (Throwable $e) {
			// A run that cannot proceed fails with its reason recorded, rather than
			// being retried forever against a file that will never open.
			$importer->fail($e->getMessage());
			error_log('RunMailImports: run ' . $claimed->key . ' failed: ' . $e->getMessage());
			return array('status' => 'success',
				'message' => 'Import run #' . $claimed->key . ' failed: ' . $e->getMessage());
		} finally {
			// Hand the run back. The claim exists to stop two passes overlapping, not
			// to space passes out — an import should advance on every cron pass until
			// it is done. Only a pass that DIES leaves its claim standing, which is
			// exactly what the stale window is there to recover.
			$this->release(intval($claimed->key));
		}
	}

	/**
	 * Walk more of the archive. The run stops at `scanned` when the walk finishes —
	 * a genuine wait for the user, who is now choosing from exact counts rather
	 * than an estimate.
	 */
	private function scanPass(MailImportRun $run, MailArchiveImporter $importer, int $scanSeconds): array {
		if ((string)$run->get('mir_state') === MailImportRun::STATE_QUEUED) {
			$run->moveTo(MailImportRun::STATE_SCANNING);
		}

		$result = $importer->scanBatch(microtime(true) + $scanSeconds, self::SCAN_ENTRY_LIMIT);

		if (!$result['done']) {
			return array('status' => 'success', 'message' => 'Import run #' . $run->key . ': scanned '
				. $result['found'] . ' more message(s), ' . $run->get('mir_total_entries') . ' so far.');
		}

		// A container inside the container is reported rather than followed —
		// recursing is how a small archive becomes a full disk.
		$note = '';
		if (!empty($result['nested'])) {
			$note = ' Skipped ' . count($result['nested']) . ' nested archive(s): '
				. implode(', ', array_slice($result['nested'], 0, 5)) . '.';
			$run->set('mir_error', trim('Nested archives were not opened:' . $note));
		}

		$run->moveTo(MailImportRun::STATE_SCANNED);

		// The run now STOPS and waits for a decision only its owner can make. Tell
		// them, rather than relying on them remembering to come back to a page they
		// were invited to leave.
		self::announce('mail_import.scanned', $run, array(
			'found' => intval($run->get('mir_total_entries')),
		));

		return array('status' => 'success', 'message' => 'Import run #' . $run->key . ' scanned: '
			. $run->get('mir_total_entries') . ' message(s) found, waiting for the user to choose.' . $note);
	}

	/** Store one batch, and finish the run when there is nothing pending left. */
	private function importPass(MailImportRun $run, MailArchiveImporter $importer, int $batchSize): array {
		$counts = $importer->importBatch($batchSize);

		if (!empty($counts['exhausted'])) {
			$importer->finish();
			$run->load();
			self::announce('mail_import.finished', $run, array(
				'stored' => intval($run->get('mir_stored')),
				'failed' => intval($run->get('mir_failed')),
			));
			return array('status' => 'success', 'message' => 'Import run #' . $run->key . ' finished: '
				. $run->get('mir_stored') . ' stored, ' . $run->get('mir_dedup') . ' already present, '
				. $run->get('mir_skipped') . ' skipped, ' . $run->get('mir_failed') . ' failed.');
		}

		return array('status' => 'success', 'message' => 'Import run #' . $run->key . ': stored '
			. $counts['stored'] . ', duplicates ' . $counts['dedup'] . ', failed ' . $counts['failed']
			. ' this batch (' . $run->get('mir_processed') . '/' . $run->get('mir_total_entries') . ' done).');
	}

	/**
	 * Atomically take one active run, oldest claim first, and return it — or null
	 * when there is nothing to do. Stamping mir_claim_time inside the same UPDATE
	 * that selects the row is what makes it a claim: a concurrent pass updates zero
	 * rows and moves on.
	 *
	 * $slots is 0 when the concurrency cap is already met, which still allows an
	 * already-active run to continue — the cap limits how many imports are on the
	 * go, not how fast each one moves.
	 */
	private function claim(int $slots): ?MailImportRun {
		$db = DbConnector::get_instance()->get_db_link();

		$states = ($slots > 0)
			? MailImportRun::ACTIVE_STATES
			: array(MailImportRun::STATE_SCANNING, MailImportRun::STATE_IMPORTING);
		$in = "'" . implode("','", $states) . "'";

		$sql = "UPDATE mir_mail_import_runs SET mir_claim_time = now()
				WHERE mir_mail_import_run_id = (
					SELECT mir_mail_import_run_id FROM mir_mail_import_runs
					WHERE mir_state IN ($in)
					  AND mir_delete_time IS NULL
					  AND (mir_claim_time IS NULL
					       OR mir_claim_time < now() - INTERVAL '" . self::STALE_CLAIM_SECONDS . " seconds')
					ORDER BY mir_claim_time ASC NULLS FIRST, mir_mail_import_run_id ASC
					LIMIT 1
					FOR UPDATE SKIP LOCKED)
				RETURNING mir_mail_import_run_id";
		$stmt = $db->prepare($sql);
		$stmt->execute();
		$id = $stmt->fetchColumn();
		if ($id === false) {
			return null;
		}
		$run = new MailImportRun(intval($id), TRUE);
		return $run->key ? $run : null;
	}

	/**
	 * Tell the run's owner something happened to it.
	 *
	 * Targeted at exactly one person — the one who started it — rather than
	 * broadcast to a topic: an import is personal, and nobody else can answer the
	 * question it is asking. Delivery (in-app, email, both, neither) is the
	 * recipient's own preference to make, which is precisely why this goes through
	 * the signal bus instead of sending anything directly.
	 *
	 * Best-effort by construction: SignalBus::dispatch swallows its own failures,
	 * and a lost notification must never fail a run whose mail is already safe.
	 */
	private static function announce(string $signal, MailImportRun $run, array $extra): void {
		try {
			require_once(PathHelper::getIncludePath('includes/SignalBus.php'));
			require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_email_alias_class.php'));

			$mailbox = '';
			try {
				$alias = new InboundEmailAlias(intval($run->get('mir_iea_inbound_email_alias_id')), TRUE);
				if ($alias->key) {
					$mailbox = (string)$alias->get_full_address();
				}
			} catch (Throwable $e) {
				// A missing mailbox is worth reporting around, not staying silent over.
			}

			SignalBus::dispatch($signal, array_merge(array(
				'run_id'      => intval($run->key),
				'source_name' => (string)($run->get('mir_source_name') ?: 'the archive'),
				'mailbox'     => $mailbox,
				'recipients'  => array(intval($run->get('mir_usr_user_id'))),
			), $extra));
		} catch (Throwable $e) {
			error_log('RunMailImports: could not announce ' . $signal
				. ' for run ' . $run->key . ' — ' . $e->getMessage());
		}
	}

	/**
	 * Drop the claim so the next pass can pick the run straight back up. Never
	 * throws: failing to release would only cost this run its next half hour, and
	 * that must not turn into a failed task run.
	 */
	private function release(int $runId): void {
		try {
			DbConnector::get_instance()->get_db_link()
				->prepare('UPDATE mir_mail_import_runs SET mir_claim_time = NULL
					WHERE mir_mail_import_run_id = ?')
				->execute(array($runId));
		} catch (Throwable $e) {
			error_log('RunMailImports: could not release the claim on run ' . $runId . ' — ' . $e->getMessage());
		}
	}

	private function positive($value): int {
		$n = intval($value);
		return $n > 0 ? $n : 0;
	}

	private function truthy($value): bool {
		if (is_bool($value)) { return $value; }
		$v = strtolower(trim((string)$value));
		return in_array($v, array('1', 'true', 'yes', 'on'), true);
	}
}
?>
