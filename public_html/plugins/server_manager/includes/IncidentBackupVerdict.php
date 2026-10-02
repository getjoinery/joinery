<?php
/**
 * IncidentBackupVerdict — one node's fleet backup health, asked once per pass
 * for the three backup incidents (incident_triage.md WP3).
 *
 * The three backup sources (failed, stopped, unverified) are three views of
 * NodeMonitorHealth::fleet_backup_health(), the same judgement the dashboard's
 * backup panel shows, so the incidents and the panel cannot disagree. A node
 * the scheduler would not back up (FleetBackupPolicy::is_eligible), or whose
 * fleet backups somebody switched off, has no verdict: nothing to alarm on.
 *
 * Held for a few seconds per node, so one reconciler pass asks the job table
 * once per node rather than once per backup source.
 *
 * @version 1.0
 */
class IncidentBackupVerdict {

	const HOLD_SECONDS = 20;

	/** @var array<int, array{at:int, verdict:?array}> */
	private static $memo = array();

	/** The health result (with its kind), or null when backups are not this node's concern. */
	public static function for_node(ManagedNode $node): ?array {
		$id = (int)$node->key;
		if (isset(self::$memo[$id]) && time() - self::$memo[$id]['at'] < self::HOLD_SECONDS) {
			return self::$memo[$id]['verdict'];
		}
		$verdict = null;
		if (FleetBackupPolicy::is_eligible($node)) {
			$policy = FleetBackupPolicy::for_node($node);
			if (!empty($policy['enabled'])) {
				$verdict = NodeMonitorHealth::fleet_backup_health($node, $policy);
			}
		}
		self::$memo[$id] = array('at' => time(), 'verdict' => $verdict);
		return $verdict;
	}

	/**
	 * The incident for one kind, or null: the verdict's own label and words,
	 * with the run that failed when there is one.
	 */
	public static function incident(ManagedNode $node, string $kind, string $severity): ?array {
		$v = self::for_node($node);
		if ($v === null || empty($v['is_problem']) || ($v['kind'] ?? '') !== $kind) {
			return null;
		}
		$detail = array('What it found' => (string)$v['detail']);
		if (!empty($v['job_id'])) {
			$detail['The run'] = '/admin/server_manager/job_detail?job_id=' . (int)$v['job_id'];
		}
		return array('title' => (string)$v['label'], 'severity' => $severity, 'detail' => $detail);
	}

	/** Forget what was held (tests). */
	public static function forget(): void {
		self::$memo = array();
	}
}
?>
