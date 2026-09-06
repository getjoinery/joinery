<?php
/**
 * HostedPlanNotice — what a hosted site's admins are told about its hosting.
 *
 * A deployment somebody else runs and pays for carries five settings the
 * management node writes and nothing local edits: where the hosting stands,
 * the date the current state runs to, a sentence the operator wants read, how
 * much of each allowance is used, and where the owner manages it. This renders
 * them.
 *
 * It says two kinds of thing and never a third:
 *
 *   WHERE THE HOSTING STANDS. A free trial with a date on it, a paid
 *   subscription, a failed charge with a deadline, a suspension. The dates
 *   matter: the machine is shut down thirty days after a charge fails, and the
 *   only reason to say that early and plainly is so nobody is surprised.
 *
 *   WHERE AN ALLOWANCE IS RUNNING OUT, and the ONE action for that service —
 *   which is always "open your own account", never a bigger plan. There is no
 *   bigger plan. A customer who outgrows the hosting is better served by their
 *   own mail provider or their own bucket, and this notice says so rather than
 *   selling them something.
 *
 * ADMINS ONLY. Everything here is about the operator's arrangement with the
 * site's owner; a member reading the site has no part in it and no way to act
 * on it. Callers render it behind a permission check.
 *
 * Every deployment carries this class and almost every deployment renders
 * nothing: with no hosted_plan_state there is no notice, which is what keeps a
 * self-hosted install silent.
 *
 * @version 1.0
 */

class HostedPlanNotice {

	/**
	 * The hosting states this renders. Anything else renders nothing.
	 *
	 * These are BILLING states and only billing states. Anything else the
	 * operator has done to the site — stopped its sending, paused its backups —
	 * arrives as the notice sentence instead, because those are independent of
	 * whether the customer is paying and folding them in here would lose one
	 * fact to say the other.
	 */
	const STATES = array('trial', 'subscribed', 'grace', 'shutdown');

	/** Percentage of an allowance at which its line turns into a warning. */
	const ALLOWANCE_WARN_PERCENT = 80;

	/** Days left in a trial or grace period before the notice sharpens. */
	const URGENT_DAYS = 7;
	const SOON_DAYS   = 21;

	/** Does this deployment have hosting somebody else runs? */
	public static function applies(): bool {
		return in_array(self::state(), self::STATES, true);
	}

	/** The stored hosting state, or '' on a deployment nobody hosts. */
	public static function state(): string {
		return trim((string)Globalvars::get_instance()->get_setting('hosted_plan_state', false, true));
	}

	/**
	 * The notice, or '' when there is nothing to say.
	 *
	 * Renders even for a healthy subscription with every allowance quiet: a
	 * site whose hosting is somebody else's arrangement should say so where its
	 * admins look, not only when something is wrong. That line is calm and one
	 * sentence long.
	 */
	public static function render(): string {
		if (!self::applies()) {
			return '';
		}
		$settings = Globalvars::get_instance();
		$state  = self::state();
		$until  = trim((string)$settings->get_setting('hosted_plan_until_time', false, true));
		$notice = trim((string)$settings->get_setting('hosted_plan_notice', false, true));
		$url    = trim((string)$settings->get_setting('hosted_plan_manage_url', false, true));

		$days = self::daysUntil($until);
		$when = self::whenPhrase($until);
		list($headline, $body) = self::wording($state, $when);

		$allowances = self::allowances();
		$level = self::level($state, $days, $allowances, $notice);

		$out = '<div class="jy-hosted-plan jy-hosted-plan--' . $level . '" role="status">'
			. '<div class="jy-hosted-plan__text">'
			. '<strong>' . htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') . '</strong> '
			. htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
		if ($notice !== '') {
			$out .= ' ' . htmlspecialchars($notice, ENT_QUOTES, 'UTF-8');
		}
		$out .= '</div>';

		if ($url !== '' && preg_match('#^https://#i', $url)) {
			$out .= '<a class="jy-hosted-plan__action" href="'
				. htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Manage hosting</a>';
		}

		$rows = self::renderAllowances($allowances);
		if ($rows !== '') {
			$out .= '<div class="jy-hosted-plan__allowances">' . $rows . '</div>';
		}

		return self::css() . $out . '</div>';
	}

