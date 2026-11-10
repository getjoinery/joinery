<?php
/**
 * CampaignRules — when a campaign may go out (spec email_lists_plugin §5).
 *
 * A site that sends through the included Joinery email (EmailSender::
 * usesIncludedMail()) has a small monthly allowance meant for its everyday
 * mail. One campaign to a list could use it up and stop the site's password
 * resets until the month rolls over, so a campaign needs the site's own
 * provider: queueing is refused, and one queued before the site moved back to
 * included email is held at send time. Lists, signups, MailChimp sync and a
 * test send to yourself stay allowed.
 *
 * @version 1.0
 */
class CampaignRules {

	const REFUSAL = 'Connect your own email provider to send to a list. Included email is for your everyday mail.';
	const HELD = 'Held: this site sends through the included email, which is for everyday mail. Connect your own email provider and it goes out on the next run.';

	/** Why a campaign may not be queued or sent now, or null when it may. */
	public static function refusal(): ?string {
		return EmailSender::usesIncludedMail() ? self::REFUSAL : null;
	}

	/** The banner for the compose and campaign pages, or '' when campaigns may send. */
	public static function banner(): string {
		if (self::refusal() === null) {
			return '';
		}
		return '<div class="alert alert-warning"><strong>' . htmlspecialchars(self::REFUSAL) . '</strong> '
			. 'You can build lists and write campaigns now, and send yourself a test. '
			. '<a href="' . HostedPlanNotice::OWN_EMAIL_PROVIDER_URL . '">' . HostedPlanNotice::OWN_EMAIL_PROVIDER_LABEL . '</a></div>';
	}
}

class CampaignRefusedException extends DisplayableUserException {}
