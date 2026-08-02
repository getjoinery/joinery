<?php
/** @joinery-test
 * name: harness_contract
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * Standing gate on the shape of the test estate itself (read-only, no DB).
 *
 * Some ways of writing a test are wrong in a way the test cannot report,
 * because the failure mode is the test lying about its own result. Those
 * cannot be caught by running the suite — only by reading it. This does that,
 * over every declared test, so the shape is enforced rather than remembered.
 *
 * 1. harness_finish() must never be called from inside a finally block.
 *    harness_finish() ends in exit(). Reached while an exception is unwinding,
 *    it terminates the process before the throw can surface — so the run
 *    reports PASS on however many checks happened to complete, and a suite that
 *    silently shrank to a third of its size looks exactly like a passing one.
 *    Put cleanup in the finally and harness_finish() after the try.
 *
 * 2. Every declared PHP test must call harness_finish() at least once, or it
 *    never emits the result contract the runner reads.
 *
 * 3. A file that looks like a test must declare itself with an @joinery-test
 *    header, or it is invisible to the runner while looking covered.
 *
 * Run: php tests/run.php safe --filter=harness_contract
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/** Every *_test.php in the estate, repo-relative. */
function hc_test_files(): array {
	$root = dirname(__DIR__, 1);            // …/public_html/tests
	$public = dirname($root);               // …/public_html
	$roots = array($root, $public . '/plugins');
	$out = array();
	foreach ($roots as $dir) {
		if (!is_dir($dir)) continue;
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
		foreach ($it as $file) {
			if (substr($file->getFilename(), -9) !== '_test.php') continue;
			$out[] = str_replace($public . '/', '', $file->getPathname());
		}
	}
	sort($out);
	return $out;
}

/**
 * Lines where $function is called lexically inside a finally block.
 *
 * Token-based rather than regex: a finally body spans nested braces, strings
 * and comments, and a regex over that reports whatever it feels like.
 */
function hc_calls_inside_finally(string $source, string $function): array {
	$tokens = token_get_all($source);
	$depth = 0;
	$finally_depths = array();
	$pending_finally = false;
	$hits = array();

	foreach ($tokens as $token) {
		if (is_array($token)) {
			if ($token[0] === T_FINALLY) {
				$pending_finally = true;
			} elseif ($token[0] === T_STRING && $token[1] === $function && !empty($finally_depths)) {
				$hits[] = $token[2];
			}
			continue;
		}
		if ($token === '{') {
			$depth++;
			if ($pending_finally) {
				$finally_depths[] = $depth;
				$pending_finally = false;
			}
		} elseif ($token === '}') {
			if (!empty($finally_depths) && end($finally_depths) === $depth) {
				array_pop($finally_depths);
			}
			$depth--;
		}
	}
	return $hits;
}

$public_root = dirname(dirname(__DIR__));
$files = hc_test_files();

section('The estate is discoverable');
check(count($files) > 50, 'found the test files to inspect', count($files) . ' file(s)');

section('harness_finish() is never called from a finally block');
$offenders = array();
$missing_finish = array();
$undeclared = array();

foreach ($files as $rel) {
	$source = @file_get_contents($public_root . '/' . $rel);
	if ($source === false) continue;

	$in_finally = hc_calls_inside_finally($source, 'harness_finish');
	if (!empty($in_finally)) {
		$offenders[] = $rel . ':' . implode(',', $in_finally);
	}

	$declared = strpos($source, '@joinery-test') !== false;
	if (!$declared) {
		$undeclared[] = $rel;
		continue;   // an undeclared file is reported once, not twice
	}
	if (strpos($source, 'harness_finish(') === false) {
		$missing_finish[] = $rel;
	}
}

check(empty($offenders),
	'no test finishes from inside a finally, where the exit() would swallow a throw',
	implode('; ', $offenders));

section('Every declared test emits the result contract');
check(empty($missing_finish),
	'every declared PHP test calls harness_finish()',
	implode('; ', $missing_finish));

section('Nothing that looks like a test is invisible to the runner');
check(empty($undeclared),
	'every *_test.php carries an @joinery-test header',
	implode('; ', $undeclared));

harness_finish();
?>
