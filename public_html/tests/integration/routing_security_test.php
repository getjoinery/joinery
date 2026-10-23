<?php
/** @joinery-test
 * name: routing_security
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * Security unit test for RouteHelper::validatePath() — the front controller's
 * path-traversal / sensitive-file gate. Runs in-process against the real static
 * method (no HTTP), so it is deterministic and prod-safe.
 *
 * The prior estate tested routing only over HTTP with accept-lists so broad they
 * could not distinguish a refused traversal from a generic redirect. This pins
 * the actual filter: the traversal family (raw, encoded, double-encoded),
 * null bytes, and backslashes are rejected; ordinary relative paths pass through.
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../../includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/RouteHelper.php'));

harness_boot();

/** A path that must be REJECTED (validatePath returns false). */
function reject($label, $path) {
	ok($label . ' rejected', RouteHelper::validatePath($path) === false);
}
/** A path that must be ACCEPTED (returns a non-false sanitized string). */
function accept($label, $path) {
	$r = RouteHelper::validatePath($path);
	ok($label . ' accepted', is_string($r) && $r !== false);
}

section('raw traversal sequences rejected');
reject('parent of root ../config', '../config/Globalvars_site.php');
reject('deep traversal a/../../etc', 'a/../../etc/passwd');
reject('mid-path foo/../bar', 'foo/../bar');
reject('leading ..', '..');
reject('leading ../', '../');
reject('windows ..\\ traversal', '..\\config');

section('separator / null-byte tricks rejected');
reject('backslash path', 'config\\secrets');
reject('null byte injection', "theme/app.css\0.php");

section('URL-encoded traversal rejected');
reject('single-encoded %2e%2e%2f', '%2e%2e%2fconfig');
reject('single-encoded %2e%2e/', '%2e%2e/config');
reject('encoded slash ..%2f', '..%2fconfig');
// Double- and triple-encoding: a single urldecode misses these; the iterative
// decode in validatePath must still catch them, since a downstream second
// decode would revive "../".
reject('double-encoded %252e%252e%252f', '%252e%252e%252fconfig');
reject('triple-encoded %25252e...', '%25252e%25252e%25252fetc/passwd');

section('legitimate paths pass through');
accept('theme asset', 'theme/joinery-system/assets/app.css');
accept('nested view path', 'views/profile/settings.php');
accept('root path (empty)', '');
accept('single segment', 'pricing');
// A filename that merely contains dots but no traversal must NOT be rejected.
accept('dotted filename app.min.css', 'assets/app.min.css');

section('a path holding .. finds no view instead of throwing');
// validatePath lets 'foo..bar' through (no traversal); routing then looks the
// name up with throw_on_fail false, which must answer not found, not throw
// (/foo..bar and /profile/x..y answered 500).
accept('a segment holding .. is not a traversal', 'foo..bar');
foreach (array(array('foo..bar.php', 'views'), array('y.php', 'views/profile/x..y')) as $ask) {
	try {
		$found = PathHelper::getThemeFilePath($ask[0], $ask[1], 'system', null, null, false, false);
		ok('lookup of ' . $ask[1] . '/' . $ask[0] . ' is not found', $found === false);
	} catch (Exception $e) {
		ok('lookup of ' . $ask[1] . '/' . $ask[0] . ' is not found', false, $e->getMessage());
	}
}
$threw = false;
try {
	PathHelper::getThemeFilePath('foo..bar.php', 'views');
} catch (Exception $e) {
	$threw = strpos($e->getMessage(), 'Path traversal') !== false;
}
ok('a caller that asks to throw still gets the traversal refusal', $threw);

section('sanitization normalizes without weakening');
$norm = RouteHelper::validatePath('foo//bar///baz');
ok('collapses repeated slashes', $norm === 'foo/bar/baz', var_export($norm, true));
$trim = RouteHelper::validatePath('/leading/and/trailing/');
ok('trims leading+trailing slashes', $trim === 'leading/and/trailing', var_export($trim, true));

harness_finish();
