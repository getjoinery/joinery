<?php
/**
 * TermsOwed — which terms a person is asked to accept, if any.
 *
 * Two different agreements meet at a sign-in, and they belong to different
 * people:
 *
 *   A SITE'S OWN TERMS govern its members. They are whatever the site names in
 *   terms_url and privacy_url. A site that names neither has none, and asks
 *   nobody to accept anything.
 *
 *   GETJOINERY'S TERMS govern the hosting. On a site getjoinery hosts, the
 *   people who run it (permission 10) are getjoinery's customers, and accept
 *   getjoinery's own pages: fixed here, because the site's settings are the
 *   owner's to point at their own members' terms. A self-hosted site's
 *   operators are bound by nobody's terms at their own sign-in.
 *
 * usr_terms_accepted_time records that the person accepted what they were
 * asked; it is the one column every path writes.
 *
 * @version 1.0 - specs/easy_signup_completion.md WP6, Q7
 */
class TermsOwed {

	const JOINERY_TERMS_URL = 'https://getjoinery.com/page/terms';
	const JOINERY_PRIVACY_URL = 'https://getjoinery.com/page/privacy';

	/**
	 * The terms $user is asked to accept, whether or not they already have:
	 * array('terms' => url or '', 'privacy' => url or '', 'joinery' => bool),
	 * or null when there is nothing to accept.
	 */
	public static function termsFor(User $user): ?array {
		if (Access::allows(10, (int)$user->get('usr_permission'))) {
			// Hosted, not merely renting getjoinery's mail or backups: a
			// self-hosted site on the services plan still runs its own machine.
			$state = HostedPlanNotice::state();
			if (!HostedPlanNotice::applies() || $state === HostedPlanNotice::STATE_SERVICES) {
				return null;
			}
			return array('terms' => self::JOINERY_TERMS_URL, 'privacy' => self::JOINERY_PRIVACY_URL, 'joinery' => true);
		}
		$settings = Globalvars::get_instance();
		$terms = trim((string)$settings->get_setting('terms_url'));
		$privacy = trim((string)$settings->get_setting('privacy_url'));
		if ($terms === '' && $privacy === '') {
			return null;
		}
		return array('terms' => $terms, 'privacy' => $privacy, 'joinery' => false);
	}

	/** The terms $user still has to accept, or null when they owe none. */
	public static function owed(User $user): ?array {
		if (!empty($user->get('usr_terms_accepted_time'))) {
			return null;
		}
		return self::termsFor($user);
	}

	/**
	 * "Terms of Use and Privacy Policy" as links, naming only what exists, with
	 * getjoinery named when the terms are its own. HTML.
	 */
	public static function linksHtml(array $owed): string {
		$parts = array();
		foreach (array('terms' => 'Terms of Use', 'privacy' => 'Privacy Policy') as $key => $label) {
			$url = (string)($owed[$key] ?? '');
			if ($url !== '') {
				$parts[] = '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $label . '</a>';
			}
		}
		return (!empty($owed['joinery']) ? "getjoinery's " : 'the ') . implode(' and ', $parts);
	}

	/** The same words as plain text, for a checkbox label (FormWriter escapes labels). */
	public static function labelText(array $owed): string {
		$parts = array();
		if ((string)($owed['terms'] ?? '') !== '') {
			$parts[] = 'Terms of Use';
		}
		if ((string)($owed['privacy'] ?? '') !== '') {
			$parts[] = 'Privacy Policy';
		}
		return 'I agree to ' . (!empty($owed['joinery']) ? "getjoinery's " : 'the ') . implode(' and ', $parts) . '.';
	}
}