	/**
	 * The allowance list, as stored: a list of
	 * {label, used, allowance, percent, action_label, action_url}.
	 *
	 * Anything malformed is dropped rather than rendered. This value is written
	 * by the management node, and the one thing that must never happen is a
	 * broken usage line hiding the state sentence above it.
	 */
	public static function allowances(): array {
		$raw = trim((string)Globalvars::get_instance()->get_setting('hosted_plan_allowances', false, true));
		if ($raw === '') {
			return array();
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return array();
		}
		$out = array();
		foreach ($decoded as $entry) {
			if (!is_array($entry) || trim((string)($entry['label'] ?? '')) === '') {
				continue;
			}
			$out[] = array(
				'label'        => (string)$entry['label'],
				'used'         => (string)($entry['used'] ?? ''),
				'allowance'    => (string)($entry['allowance'] ?? ''),
				'percent'      => max(0, min(999, (int)($entry['percent'] ?? 0))),
				'action_label' => (string)($entry['action_label'] ?? ''),
				'action_url'   => (string)($entry['action_url'] ?? ''),
			);
		}
		return $out;
	}

	/** Whole days until a stored UTC timestamp, or null when unreadable. */
	public static function daysUntil(string $when): ?int {
		$when = trim($when);
		if ($when === '') {
			return null;
		}
		$stamp = strtotime($when . ' UTC');
		return $stamp === false ? null : (int)floor(($stamp - time()) / 86400);
	}

	/**
	 * 'calm' | 'soon' | 'urgent'. A failed charge or a shutdown is urgent
	 * whatever the dates say, and an operator notice is never calm; otherwise
	 * the nearer of the deadline and the fullest allowance decides.
	 */
	public static function level(string $state, ?int $days, array $allowances, string $notice = ''): string {
		if ($state === 'shutdown') {
			return 'urgent';
		}
		if ($state === 'grace') {
			return ($days !== null && $days <= self::URGENT_DAYS) ? 'urgent' : 'soon';
		}
		// A sentence from the operator means something has actually been done to
		// this site — sending stopped, backups paused — and a calm-looking banner
		// is the wrong frame for it whatever the billing state says.
		if ($notice !== '') {
			return 'soon';
		}
		foreach ($allowances as $a) {
			if ($a['percent'] >= 100) { return 'urgent'; }
		}
		if ($days !== null && $days <= self::URGENT_DAYS) {
			return 'soon';
		}
		foreach ($allowances as $a) {
			if ($a['percent'] >= self::ALLOWANCE_WARN_PERCENT) { return 'soon'; }
		}
		return ($days !== null && $days <= self::SOON_DAYS) ? 'soon' : 'calm';
	}

	/** The headline and body for one state. */
	private static function wording(string $state, string $when): array {
		switch ($state) {
			case 'trial':
				return array(
					'This site is on a free trial.',
					'Hosting, email and backups are included' . ($when !== '' ? ' until ' . $when : '')
						. '. Your card is already on file, and the subscription starts on its own when the '
						. 'trial ends — there is nothing to do.');
			case 'grace':
				// No arithmetic on periods this deployment does not know. Both
				// the grace and the shelf are operator settings, and a sentence
				// here that hardcoded the difference between them would go quietly
				// wrong the day either changed — while still reading as a promise.
				return array(
					'A hosting payment did not go through.',
					'Everything keeps running' . ($when !== '' ? ' until ' . $when : ' for now')
						. '. After that this site is shut down, and its backups are kept for a while '
						. 'longer so it can be brought back. Updating the card on file is all it takes.');
			case 'shutdown':
				return array(
					'This site has been shut down.',
					'Hosting ended after an unpaid subscription. The backups are still on the shelf and '
						. 'the site can be brought back — with your own recovery key — until they are pruned.');
			default:
				return array(
					'Hosting for this site is looked after for you.',
					'The server, outbound email and offsite backups are all included'
						. ($when !== '' ? ', and the subscription renews ' . $when : '') . '.');
		}
	}

	/** "on March 4, 2027" / "in 6 days" / "tomorrow" / "". */
	private static function whenPhrase(string $when): string {
		$days = self::daysUntil($when);
		if ($days === null) {
			return '';
		}
		if ($days < 0) {
			// A date that has passed says nothing rather than something wrong.
			// The caller composes "until <phrase>", and "until already" is the
			// sentence a site gets when a clock somewhere stopped being wound.
			return '';
		}
		if ($days <= 1) {
			return $days === 0 ? 'today' : 'tomorrow';
		}
		if ($days <= self::SOON_DAYS) {
			return 'in ' . $days . ' days';
		}
		$local = LibraryFunctions::convert_time($when, 'UTC',
			SessionControl::get_instance()->get_timezone(), 'F j, Y');
		return $local ? 'on ' . $local : '';
	}

