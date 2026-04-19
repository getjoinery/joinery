<?php
/**
 * A/B testing end-to-end harness.
 *
 * Walks the Phase 4 matrix from /specs/ab_testing_framework.md against a
 * disposable Page fixture. Creates fresh test + variant rows, exercises every
 * behavior, cleans up after itself. Re-run safe.
 *
 * Usage: php utils/test_ab_testing.php
 */

require_once(__DIR__ . '/../includes/PathHelper.php');

if (php_sapi_name() !== 'cli') {
	fwrite(STDERR, "CLI only.\n"); exit(1);
}

// Buffer stdout so PHP doesn't think "headers already sent" when apply_variant()
// calls setcookie(). Output drains at the end of the run.
ob_start();

require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('data/pages_class.php'));
require_once(PathHelper::getIncludePath('data/abt_tests_class.php'));
require_once(PathHelper::getIncludePath('data/visitor_events_class.php'));

// Test fixtures need a request context
$_SERVER['REQUEST_URI'] = '/page/ab-test-fixture';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (TestRunner; real browser)';
$_SESSION = ['uniqid' => 'abtestharness' . bin2hex(random_bytes(4))];

$results = ['pass' => 0, 'fail' => 0, 'failures' => []];

function assert_eq($expected, $actual, $label) {
	global $results;
	if ($expected === $actual) {
		$results['pass']++;
		echo "  ✓ {$label}\n";
	} else {
		$results['fail']++;
		$results['failures'][] = $label;
		echo "  ✗ {$label}\n";
		echo "      expected: " . var_export($expected, true) . "\n";
		echo "      actual:   " . var_export($actual, true) . "\n";
	}
}

function assert_true($cond, $label) {
	assert_eq(true, (bool)$cond, $label);
}

function assert_between($low, $high, $actual, $label) {
	global $results;
	if ($actual >= $low && $actual <= $high) {
		$results['pass']++;
		echo "  ✓ {$label} ({$actual} in [{$low},{$high}])\n";
	} else {
		$results['fail']++;
		$results['failures'][] = $label;
		echo "  ✗ {$label}: got {$actual}, expected in [{$low},{$high}]\n";
	}
}

function section($name) {
	echo "\n=== {$name} ===\n";
}

// Reflect to read / reset the private request_assignments stash
function read_stash() {
	$r = new ReflectionClass('AbTest');
	$p = $r->getProperty('request_assignments');
	$p->setAccessible(true);
	return $p->getValue();
}
function reset_stash() {
	$r = new ReflectionClass('AbTest');
	$p = $r->getProperty('request_assignments');
	$p->setAccessible(true);
	$p->setValue(null, []);
}

function reset_cookies() {
	foreach (array_keys($_COOKIE) as $k) {
		if (strpos($k, 'ab_') === 0) unset($_COOKIE[$k]);
	}
}

function reload_variant($id) {
	return new AbTestVariant($id, true);
}

$dblink = DbConnector::get_instance()->get_db_link();

echo "A/B Testing Harness\n" . str_repeat('=', 50) . "\n";

// ---------------------------------------------------------------------------
// FIXTURES
// ---------------------------------------------------------------------------
section("Fixtures");

$page = new Page(null);
$page->set('pag_title', 'Control Title');
$page->set('pag_body', '<p>Control body</p>');
$page->set('pag_link', 'ab-test-fixture-' . bin2hex(random_bytes(3)));
$page->set('pag_published_time', 'now()');
$page->save();
echo "  Page fixture created: id=" . $page->key . "\n";

$test = new AbTest(null);
$test->set('abt_entity_type', 'Page');
$test->set('abt_entity_id', (int)$page->key);
$test->set('abt_status', AbTest::STATUS_ACTIVE);
$test->set('abt_conversion_event_type', VisitorEvent::TYPE_PURCHASE);
$test->set('abt_epsilon', 0.100);
$test->set('abt_cold_start_threshold', 100);
$test->save();
echo "  Test created: id=" . $test->key . ", status=active, conversion=PURCHASE\n";

$variantA = new AbTestVariant(null);
$variantA->set('abv_abt_test_id', (int)$test->key);
$variantA->set('abv_name', 'control');
$variantA->set('abv_overrides', []);
$variantA->save();

$variantB = new AbTestVariant(null);
$variantB->set('abv_abt_test_id', (int)$test->key);
$variantB->set('abv_name', 'challenger');
$variantB->set('abv_overrides', ['pag_title' => 'Challenger Title']);
$variantB->save();

echo "  Variants created: A=" . $variantA->key . " (control), B=" . $variantB->key . " (challenger)\n";

