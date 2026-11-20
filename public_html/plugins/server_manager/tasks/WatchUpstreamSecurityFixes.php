<?php
/**
 * WatchUpstreamSecurityFixes - which security fixes upstream has shipped that
 * Ubuntu has not, for every package the nodes run, and when Ubuntu catches up
 * with a package fix of ours (specs/package_fixes_ahead_of_ubuntu.md WP5).
 * The work is UpstreamFixWatch's; its conclusion becomes incidents through
 * IncidentSourceFixAheadOfUbuntu and IncidentSourcePackageFixUbuntuNewer.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class WatchUpstreamSecurityFixes implements ScheduledTaskInterface {

	public function run(array $config) {
		$r = UpstreamFixWatch::run();
		return array('status' => $r['status'], 'message' => $r['message']);
	}
}
