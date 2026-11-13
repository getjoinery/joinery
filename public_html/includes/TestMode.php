<?php
/**
 * TestMode — whether this request runs against the test side of every outside
 * service that has one: Stripe's and PayPal's test accounts today.
 *
 * Two things turn it on. The site's `debug` setting turns it on for every
 * visitor (dev and test sites). A superadmin can turn it on for their own
 * browser session alone, so a live site can take one test purchase while
 * every other visitor still pays for real. The session switch ends when it is
 * turned off or the browser signs out, and every page shows a banner while it
 * is on, with the button that turns it off.
 *
 * Code that picks a test or live credential asks active(); nothing reads the
 * session key or the debug setting for this itself.
 *
 * @version 1.2 - the banner is a <template> base.js inserts, not an inline script
 * @version 1.1 - on_test_side(): run one piece of work against the test side, whatever the session and
 *   the debug setting say, for a record made on the test side (a test-mode order's subscription)
 * @version 1.0
 */
class TestMode {

	/** The session key. admin_settings_payments sets it briefly to test one side's keys. */
	const SESSION_KEY = 'test_mode';

	/** The level that may turn it on for their session. */
	const TURN_ON_LEVEL = 10;

	/** @var int how many on_test_side() calls are running in this request */
	private static $test_side = 0;

	/** True when this request uses the test side of outside services. */
	public static function active(): bool {
		return self::$test_side > 0 || self::session_on() || self::site_wide();
	}

	/**
	 * Run $fn against the test side, and return what it returns. For acting on
	 * something that lives there, such as a test-mode order's subscription,
	 * from a request that may itself be on the live side. Only this call is
	 * affected: the session and the setting are not touched.
	 */
	public static function on_test_side(callable $fn) {
		self::$test_side++;
		try {
			return $fn();
		} finally {
			self::$test_side--;
		}
	}

	/** True when this browser session turned it on. */
	public static function session_on(): bool {
		return !empty($_SESSION[self::SESSION_KEY]);
	}

	/** True when the site's debug setting turns it on for everyone. */
	public static function site_wide(): bool {
		return (bool)Globalvars::get_instance()->get_setting('debug');
	}

	/** Whether this session's user may turn it on. */
	public static function may_turn_on($session): bool {
		return $session->is_logged_in() && Access::decide(self::TURN_ON_LEVEL, $session);
	}

	/** Turn it on for this session. False when the user may not. */
	public static function turn_on($session): bool {
		if (!self::may_turn_on($session)) {
			return false;
		}
		$_SESSION[self::SESSION_KEY] = true;
		error_log('TestMode: turned on for the session of user ' . (int)$session->get_user_id());
		return true;
	}

	/** Turn it off for this session. Anyone may: it only ever makes a session safer. */
	public static function turn_off(): void {
		unset($_SESSION[self::SESSION_KEY]);
	}

	/**
	 * The banner every page carries while this session has it on: a template
	 * that base.js puts at the top of the page once the body exists (this is
	 * printed in the head). Nothing when it is off, or on only through debug, where
	 * every page of the site is a test page anyway.
	 */
	public static function banner_html(): string {
		if (!self::session_on()) {
			return '';
		}
		$bar = '<div id="jy-test-mode-banner" role="status" style="position:sticky;top:0;z-index:100000;'
			. 'background:#b45309;color:#fff;font:600 14px/1.4 system-ui,sans-serif;padding:8px 16px;'
			. 'display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;justify-content:center;text-align:center">'
			. '<span>Test mode is on in this browser: payments go to Stripe\'s and PayPal\'s test accounts, and nothing is charged.</span>'
			. '<form method="post" action="/test_mode" style="margin:0">'
			. '<input type="hidden" name="action" value="off">'
			. '<input type="hidden" name="return" value="" data-jy-current-path>'
			. '<button type="submit" style="font:inherit;background:#fff;color:#b45309;border:0;border-radius:4px;padding:2px 10px;cursor:pointer">Turn off</button>'
			. '</form></div>';
		// base.js puts it at the top of <body> and fills in the page's own
		// address for Turn off to come back to: no inline script.
		return '<template data-jy-body-insert="afterbegin">' . $bar . '</template>' . "\n";
	}
}
