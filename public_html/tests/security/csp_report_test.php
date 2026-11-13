<?php
/** @joinery-test
 * name: csp_report
 * tier: db
 * env: dev-only
 * needs: [dev-web]
 */
/**
 * What a browser reports the strict script policy refused becomes one small,
 * deduplicated error row (specs/strict_script_policy.md WP2; CspReport,
 * logic/csp_report_logic.php).
 *
 * A report is a claim anyone can post, so: only a page on this site's own host
 * is kept, by path; the blocked item is an origin at most; the sample is cut
 * to 40 characters; the same page, directive, file and line is one row an
 * hour; one network records at most CspReport::MAX_PER_NETWORK_HOUR rows an
 * hour and every page together at most CspReport::MAX_PER_HOUR; and reports
 * go to their own channel, never the general API budget. The endpoint is checked over HTTP the way a browser calls it: no
 * sign-in, no CSRF header, Content-Type application/csp-report.
 *
 * Every row this test makes is removed at the end.
 *
 * Run: php tests/run.php --only=tests/security/csp_report_test.php
 *
 * @version 1.1 - the channel at /api/v1/csp-report, the per-network cap, the markup-free sample
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/http.php');
harness_boot();
harness_http_boot($argv);

$dblink = DbConnector::get_instance()->get_db_link();
$run = strtolower(LibraryFunctions::random_string(8));
$made_before = (int)$dblink->query("SELECT coalesce(max(err_general_error_id), 0) FROM err_general_errors")->fetchColumn();
$logged_before = (int)$dblink->query("SELECT coalesce(max(rql_request_log_id), 0) FROM rql_request_logs")->fetchColumn();
harness_defer(function () use ($dblink, $made_before, $logged_before) {
	$q = $dblink->prepare("DELETE FROM err_general_errors WHERE err_general_error_id > ? AND err_file LIKE ?");
	$q->execute(array($made_before, CspReport::FILE_PREFIX . '%/csp-test-%'));
	// The channel's request rows this run made, so the next run's per-network
	// count starts where this one did.
	$q = $dblink->prepare("DELETE FROM rql_request_logs WHERE rql_request_log_id > ? AND rql_feature = ?");
	$q->execute(array($logged_before, CspReport::FEATURE));
});

$host = 'site.example.test';
$report = function (array $over = array()) use ($host, $run) {
	return array_merge(array(
		'document-uri'        => "https://$host/csp-test-$run/page?token=secret#frag",
		'effective-directive' => 'script-src-attr',
		'blocked-uri'         => 'inline',
		'source-file'         => "https://$host/csp-test-$run/page?token=secret",
		'line-number'         => 12,
		'script-sample'       => 'doThing(1)',
	), $over);
};

section('A report is read narrowly');
$p = CspReport::parse($report(), $host);
check(is_array($p) && $p['page'] === "/csp-test-$run/page", 'the page is kept by path, without its query or fragment', json_encode($p));
check($p['source'] === "/csp-test-$run/page" && $p['line'] === 12 && $p['directive'] === 'script-src-attr', 'the file, line and directive are kept');
check(CspReport::parse($report(array('document-uri' => 'https://elsewhere.example/x')), $host) === null, "a page on another host is not this site's: dropped");
check(CspReport::parse($report(array('document-uri' => 'javascript:alert(1)')), $host) === null, 'neither is a page with no host');
$p2 = CspReport::parse($report(array('blocked-uri' => 'https://evil.example/a/b.js?k=v', 'effective-directive' => 'script-src-elem')), $host);
check($p2['blocked'] === 'https://evil.example', 'a blocked URL is kept as its origin only');
$p3 = CspReport::parse($report(array('script-sample' => str_repeat('x', 80) . "\n<b>", 'effective-directive' => "script-src'; drop")), $host);
check(strlen($p3['sample']) === CspReport::SAMPLE_CAP && $p3['directive'] === 'script-src', 'the sample is cut to 40 characters and the directive to its name');
$p4 = CspReport::parse($report(array('script-sample' => '<img src=x onerror="alert(1)">\'`&')), $host);
check(strpbrk($p4['sample'], '<>"\'`&') === false, 'no markup or quote character survives in the sample', $p4['sample']);
check(CspReport::parse($report(array('source-file' => 'https://cdn.example/x.js')), $host)['source'] === '', "another host's file is not named");
check(CspReport::describe($p) === "Strict script policy would refuse an inline handler on /csp-test-$run/page: doThing(1)", 'a handler reads as one');
check(strpos(CspReport::describe(CspReport::parse($report(array('effective-directive' => 'script-src-elem')), $host)), 'an inline script on') !== false, 'a block reads as an inline script');

section('A browser posts it, and it is recorded once');
$site_host = (string)parse_url(harness_http_base_url(), PHP_URL_HOST);
$post = function (string $body) {
	return harness_request('POST', PublicPageBase::CSP_REPORT_PATH, array(
		'body' => $body, 'encode' => 'raw', 'headers' => array('Content-Type: application/csp-report'),
	));
};
$live = array('csp-report' => array_merge($report(), array(
	'document-uri' => "https://$site_host/csp-test-$run/live", 'source-file' => "https://$site_host/csp-test-$run/live",
)));
$r = $post(json_encode($live));
check($r['status'] === 200 && ($r['json']['data']['recorded'] ?? null) === true, 'no sign-in and no CSRF header: the report is taken and recorded', $r['raw']);
$q = $dblink->prepare("SELECT err_level, err_message, err_line FROM err_general_errors WHERE err_file LIKE ? ORDER BY err_general_error_id DESC");
$q->execute(array(CspReport::FILE_PREFIX . "/csp-test-$run/live%"));
$rows = $q->fetchAll(PDO::FETCH_ASSOC);
check(count($rows) === 1 && $rows[0]['err_level'] === 'Warning' && (string)$rows[0]['err_line'] === '12'
	&& strpos($rows[0]['err_message'], "/csp-test-$run/live: doThing(1)") !== false,
	'one Warning row, saying what was refused where', json_encode($rows));
$r = $post(json_encode($live));
$q->execute(array(CspReport::FILE_PREFIX . "/csp-test-$run/live%"));
check(($r['json']['data']['recorded'] ?? null) === false && count($q->fetchAll()) === 1, 'the same report again within the hour adds nothing');
$r = $post(json_encode(array('csp-report' => $report())));
check($r['status'] === 200 && ($r['json']['data']['recorded'] ?? null) === false, "a report about another host's page is answered and dropped");
$r = $post('not json');
check($r['status'] === 200 && ($r['json']['data']['recorded'] ?? null) === false, 'a body that is no report is answered and dropped', $r['raw']);
$r = harness_request('GET', PublicPageBase::CSP_REPORT_PATH);
check($r['status'] === 405, 'a GET is refused', (string)$r['status']);
$q = $dblink->prepare("SELECT count(*) FROM rql_request_logs WHERE rql_request_log_id > ? AND rql_feature = 'api' AND rql_action ILIKE '%csp%'");
$q->execute(array($logged_before));
check((int)$q->fetchColumn() === 0, "reports never spend the general API budget: no 'api' request row was logged for them");

section('One network records at most its share in an hour');
$network_rows = function () use ($dblink) {
	$c = $dblink->prepare("SELECT count(*) FROM rql_request_logs WHERE rql_feature = ? AND rql_was_success
		AND rql_create_time > now() - interval '1 hour' AND rql_ip_network = (SELECT rql_ip_network FROM rql_request_logs
			WHERE rql_feature = ? ORDER BY rql_request_log_id DESC LIMIT 1)");
	$c->execute(array(CspReport::FEATURE, CspReport::FEATURE));
	return (int)$c->fetchColumn();
};
for ($i = 0; $i < CspReport::MAX_PER_NETWORK_HOUR + 2; $i++) {
	$one = $live;
	$one['csp-report']['document-uri'] = "https://$site_host/csp-test-$run/net-$i";
	$post(json_encode($one));
}
check($network_rows() === CspReport::MAX_PER_NETWORK_HOUR, 'recording stops at ' . CspReport::MAX_PER_NETWORK_HOUR . ' rows for the network',
	(string)$network_rows());

section('A flood of made-up pages stops at the hourly cap');
$count = function () use ($dblink) {
	$c = $dblink->prepare("SELECT count(*) FROM err_general_errors WHERE err_create_time > now() - interval '1 hour' AND err_file LIKE ?");
	$c->execute(array(CspReport::FILE_PREFIX . '%'));
	return (int)$c->fetchColumn();
};
$tries = 0;
while ($count() < CspReport::MAX_PER_HOUR && $tries < CspReport::MAX_PER_HOUR + 5) {
	CspReport::record(CspReport::parse($report(array('document-uri' => "https://$host/csp-test-$run/flood-$tries")), $host));
	$tries++;
}
check($count() === CspReport::MAX_PER_HOUR, 'the hour fills to the cap');
check(CspReport::record(CspReport::parse($report(array('document-uri' => "https://$host/csp-test-$run/one-more")), $host)) === null
	&& $count() === CspReport::MAX_PER_HOUR, 'and one more page past it records nothing');

harness_finish();
