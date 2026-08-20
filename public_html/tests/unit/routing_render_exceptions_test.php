<?php
/** @joinery-test
 * name: routing_render_exceptions
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * specs/routing_render_exception_handling.md — a render-time exception on a
 * fallback-routed page reaches the registered error handler; it is never
 * answered with the 404 page. The live behavior was proven over HTTPS on dev
 * (2026-08-20: Displayable → 500 with its message, generic Exception → 500
 * error page, missing view → 404, no cache entry or nostatic mark for the
 * erroring URLs). These pin the shape of the code so the mask cannot quietly
 * return.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$source = file_get_contents(PathHelper::getIncludePath('includes/RouteHelper.php'));
check($source !== false && $source !== '', 'RouteHelper.php is readable');

// The fallback render block: everything from the view-fallback marker to the
// final-404 marker. The assertions below are scoped to it so a legitimate
// display_404_page() elsewhere in routing (URL shortener, strict .php policy)
// stays allowed.
$start = strpos($source, '6. View directory fallback');
$end = strpos($source, '8. Final fallback', (int)$start);
check($start !== false && $end !== false && $end > $start,
	'the fallback render block is locatable by its step markers');
$block = substr($source, (int)$start, (int)$end - (int)$start);

section('The catch re-throws instead of answering 404');
check(strpos($block, 'display_404_page') === false,
	'no display_404_page call inside the fallback render block',
	'an exception answered with the 404 page is the mask this spec removes');
check(strpos($block, 'Asset/view not found') === false,
	'the mislabeled log line is gone');
check((bool)preg_match('/catch\s*\(\s*\\\\?Throwable\s+\$\w+\s*\)/', $block),
	'the render catch names Throwable, not Exception',
	'buffer cleanup is owed to an Error too');
check((bool)preg_match('/throw\s+\$\w+\s*;/', $block),
	'the caught throwable is re-thrown to the registered error handler');

section('An errored request never poisons the static cache');
$catch_pos = strpos($block, 'catch');
$rethrow_pos = strpos($block, 'throw $', (int)$catch_pos);
check($catch_pos !== false && $rethrow_pos !== false,
	'catch and re-throw are both present');
$catch_body = substr($block, (int)$catch_pos, (int)$rethrow_pos - (int)$catch_pos);
check(strpos($catch_body, 'ob_end_clean') !== false,
	'the cache output buffer is discarded before the re-throw',
	'partial page output must not be cached or shape a nostatic verdict');
check(strpos($catch_body, 'createCache') === false
		&& strpos($catch_body, 'markAsNostatic') === false,
	'no cache write and no nostatic mark on the error path');

section('The success path still saves the cache');
$after_catch = substr($block, (int)$rethrow_pos);
check(strpos($after_catch, 'createCache') !== false
		&& strpos($after_catch, 'markAsNostatic') !== false,
	'cache save and nostatic evaluation run after the render succeeds');

harness_finish();
