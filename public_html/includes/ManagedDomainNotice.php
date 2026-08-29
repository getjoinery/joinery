<?php
/**
 * ManagedDomainNotice - the one thing a managed-domain owner has to do.
 *
 * A deployment whose domain was bought for it at checkout carries four
 * settings the management node writes and nothing local edits. While that domain
 * still sits in the operator's registrar account, its renewal bills the
 * OPERATOR — and the platform never renews a customer's domain and never
 * fronts the cost. So the domain has to move into the owner's own registrar
 * account before it expires, and this notice is how they find that out.
 *
 * It is deliberately late and deliberately loud in that order. Nothing appears
 * for the first six months of the year the owner paid for; the management node
 * pushes a custody state at expiry minus six months and that is the first
 * mention anywhere. From there the notice sharpens as the date approaches,
 * because a lapsed domain takes the website and the email address with it.
 *
 * Every deployment carries this class and almost every deployment renders
 * nothing: with no managed_domain_state there is no notice, which is what
 * keeps a zero-config install silent.
 *
 * @version 1.0
 */

class ManagedDomainNotice {

	/** Custody states that mean "still ours to hand over". */
	const OPEN_STATES = array('operator_managed', 'push_requested', 'push_sent');

	/** Days-to-expiry thresholds, most urgent first. */
	const URGENT_DAYS = 7;
	const SOON_DAYS   = 30;

	/**
	 * Whether this deployment has a take-ownership notice to show.
	 *
	 * False once custody is the owner's (self_custody) — at that point there is
	 * nothing for them to do here, and the confirmation already went by email.
	 */
	public static function applies(): bool {
		$settings = Globalvars::get_instance();
		$state = trim((string)$settings->get_setting('managed_domain_state', false, true));
		$domain = trim((string)$settings->get_setting('managed_domain_name', false, true));
		return $domain !== '' && in_array($state, self::OPEN_STATES, true);
	}

	/** The notice, or '' when there is nothing to say. */
	public static function render(): string {
		if (!self::applies()) {
			return '';
		}
		$settings = Globalvars::get_instance();
		$domain = trim((string)$settings->get_setting('managed_domain_name', false, true));
		$state  = trim((string)$settings->get_setting('managed_domain_state', false, true));
		$url    = trim((string)$settings->get_setting('managed_domain_manage_url', false, true));
		$expiry = trim((string)$settings->get_setting('managed_domain_expiry_time', false, true));

		$days = self::daysToExpiry($expiry);
		$level = self::level($days);
		$when = self::expiryPhrase($expiry);

		if ($state === 'push_requested') {
			$headline = 'We are moving ' . $domain . ' into your registrar account.';
			$body = 'Nothing to do right now — watch for an email from Namecheap inviting you to '
				. 'accept the domain. Your site and email keep working throughout.';
		} elseif ($state === 'push_sent') {
			$headline = $domain . ' is on its way to your account.';
			$body = 'Accept the invitation your registrar emailed you, then add a payment method and '
				. 'turn on auto-renew' . ($when !== '' ? ' — the domain expires ' . $when . '.' : '.');
		} else {
			$headline = 'Move ' . $domain . ' into your own registrar account';
			$body = 'You already own this domain — your name is on the public registration. What has '
				. 'not moved yet is the billing'
				. ($when !== '' ? ', and it renews ' . $when . '.' : '.')
				. ' Until you take it over, nobody is paying that renewal, and the domain lapses on '
				. 'that date — taking this site and your email address with it. It takes about five '
				. 'minutes and costs nothing.';
		}

		$link = '';
		if ($url !== '') {
			$link = '<a class="jy-managed-domain-notice__action" href="'
				. htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Take ownership</a>';
		}

		return self::css() . '<div class="jy-managed-domain-notice jy-managed-domain-notice--' . $level
			. '" role="status">'
			. '<div class="jy-managed-domain-notice__text">'
			. '<strong>' . htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') . '</strong> '
			. htmlspecialchars($body, ENT_QUOTES, 'UTF-8')
			. '</div>' . $link . '</div>';
	}

	/** Whole days until the stored expiry, or null when it is unreadable. */
	public static function daysToExpiry(string $expiry): ?int {
		$expiry = trim($expiry);
		if ($expiry === '') {
			return null;
		}
		$stamp = strtotime($expiry . ' UTC');
		return $stamp === false ? null : (int)floor(($stamp - time()) / 86400);
	}

	/** 'calm' | 'soon' | 'urgent' — how hard the notice pushes. */
	public static function level(?int $days): string {
		if ($days === null) {
			return 'calm';
		}
		if ($days <= self::URGENT_DAYS) {
			return 'urgent';
		}
		if ($days <= self::SOON_DAYS) {
			return 'soon';
		}
		return 'calm';
	}

	/** "on March 4, 2027" / "in 6 days" / "" — the date, said naturally. */
	private static function expiryPhrase(string $expiry): string {
		$days = self::daysToExpiry($expiry);
		if ($days === null) {
			return '';
		}
		if ($days <= 1) {
			return $days < 0 ? 'is already past due' : 'tomorrow';
		}
		if ($days <= self::SOON_DAYS) {
			return 'in ' . $days . ' days';
		}
		$local = LibraryFunctions::convert_time($expiry, 'UTC',
			SessionControl::get_instance()->get_timezone(), 'F j, Y');
		return $local ? 'on ' . $local : '';
	}

	/**
	 * Vanilla CSS, emitted once per page.
	 *
	 * Inline deliberately: this notice renders on every deployment, under
	 * whatever theme that box happens to run, and on admin and public pages
	 * alike. A stylesheet would have to exist in all of those places, and a
	 * renewal warning that renders unstyled because a theme lacked a rule is a
	 * warning nobody reads.
	 */
	private static function css(): string {
		static $emitted = false;
		if ($emitted) {
			return '';
		}
		$emitted = true;
		return '<style id="jy-managed-domain-notice-css">'   /* jy-allow-style */ . '
.jy-managed-domain-notice{display:flex;flex-wrap:wrap;align-items:center;gap:.75rem 1rem;
 padding:.85rem 1.1rem;margin:0 0 1rem;border:1px solid;border-radius:.5rem;font-size:.9rem;line-height:1.45}
.jy-managed-domain-notice__text{flex:1 1 22rem;min-width:0}
.jy-managed-domain-notice__action{flex:0 0 auto;display:inline-block;padding:.45rem .95rem;
 border-radius:.35rem;font-weight:600;text-decoration:none;background:#1f2937;color:#fff}
.jy-managed-domain-notice__action:hover{background:#111827;color:#fff}
.jy-managed-domain-notice--calm{background:#eff6ff;border-color:#bfdbfe;color:#1e3a5f}
.jy-managed-domain-notice--soon{background:#fffbeb;border-color:#fcd34d;color:#78350f}
.jy-managed-domain-notice--urgent{background:#fef2f2;border-color:#fca5a5;color:#7f1d1d}
.jy-managed-domain-notice--urgent .jy-managed-domain-notice__action{background:#b91c1c}
.jy-managed-domain-notice--urgent .jy-managed-domain-notice__action:hover{background:#991b1b}
@media (prefers-color-scheme:dark){
 .jy-managed-domain-notice--calm{background:#16243a;border-color:#2c4a72;color:#dbeafe}
 .jy-managed-domain-notice--soon{background:#3a2c10;border-color:#78561a;color:#fef3c7}
 .jy-managed-domain-notice--urgent{background:#3a1717;border-color:#7f2a2a;color:#fee2e2}
}
</style>';
	}
}
