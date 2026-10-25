<?php
/** @joinery-test
 * name: php_warnings
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * The runner's read of the site's error log (tests/lib/php_warnings.php): a
 * warning a request raised during a run fails the run, once per place.
 *
 * Reads a temporary log of its own; never the site's.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/php_warnings.php');

$root = '/var/www/html/site/public_html';
$line = "[Sun Oct 25 01:55:39.279955 2026] [proxy_fcgi:error] [pid 1:tid 2] [client 192.0.2.1:0] AH01071: Got error 'PHP message: "
	. "PHP Warning:  Undefined variable \$acting_permission in $root/includes/ApiLogicEndpoint.php on line 474', referer: https://example.com/x\n";

section('Reading log text');
$out = php_warnings_parse($line . $line . $line, $root);
check($out === array('PHP Warning: Undefined variable $acting_permission at includes/ApiLogicEndpoint.php:474 (written 3 times)'),
	'one entry per place, with how often it was written', json_encode($out));
check(php_warnings_parse(str_replace($root, '/usr/share/php', $line), $root) === array(),
	'a file outside the site root is not ours to count');
check(php_warnings_parse("PHP message: PHP Deprecated:  something in $root/a.php on line 3\n", $root) === array(),
	'a deprecation is not a warning');
$fatal = php_warnings_parse("PHP message: PHP Fatal error:  Uncaught Error: Call to undefined function x() in $root/views/a.php:12\n", $root);
check(count($fatal) === 1 && strpos($fatal[0], 'PHP Fatal error') === 0 && strpos($fatal[0], 'views/a.php:12') !== false,
	'a fatal error counts, in its file:line form', json_encode($fatal));

section('Reading only what was added');
$tmp = tempnam(sys_get_temp_dir(), 'pw');
harness_defer(function () use ($tmp) { @unlink($tmp); });
file_put_contents($tmp, $line);
clearstatcache(true, $tmp);
$st = stat($tmp);
$mark = array($tmp, (int)$st['ino'], (int)$st['size']);
check(php_warnings_since($mark, $root) === array(), 'what was there before the mark is not read');
file_put_contents($tmp, str_replace('line 474', 'line 475', $line), FILE_APPEND);
$added = php_warnings_since($mark, $root);
check(count($added) === 1 && strpos($added[0], ':475') !== false, 'what was added after it is', json_encode($added));
check(php_warnings_since(null) === array(), 'no log, nothing to read');

harness_finish();