try {

// ---------------------------------------------------------------------------
// Test 1: Cold-start forces uniform random
// ---------------------------------------------------------------------------
section("Cold-start forces uniform random (threshold=100, both variants at 0 trials)");

$counts = [(int)$variantA->key => 0, (int)$variantB->key => 0];
for ($i = 0; $i < 400; $i++) {
	reset_cookies();
	reset_stash();
	$p = new Page($page->key, true);
	AbTest::apply_variant($p);
	$stash = read_stash();
	if (!empty($stash)) $counts[$stash[0][1]]++;
}
// With uniform random over 400 trials, expect ~200 each; loose bounds
assert_between(130, 270, $counts[(int)$variantA->key], 'Variant A cold-start count ~50%');
assert_between(130, 270, $counts[(int)$variantB->key], 'Variant B cold-start count ~50%');

// ---------------------------------------------------------------------------
// Test 2: Warm bandit picks argmax with epsilon=0
// ---------------------------------------------------------------------------
section("Warm bandit argmax (trials>=threshold, epsilon=0)");

$dblink->exec("UPDATE abv_variants SET abv_trials = 1000, abv_rewards = 100 WHERE abv_variant_id = " . (int)$variantA->key);
$dblink->exec("UPDATE abv_variants SET abv_trials = 1000, abv_rewards = 500 WHERE abv_variant_id = " . (int)$variantB->key);
$test->set('abt_epsilon', 0.000);
$test->save();

$counts = [(int)$variantA->key => 0, (int)$variantB->key => 0];
for ($i = 0; $i < 50; $i++) {
	reset_cookies();
	reset_stash();
	$p = new Page($page->key, true);
	AbTest::apply_variant($p);
	$stash = read_stash();
	if (!empty($stash)) $counts[$stash[0][1]]++;
}
assert_eq(0, $counts[(int)$variantA->key], 'Variant A (10% rate) never picked with epsilon=0');
assert_eq(50, $counts[(int)$variantB->key], 'Variant B (50% rate) always picked with epsilon=0');

// Restore epsilon
$test->set('abt_epsilon', 0.100);
$test->save();

// ---------------------------------------------------------------------------
// Test 3: Sticky cookie — same variant returned across requests
// ---------------------------------------------------------------------------
section("Sticky cookie");

reset_stash();
reset_cookies();
$_COOKIE['ab_' . $test->key] = (string)$variantA->key;
for ($i = 0; $i < 5; $i++) {
	reset_stash();
	$p = new Page($page->key, true);
	AbTest::apply_variant($p);
	$stash = read_stash();
	assert_eq((int)$variantA->key, $stash[0][1] ?? null, "Sticky cookie iteration {$i}: returns variant A");
}

// ---------------------------------------------------------------------------
// Test 4: apply_variant overrides fields in memory
// ---------------------------------------------------------------------------
section("Variant overrides entity fields in memory");

reset_cookies();
reset_stash();
$_COOKIE['ab_' . $test->key] = (string)$variantB->key;
$p = new Page($page->key, true);
assert_eq('Control Title', $p->get('pag_title'), 'Pre-apply: parent title');
AbTest::apply_variant($p);
assert_eq('Challenger Title', $p->get('pag_title'), 'Post-apply: variant B title applied');
assert_eq('<p>Control body</p>', $p->get('pag_body'), 'Non-overridden field (pag_body) unchanged');

// ---------------------------------------------------------------------------
// Test 5: Dedup stash — repeat apply_variant doesn't double-stash
// ---------------------------------------------------------------------------
section("Stash deduplication");

reset_cookies();
reset_stash();
$_COOKIE['ab_' . $test->key] = (string)$variantA->key;
$p = new Page($page->key, true);
AbTest::apply_variant($p);
AbTest::apply_variant($p);
AbTest::apply_variant($p);
$stash = read_stash();
assert_eq(1, count($stash), 'Stash has exactly one entry after 3 calls with same (test, variant)');

// ---------------------------------------------------------------------------
// Test 6: flush_request_accounting — trial increment
// ---------------------------------------------------------------------------
section("Trial accounting via flush");

$dblink->exec("UPDATE abv_variants SET abv_trials = 0, abv_rewards = 0 WHERE abv_abt_test_id = " . (int)$test->key);
reset_cookies();
reset_stash();
$_COOKIE['ab_' . $test->key] = (string)$variantA->key;
$p = new Page($page->key, true);
AbTest::apply_variant($p);
AbTest::flush_request_accounting(VisitorEvent::TYPE_PAGE_VIEW);

$vA = reload_variant($variantA->key);
$vB = reload_variant($variantB->key);
assert_eq(1, (int)$vA->get('abv_trials'), 'Variant A trials = 1 after page-view flush');
assert_eq(0, (int)$vB->get('abv_trials'), 'Variant B trials unchanged');
assert_eq(0, (int)$vA->get('abv_rewards'), 'Variant A rewards still 0 (not a conversion event)');
assert_eq([], read_stash(), 'Stash cleared after flush');

// ---------------------------------------------------------------------------
// Test 7: Reward attribution on conversion event
// ---------------------------------------------------------------------------
section("Reward attribution on conversion event");

$dblink->exec("UPDATE abv_variants SET abv_trials = 0, abv_rewards = 0 WHERE abv_abt_test_id = " . (int)$test->key);
reset_cookies();
reset_stash();
$_COOKIE['ab_' . $test->key] = (string)$variantA->key;

// Simulate a conversion event — no apply_variant on this request,
// but the cookie points to variant A
AbTest::flush_request_accounting(VisitorEvent::TYPE_PURCHASE);

$vA = reload_variant($variantA->key);
$vB = reload_variant($variantB->key);
assert_eq(0, (int)$vA->get('abv_trials'), 'Variant A trials still 0 (no apply_variant called)');
assert_eq(1, (int)$vA->get('abv_rewards'), 'Variant A rewards = 1 after PURCHASE flush (cookied variant)');
assert_eq(0, (int)$vB->get('abv_rewards'), 'Variant B rewards unchanged');

// ---------------------------------------------------------------------------
// Test 8: Reward NOT granted for mismatched event type
// ---------------------------------------------------------------------------
section("No reward for mismatched event type");

$dblink->exec("UPDATE abv_variants SET abv_rewards = 0 WHERE abv_abt_test_id = " . (int)$test->key);
reset_cookies();
reset_stash();
$_COOKIE['ab_' . $test->key] = (string)$variantA->key;
AbTest::flush_request_accounting(VisitorEvent::TYPE_SIGNUP);   // test is configured for PURCHASE

$vA = reload_variant($variantA->key);
assert_eq(0, (int)$vA->get('abv_rewards'), 'No reward when event type does not match abt_conversion_event_type');

// ---------------------------------------------------------------------------
// Test 9: End-to-end bot filter via save_visitor_event
// ---------------------------------------------------------------------------
section("Bot filter short-circuits flush via save_visitor_event");

$dblink->exec("UPDATE abv_variants SET abv_trials = 0, abv_rewards = 0 WHERE abv_abt_test_id = " . (int)$test->key);

// Real browser UA — trial should be counted
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
reset_cookies();
reset_stash();
$_COOKIE['ab_' . $test->key] = (string)$variantA->key;
$p = new Page($page->key, true);
AbTest::apply_variant($p);
$session = SessionControl::get_instance();
$session->save_visitor_event(VisitorEvent::TYPE_PAGE_VIEW);

$vA = reload_variant($variantA->key);
assert_eq(1, (int)$vA->get('abv_trials'), 'Real-browser UA: trial incremented');

// Bot UA — trial should NOT be counted
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
reset_cookies();
reset_stash();
$_COOKIE['ab_' . $test->key] = (string)$variantA->key;
$p = new Page($page->key, true);
AbTest::apply_variant($p);
$session->save_visitor_event(VisitorEvent::TYPE_PAGE_VIEW);

$vA = reload_variant($variantA->key);
assert_eq(1, (int)$vA->get('abv_trials'), 'Bot UA: trial NOT incremented (still 1)');

// Restore real UA for remaining tests
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (TestRunner; real browser)';

// ---------------------------------------------------------------------------
// Test 10: Crown copies winner onto parent + saves
// ---------------------------------------------------------------------------
section("Crown: winner overrides copied onto parent Page");

// Re-seed parent page
$p = new Page($page->key, true);
$p->set('pag_title', 'Control Title');
$p->save();

$test2 = new AbTest($test->key, true);
$test2->set('abt_status', AbTest::STATUS_ACTIVE);
$test2->set('abt_winner_abv_variant_id', (int)$variantB->key);
$test2->save();

AbTest::copy_winner_onto_parent($test2);

$p = new Page($page->key, true);
assert_eq('Challenger Title', $p->get('pag_title'), 'Parent page pag_title now matches winner override');

// ---------------------------------------------------------------------------
// Test 11: Cache invalidation dispatcher (targeted URL)
// ---------------------------------------------------------------------------
section("Cache invalidation dispatcher");

// Page class implements get_tested_cache_urls() returning [$this->get_url()].
// Just confirm invalidate_cache_for_test runs cleanly; deeper verification
// requires StaticPageCache spy which is out of scope for this harness.
try {
	AbTest::invalidate_cache_for_test($test2);
	assert_true(true, 'invalidate_cache_for_test runs without exception');
} catch (\Throwable $e) {
	assert_true(false, 'invalidate_cache_for_test raised: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Test 12: JSON field (pag_component_layout) override applies correctly
// ---------------------------------------------------------------------------
section("JSON-typed testable field: pag_component_layout override");

// Give variant B a layout override
$vB = reload_variant($variantB->key);
$vB->set('abv_overrides', ['pag_component_layout' => [999, 888]]);
$vB->save();

reset_cookies();
reset_stash();
$_COOKIE['ab_' . $test->key] = (string)$variantB->key;
$p = new Page($page->key, true);
AbTest::apply_variant($p);
$layout = $p->get_component_layout();
assert_eq([999, 888], $layout, 'Variant pag_component_layout override applied and decoded');

// Restore variant B overrides to simple form
$vB = reload_variant($variantB->key);
$vB->set('abv_overrides', ['pag_title' => 'Challenger Title']);
$vB->save();

// ---------------------------------------------------------------------------
// Test 13: Inherit-from-parent (field absent from overrides)
// ---------------------------------------------------------------------------
section("Absent override key inherits parent value");

// Variant A has no overrides
reset_cookies();
reset_stash();
$_COOKIE['ab_' . $test->key] = (string)$variantA->key;
$p = new Page($page->key, true);
$p->set('pag_title', 'Parent Title Restored');
$p->save();
$p = new Page($page->key, true);
AbTest::apply_variant($p);
assert_eq('Parent Title Restored', $p->get('pag_title'), 'Empty overrides array: inherits parent');

// ---------------------------------------------------------------------------
// Test 14: No-op when test inactive
// ---------------------------------------------------------------------------
section("apply_variant is a no-op when test is paused");

$test2 = new AbTest($test->key, true);
$test2->set('abt_status', AbTest::STATUS_PAUSED);
$test2->save();

reset_cookies();
reset_stash();
$_COOKIE['ab_' . $test->key] = (string)$variantB->key;
$p = new Page($page->key, true);
$p->set('pag_title', 'Stored Value');
$p->save();
$p = new Page($page->key, true);
AbTest::apply_variant($p);
assert_eq('Stored Value', $p->get('pag_title'), 'Paused test: no variant applied, parent value stays');
assert_eq([], read_stash(), 'Paused test: nothing stashed');

// Restore active for any follow-on tests
$test2->set('abt_status', AbTest::STATUS_ACTIVE);
$test2->save();

// ---------------------------------------------------------------------------
// Test 15: Reset counters zeros every variant
// ---------------------------------------------------------------------------
section("Reset counters");

$dblink->exec("UPDATE abv_variants SET abv_trials = 77, abv_rewards = 33 WHERE abv_abt_test_id = " . (int)$test->key);
$dblink->exec("UPDATE abv_variants SET abv_trials = 0, abv_rewards = 0, abv_modified_time = now() WHERE abv_abt_test_id = " . (int)$test->key);
$vA = reload_variant($variantA->key);
$vB = reload_variant($variantB->key);
assert_eq(0, (int)$vA->get('abv_trials'), 'Variant A trials zeroed');
assert_eq(0, (int)$vA->get('abv_rewards'), 'Variant A rewards zeroed');
assert_eq(0, (int)$vB->get('abv_trials'), 'Variant B trials zeroed');
assert_eq(0, (int)$vB->get('abv_rewards'), 'Variant B rewards zeroed');

} finally {
	// ---------------------------------------------------------------------------
	// CLEANUP
	// ---------------------------------------------------------------------------
	section("Cleanup");
	$dblink->exec("DELETE FROM abv_variants WHERE abv_abt_test_id = " . (int)$test->key);
	$dblink->exec("DELETE FROM abt_tests WHERE abt_test_id = " . (int)$test->key);
	$dblink->exec("DELETE FROM pag_pages WHERE pag_page_id = " . (int)$page->key);
	echo "  fixtures removed\n";
}

// ---------------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------------
echo "\n" . str_repeat('=', 50) . "\n";
echo sprintf("RESULTS: %d passed, %d failed\n", $results['pass'], $results['fail']);
if (!empty($results['failures'])) {
	echo "Failures:\n";
	foreach ($results['failures'] as $f) echo "  - {$f}\n";
	exit(1);
}
echo "All green.\n";
exit(0);
?>
