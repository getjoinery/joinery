<?php
/**
 * PollImapAccounts - Scheduled Task
 *
 * The "pull" inbound transport's heartbeat. Each run polls every enabled IMAP
 * account whose own poll interval has elapsed, ingesting new mail into its bound
 * mailbox via ImapIngestor. The task frequency is the floor; the per-account
 * interval is the real cadence (self-throttling), so the task can run every cron
 * pass without hammering any one mailbox.
 *
 * Overlap guard: each account is claimed with an atomic conditional UPDATE that
 * stamps iia_last_poll_time on pickup, so two concurrent runs can't race on the
 * same account's last_seen_uid. Failures are per-account and non-fatal — one
 * unreachable mailbox or expired token never stops the rest.
 *
 * @version 1.1
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/inbound_imap_account_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapIngestor.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/ImapSyncer.php'));

class PollImapAccounts implements ScheduledTaskInterface {

	const DEFAULT_MAX_PER_ACCOUNT = 50;

	public function run(array $config) {
		// Global on/off lives in the task config (default on so activating the
		// task is enough). A falsy explicit value disables without deactivating.
		if (array_key_exists('polling_enabled', $config) && !$this->truthy($config['polling_enabled'])) {
			return array('status' => 'skipped', 'message' => 'IMAP polling is disabled in the task config.');
		}

		$maxPerAccount = isset($config['max_per_account']) ? (int)$config['max_per_account'] : 0;
		if ($maxPerAccount <= 0) {
			$maxPerAccount = self::DEFAULT_MAX_PER_ACCOUNT;
		}

		// Enabled + due accounts. The claim below re-checks dueness atomically.
		// An optional alias_id scopes the run to one alias's accounts — production
		// leaves it unset (poll everything); tests pass their fixture alias so a
		// run can never touch, connect to, or stamp a real account.
		$account_filters = array('enabled' => true, 'due' => true, 'deleted' => false);
		if (isset($config['alias_id'])) {
			$account_filters['alias_id'] = (int)$config['alias_id'];
		}
		$accounts = new MultiInboundImapAccount(
			$account_filters,
			array('iia_last_poll_time' => 'ASC')
		);
		$accounts->load();

		if (!count($accounts)) {
			return array('status' => 'success', 'message' => 'No IMAP accounts due for polling.');
		}

		$polled = 0; $stored = 0; $errors = 0; $skipped = 0; $failedMessages = 0;
		$messages = array();

		foreach ($accounts as $account) {
			if (!$this->claim($account->key)) {
				$skipped++; // another run claimed it first
				continue;
			}

			if (!$account->isConnectable()) {
				$account->recordStatus('Skipped: account is not fully credentialed (connect/authorize first).');
				$skipped++;
				continue;
			}

			$polled++;
			$ingestor = new ImapIngestor($account);
			try {
				if ($account->syncEnabled()) {
					// Two-way / read-only sync: run the whole cycle on one connection
					// (specs/two_way_imap_sync.md §7) — Pull → Ingest → Push.
					$syncer = new ImapSyncer($account, $ingestor);
					$syncer->prepare();                 // capabilities + folder discovery
					$syncer->pull();                    // flags + VANISHED (pull|both)
					$result = $ingestor->poll($maxPerAccount); // ingest, seeding ilm_ labels + Trash soft-deletes
					if ($account->isTwoWay()) {
						$syncer->push($maxPerAccount);  // STORE / COPY / MOVE / EXPUNGE / trash
					}
				} else {
					$result = $ingestor->poll($maxPerAccount);
				}
				$stored += intval($result['stored'] ?? 0);
				$failedMessages += intval($result['failed'] ?? 0);
				$messages[] = $this->describe($account) . ': ' . ($result['status'] ?? 'ok');
			} catch (Throwable $e) {
				$errors++;
				// recordStatus is credential-free by construction.
				$account->recordStatus('Fetch error: ' . substr($e->getMessage(), 0, 400));
				$messages[] = $this->describe($account) . ': ERROR ' . $e->getMessage();
				error_log('PollImapAccounts: account ' . $account->key . ' failed: ' . $e->getMessage());
			} finally {
				$ingestor->close();
			}
		}

		// Message-level failures are counted separately from account-level errors:
		// an account can connect fine and still lose individual messages. Per-run
		// detail lives in the run record (evl_event_logs, mailbox_imap_ingest).
		$summary = sprintf(
			'Fetched from %d account(s): %d message(s) stored, %d message(s) failed, %d account error(s), %d skipped.',
			$polled, $stored, $failedMessages, $errors, $skipped
		);
		if ($messages) {
			$summary .= ' ' . implode(' | ', $messages);
		}

		// Per-account failures are non-fatal: the run itself succeeded.
		return array('status' => 'success', 'message' => $summary);
	}

	/**
	 * Atomically claim an account for this run: stamp iia_last_poll_time only if
	 * it is still enabled and due. Returns true iff this run won the claim (1 row
	 * updated). A concurrent run that already claimed it updates 0 rows here.
	 */
	private function claim($accountId): bool {
		$db = DbConnector::get_instance()->get_db_link();
		$sql = "UPDATE iia_inbound_imap_accounts
				SET iia_last_poll_time = now()
				WHERE iia_inbound_imap_account_id = :id
				  AND iia_is_enabled = true
				  AND iia_delete_time IS NULL
				  AND (iia_last_poll_time IS NULL
				       OR iia_last_poll_time + (iia_poll_interval_seconds * INTERVAL '1 second') <= now())";
		$stmt = $db->prepare($sql);
		$stmt->execute(array(':id' => $accountId));
		return $stmt->rowCount() > 0;
	}

	private function describe($account): string {
		$label = $account->get('iia_label') ?: $account->get('iia_username');
		return '#' . $account->key . ' ' . $label;
	}

	private function truthy($value): bool {
		if (is_bool($value)) { return $value; }
		$v = strtolower(trim((string)$value));
		return in_array($v, array('1', 'true', 'yes', 'on'), true);
	}
}
?>
