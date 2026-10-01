<?php
/**
 * AdvanceSiteCopies - carry a site copy from one agent job to the next.
 *
 * specs/site_copy.md WP8. Each tick asks SiteCopyRunner to take one step on
 * each copy that is waiting for its server or in a copy run. Cheap when there
 * is none (two indexed queries).
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class AdvanceSiteCopies implements ScheduledTaskInterface {

	public function run(array $config) {
		$moved = SiteCopyRunner::advance_all();
		return array('status' => 'success',
			'message' => $moved ? "Advanced {$moved} site copy(ies)." : 'No site copy is waiting or copying.');
	}
}
