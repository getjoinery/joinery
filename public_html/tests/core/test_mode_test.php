<?php
/** @joinery-test
 * name: test_mode
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Test mode (TestMode): a superadmin turns it on for their own browser
 * session; anyone may turn it off; the debug setting turns it on for every
 * visitor; every page says so while a session has it on; and the store's
 * test-or-live choice follows it.
 *
 * debug is held off in memory for the run, so the session switch is what is
 * measured, then turned back on to check the site-wide case.
 *
 * Run: php tests/run.php --only=tests/core/test_mode_test.php
 *
 * @version 1.0
 */
if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('logic/test_mode_logic.php'));

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
harness_defer(function () { unset($_SESSION[TestMode::SESSION_KEY]); });
harness_set_setting_mem('debug', '0');
$session = SessionControl::get_instance();

section('Off by default, and a member cannot turn it on');
unset($_SESSION[TestMode::SESSION_KEY]);
check(!TestMode::active(), 'with debug off and no switch, test mode is off');
check(TestMode::banner_html() === '', 'no banner while it is off');
$member = make_user('tm_member', 0);
$session->store_session_variables($member);
check(!TestMode::may_turn_on($session), 'a member may not turn it on');
$r = test_mode_logic(array('action' => 'on'));
check($r->error !== null && !TestMode::session_on(), 'the action refuses a member and leaves it off', (string)$r->error);

section('A superadmin turns it on for their session');
$admin = make_user('tm_admin', 10);
$session->store_session_variables($admin);
$r = test_mode_logic(array('action' => 'on'));
check($r->error === null && TestMode::session_on() && TestMode::active(), 'on for this session');
check(StripeHelper::isTestMode(), 'the store uses the test accounts');
check(StripeHelper::getStripeSettingKey('stripe_api_key') === 'stripe_api_key_test', 'the store reads the test key');
$banner = TestMode::banner_html();
check(strpos($banner, 'jy-test-mode-banner') !== false && strpos($banner, '\/test_mode') !== false
	&& strpos($banner, 'Turn off') !== false, 'the banner is printed, with the Turn off form', substr($banner, 0, 160));

section('Anyone turns it off, and the banner button goes back where it was pressed');
$session->store_session_variables($member);
check(TestMode::session_on(), 'the switch belongs to the browser session, not the user');
$r = test_mode_logic(array('action' => 'off', 'return' => '/cart'));
check(!TestMode::session_on() && !TestMode::active(), 'a member can turn it off');
check($r->redirect === '/cart', 'returns to the page it was pressed on', var_export($r->redirect, true));
$_SESSION[TestMode::SESSION_KEY] = true;
$r = test_mode_logic(array('action' => 'off', 'return' => '//evil.example/x'));
check($r->redirect === null || $r->redirect === '', 'refuses to return to another site');

section('The debug setting turns it on for everyone, with no banner');
unset($_SESSION[TestMode::SESSION_KEY]);
harness_set_setting_mem('debug', '1');
check(TestMode::active() && TestMode::site_wide() && !TestMode::session_on(), 'debug: on site-wide');
check(TestMode::banner_html() === '', 'no banner from debug alone');
$r = test_mode_logic(array('action' => 'bogus'));
check($r->error !== null, 'an unknown action is refused');

harness_finish();
