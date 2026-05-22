<?php
/**
 * SendQueuedEmails - Scheduled Task
 *
 * Sends bulk emails that are queued and past their scheduled date,
 * retries failed transactional emails with retry count tracking, and
 * sends intentionally-queued emails (e.g. notification hook emails).
 *
 * @version 2.1
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('data/emails_class.php'));
require_once(PathHelper::getIncludePath('data/queued_email_class.php'));

class SendQueuedEmails implements ScheduledTaskInterface {

	public function run(array $config) {
		$parts = [];

		// --- Pass 1: Send scheduled campaign/bulk emails (existing logic) ---
		$campaign_queued = new MultiEmail(array('scheduleddate' => MultiEmail::SCHEDULED_PAST, 'status' => Email::EMAIL_QUEUED));
		$campaign_count = $campaign_queued->count_all();

		if ($campaign_count > 0) {
			if (!defined('SCHEDULED_TASK_CONTEXT')) {
				define('SCHEDULED_TASK_CONTEXT', true);
			}

			ob_start();
			require(PathHelper::getIncludePath('adm/admin_emails_send.php'));
			$output = ob_get_clean();

			$parts[] = 'Processed ' . $campaign_count . ' queued email(s). ' . trim(strip_tags($output));
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

		// --- Pass 3: Send intentionally-queued emails (e.g. notification hook emails) ---
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
