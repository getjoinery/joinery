<?php
/** @joinery-test
 * name: joinery_confirm
 * tier: safe
 * parallel: true
 * env: dev-only
 * needs: [chrome]
 * covers: [public_html/assets/js/base.js]
 * timeout: 90
 */
/**
 * PURPOSE: The kit's confirm and alert attributes do what the inline handlers
 * they replaced did (specs/strict_script_policy.md WP4a).
 *
 * tests/fixtures/joinery_confirm/runner.html loads assets/js/base.js, clicks
 * each kind of button, and writes one <li data-result> per check. This runs
 * it in headless Chrome and reads the rows back. Pinned:
 *   - data-jy-confirm + data-jy-submit="id": asks, Cancel does nothing,
 *     Confirm submits that form with submit();
 *   - a submit button with data-jy-confirm submits its own form as that button
 *     (requestSubmit), so its name and value are sent;
 *   - an empty data-jy-submit uses the button's own form;
 *   - data-jy-confirm-typed keeps Confirm disabled until the word is typed;
 *   - a link with data-jy-submit submits and is not followed; a link with only
 *     data-jy-confirm is followed after Confirm;
 *   - data-jy-alert shows one button and submits nothing;
 *   - data-jy-confirm-label and -style set the confirm button's words and style;
 *   - a form's own data-jy-confirm still asks on submit.
 *
 * Run: php tests/run.php --only=tests/unit/joinery_confirm_test.php
 *
 * @version 1.2 - covers the JS files Chrome loads, so a change to them runs this suite
 * @version 1.1 - the confirm button's label and style attributes
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$runner = realpath(__DIR__ . '/../fixtures/joinery_confirm/runner.html');
check($runner !== false && is_file($runner), 'runner.html is present', (string)$runner);

$chrome = trim((string)shell_exec('command -v google-chrome 2>/dev/null'));
if ($chrome === '') {
	check(false, 'google-chrome is on PATH (declare/enforce needs:[chrome])');
	harness_finish();
}

section('Headless Chrome runs the fixtures to completion');
$cmd = 'timeout 60 ' . escapeshellarg($chrome)
	. ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage'
	. ' --virtual-time-budget=3000 --dump-dom ' . escapeshellarg('file://' . $runner) . ' 2>/dev/null';
$dom = (string)shell_exec($cmd);
check($dom !== '', 'Chrome returned a DOM', strlen($dom) . ' bytes');
check(strpos($dom, 'data-done="1"') !== false, 'the fixture script ran to its last line',
	'a missing marker means it threw before reporting; open runner.html in a browser');

section('Every browser-side fixture passed');
$rows = 0;
if (preg_match_all('/<li data-result="(pass|fail)" data-name="([^"]*)">(.*?)<\/li>/s', $dom, $m, PREG_SET_ORDER)) {
	foreach ($m as $row) {
		$rows++;
		check($row[1] === 'pass', html_entity_decode($row[2], ENT_QUOTES | ENT_HTML5), html_entity_decode(strip_tags($row[3]), ENT_QUOTES | ENT_HTML5));
	}
}
check($rows >= 20, 'the fixture set is the full one, not a page that half-loaded', $rows . ' rows');

harness_finish();
