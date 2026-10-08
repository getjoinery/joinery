<?php
/**
 * AutoApproveJoins - approve joins from machines this plane provisioned.
 *
 * the auto_approve_provisioned_joins spec. Each tick asks JoinAutoApproval to
 * approve every pending join whose address, name and key match what the plane saw
 * its own install produce, and whose instance the provider confirms. Anything else
 * stays in the dashboard's "Agents asking to join" for a person. Cheap when nothing
 * is pending (one indexed query).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class AutoApproveJoins implements ScheduledTaskInterface {

	public function run(array $config) {
		if (!JoinAutoApproval::enabled()) {
			return array('status' => 'success', 'message' => 'Automatic join approval is switched off.');
		}
		$n = JoinAutoApproval::run();
		return array('status' => 'success',
			'message' => $n ? "Approved {$n} join(s) from provisioned machines." : 'No join to approve automatically.');
	}
}