	/** One row per allowance, with its own action where there is one. */
	private static function renderAllowances(array $allowances): string {
		$rows = '';
		foreach ($allowances as $a) {
			$near = $a['percent'] >= self::ALLOWANCE_WARN_PERCENT;
			$usage = trim($a['used'] . ($a['allowance'] !== '' ? ' of ' . $a['allowance'] : ''));
			$rows .= '<div class="jy-hosted-plan__allowance' . ($near ? ' is-near' : '') . '">'
				. '<span class="jy-hosted-plan__allowance-label">'
				. htmlspecialchars($a['label'], ENT_QUOTES, 'UTF-8') . '</span>'
				. '<span class="jy-hosted-plan__allowance-usage">'
				. htmlspecialchars($usage, ENT_QUOTES, 'UTF-8') . '</span>';
			// The one action for a service that is running out is always "open
			// your own account", and it is only offered when it is actually
			// needed — a quiet allowance is not a sales opportunity.
			if ($near && $a['action_label'] !== '' && preg_match('#^https://#i', $a['action_url'])) {
				$rows .= '<a class="jy-hosted-plan__allowance-action" href="'
					. htmlspecialchars($a['action_url'], ENT_QUOTES, 'UTF-8') . '" rel="noopener">'
					. htmlspecialchars($a['action_label'], ENT_QUOTES, 'UTF-8') . '</a>';
			}
			$rows .= '</div>';
		}
		return $rows;
	}

	/**
	 * Vanilla CSS, emitted once per page.
	 *
	 * Inline for the same reason ManagedDomainNotice is: this renders under
	 * whatever theme the hosted box happens to run, and a shutdown warning that
	 * renders unstyled because a theme lacked a rule is a warning nobody reads.
	 */
	private static function css(): string {
		static $emitted = false;
		if ($emitted) {
			return '';
		}
		$emitted = true;
		return '<style id="jy-hosted-plan-css">'   /* jy-allow-style */ . '
.jy-hosted-plan{display:flex;flex-wrap:wrap;align-items:center;gap:.75rem 1rem;
 padding:.85rem 1.1rem;margin:0 0 1rem;border:1px solid;border-radius:.5rem;font-size:.9rem;line-height:1.45}
.jy-hosted-plan__text{flex:1 1 22rem;min-width:0}
.jy-hosted-plan__action{flex:0 0 auto;display:inline-block;padding:.45rem .95rem;
 border-radius:.35rem;font-weight:600;text-decoration:none;background:#1f2937;color:#fff}
.jy-hosted-plan__action:hover{background:#111827;color:#fff}
.jy-hosted-plan__allowances{flex:1 1 100%;display:flex;flex-wrap:wrap;gap:.35rem .9rem;
 margin:.25rem 0 0;padding:.55rem 0 0;border-top:1px solid rgba(0,0,0,.12);font-size:.85rem}
.jy-hosted-plan__allowance{display:flex;align-items:baseline;gap:.4rem}
.jy-hosted-plan__allowance-label{font-weight:600}
.jy-hosted-plan__allowance.is-near .jy-hosted-plan__allowance-usage{font-weight:700}
.jy-hosted-plan__allowance-action{text-decoration:underline}
.jy-hosted-plan--calm{background:#eff6ff;border-color:#bfdbfe;color:#1e3a5f}
.jy-hosted-plan--soon{background:#fffbeb;border-color:#fcd34d;color:#78350f}
.jy-hosted-plan--urgent{background:#fef2f2;border-color:#fca5a5;color:#7f1d1d}
.jy-hosted-plan--urgent .jy-hosted-plan__action{background:#b91c1c}
.jy-hosted-plan--urgent .jy-hosted-plan__action:hover{background:#991b1b}
@media (prefers-color-scheme:dark){
 .jy-hosted-plan__allowances{border-top-color:rgba(255,255,255,.18)}
 .jy-hosted-plan--calm{background:#16243a;border-color:#2c4a72;color:#dbeafe}
 .jy-hosted-plan--soon{background:#3a2c10;border-color:#78561a;color:#fef3c7}
 .jy-hosted-plan--urgent{background:#3a1717;border-color:#7f2a2a;color:#fee2e2}
}
</style>';
	}
}
