<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

/**
 * PluginHealthReport — records the provisioning checks a plugin declares
 * `"fleet_report": true` (PluginProvisioning::recordFleetReport()).
 *
 * A management node learns a node's status from a report the node's agent
 * gathers out of the database without running PHP, so a plugin's own check
 * reaches it only as a recorded result. This is what records it. The node's
 * status carries the record as plugin_checks, and a check that does not pass
 * fails the node's health there.
 *
 * @version 1.0
 */
class PluginHealthReport implements ScheduledTaskInterface {

	public function run(array $config) {
		$report = PluginProvisioning::recordFleetReport();
		$failing = array();
		foreach ($report['checks'] as $check) {
			if ($check['state'] !== 'verified' && $check['state'] !== 'reachable') {
				$failing[] = $check['plugin'] . '/' . $check['key'] . ' ' . $check['state'];
			}
		}
		$message = count($report['checks']) . ' check' . (count($report['checks']) === 1 ? '' : 's') . ' recorded';
		if (count($failing)) {
			$message .= '; not passing: ' . implode(', ', $failing);
		}
		return array('status' => 'success', 'message' => $message);
	}
}
