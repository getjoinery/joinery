<?php
/** @joinery-test
 * name: fleet_backup_run_pass
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * A whole backup pass over two sites that share one machine, when one of them
 * is due a verification.
 *
 * A verification takes its machine's turn the way a backup does. The pass
 * keeps one map of busy machines for its whole length; a verify that is due
 * must add to that map, never replace it, or the rest of the pass loses track
 * of every machine and the next sibling's backup starts beside the verify.
 *
 * Run as a dry pass, so nothing is dispatched. Rows go to the test database,
 * which carries no nodes of its own, and are deleted.
 *
 * Run: php tests/run.php --only=plugins/server_manager/tests/fleet_backup_run_pass_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
require_once(PathHelper::getIncludePath('plugins/server_manager/tasks/FleetBackupRun.php'));

$suffix = getmypid() . '-' . random_int(1000, 9999);

$host = new ManagedHost(NULL);
$host->set('mgh_slug', 'fbrp-host-' . $suffix);
$host->set('mgh_name', 'fbrp-host-' . $suffix);
$host->set('mgh_host', '198.51.100.60');
$host->save();
$host->load();
harness_register_row('mgh_managed_hosts', 'mgh_managed_host_id', $host->key);

/** A container site on the fixture host, past install, holding a proven recovery key. */
function fbrp_node(string $slug, int $host_id, array $extra) {
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', $slug);
	$node->set('mgn_slug', $slug);
	$node->set('mgn_host', '198.51.100.60');
	$node->set('mgn_ssh_user', 'root');
	$node->set('mgn_container_name', $slug);
	$node->set('mgn_enabled', true);
	$node->set('mgn_web_root', '/var/www/html/' . $slug . '/public_html');
	$node->set('mgn_mgh_managed_host_id', $host_id);
	$node->set('mgn_last_status_data', array('backup_recovery_state' => 'proven'));
	$node->set('mgn_backup_recovery_fpr', str_repeat('c', 64));
	// A window opening at midnight UTC: the slot has always passed by the time the pass runs.
	$node->set('mgn_backup_policy', array('window_start' => '00:00', 'window_minutes' => 1));
	foreach ($extra as $field => $value) { $node->set($field, $value); }
	$node->prepare();
	$node->save();
	$node->load();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
	return $node;
}

// Passed in name order: the verify comes first, the sibling's backup after it.
$verifying = fbrp_node('fbrp-a-' . $suffix, (int)$host->key, array(
	'mgn_last_backup_outcome' => 'success',
	'mgn_last_backup_time'    => gmdate('Y-m-d H:i:s', time() - 86400),
));
$sibling = fbrp_node('fbrp-b-' . $suffix, (int)$host->key, array());

section('A verify that is due holds its machine for the rest of the pass');

$result = null;
$error = '';
try {
	$result = (new FleetBackupRun())->dryRun(array());
} catch (Throwable $e) {
	$error = get_class($e) . ': ' . $e->getMessage();
}
$message = is_array($result) ? (string)$result['message'] : '';

check($error === '', 'the pass completes when a verification is due', $error);
check(strpos($message, 'would verify 1 node') !== false && strpos($message, $verifying->get('mgn_slug')) !== false,
	'the site due a verification is verified', $message);
check(strpos($message, $sibling->get('mgn_slug') . ' (waiting for ' . $verifying->get('mgn_slug')
		. '\'s verification on the same machine)') !== false,
	'its sibling on the same machine waits for the verification instead of backing up beside it', $message);
check(strpos($message, 'none are due') !== false,
	'nothing else is backed up', $message);

harness_finish();
