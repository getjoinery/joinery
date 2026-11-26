<?php
/**
 * SendQueuedEmails - Scheduled Task
 *
 * Sends waiting direct messages (DirectMessage), then works the outbox
 * (equ_queued_emails, specs/email_outbox.md): settles rows a dead process left
 * Locked, ends rows that have used their tries, retries failed emails and
 * sends queued ones. Every status change is QueuedEmail's.
 * Campaigns are the email_lists plugin's SendCampaigns task.
 *
 * @version 3.0 - the outbox: expire_stale(), give_up() and claim() write every status change; a retry is sent whole
 * @version 2.5 - a send that throws leaves its row at Error sending (QueuedEmail::send), never Locked; any Throwable is caught per row, so one bad row never ends the batch
 * @version 2.4 - campaigns moved to the email_lists plugin's SendCampaigns task
 * @version 2.3 - sends waiting direct messages first
 * @version 2.2
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));

class SendQueuedEmails implements ScheduledTaskInterface {

	public function run(array $config) {
		$parts = [];

		// --- Direct messages: an admin's message to a member, a group or an event's people ---
		$direct = DirectMessage::send_pending();
		if ($direct['sent'] + $direct['failed'] > 0) {
			$parts[] = 'Direct messages: ' . $direct['sent'] . ' sent, ' . $direct['failed'] . ' not sent';
		}

		$max_retries = intval($config['max_retries'] ?? 3);

		// --- Rows a process left Locked when it died ---
		$stale = QueuedEmail::expire_stale();
		if ($stale > 0) {
			$parts[] = "$stale stale email(s) settled";
		}

		// --- Rows that have used their tries ---
		$permanent = QueuedEmail::give_up($max_retries);

		// --- Retry failed emails ---
		$failed_emails = new MultiQueuedEmail(
			array('multi_status' => array(QueuedEmail::ERROR_SENDING)),
			array('equ_queued_email_id' => 'ASC'),
			50
		);
		$retry_total = $failed_emails->count_all();
		if ($retry_total > 0 || $permanent > 0) {
			$sent = 0;
			foreach ($failed_emails as $email) {
				try {
					if ($email->send(false)) {
						$sent++;
					}
				} catch (Throwable $e) {
					// The row is at Error sending (or already claimed by another run).
				}
			}
			$parts[] = "Retried $retry_total failed: $sent sent, $permanent permanent failures";
		}

		// --- Send queued emails (e.g. notification emails queued by Notify) ---
		$ready = new MultiQueuedEmail(
			array('multi_status' => array(QueuedEmail::READY_TO_SEND)),
			array('equ_queued_email_id' => 'ASC'),
			100
		);
		$ready_total = $ready->count_all();

		if ($ready_total > 0) {
			$ready_sent = 0;
			foreach ($ready as $email) {
				try {
					if ($email->send(false)) {
						$ready_sent++;
					}
				} catch (Throwable $e) {
					// The row is at Error sending; the retry pass takes it next run.
				}
			}
			$parts[] = "Sent $ready_sent of $ready_total queued email(s)";
		}

		if (empty($parts)) {
			$parts[] = 'No queued or failed emails to process';
		}

		return array('status' => 'success', 'message' => implode('. ', $parts));
	}
}
