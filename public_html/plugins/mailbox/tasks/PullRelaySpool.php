<?php
/**
 * PullRelaySpool - Scheduled Task (relay spool pull consumer).
 *
 * The pull half of the hardened ingest relay's transport
 * (specs/inbound_email_hardened_ingest_relay_executor.md § Phase 4). Every cron
 * pass it dials out over WireGuard, rsyncs new sealed blobs off the relay spool
 * (copy-only), stores each durably (transport blobs open + ingest now;
 * Fortress blobs land pending-parse), and deletes the entries it durably stored
 * — the delete-after-store is the ack. Email tolerates the poll interval, so no
 * long-poll/push machinery is needed.
 *
 * No-op on colocated deployments (no active relay).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelaySpoolConsumer.php'));

class PullRelaySpool implements ScheduledTaskInterface {

	public function run(array $config) {
		$relay = MailboxRelay::active();
		if ($relay === null) {
			return array('status' => 'skipped', 'message' => 'No active relay (colocated deployment).');
		}

		$max = isset($config['max_per_run']) ? (int)$config['max_per_run'] : 0;
		if ($max <= 0) {
			$max = RelaySpoolConsumer::DEFAULT_MAX;
		}

		try {
			$consumer = new RelaySpoolConsumer($relay);
			$result = $consumer->pull($max);
		} catch (\Throwable $e) {
			error_log('PullRelaySpool: ' . $e->getMessage());
			return array('status' => 'error', 'message' => 'Relay pull failed: ' . $e->getMessage());
		}

		$status = ($result['status'] === 'error') ? 'error' : 'success';
		return array('status' => $status, 'message' => 'Relay spool: ' . $result['message']);
	}
}
?>
