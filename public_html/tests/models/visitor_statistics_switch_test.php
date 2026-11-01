<?php
/** @joinery-test
 * name: visitor_statistics_switch
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */

/**
 * The visitor_statistics setting turns built-in visitor recording on and off.
 *
 * In the test database: with the setting off, a page view and a conversion
 * through SessionControl::save_visitor_event() add no row, and
 * VisitorEvent::recordPageVisit() adds none; with it on, both event kinds add
 * a row, and no row keeps an IP address. The setting is restored afterwards.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();   // all writes below go to the copied test database

$dblink = DbConnector::get_instance()->get_db_link();
$settings = Globalvars::get_instance();
$session = SessionControl::get_instance();

// A request a real browser would make, so the bot and URL filters let it through.
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0';
$_SERVER['REQUEST_URI'] = '/visitor-statistics-switch-test';
$_SERVER['QUERY_STRING'] = '';
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
if (empty($_SESSION['uniqid'])) {
	$_SESSION['uniqid'] = 'vsst' . mt_rand(100000, 999999);
}

$had_row = (bool)$dblink->query("SELECT 1 FROM stg_settings WHERE stg_name = 'visitor_statistics'")->fetchColumn();
$original = $had_row
	? (string)$dblink->query("SELECT stg_value FROM stg_settings WHERE stg_name = 'visitor_statistics'")->fetchColumn()
	: null;

$set = function (string $value) use ($dblink, $settings) {
	$dblink->exec("DELETE FROM stg_settings WHERE stg_name = 'visitor_statistics'");
	$q = $dblink->prepare("INSERT INTO stg_settings (stg_name, stg_value) VALUES ('visitor_statistics', :v)");
	$q->execute(array(':v' => $value));
	$settings->forget_setting('visitor_statistics');
};

harness_defer(function () use ($dblink, $settings, $had_row, $original) {
	$dblink->exec("DELETE FROM stg_settings WHERE stg_name = 'visitor_statistics'");
	if ($had_row) {
		$q = $dblink->prepare("INSERT INTO stg_settings (stg_name, stg_value) VALUES ('visitor_statistics', :v)");
		$q->execute(array(':v' => $original));
	}
	$dblink->exec("DELETE FROM vse_visitor_events WHERE vse_page = '/visitor-statistics-switch-test'");
	$settings->forget_setting('visitor_statistics');
});

$count = function () use ($dblink) {
	return (int)$dblink->query("SELECT count(*) FROM vse_visitor_events WHERE vse_page = '/visitor-statistics-switch-test'")->fetchColumn();
};

section('Off records nothing');
$set('0');
ok('visitor_statistics_on() is false', SessionControl::visitor_statistics_on() === false);
$before = $count();
$session->save_visitor_event(VisitorEvent::TYPE_PAGE_VIEW);
$session->save_visitor_event(VisitorEvent::TYPE_SIGNUP, FALSE, 'user', 1);
VisitorEvent::recordPageVisit('/visitor-statistics-switch-test');
ok('no page view, conversion or JS visit row while off', $count() === $before, 'rows: ' . ($count() - $before));

section('On records');
$set('1');
ok('visitor_statistics_on() is true', SessionControl::visitor_statistics_on() === true);
$before = $count();
$session->save_visitor_event(VisitorEvent::TYPE_PAGE_VIEW);
$session->save_visitor_event(VisitorEvent::TYPE_SIGNUP, FALSE, 'user', 1);
ok('a page view and a conversion each add a row while on', $count() === $before + 2, 'rows: ' . ($count() - $before));

section('No IP address');
ok('vse_ip is not a declared column', !isset(VisitorEvent::$field_specifications['vse_ip']));
$has_ip_column = (bool)$dblink->query("SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'vse_visitor_events' AND column_name = 'vse_ip'")->fetchColumn();
if ($has_ip_column) {
	$with_ip = (int)$dblink->query("SELECT count(*) FROM vse_visitor_events WHERE vse_page = '/visitor-statistics-switch-test' AND vse_ip IS NOT NULL")->fetchColumn();
	ok('the rows just recorded carry no IP address', $with_ip === 0, 'rows with an IP: ' . $with_ip);
}

harness_finish();
