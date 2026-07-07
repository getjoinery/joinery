<?php
/**
 * NotifiesRelayMapOnChange - data-layer hook that keeps the relay alias map fresh.
 *
 * (specs/mailbox_relay_fix_pack.md § Fix 7). A newly created alias on a
 * reject_unmatched domain would bounce 554 (permanent, no retry) until the next
 * periodic reconcile if the relay map were only pushed on a timer. Hooking at the
 * data layer — not in individual admin logic files — means EVERY write path (admin
 * UI, API, AI surface) triggers an immediate best-effort push.
 *
 * Applied to the classes whose state feeds RelayMapExporter::build():
 * InboundEmailAlias, InboundEmailDomain, and InboundEmailMailboxGrant (the
 * single-owner source that decides a Fortress seal target).
 *
 * save() covers create, update, and soft_delete (which calls save() internally);
 * permanent_delete() covers the hard-delete path. RelayMapSync::onChange() no-ops
 * when there is no relay and push() hash-skips an unchanged map — so this is cheap
 * on saves that do not affect routing, and best-effort on network failure with the
 * SyncRelayMap reconcile as the backstop.
 *
 * @version 1.0
 */

trait NotifiesRelayMapOnChange {

	public function save($debug = false) {
		$result = parent::save($debug);
		self::notifyRelayMapChange();
		return $result;
	}

	public function permanent_delete($debug = false) {
		$result = parent::permanent_delete($debug);
		self::notifyRelayMapChange();
		return $result;
	}

	private static function notifyRelayMapChange(): void {
		try {
			require_once(PathHelper::getIncludePath('plugins/mailbox/includes/RelayMapSync.php'));
			RelayMapSync::onChange();
		} catch (\Throwable $e) {
			error_log('NotifiesRelayMapOnChange: ' . $e->getMessage());
		}
	}
}
