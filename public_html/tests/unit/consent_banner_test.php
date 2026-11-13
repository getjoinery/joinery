<?php
/** @joinery-test
 * name: consent_banner
 * tier: safe
 * parallel: true
 * env: dev-only
 * needs: [chrome]
 * covers: [public_html/assets/js/consent.js, public_html/assets/js/base.js]
 * timeout: 90
 */
/**
 * PURPOSE: The cookie banner (assets/js/consent.js) in both modes, in headless
 * Chrome against tests/fixtures/consent_banner/{ccpa,gdpr}.html. Pinned:
 *   - CCPA: tracking runs until the visitor opts out, the Do Not Sell link
 *     opens the preferences with the current choice ticked, and saving an
 *     opt-out stores and records it (reviewer2 B7: none of that worked);
 *   - GDPR: nothing runs before a choice, the banner asks, the policy link
 *     cannot break out of its href (B9), Accept All releases held-back tags.
 *
 * Run: php tests/run.php --only=tests/unit/consent_banner_test.php
 *
 * @version 1.1 - covers the JS files Chrome loads, so a change to them runs this suite
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$chrome = trim((string)shell_exec('command -v google-chrome 2>/dev/null'));
if ($chrome === '') {
	check(false, 'google-chrome is on PATH (declare/enforce needs:[chrome])');
	harness_finish();
}
foreach (array('ccpa' => 7, 'gdpr' => 6) as $mode => $want) {
	section("The banner in $mode mode");
	$runner = realpath(__DIR__ . "/../fixtures/consent_banner/$mode.html");
	check($runner !== false, "$mode.html is present");
	$cmd = 'timeout 60 ' . escapeshellarg($chrome)
		. ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage'
		. ' --virtual-time-budget=3000 --dump-dom ' . escapeshellarg('file://' . $runner) . ' 2>/dev/null';
	$dom = (string)shell_exec($cmd);
	check(strpos($dom, 'data-done="1"') !== false, "the $mode checks ran to the end");
	$rows = 0;
	if (preg_match_all('/<li data-result="(pass|fail)" data-name="([^"]*)">(.*?)<\/li>/s', $dom, $m, PREG_SET_ORDER)) {
		foreach ($m as $row) {
			$rows++;
			check($row[1] === 'pass', html_entity_decode($row[2], ENT_QUOTES | ENT_HTML5), html_entity_decode(strip_tags($row[3]), ENT_QUOTES | ENT_HTML5));
		}
	}
	check($rows >= $want, "all the $mode checks ran", (string)$rows);
}

harness_finish();
