<?php
/**
 * RebootWhenQuiet - reboot a machine that owes a reboot when nobody is busy
 * on it (specs/automatic_reboot_when_quiet.md WP2). The logic is
 * RebootWhenQuietRunner::tick(); cheap when nothing owes a reboot.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class RebootWhenQuiet implements ScheduledTaskInterface {

	public function run(array $config) {
		return array('status' => 'success', 'message' => RebootWhenQuietRunner::tick());
	}
}
