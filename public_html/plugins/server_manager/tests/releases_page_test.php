<?php
/** @joinery-test
 * name: releases_page
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The public releases page's logic (/server_manager/releases; spec
 * release_transparency, D7, O5), on the box that publishes releases: one row
 * per logged statement, newest first, each with its commits and log entry;
 * one row per log nodes trust; the verify command's source is this site;
 * each release names the keys it installs, each log key with Sigstore's answer.
 *
 * Run: php tests/run.php db --filter=releases_page
 *
 * @version 1.1 - the keys each release installs
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/server_manager/logic/releases_logic.php'));

if (!ReleaseLogWatch::watches()) {
	check(releases_logic(array())->data['is_valid_page'] === false, 'a site that does not publish releases says so');
	harness_finish();
}

$vars = releases_logic(array())->data;
check($vars['is_valid_page'] === true, 'the publishing site shows the page');
$ledger = count(new MultiReleaseLogEntry(array()));
check(count($vars['releases']) === $ledger, 'one row per logged statement', count($vars['releases']) . ' of ' . $ledger);
$indexes = array_column($vars['releases'], 'log_index');
$sorted = $indexes; rsort($sorted);
check($indexes === $sorted, 'newest first');
foreach ($vars['releases'] as $r) {
	check(preg_match('/^[0-9a-f]{40}$/', $r['core_commit']) && preg_match('/^[0-9a-f]{40}$/', $r['agent_commit']) && $r['log_origin'] !== '',
		"{$r['version']} names both commits and its log entry");
}
foreach ($vars['releases'] as $r) {
	check(count($r['keys']['statement']) >= 1 && count($r['keys']['log']) >= 1
		&& !array_diff(array_column($r['keys']['log'], 'sigstore'), array('listed', 'not listed', 'not checked yet')),
		"{$r['version']} names the keys it installs, each log key with Sigstore's answer", json_encode($r['keys']));
}
check(array_column($vars['logs'], 'origin') === ReleaseLogTail::logOrigins(), 'one row per log nodes trust');
check(strpos($vars['source'], 'https://') === 0 && substr($vars['source'], -1) !== '/', 'the verify command names this site as the source', $vars['source']);
check(json_encode($vars) !== false, 'everything it returns is plain data, as the API serves it');

harness_finish();
