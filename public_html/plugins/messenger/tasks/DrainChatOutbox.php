<?php
/**
 * DrainChatOutbox - Scheduled Task
 *
 * The retry half of cross-instance chat.
 *
 * A message to someone on another Joinery instance is attempted the moment it
 * is sent, while the member is watching, so the ticks mean something. What that
 * first attempt cannot do is wait: if the far instance is restarting, the
 * sender should not be held on a spinner, and the message should not be lost.
 * So an attempt that failed for a reason that might change is queued with a
 * doubling backoff, and this drains that queue.
 *
 * A REFUSAL IS NOT RETRIED. "This person does not accept chat from you" is an
 * answer, not an outage, and asking again every hour would be both pointless
 * and a way to hammer someone who has declined. Those are marked failed at the
 * first attempt and never enter the queue.
 *
 * Reactions and deletes are deliberately absent: they correct something already
 * delivered, and a missing reaction chip does not justify a second queue.
 *
 * @version 1.0
 */

require_once(PathHelper::getIncludePath('includes/ScheduledTaskInterface.php'));
require_once(PathHelper::getIncludePath('data/conversations_class.php'));
require_once(PathHelper::getIncludePath('data/messages_class.php'));
require_once(PathHelper::getIncludePath('plugins/messenger/includes/MessengerFederation.php'));

class DrainChatOutbox implements ScheduledTaskInterface {

	/** Messages attempted per pass when nothing is configured. */
	const DEFAULT_BATCH_SIZE = 50;

	public function run(array $config) {
		if (!MessengerFederation::available()) {
			return array(
				'status'  => 'skipped',
				'message' => 'Cross-site chat is not set up on this deployment.',
			);
		}

		$batch = (int)($config['batch_size'] ?? 0);
		if ($batch <= 0) {
			$batch = self::DEFAULT_BATCH_SIZE;
		}

		$queued = new MultiMessage(
			array('delivery_state' => Message::DELIVERY_QUEUED, 'delivery_due' => true),
			array('msg_message_id' => 'ASC'),
			$batch
		);

		$attempted = 0;
		$delivered = 0;
		$still_queued = 0;
		$failed = 0;

		foreach ($queued as $message) {
			$conversation_id = (int)$message->get('msg_cnv_conversation_id');
			if ($conversation_id <= 0) {
				continue;
			}
			$conversation = new Conversation($conversation_id, TRUE);
			if (!$conversation->key || $conversation->get('cnv_delete_time')) {
				// The conversation is gone; there is nothing left to deliver to.
				$message->set('msg_delivery_state', Message::DELIVERY_FAILED);
				$message->set('msg_delivery_next_try', null);
				$message->save();
				$failed++;
				continue;
			}

			$attempted++;
			// A protected conversation's body can only be read while one of its
			// members is present, and nobody is at 3am. Such a message simply
			// waits — the next attempt after someone signs in will find the key.
			$state = MessengerFederation::sendMessage($conversation, $message);

			if ($state === Message::DELIVERY_DELIVERED) {
				$delivered++;
			} elseif ($state === Message::DELIVERY_FAILED) {
				$failed++;
			} else {
				$still_queued++;
			}
		}

		if ($attempted === 0) {
			return array('status' => 'success', 'message' => 'Nothing waiting to send.');
		}

		return array(
			'status'  => 'success',
			'message' => 'Attempted ' . $attempted . ': ' . $delivered . ' delivered, '
				. $still_queued . ' still queued, ' . $failed . ' given up on.',
		);
	}
}
