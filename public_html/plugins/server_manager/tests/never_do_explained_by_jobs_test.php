<?php
/** @joinery-test
 * name: never_do_explained_by_jobs
 * tier: test-db
 * env: dev-only
 * needs: []
 * timeout: 120
 */
/**
 * NeverDoCheck::explained_by_jobs (specs/implemented/never_do_false_alarms.md,
 * live check 2026-11-08): a machine-changing job on a node explains its
 * review list's changes for 30 minutes; so does one on a container site the
 * node hosts, since a host runs the check from a site tree on it (docker-prod
 * listed its engine files after its sites upgraded). A job on an unrelated
 * node, an old one, and a routine type explain nothing.
 *
 * Rows go to the test database and are deleted.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();

$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$node = function (string $tag, ?int $host_id = null) use ($suffix) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest never-do ' . $tag . ' ' . $suffix);
	$n->set('mgn_slug', 'nd-' . $tag . '-' . $suffix);
	$n->set('mgn_host', '198.51.100.' . random_int(2, 250));
	if ($host_id) { $n->set('mgn_mgh_managed_host_id', $host_id); }
	$n->save();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', (int)$n->key);
	return $n;
};
$job = function ($n, string $type, int $ago) {
	$j = new ManagementJob(NULL);
	$j->set('mjb_mgn_managed_node_id', (int)$n->key);
	$j->set('mjb_job_type', $type);
	$j->set('mjb_status', 'completed');
	$j->set('mjb_commands', array());
	$j->set('mjb_completed_time', gmdate('Y-m-d H:i:s', time() - $ago));
	$j->save();
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', (int)$j->key);
	return $j;
};

$mh = new ManagedHost(NULL);
$mh->set('mgh_slug', 'nd-host-' . $suffix);
$mh->set('mgh_name', 'HarnessTest never-do host ' . $suffix);
$mh->set('mgh_host', '198.51.100.251');
$mh->save();
harness_register_row('mgh_managed_hosts', 'mgh_managed_host_id', (int)$mh->key);
$host = $node('host', (int)$mh->key);
$mh->set('mgh_mgn_managed_node_id', (int)$host->key);
$mh->save();
$site = $node('site', (int)$mh->key);
$other = $node('other');

section('A job on the node itself');

$now = time();
check(!NeverDoCheck::explained_by_jobs((int)$host->key, $now), 'a node with no jobs is not explained');
$job($host, 'never_do_check', 60);
check(!NeverDoCheck::explained_by_jobs((int)$host->key, $now), 'a routine check explains nothing');
$job($host, 'apply_update', NeverDoCheck::EXPLAIN_WINDOW_SECONDS + 600);
check(!NeverDoCheck::explained_by_jobs((int)$host->key, $now), 'an upgrade finished more than 30 minutes ago explains nothing');

section('A job on a container site the node hosts');

$job($site, 'apply_update', 300);
check(NeverDoCheck::explained_by_jobs((int)$host->key, $now), 'a site upgrade on the host explains the host\'s changes');
check(NeverDoCheck::explained_by_jobs((int)$site->key, $now), 'and the site\'s own');
check(!NeverDoCheck::explained_by_jobs((int)$other->key, $now), 'but not an unrelated node\'s');

section('A job on an unrelated node');

$job($other, 'restart_container', 60);
$lone = $node('lone');
check(!NeverDoCheck::explained_by_jobs((int)$lone->key, $now), 'another node\'s job explains nothing here');

harness_finish();
