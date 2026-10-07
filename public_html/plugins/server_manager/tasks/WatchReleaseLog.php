<?php
/**
 * WatchReleaseLog - whether the next release can still be logged on
 * Sigstore's public log (spec release_transparency, O6, WP3). The work is
 * ReleaseLogWatch's; its conclusion becomes incidents through
 * IncidentSourceReleaseLog and IncidentSourceReleaseLogBlind.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class WatchReleaseLog implements ScheduledTaskInterface {

	public function run(array $config) {
		return ReleaseLogWatch::run();
	}
}
