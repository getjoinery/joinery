<?php
/**
 * IncidentSourceDataRootLocked — plane:data_root_locked: the machine answers,
 * and says its encrypted data root is waiting for its key
 * (specs/hosted_data_promise_disk_encryption.md D2b, T-U2).
 *
 * Reads what RunNodeUptimeChecks stored when a probe got 503 with
 * X-Joinery-Locked: mgn_uptime_last_status is 'locked'. Critical: visitors
 * cannot reach the site until it is unlocked. Where this management node
 * holds the machine's key, the unlock is here, on the node's page, and the
 * incident says so; the machine's own page is named only where this holder
 * does not hold it, since a machine in someone else's hands could serve a page
 * that takes the whole recovery key.
 *
 * Not while the data root was stopped by hand: a stopped encrypted data root
 * reads as locked, and that is IncidentSourceDataRootStopped, a warning.
 *
 * @version 1.1 - waits, up to IncidentSourceDataRootStopped::HOLD_SECONDS, for the machine's host report it asked
 *                for, then stands down while the data root was stopped by hand (reviewer2 B17 D2)
 * @version 1.0
 */
class IncidentSourceDataRootLocked implements IncidentSource {

	const NAME = 'plane:data_root_locked';

	public function name(): string {
		return self::NAME;
	}

	public function evaluate(ManagedNode $node): ?array {
		if (!$node->get('mgn_enabled') || !$node->get('mgn_uptime_enabled')) {
			return null;
		}
		if ((string)$node->get('mgn_uptime_last_status') !== 'locked') {
			return null;
		}
		if (IncidentSourceDataRootStopped::stopped_for($node) !== null
				|| IncidentSourceDataRootStopped::awaiting_report($node, 'mgn_uptime_locked_since')) {
			return null;
		}
		$detail = array();
		$url = trim((string)$node->get('mgn_site_url'));
		if ($url !== '') {
			$detail['Site'] = $url;
		}
		$since = trim((string)$node->get('mgn_uptime_locked_since'));
		if ($since !== '') {
			$detail['Locked since'] = $since . ' UTC';
		}
		$held = DataRootEscrow::for_node((int)$node->key);
		if ($held && (string)$held->get('dre_status') !== 'retired') {
			$detail['Unlock it'] = 'on this node\'s page, Overview, Encrypted data root (/admin/server_manager/node_detail?mgn_managed_node_id='
				. (int)$node->key . '): this management node holds its key, so the recovery key never reaches the machine';
			if ($held->get('dre_refusal')) {
				$detail['Why this holder did not open it'] = (string)$held->get('dre_refusal');
			}
		} else {
			$detail['Unlock it'] = 'at the machine\'s own page' . ($url !== '' ? ' (' . $url . ')' : '') . ' or its console, with the recovery key: this management node does not hold its key';
		}
		return array(
			'title'    => 'Its encrypted data root is locked, waiting for its key',
			'severity' => IncidentRecord::SEVERITY_CRITICAL,
			'detail'   => $detail,
		);
	}

	public function cleared_text(ManagedNode $node): string {
		if (!$node->get('mgn_enabled') || !$node->get('mgn_uptime_enabled')) {
			return 'Uptime monitoring for this node was turned off, so whether it is locked is no longer checked.';
		}
		if (IncidentSourceDataRootStopped::stopped_for($node) !== null) {
			return 'Its data root was stopped by hand, which its machine\'s incident says; it opens when it is started again.';
		}
		return 'Its data root is open and it answers again.';
	}
}
?>
