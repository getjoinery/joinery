<?php
/**
 * SendQueuedEmails - Scheduled Task
 *
 * Sends waiting direct messages (DirectMessage), retries failed
 * transactional emails with retry count tracking, and sends
 * intentionally-queued emails (e.g. notification emails queued by Notify).
 * Campaigns are the email_lists plugin's SendCampaigns task.
 *
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

		// --- Pass 2: Retry failed transactional emails ---
		$max_retries = intval($config['max_retries'] ?? 3);

		$failed_emails = new MultiQueuedEmail(
			array('multi_status' => array(QueuedEmail::ERROR_SENDING, QueuedEmail::NORMAL_MAILER_ERROR)),
			array('equ_queued_email_id' => 'ASC'),
			50
		);

		$retry_total = $failed_emails->count_all();
		$sent = 0;
		$permanent = 0;

		if ($retry_total > 0) {
			$failed_emails->load();

			foreach ($failed_emails as $email) {
				$retries = intval($email->get('equ_retry_count'));

				if ($retries >= $max_retries) {
					$email->set('equ_status', QueuedEmail::PERMANENT_FAILURE);
					$email->save();
					$permanent++;
					continue;
				}

				// Set to READY_TO_SEND so send() accepts it, pass false to prevent re-queuing
				$email->set('equ_status', QueuedEmail::READY_TO_SEND);
				$email->save();

				try {
					$email->send(false);
				} catch (Exception $e) {
					// send() already sets ERROR_SENDING on failure
				}

				$email->load();
				if ($email->get('equ_status') == QueuedEmail::SENT) {
					$sent++;
				} else {
					$email->set('equ_retry_count', $retries + 1);
					$email->save();
				}
			}

			$parts[] = "Retried $retry_total failed: $sent sent, $permanent permanent failures";
		}

		// --- Pass 3: Send intentionally-queued emails (e.g. notification emails queued by Notify) ---
		$ready = new MultiQueuedEmail(
			array('multi_status' => array(QueuedEmail::READY_TO_SEND)),
			array('equ_queued_email_id' => 'ASC'),
			100
		);
		$ready_total = $ready->count_all();

		if ($ready_total > 0) {
			$ready->load();
			$ready_sent = 0;

			foreach ($ready as $email) {
				try {
					$email->send(false);
				} catch (Exception $e) {
					// send() sets ERROR_SENDING on failure; Pass 2 retries it next run.
				}

				$email->load();
				if ($email->get('equ_status') == QueuedEmail::SENT) {
					$ready_sent++;
				}
			}

			$parts[] = "Sent $ready_sent of $ready_total queued notification email(s)";
		}

		if (empty($parts)) {
			$parts[] = 'No queued or failed emails to process';
		}

		return array('status' => 'success', 'message' => implode('. ', $parts));
	}
}
