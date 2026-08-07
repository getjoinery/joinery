<?php
require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarEmailEngine.php'));

/**
 * Sends calendar reminder emails (at each member's chosen lead before an
 * entry starts) and daily/weekly calendar summary emails. Every behavior
 * lives in CalendarEmailEngine; this wrapper reports results and writes the
 * run record. Runs every cron tick — 5-minute leads need minute resolution —
 * and sends nothing until a member opts in on /profile/calendar_settings.
 *
 * @version 1.0
 */
class CalendarEmails implements ScheduledTaskInterface, ScheduledTaskDryRunnable {

	public function run(array $config) {
		$engine = new CalendarEmailEngine();
		$result = $engine->run(false);

		$sent = $result['reminders'] + $result['summaries'];
		$message = 'Sent ' . $result['reminders'] . ' reminder(s) and ' . $result['summaries'] . ' summary email(s).';

		if ($sent > 0) {
			$this->logRun($message);
		}

		return array('status' => 'success', 'message' => $message);
	}

	public function dryRun(array $config) {
		$engine = new CalendarEmailEngine();
		$result = $engine->run(true);

		$message = 'Would send ' . $result['reminders'] . ' reminder(s) and '
			. $result['summaries'] . ' summary email(s) right now.';

		$html = '';
		if (!empty($result['preview'])) {
			$html = '<ul>';
			foreach ($result['preview'] as $line) {
				$html .= '<li>' . htmlspecialchars($line) . '</li>';
			}
			$html .= '</ul>';
		}

		return array('status' => 'success', 'message' => $message, 'html' => $html);
	}

	/** Run record in the generic event log — only when something was sent. */
	private function logRun(string $note): void {
		try {
			require_once(PathHelper::getIncludePath('data/event_logs_class.php'));
			$log = new EventLog(NULL);
			$log->set('evl_event', 'calendar_emails_run');
			$log->set('evl_was_success', true);
			$log->set('evl_note', $note);
			$log->save();
		} catch (Throwable $e) {
			error_log('calendar_emails_run: could not write the run record — ' . $e->getMessage());
		}
	}
}
