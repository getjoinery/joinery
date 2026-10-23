<?php
/**
 * mail_apps/mail_submit — send what a mail program submitted (SMTP
 * submission): {message (base64), mail_from, rcpt_to[]}.
 *
 *   1. The From must be this mailbox (MailboxSender refuses anything else).
 *   2. The message is carried over part for part and sent the way the web
 *      reader sends (MailboxSender::sendRaw()): in-app DKIM, the mailbox's
 *      transport, the hidden-origin rules, send protection, the attempt
 *      record, one stored Sent row sealed where the mailbox is. The gateway
 *      never relays mail itself.
 *   3. Reply marks: a forward's original (X-Forwarded-Message-Id, asked first,
 *      since a forward names its original in In-Reply-To too) gets
 *      $Forwarded; otherwise the message it replies to gets \Answered.
 *      Thunderbird stores these itself after sending; other programs may not.
 *
 * Refusals carry the SMTP reply the gateway gives: 454 4.7.0 when the
 * mailbox's signing key waits on the lock (a temporary failure: Thunderbird
 * shows the reason and keeps the message open to send again), 550 5.7.1 for a From that is not
 * this mailbox, 552 for size, 554 5.6.0 for a message signed or encrypted as
 * a whole, 451 4.3.0 for a send that could not go now.
 *
 * @version 1.0.1 - From is read through MailHeaderText, which keeps raw UTF-8 (B30)
 * @version 1.0.0
 */

function mail_submit_logic(array $input): LogicResult {
	return MailAppAction::run($input, function (MailAppContext $ctx, array $input) {
		// The message arrives base64 in JSON and is parsed and stored: held several
		// times over for the largest mail a mailbox takes.
		@ini_set('memory_limit', '384M');
		$raw = base64_decode((string)$input['message'], true);
		if ($raw === false || $raw === '') {
			throw new MailAppRefusal('That message did not arrive whole.', 'CLIENTBUG', '554 5.6.0');
		}
		if (strlen($raw) > MailboxSender::MAX_TOTAL_BYTES + 2097152) {
			throw new MailAppRefusal('This message is larger than the 25 MB this mailbox sends.', 'CANNOT', '552 5.3.4');
		}
		$from = MailAppMutations::headerValue($raw, 'from');
		$from_list = MailAddressList::parse(MailHeaderText::decode((string)$from));
		if (count($from_list) !== 1 || strtolower($from_list[0]['email']) !== $ctx->address) {
			throw new MailAppRefusal('This message is not from ' . $ctx->address . ', so it cannot be sent as this mailbox.',
				'CANNOT', '550 5.7.1');
		}
		$type = strtolower(MailAppMutations::headerValue($raw, 'content-type'));
		if (preg_match('~^(multipart/(signed|encrypted)|application/(x-)?pkcs7-mime)~', $type)) {
			throw new MailAppRefusal('This mailbox signs everything it sends as itself, which would break a message '
				. 'signed or encrypted as a whole (PGP/MIME or S/MIME). Send it without that, or use inline PGP.',
				'CANNOT', '554 5.6.0');
		}

		$sender = new MailboxSender($ctx->viewer);
		try {
			$sent = $sender->sendRaw($ctx->alias, $raw, (string)$input['mail_from'], (array)$input['rcpt_to']);
		} catch (MailboxLockedException $e) {
			throw new MailAppRefusal('Mailbox locked - unlock at ' . $ctx->unlockUrl(), 'UNAVAILABLE', '454 4.7.0');
		} catch (MailboxSenderException $e) {
			// Refused as it is (shape, size, recipients): permanent, so the program
			// does not retry it. Anything else may go through later.
			throw new MailAppRefusal($e->getMessage(), 'CANNOT',
				$e->getCode() === MailboxSenderException::REFUSED ? '554 5.6.0' : '451 4.3.0');
		}

		// The message has left. Nothing after this point may fail the
		// submission: an error here answered as "try again" would have the
		// person send it twice. The bookkeeping is logged and skipped instead.
		try {
			// The Message-ID the program sent under, when the Sent row keeps another
			// one: its copy to Sent is then still recognised as this message.
			$theirs = trim(MailAppMutations::headerValue($raw, 'message-id'));
			if ($theirs !== '' && $theirs !== $sent['message_id'] && intval($sent['outbound_id']) > 0) {
				DbConnector::get_instance()->get_db_link()->prepare('INSERT INTO mam_mail_app_messages
						(mam_iem_inbound_email_message_id, mam_submitted_message_id) VALUES (?, ?)
					ON CONFLICT (mam_iem_inbound_email_message_id) DO UPDATE SET mam_submitted_message_id = EXCLUDED.mam_submitted_message_id')
					->execute(array(intval($sent['outbound_id']), substr($theirs, 0, 255)));
			}

			// Reply marks on the original. A forward names its original in
			// X-Forwarded-Message-Id AND in In-Reply-To, so it is asked first: a
			// forward is never marked answered.
			$forwarded = MailAppMutations::headerValue($raw, 'x-forwarded-message-id') !== '';
			$marks = $forwarded
				? array('x-forwarded-message-id' => array(null, true))
				: array('in-reply-to' => array(true, null));
			foreach ($marks as $header => $mark) {
				$value = MailAppMutations::headerValue($raw, $header);
				if (!preg_match('/<[^<>]+>/', $value, $m)) {
					continue;
				}
				$stmt = DbConnector::get_instance()->get_db_link()->prepare("SELECT iem_inbound_email_message_id
					FROM iem_inbound_email_messages WHERE iem_message_id_header = ? AND iem_iea_inbound_email_alias_id = ?
					  AND iem_delete_time IS NULL AND iem_direction IS DISTINCT FROM 'draft'");
				$stmt->execute(array(substr($m[0], 0, 255), $ctx->alias_id));
				$ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
				if ($ids) {
					MailAppMutations::setReplyMarks($ids, $mark[0], $mark[1]);
				}
			}
		} catch (\Throwable $e) {
			error_log('mail_apps/mail_submit: sent, but the bookkeeping after it failed: ' . $e->getMessage());
		}

		$out = array('message_id' => $sent['message_id'], 'stored' => intval($sent['outbound_id']) > 0);
		if (!empty($sent['warning'])) {
			$out['warning'] = $sent['warning'];
		}
		return LogicResult::render($out);
	});
}

function mail_submit_logic_descriptor() {
	return MailAppAction::descriptor('Send a message a mail program submitted, as this mailbox', array(
		'message'   => array('type' => 'string', 'required' => true, 'label' => 'Message (base64)'),
		'mail_from' => array('type' => 'string', 'required' => false, 'label' => 'Envelope sender', 'max_length' => 320),
		'rcpt_to'   => array('type' => 'array', 'required' => true, 'label' => 'Envelope recipients', 'max_items' => 500,
			'items' => array('type' => 'string', 'max_length' => 320)),
	));
}
?>
