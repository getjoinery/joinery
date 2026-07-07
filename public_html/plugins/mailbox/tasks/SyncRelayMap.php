<?php
/**
 * SyncRelayMap - Scheduled Task (relay alias-map reconcile).
 *
 * The periodic half of the alias-map sync (specs/inbound_email_hardened_ingest_relay_executor.md
 * § Phase 3). Routing edits push immediately (RelayMapSync::onChange), but this
 * reconcile is the safety net: every cron pass it rebuilds the map from the
 * enabled domains/aliases and pushes it if it differs from what the relay last
 * received (a content-hash compare, so an unchanged map costs one DB read and no
 * SSH). This is what makes freshness beat the reject_unmatched gate even if an
 * immediate push failed.
 *
 * No-op on colocated deployments (no active relay).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/data/mailbox_relay_class.php'));
require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayMapSync.php'));

class SyncRelayMap implements ScheduledTaskInterface {

	public function run(array $config) {
		$relay = MailboxRelay::active();
		if ($relay === null) {
			return array('status' => 'skipped', 'message' => 'No active relay (colocated deployment).');
		}

		$force = array_key_exists('force', $config) && $this->truthy($config['force']);
		$result = RelayMapSync::push($relay, $force);

		$status = ($result['status'] === 'error') ? 'error' : 'success';
		return array('status' => $status, 'message' => 'Relay map: ' . $result['message']);
	}

	private function truthy($value): bool {
		if (is_bool($value)) { return $value; }
		$v = strtolower(trim((string)$value));
		return in_array($v, array('1', 'true', 'yes', 'on'), true);
	}
}
?>
