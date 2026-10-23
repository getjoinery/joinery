<?php
/** @joinery-test
 * name: edge_origin_probe
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * EdgeOriginProbe: is a site slow, or is the path to it slow?
 *
 * The comparison that settled the jeremytunnell.com stalls of 2026-10-23 by
 * hand, done by the management node (specs/slow_request_diagnosis_words.md
 * WP5). This suite holds what it decides without the network: which
 * addresses it times directly, how it counts a stall, its percentiles, and
 * the verdict a person reads first. And the node's reported addresses
 * (ManagedNode::record_reported_addresses), which give it the IPv6 path.
 *
 * Run: php plugins/server_manager/tests/edge_origin_probe_test.php
 *
 * @version 1.1 - an edge that challenges the probe is named in the verdict
 * @version 1.0
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

section('The node\'s reported addresses');

$node = new ManagedNode(NULL);
$node->set('mgn_host', '45.79.204.178');
check($node->record_reported_addresses(array('45.79.204.178', '2600:3c03:0:0:f03c:95ff:fe00:1', 'not-an-ip', 7, '10.0.0.5')),
	'a report of addresses is kept');
check($node->reported_addresses() === array('45.79.204.178', '2600:3c03::f03c:95ff:fe00:1', '10.0.0.5'),
	'valid addresses only, each in its canonical form', json_encode($node->reported_addresses()));
check(!$node->record_reported_addresses(array('45.79.204.178', '2600:3c03::f03c:95ff:fe00:1', '10.0.0.5')),
	'the same list again changes nothing');
check(!$node->record_reported_addresses(array()) && count($node->reported_addresses()) === 3,
	'an empty report is not a machine with no addresses: the list stands');
check(!$node->record_reported_addresses('45.79.204.178'), 'a report that is not a list changes nothing');

section('What is timed directly');

check(EdgeOriginProbe::origins($node) === array('45.79.204.178', '2600:3c03::f03c:95ff:fe00:1'),
	'the node\'s address and its reported public ones, IPv4 first; a private address is never timed',
	json_encode(EdgeOriginProbe::origins($node)));
$node->set('mgn_site_url', 'https://Jeremytunnell.com/');
check(EdgeOriginProbe::site($node) === array('host' => 'jeremytunnell.com', 'base' => 'https://jeremytunnell.com'),
	'the site is its public name over https');
$node->set('mgn_site_url', 'http://45.79.204.178/');
check(EdgeOriginProbe::site($node) === null, 'a site URL that is an address names no site to compare');

section('Counting');

check(EdgeOriginProbe::percentile(array(), 50) === null, 'no samples, no percentile');
check(EdgeOriginProbe::percentile(array(100, 200, 300, 400), 50) === 200 && EdgeOriginProbe::percentile(array(100, 200, 300, 400), 95) === 400,
	'percentiles by nearest rank');
$sum = EdgeOriginProbe::summarise(array(
	array('code' => 200, 'ms' => 120, 'errno' => 0),
	array('code' => 400, 'ms' => 6000, 'errno' => 0),
	array('code' => 0, 'ms' => 10000, 'errno' => 28),
	array('code' => 520, 'ms' => 300, 'errno' => 0),
	array('code' => 429, 'ms' => 50, 'errno' => 0),
));
check($sum['requests'] === 5 && $sum['stalls'] === 2, 'a slow answer and no answer are both stalls', json_encode($sum));
check($sum['errors'] === 2 && $sum['rate_limited'] === 1, 'no answer and a 5xx are errors; a 429 is the site\'s own limit, counted apart');
check($sum['statuses'] === array('200' => 1, '400' => 1, '429' => 1, '520' => 1, 'no answer' => 1), 'statuses by code');

section('The verdict');

$edge   = function ($stalls, $p95, $limited = 0) { return array('kind' => 'edge', 'requests' => 40, 'stalls' => $stalls, 'p95_ms' => $p95, 'rate_limited' => $limited); };
$direct = function ($stalls, $p95) { return array('kind' => 'direct', 'requests' => 40, 'stalls' => $stalls, 'p95_ms' => $p95, 'rate_limited' => 0); };
check(EdgeOriginProbe::verdict(array($edge(5, 9000), $direct(0, 300))) === 'Slow through the edge, fast direct (5 of 40 stalled through the edge, 0 of 40 direct).',
	'stalls through the edge only: the edge', EdgeOriginProbe::verdict(array($edge(5, 9000), $direct(0, 300))));
check(strpos(EdgeOriginProbe::verdict(array($edge(5, 9000), $direct(3, 8000))), 'Slow on the server') === 0, 'stalls direct too: the server');
check(strpos(EdgeOriginProbe::verdict(array($edge(0, 3500), $direct(0, 3400))), 'Slow on the server') === 0, 'both slow without stalling: the server');
check(strpos(EdgeOriginProbe::verdict(array($edge(0, 297), $direct(0, 297))), 'No problem seen (edge p95 297 ms, direct p95 297 ms).') === 0, 'neither: no problem');
check(strpos(EdgeOriginProbe::verdict(array($edge(0, 200, 3), $direct(0, 200))), '3 answered 429') !== false, 'a 429 is named, not counted as a stall');
check(EdgeOriginProbe::verdict(array()) === 'Nothing was measured.', 'nothing measured says so');
$challenged = $edge(0, 80); $challenged['statuses'] = array('403' => 40);
$plain = $direct(0, 300); $plain['statuses'] = array('200' => 20, '400' => 20);
check(strpos(EdgeOriginProbe::verdict(array($challenged, $plain)), 'may be challenging this probe') !== false,
	'fast 403s through the edge and none direct: the edge may be challenging the probe, and the verdict says so');
check(strpos(EdgeOriginProbe::verdict(array($edge(0, 200), $direct(0, 200))), 'challenging') === false, 'no 403s, no such note');

section('Its limits');

check(EdgeOriginProbe::RUNS_PER_HOUR <= 10, 'at most ten runs an hour: its POST legs count against the site\'s keyless limit');
check(EdgeOriginProbe::REQUEST_TIMEOUT * 1 + EdgeOriginProbe::BUDGET_SECONDS < 100,
	'it finishes inside the edge\'s 100 s in front of this management node');
check(EdgeOriginProbe::USER_AGENT === 'JoineryEdgeProbe/1', 'it says what it is');
$sc = new ReflectionMethod('SessionControl', 'crawlerDetect');
check($sc->invoke(SessionControl::get_instance(), EdgeOriginProbe::USER_AGENT) === true, 'a site never counts it as a visitor');

harness_finish();
