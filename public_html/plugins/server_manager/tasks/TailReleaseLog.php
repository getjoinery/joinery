<?php
/**
 * TailReleaseLog - reads the public logs for entries under our release
 * statement keys (spec release_transparency, O5). The work is
 * ReleaseLogTail's; what it finds becomes incidents through
 * IncidentSourceReleaseLogEntry and IncidentSourceReleaseLogTailBlind.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class TailReleaseLog implements ScheduledTaskInterface {

	public function run(array $config) {
		return ReleaseLogTail::run();
	}
}
