<?php
/**
 * AdvanceStagedRollouts - carry a staged rollout from one node to the next.
 *
 * specs/agent_recipes_and_vocabulary.md, "staged_rollout". Each tick asks
 * StagedRolloutRunner to take one step on each running rollout: queue the
 * current node's apply, or judge the apply that finished. Cheap when nothing
 * is running (one indexed query).
 *
 * It also starts the rollout a "Deploy to all managed nodes" publish asked
 * for, once that publish has completed (StagedRolloutRunner::start_pending_deploy_all),
 * and the publish an "Apply and publish" apply is to be followed by, once that
 * apply has finished and succeeded (ApplyAndPublish::advanceAll).
 *
 * @version 1.2 - starts the publish that follows an Apply and publish apply
 * @version 1.1 - starts deploy-all rollouts for completed publishes
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class AdvanceStagedRollouts implements ScheduledTaskInterface {

	public function run(array $config) {
		$published = ApplyAndPublish::advanceAll();
		$started = StagedRolloutRunner::start_pending_deploy_all();
		$moved = StagedRolloutRunner::advance_all();
		$message = $moved ? "Advanced {$moved} staged rollout(s)." : 'No staged rollout is running.';
		if ($started) {
			$message = "Started the deploy-all rollout of a completed publish. " . $message;
		}
		if ($published) {
			$message = "Started the publish that follows an applied release. " . $message;
		}
		return array('status' => 'success', 'message' => $message);
	}
}
