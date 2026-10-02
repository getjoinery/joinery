<?php
/**
 * ReconcileIncidents - open, refresh and clear the incidents this management
 * node's own detectors find (incident_triage.md WP2).
 *
 * Each tick asks IncidentReconciler to compare every registered source's
 * condition on every node with the incidents on record. Cheap: every source
 * reads stored columns, never probes.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class ReconcileIncidents implements ScheduledTaskInterface {

	public function run(array $config) {
		$c = IncidentReconciler::run();
		if ($c['busy']) {
			return array('status' => 'success', 'message' => 'Another pass is reconciling incidents; skipped.');
		}
		$parts = array();
		foreach (array('opened', 'reopened', 'cleared', 'refreshed') as $k) {
			if ($c[$k] > 0) {
				$parts[] = $c[$k] . ' ' . $k;
			}
		}
		return array('status' => 'success', 'message' => $parts ? 'Incidents: ' . implode(', ', $parts) . '.' : 'No incident changed.');
	}
}
