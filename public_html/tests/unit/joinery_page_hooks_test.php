<?php
/** @joinery-test
 * name: joinery_page_hooks
 * tier: safe
 * parallel: true
 * env: dev-only
 * needs: [chrome]
 * covers: [public_html/assets/js/base.js]
 * timeout: 90
 */
/**
 * PURPOSE: The page hooks in assets/js/base.js that stand in for inline script
 * under the strict script policy (specs/strict_script_policy.md WP3) do what
 * the scripts did. tests/fixtures/joinery_page_hooks/runner.html loads base.js
 * and writes one <li data-result> per check; this runs it in headless Chrome.
 * Pinned:
 *   - <meta name="joinery-body-class"> adds its classes to <body> (app mode);
 *   - <template data-jy-body-insert> puts its content at the end or the top of
 *     <body> (the admin chip, the domain notice, the test-mode banner);
 *   - data-jy-current-path fills in the page's own address;
 *   - data-jy-select-all selects an input's value on click;
 *   - data-jy-dismiss, data-jy-reveal and data-jy-autosubmit do their one job;
 *   - data-jy-busy marks a posting button, and not one whose submit was stopped;
 *   - the same-site path check behind data-jy-reload-to and data-jy-navigate
 *     passes /x and refuses //host, /\host, other schemes and bare words.
 *
 * Run: php tests/run.php --only=tests/unit/joinery_page_hooks_test.php
 *
 * @version 1.3 - covers the JS files Chrome loads, so a change to them runs this suite
 * @version 1.2 - data-jy-busy on a confirm-then-submit button (reviewer2 SM N1)
 * @version 1.1 - data-jy-dismiss, data-jy-reveal, data-jy-autosubmit, and data-jy-busy (which leaves a cancelled submit alone; reviewer2 step 3 B1)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$runner = realpath(__DIR__ . '/../fixtures/joinery_page_hooks/runner.html');
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
check($rows >= 15, 'the fixture set is the full one, not a page that half-loaded', $rows . ' rows');

harness_finish();
