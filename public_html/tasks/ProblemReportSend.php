<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

/**
 * ProblemReportSend — retry problem reports that have not reached the upgrade
 * source yet.
 *
 * A report is sent once when the member presses Send. If that fails (the
 * upgrade source is down, the network is out) the row is left queued or
 * failed, and this hourly pass tries again, up to ProblemReport::MAX_ATTEMPTS
 * tries in all. After that the row stays failed with its reason, which the
 * admin Problem Reports page shows. Nothing is sent while the operator's
 * problem_reports_send switch is off.
 *
 * It is also how automatic reports leave (specs/implemented/bug_reports.md, Part 2): a new
 * fault goes as a report, and recurrences counted since the last send go as a
 * count the receiver adds to the report it holds.
 *
 * @version 1.1.0 - sends automatic reports and their counts
 * @version 1.0.0
 */
class ProblemReportSend implements ScheduledTaskInterface {
	public function run(array $config) {
		if (!ProblemReport::sendingEnabled()) {
			return array('status' => 'skipped', 'message' => 'Sending problem reports is switched off on this site.');
		}
		list($sent, $failed) = ProblemReport::sendDue();
		if ($sent === 0 && $failed === 0) {
			return array('status' => 'success', 'message' => 'No problem reports waiting to send.', 'run_chain' => false);
		}
		return array(
			'status'  => 'success',
			'message' => $sent . ' problem report(s) sent, ' . $failed . ' not sent yet.',
		);
	}
}
