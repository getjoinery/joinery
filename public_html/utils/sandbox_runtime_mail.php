<?php
/**
 * sandbox_runtime_mail.php - mail inside a sandboxed plugin's fence
 * (spec sandboxed_plugin_email). Loaded by utils/sandbox_runtime.php.
 *
 * The plugin writes mail code as native code does: EmailMessage,
 * EmailSender::send(), sendBatch(), quickSend(), sendTemplate(),
 * EmailMessage::fromTemplate(), EmailTemplateRenderer. Those are core's own
 * classes here too. EmailSender::$remote is set to this, so each send, and
 * each template fill, is an ask to the site, which runs it with the same
 * classes and every rule of its mail: its From and Reply-To defaults, the
 * protected identity domains, test mode, the retry queue. The site allows it
 * only when the plugin was approved to send mail (SandboxRelay::email()).
 *
 * A message goes as EmailMessage::toTransfer()'s data. A file attached by
 * path is read here, in the fence, so it can be only a file the plugin may
 * read (its own src/). A DateTime in a template's values crosses tagged
 * (DATE_TAG) and is a DateTime again on the site.
 *
 * EmailSender::remote() makes it at its first use, through
 * joinery_fence_mail_remote() (sandbox_runtime.php), however EmailSender's
 * file was loaded, by name or by a require of its path (reviewer2 P10); a
 * call that sends no mail loads none of it. What send() fills in on the
 * message (From, its name, Reply-To) is set on the plugin's message too, as
 * native code finds it after a send; test mode's rewriting of the recipients
 * and subject is not copied back.
 *
 * @version 1.1 - reviewer2 P4, P6, P7a, P10: dates cross, made at first use however EmailSender was loaded,
 *                send()'s defaults copied back
 * @version 1.0
 */

final class SandboxRemoteMail implements EmailRemote {

	/** The tag a date crosses in; SandboxRelay::DATE_TAG on the site's side. */
	const DATE_TAG = '__sbx_datetime';

	public function send(EmailMessage $message, bool $queue_on_failure): array {
		$answer = SandboxPipe::ask('email.send', array('message' => $message->toTransfer(), 'queue_on_failure' => $queue_on_failure));
		self::filledIn($message, $answer);
		return array('sent' => !empty($answer['sent']), 'report' => is_array($answer['report'] ?? null) ? $answer['report'] : array());
	}

	public function sendBatch(EmailMessage $message, array $recipients): array {
		$answer = SandboxPipe::ask('email.batch', array('message' => $message->toTransfer(), 'recipients' => $recipients));
		self::filledIn($message, $answer);
		return array('success' => !empty($answer['success']),
			'failed_recipients' => array_values((array)($answer['failed_recipients'] ?? array())));
	}

	public function render(string $inner, ?string $outer, $footer, array $values): array {
		try {
			$answer = SandboxPipe::ask('email.render', array('inner' => $inner, 'outer' => $outer, 'footer' => $footer,
				'values' => (object)self::tagDates($values)));
		} catch (SandboxPipeException $e) {
			// What the site's renderer throws for a template it cannot fill.
			throw new EmailTemplateError($e->getMessage());
		}
		return array(
			'subject' => isset($answer['subject']) ? (string)$answer['subject'] : null,
			'html' => isset($answer['html']) ? (string)$answer['html'] : null,
			'text' => isset($answer['text']) ? (string)$answer['text'] : null,
			'has_content' => !empty($answer['has_content']),
			'set_values' => $answer['set_values'] ?? null,
		);
	}

	/** The site's defaults that send() set on its copy, set on the plugin's message as native send() sets them. */
	private static function filledIn(EmailMessage $message, array $answer): void {
		if (!$message->getFrom() && is_string($answer['from'] ?? null) && $answer['from'] !== '') {
			$message->from($answer['from'], is_string($answer['from_name'] ?? null) ? $answer['from_name'] : null);
		}
		if (!$message->getReplyTo() && is_string($answer['reply_to'] ?? null) && $answer['reply_to'] !== '') {
			$message->replyTo($answer['reply_to']);
		}
	}

	/** Each DateTime in the values as a tagged array, which JSON can carry. */
	private static function tagDates(array $values): array {
		foreach ($values as $key => $value) {
			if ($value instanceof DateTimeInterface) {
				$values[$key] = array(self::DATE_TAG => $value->format('Y-m-d\TH:i:s.u'), 'tz' => $value->getTimezone()->getName());
			} elseif (is_array($value)) {
				$values[$key] = self::tagDates($value);
			}
		}
		return $values;
	}

}
