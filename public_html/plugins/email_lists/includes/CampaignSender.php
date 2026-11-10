<?php
/**
 * CampaignSender — sends queued campaign emails, one recipient at a time.
 *
 * The SendCampaigns task calls send_due() every run; the "Send queued emails"
 * and "Send test" buttons on the campaign admin pages call send_due() and
 * send_test(). Each recipient is checked again at send time (spec
 * email_lists_plugin B6): someone who left the campaign's list, or
 * unsubscribed from its contact type, since it was queued is not sent to.
 * A recipient is marked sent only after the provider took the message, and a
 * second pass skips anyone already sent, so a pass that stops part way
 * resumes where it stopped. On the included email nothing is queued or sent
 * (CampaignRules); a test send to yourself still is.
 *
 * @version 1.0 - from the adm/admin_emails_send.php page
 */
class CampaignSender {

	/**
	 * Send every queued campaign whose scheduled time has passed.
	 *
	 * @return array{emails:int, sent:int, failed:int, skipped:int, held:int}
	 */
	public static function send_due(): array {
		$totals = array('emails' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'held' => 0);
		$emails = new MultiEmail(array('scheduleddate' => MultiEmail::SCHEDULED_PAST, 'status' => Email::EMAIL_QUEUED));
		// Queued before the site moved back to included email: held, not sent,
		// and its page says why (spec email_lists_plugin §5).
		if (CampaignRules::refusal() !== null) {
			foreach ($emails as $email) {
				if ($email->get('eml_held_reason') !== CampaignRules::HELD) {
					$email->set('eml_held_reason', CampaignRules::HELD);
					$email->save();
				}
				$totals['held']++;
			}
			return $totals;
		}
		foreach ($emails as $email) {
			$result = self::send_email($email);
			$totals['emails']++;
			foreach (array('sent', 'failed', 'skipped') as $k) {
				$totals[$k] += $result[$k];
			}
		}
		return $totals;
	}

	/**
	 * Send one campaign to its recipients not yet sent, then mark it sent.
	 *
	 * @return array{sent:int, failed:int, skipped:int}
	 */
	public static function send_email(Email $email): array {
		$refusal = CampaignRules::refusal();
		if ($refusal !== null) {
			throw new CampaignRefusedException($refusal);
		}
		if ($email->get('eml_held_reason')) {
			$email->set('eml_held_reason', null);
		}
		list($list, $list_name) = self::list_of($email);
		$contact_type_id = $email->get('eml_ctt_contact_type_id');
		$out = array('sent' => 0, 'failed' => 0, 'skipped' => 0);

		foreach (new MultiEmailRecipient(array('email_id' => $email->key, 'sent' => FALSE)) as $recipient) {
			// Another pass may have sent this one since the list was read.
			$fresh = new EmailRecipient($recipient->key, TRUE);
			if ($fresh->is_sent() || (int)$fresh->get('erc_status') === EmailRecipient::UNSUBSCRIBED) {
				continue;
			}

			$user_id = $recipient->get('erc_usr_user_id');
			$user = $user_id ? new User($user_id, TRUE) : null;

			// Still wanted, now? (B6)
			// Consented, not only on the list: an address changed without proof since
			// queueing has not confirmed, and the send would go to it.
			$left_list = $list && $user && !$list->is_consented($user->key);
			$left_type = $contact_type_id && $user && $user->is_unsubscribed_to_contact_type($contact_type_id);
			if ($left_list || $left_type) {
				$recipient->set('erc_status', EmailRecipient::UNSUBSCRIBED);
				$recipient->save();
				$out['skipped']++;
				continue;
			}

			$address = $user ? $user->get('usr_email') : $recipient->get('erc_email');
			$name = $user ? $user->display_name() : $recipient->get('erc_name');
			$data = $user ? $user->export_as_array() : array(
				'usr_email' => $recipient->get('erc_email'),
				'usr_first_name' => $recipient->get('erc_name'),
			);

			$sender = new EmailSender();
			$ok = (bool)$sender->send(self::build_message($email, $data, $address, $name, $list, $list_name));
			if ($ok) {
				$recipient->set('erc_sent_time', 'now()');
				$recipient->set('erc_status', EmailRecipient::EMAIL_SENT);
				$out['sent']++;
			} else {
				$recipient->set('erc_status', EmailRecipient::SEND_FAILURE);
				$out['failed']++;
			}
			$recipient->save();
		}

		$email->set('eml_sent_time', 'now()');
		$email->set('eml_status', Email::EMAIL_SENT);
		$email->save();
		return $out;
	}

	/**
	 * Queue a campaign to a mailing list's subscribers, for a task or a plugin
	 * that writes one (the event_manager weekly digest). Every campaign rule
	 * applies, as for one composed on the admin pages. Returns the Email, or
	 * null when nobody on the list has an address (nothing is kept). Throws
	 * CampaignRefusedException on the included email, before writing anything.
	 */
	public static function queue_to_list(int $mailing_list_id, string $subject, string $html, string $inner_template): ?Email {
		$refusal = CampaignRules::refusal();
		if ($refusal !== null) {
			throw new CampaignRefusedException($refusal);
		}
		$email = new Email(NULL);
		$email->set('eml_subject', mb_substr($subject, 0, 255));
		$email->set('eml_message_html', $html);
		$email->set('eml_message_plain', LibraryFunctions::htmlToText($html));
		$email->set('eml_message_template_html', $inner_template);
		$email->set('eml_status', Email::EMAIL_CREATED);
		$email->set('eml_type', Email::TYPE_MARKETING);
		$email->set('eml_mlt_mailing_list_id', $mailing_list_id);
		$email->save();
		$email->load();
		if ($email->queue() === 0) {
			$email->permanent_delete();
			return null;
		}
		return $email;
	}

	/** Send the campaign to $to alone, as a test. True when the provider took it. */
	public static function send_test(Email $email, User $to): bool {
		list($list, $list_name) = self::list_of($email);
		$message = self::build_message($email, $to->export_as_array(), $to->get('usr_email'), $to->display_name(), $list, $list_name);
		$sender = new EmailSender();
		return (bool)$sender->send($message);
	}

	/** The campaign's mailing list and its name, or [null, null]. */
	private static function list_of(Email $email): array {
		if (!$email->get('eml_mlt_mailing_list_id')) {
			return array(null, null);
		}
		$list = new MailingList($email->get('eml_mlt_mailing_list_id'), TRUE);
		return $list->key ? array($list, $list->get('mlt_name')) : array(null, null);
	}

	private static function build_message(Email $email, array $recipient_data, $address, $name, ?MailingList $list, ?string $list_name): EmailMessage {
		$message = EmailMessage::fromTemplate($email->get('eml_message_template_html'), array(
			'subject' => $email->get('eml_subject'),
			'preview_text' => $email->get('eml_preview_text'),
			'body' => $email->get('eml_message_html'),
			'utm_medium' => 'email',
			'utm_campaign' => $list_name,
			'utm_content' => urlencode((string)$email->get('eml_subject')),
			'mailing_list_id' => $list ? $list->key : null,
			'mailing_list_string' => $list_name,
			'recipient' => $recipient_data,
		));
		$message->subject($email->get('eml_subject'))->to($address, $name);
		$settings = Globalvars::get_instance();
		if ($email->get('eml_from_address') && $email->get('eml_from_address') != $settings->get_setting('defaultemail')) {
			$message->from($email->get('eml_from_address'), $email->get('eml_from_name'));
		}
		return $message;
	}
}
