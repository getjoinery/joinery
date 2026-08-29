<?php
/** @joinery-test
 * name: analytics_rollup
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */

/**
 * End-to-end coverage for the visitor-events rollup and prune.
 *
 * Seeds the (empty) test-database vse_visitor_events with a mix of old page
 * views, an old 404, an old conversion, and recent page views, runs
 * VisitorEvent::rollupAndPrunePageViews(), and checks that:
 *   - old page-view rows are collapsed into the two rollup tables and deleted,
 *   - the query string is stripped so /product?id=N collapse to one row,
 *   - the conversion row and the recent page views are left raw,
 *   - the distinct-visitor rollup carries the day's unique count,
 *   - a second run is idempotent (nothing left to do, no double count),
 *   - the reporting relation blends real visitors (recent) with the event-count
 *     stand-in (rolled up).
 *
 * The tables are created by update_database from the rollup data classes. If
 * they are not present yet the whole suite skips with that reason rather than
 * failing, so it is safe to run before the first schema sync.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();   // all writes below go to the copied test database

$dblink = DbConnector::get_instance()->get_db_link();

$have_tables = $dblink->query("SELECT to_regclass('vsr_visitor_stats_rollup') IS NOT NULL
	AND to_regclass('vsu_visitor_daily_uniques') IS NOT NULL")->fetchColumn();

if (!$have_tables) {
	section('Analytics rollup');
	harness_skip('rollup tables present', 'run update_database to create vsr_visitor_stats_rollup / vsu_visitor_daily_uniques');
	harness_finish();
	return;
}

// A visitor-id prefix unique to this run, so seed rows and cleanup never touch
// anything else that might share the day.
$tag = 'rlp' . substr((string)mt_rand(1000, 9999), 0, 4);
$old = gmdate('Y-m-d H:i:s', strtotime('-100 days'));
$now = gmdate('Y-m-d H:i:s');
$old_day = gmdate('Y-m-d', strtotime('-100 days'));

$PV   = VisitorEvent::TYPE_PAGE_VIEW;
$BUY  = VisitorEvent::TYPE_PURCHASE;

$seed = function($vid, $type, $page, $source, $is404, $ts) use ($dblink, $tag) {
	$q = $dblink->prepare(
		"INSERT INTO vse_visitor_events (vse_visitor_id, vse_type, vse_page, vse_source, vse_is_404, vse_timestamp)
		 VALUES (:vid, :t, :pg, :src, :f, :ts)");
	$q->execute(array(':vid' => $tag . $vid, ':t' => $type, ':pg' => $page, ':src' => $source, ':f' => $is404, ':ts' => $ts));
};

// Old page views: two visitors, /product with three query strings (→ one /product
// row, event_count 3, distinct visitors 2), plus an old 404 and an old purchase.
$seed('a', $PV, '/product?id=1', 'google', 'f', $old);
$seed('a', $PV, '/product?id=2', 'google', 'f', $old);
$seed('b', $PV, '/product?id=3', 'google', 'f', $old);
$seed('b', $PV, '/missing',      'google', 't', $old);
$seed('b', $BUY, '/checkout',    'google', 'f', $old);   // conversion — must survive
// Recent page views: two visitors on /home, inside the window.
$seed('c', $PV, '/home', null, 'f', $now);
$seed('d', $PV, '/home', null, 'f', $now);

// Clean up everything this run created, whatever happens.
harness_defer(function() use ($dblink, $tag, $old_day) {
	$dblink->prepare("DELETE FROM vse_visitor_events WHERE vse_visitor_id LIKE :p")->execute(array(':p' => $tag . '%'));
	$dblink->prepare("DELETE FROM vsr_visitor_stats_rollup WHERE vsr_day = :d")->execute(array(':d' => $old_day));
	$dblink->prepare("DELETE FROM vsu_visitor_daily_uniques WHERE vsu_day = :d")->execute(array(':d' => $old_day));
});

// --- Run the purge ------------------------------------------------------------
section('Rollup and prune');
$res = VisitorEvent::rollupAndPrunePageViews(90);
check(is_array($res) && isset($res['removed']), 'purge returns a result array');
check((int)$res['removed'] === 4, 'four old page-view-class rows pruned', 'removed=' . ($res['removed'] ?? 'null'));

// Old page views gone, conversion + recent page views kept.
$old_pv = $dblink->prepare("SELECT count(*) FROM vse_visitor_events WHERE vse_visitor_id LIKE :p AND vse_type = :t AND vse_timestamp < :cut");
$old_pv->execute(array(':p' => $tag . '%', ':t' => $PV, ':cut' => gmdate('Y-m-d H:i:s', strtotime('-1 day'))));
check((int)$old_pv->fetchColumn() === 0, 'old page-view rows deleted');

$conv = $dblink->prepare("SELECT count(*) FROM vse_visitor_events WHERE vse_visitor_id LIKE :p AND vse_type = :t");
$conv->execute(array(':p' => $tag . '%', ':t' => $BUY));
check((int)$conv->fetchColumn() === 1, 'conversion row kept raw');

$recent = $dblink->prepare("SELECT count(*) FROM vse_visitor_events WHERE vse_visitor_id LIKE :p AND vse_page = '/home'");
$recent->execute(array(':p' => $tag . '%'));
check((int)$recent->fetchColumn() === 2, 'recent page views kept raw');

// --- Rollup content -----------------------------------------------------------
section('Rollup content');
$prod = $dblink->prepare("SELECT vsr_event_count FROM vsr_visitor_stats_rollup WHERE vsr_day = :d AND vsr_page = '/product' AND vsr_is_404 = FALSE");
$prod->execute(array(':d' => $old_day));
check((int)$prod->fetchColumn() === 3, '/product query strings collapse to one row, event_count 3');

$notfound = $dblink->prepare("SELECT vsr_event_count FROM vsr_visitor_stats_rollup WHERE vsr_day = :d AND vsr_page = '/missing' AND vsr_is_404 = TRUE");
$notfound->execute(array(':d' => $old_day));
check((int)$notfound->fetchColumn() === 1, '404 row rolled up with is_404 set');

$uniq = $dblink->prepare("SELECT vsu_unique_visitors FROM vsu_visitor_daily_uniques WHERE vsu_day = :d AND vsu_type = :t");
$uniq->execute(array(':d' => $old_day, ':t' => $PV));
check((int)$uniq->fetchColumn() === 2, 'daily uniques rollup records 2 distinct visitors');

// --- Idempotent second run ----------------------------------------------------
section('Idempotency');
$res2 = VisitorEvent::rollupAndPrunePageViews(90);
check((int)$res2['removed'] === 0, 'second run prunes nothing (day already rolled up)');
$prod->execute(array(':d' => $old_day));
check((int)$prod->fetchColumn() === 3, 'event_count unchanged after second run (no double count)');

// --- Reporting relation blends raw + rollup -----------------------------------
section('Reporting relation');
$relation = AnalyticsRollup::pageview_relation();
$measure  = AnalyticsRollup::VISITORS;
$sql = "SELECT page, {$measure} AS c FROM {$relation}
        WHERE is_404 IS NOT TRUE AND (source = 'google' OR source IS NULL) AND page IN ('/home','/product')
        GROUP BY page ORDER BY page";
$q = $dblink->prepare($sql);
$q->bindValue(':av_start', gmdate('Y-m-d H:i:s', strtotime('-200 days')));
$q->bindValue(':av_end', $now);
$q->execute();
$counts = array();
foreach ($q->fetchAll(PDO::FETCH_OBJ) as $r) { $counts[$r->page] = (int)$r->c; }
check(($counts['/home'] ?? null) === 2, '/home counts 2 real distinct visitors (raw)', json_encode($counts));
check(($counts['/product'] ?? null) === 3, '/product counts 3 via event-count stand-in (rolled up)', json_encode($counts));

harness_finish();
