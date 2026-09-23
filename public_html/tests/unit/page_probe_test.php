<?php
/** @joinery-test
 * name: page_probe
 * tier: safe
 * env: any
 * needs: []
 */

/**
 * The request side of page_probe (includes/PageProbe.php) and the list of
 * pages a probe never renders (specs/agent_recipes_and_vocabulary.md,
 * "page_probe {page, viewer}").
 *
 *   - REFUSED_PAGES is pinned here, entry for entry: a page leaves or joins
 *     the list only by an edit a reviewer sees.
 *   - The list is also pinned the way the write-on-GET callers are: a core
 *     view whose logic writes during a page view (server_initiated_write) and
 *     is not named on the list fails this test.
 *   - A request is a probe only when every condition holds; each one missing
 *     is refused before the database is asked.
 *   - A report carries a warning's type and place, never its text.
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('The pages a probe never renders');

check(PageProbe::REFUSED_PAGES === array(
	'/logout', '/oauth_callback', '/setup', '/app_bridge', '/services_connected', '/sm_ssl_probe',
	'/survey_finish', '/recovery-verify', '/password-reset-1', '/password-reset-2', '/password-reset-2fa',
	'/password-reset-totp', '/password-set', '/terms-accept', '/verify-stepup', '/verify-totp',
	'/change-password-required', '/admin/admin_user_login_as',
), 'the refused list is exactly the pinned one');

$root = PathHelper::getRootDir();
foreach (PageProbe::REFUSED_PAGES as $p) {
	$name = basename($p);
	$exists = is_file($root . '/views/' . $name . '.php') || is_file($root . '/adm/' . $name . '.php');
	check($exists, "{$p} names a page this platform has");
}

// A core view whose own logic writes on a page view acts on arrival.
foreach (glob($root . '/logic/*_logic.php') as $logic) {
	$src = (string)file_get_contents($logic);
	if (strpos($src, 'server_initiated_write(') === false) { continue; }
	$view = str_replace('_', '-', basename($logic, '_logic.php'));
	$view_u = basename($logic, '_logic.php');
	if (!is_file($root . '/views/' . $view_u . '.php') && !is_file($root . '/views/' . $view . '.php')) { continue; }
	check(PageProbe::refused('/' . $view_u) || PageProbe::refused('/' . $view),
		'the view behind ' . basename($logic) . ' writes on arrival and is refused');
}

$pages = PageProbe::node_pages();
check(in_array('/', $pages, true), 'the home page is on the node\'s own list');
foreach (PageProbe::REFUSED_PAGES as $p) {
	check(!in_array($p, $pages, true), "{$p} is never on the list a probe may ask for");
}
foreach ($pages as $p) {
	if (!preg_match(PageProbe::PAGE_PATTERN, $p)) {
		check(false, "{$p} on the list matches the page pattern");
	}
}
check(count(array_filter($pages, function ($p) { return strpos($p, '?') !== false; })) === 0, 'no listed page carries a query string');

section('A request is a probe only when every condition holds');

$saved = $_SERVER;
$token = str_repeat('ab', 32);
$base = array(PageProbe::HEADER => $token, 'REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '127.0.0.1',
	'SERVER_ADDR' => '203.0.113.5', 'REQUEST_URI' => '/admin/admin_users');
$refusals = array(
	'no header'              => array(PageProbe::HEADER => ''),
	'a token of the wrong shape' => array(PageProbe::HEADER => 'not-a-token'),
	'a POST'                 => array('REQUEST_METHOD' => 'POST'),
	'from another machine'   => array('REMOTE_ADDR' => '198.51.100.9'),
	'a query string'         => array('REQUEST_URI' => '/admin/admin_users?x=1'),
);
foreach ($refusals as $label => $over) {
	$_SERVER = array_merge($saved, $base, $over);
	check(PageProbe::claim_request() === null && !PageProbe::active(), 'refused: ' . $label);
}
$_SERVER = $saved;

section('A probe request is view-only in fact (review B3)');

$threw = function ($sql) {
	try { GuardedPdo::assertMayRun($sql); return false; } catch (RuntimeException $e) { return true; }
};
GuardedPdo::$read_only = true;
foreach (array("INSERT INTO x VALUES (1)", "  update usr_users set a=1", "DELETE FROM y", "WITH t AS (SELECT 1) UPDATE z SET a=1", "TRUNCATE q", "CREATE TABLE q (a int)") as $sql) {
	check($threw($sql), 'a write is refused while read-only: ' . substr($sql, 0, 30));
}
check(!$threw('SELECT * FROM usr_users') && !$threw('  select 1'), 'a read runs');
SystemBase::$allow_get_mutation = true;
check(!$threw('INSERT INTO rql_request_logs VALUES (1)'), 'the server\'s own write (server_initiated_write) runs');
SystemBase::$allow_get_mutation = false;
GuardedPdo::$read_only = false;
check(!$threw('INSERT INTO x VALUES (1)'), 'and outside a probe nothing is refused');

section('A report names a place, never a message');

check(PageProbe::type_name(E_WARNING) === 'warning' && PageProbe::type_name(E_USER_DEPRECATED) === 'deprecated'
	&& PageProbe::type_name(E_NOTICE) === 'notice' && PageProbe::type_name(E_ERROR) === 'error', 'warning types are named');
$rel = PageProbe::relative(dirname($root) . '/public_html/includes/Foo.php');
check($rel === 'public_html/includes/Foo.php', 'a file is named relative to the site root', $rel);
$src = (string)file_get_contents($root . '/includes/PageProbe.php');
check(preg_match('/\\$warnings\\[\\] = \\[[^\\]]*errstr/', $src) === 0, 'the collector never stores a warning\'s message');

harness_finish();
