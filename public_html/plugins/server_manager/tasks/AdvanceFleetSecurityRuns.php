<?php
/**
 * AdvanceFleetSecurityRuns - carry an Install security updates everywhere run
 * from one node to the next (specs/fleet_security_patching.md WP5).
 *
 * Each tick asks FleetSecurityRunner to take a step on the running run: queue
 * the current node's install_security_updates, or judge the one that
 * finished. Cheap when nothing is running (one indexed query).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class AdvanceFleetSecurityRuns implements ScheduledTaskInterface {

	public function run(array $config) {
		$moved = FleetSecurityRunner::advance_all();
		return array('status' => 'success', 'message' => $moved ? "Advanced {$moved} fleet security run(s)." : 'No fleet security run is under way.');
	}
}
