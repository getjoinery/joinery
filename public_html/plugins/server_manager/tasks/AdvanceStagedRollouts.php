<?php
/**
 * AdvanceStagedRollouts - carry a staged rollout from one node to the next.
 *
 * specs/agent_recipes_and_vocabulary.md, "staged_rollout". Each tick asks
 * StagedRolloutRunner to take one step on each running rollout: queue the
 * current node's apply, or judge the apply that finished. Cheap when nothing
 * is running (one indexed query).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class AdvanceStagedRollouts implements ScheduledTaskInterface {

	public function run(array $config) {
		$moved = StagedRolloutRunner::advance_all();
		return array('status' => 'success',
			'message' => $moved ? "Advanced {$moved} staged rollout(s)." : 'No staged rollout is running.');
	}
}
