<?php
/** @joinery-test
 * name: target_location_rules
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The rules a backup target keeps (specs/storage_targets.md R1, R6, §5, WP2):
 *
 *   - a target's location (provider, endpoint, region, bucket, folder) is fixed
 *     once anything is stored in it; its name and key stay editable
 *   - it is not switched off while it is where new backups go or a node backs
 *     up to it, and not deleted while either holds or it still holds backups;
 *     each refusal is a sentence naming what still uses it
 *   - nothing is inferred from "the one enabled target": a node that names no
 *     target has none, and backup storage for customers has none until Where
 *     new backups go names one
 *   - a switched-off target is still read (listings, restores, pruning); only
 *     new backups need it switched on
 *   - a new node is given the target Where new backups go names
 *
 * Fixture rows only; the deployment's real targets are untouched.
 *
 * Run: php tests/backups/target_location_rules_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$suffix = bin2hex(random_bytes(3));
harness_set_setting_mem('backup_target_id', '0');
harness_set_setting_mem('server_manager_backup_target_id', '0');

$make_target = function (string $label, bool $enabled = true) use ($suffix) {
	$t = new BackupTarget(NULL);
	$t->set('bkt_name', 'HarnessTest ' . $label . ' ' . $suffix);
	$t->set('bkt_provider', 's3');
	$t->set('bkt_bucket', 'harness-' . strtolower($label) . '-' . $suffix);
	$t->set('bkt_path_prefix', 'joinery-backups');
	$t->set('bkt_credentials', array('access_key' => 'AKIAHARNESS', 'secret_key' => 'harness-secret',
		'region' => 'us-east-1', 'endpoint' => 'https://s3.us-east-1.amazonaws.com'));
	$t->set('bkt_enabled', $enabled);
	$t->save();
	harness_register_row('bkt_backup_targets', 'bkt_backup_target_id', $t->key);
	return new BackupTarget($t->key, TRUE);
};
$post = function (BackupTarget $t, array $extra = array()) {
	$c = $t->get_credentials();
	return array_merge(array(
		'bkt_name' => $t->get('bkt_name'), 'bkt_provider' => $t->get('bkt_provider'),
		'bkt_bucket' => $t->get('bkt_bucket'), 'bkt_path_prefix' => $t->get('bkt_path_prefix'),
		'access_key' => $c['access_key'], 'region' => $c['region'], 'bkt_enabled' => $t->get('bkt_enabled') ? '1' : '',
	), $extra);
};
$store_run = function (BackupTarget $t) {
	$h = new BackupHistory(NULL);
	$h->set('bkh_type', 'database');
	$h->set('bkh_outcome', 'success');
	$h->set('bkh_bkt_backup_target_id', (int)$t->key);
	$h->set('bkh_target_name', $t->get('bkt_name'));
	$h->set('bkh_upload_time', gmdate('Y-m-d H:i:s'));
	$h->save();
	harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $h->key);
	return $h;
};

// ── The location is fixed once used ─────────────────────────────────────
section('A target\'s location is fixed once anything is stored in it');

$t = $make_target('Fresh');
check($t->location_refusal() === '', 'an unused target may change where it points');
$r = BackupTargetForm::apply($t, $post($t, array('bkt_bucket' => 'harness-moved-' . $suffix)));
check($r['ok'] && $t->get('bkt_bucket') === 'harness-moved-' . $suffix, 'its bucket changes', $r['message']);

$t = $make_target('Used');
$store_run($t);
$why = $t->location_refusal();
check(strpos($why, '1 backup was stored in it') !== false && strpos($why, 'add a target') !== false,
	'a used target says why its location is fixed and what to do instead', $why);
foreach (array(
	'bucket'   => array('bkt_bucket' => 'harness-elsewhere-' . $suffix),
	'folder'   => array('bkt_path_prefix' => 'other-folder'),
	'region'   => array('region' => 'eu-west-1'),
	'provider' => array('bkt_provider' => 'wasabi', 'region' => 'us-east-1'),
) as $what => $change) {
	$fresh = new BackupTarget($t->key, TRUE);
	$r = BackupTargetForm::apply($fresh, $post($fresh, $change));
	check(!$r['ok'] && strpos($r['message'], 'cannot change') !== false, "its $what is refused", $r['message']);
}
$fresh = new BackupTarget($t->key, TRUE);
$r = BackupTargetForm::apply($fresh, $post($fresh, array('bkt_name' => 'HarnessTest Renamed ' . $suffix, 'access_key' => 'AKIAROTATED', 'secret_key' => 'rotated')));
check($r['ok'] && $fresh->get_credentials()['access_key'] === 'AKIAROTATED', 'its name and key stay editable', $r['message']);
$fresh = new BackupTarget($t->key, TRUE);
$r = BackupTargetForm::apply($fresh, $post($fresh, array('bkt_path_prefix' => '/joinery-backups/')));
check($r['ok'], 'the same folder written with slashes is the same location', $r['message']);

// ── Switching off and deleting ──────────────────────────────────────────
section('Switched off and deleted only when nothing needs it');

$why = $t->delete_refusal();
check(strpos($why, '1 backup is still stored in it') !== false, 'a target holding backups is not deleted, and says so', $why);
check($t->disable_refusal() === '', 'a target holding backups may be switched off: they stay readable');

$pruned_row = $store_run($t);
$db = DbConnector::get_instance()->get_db_link();
$db->prepare("UPDATE bkh_backup_history SET bkh_pruned_time = now(), bkh_delete_time = now() WHERE bkh_bkt_backup_target_id = ?")
	->execute(array((int)$t->key));
check($t->delete_refusal() === '', 'once retention has pruned its backups, it may be deleted');
check($t->location_refusal() !== '', 'its location stays fixed: the history still points there');

harness_set_setting_mem('backup_target_id', (string)$t->key);
check(strpos($t->disable_refusal(), 'where new backups go') !== false, 'the target new backups go to is not switched off', $t->disable_refusal());
check(strpos($t->delete_refusal(), 'where new backups go') !== false, 'the target new backups go to is not deleted', $t->delete_refusal());
$fresh = new BackupTarget($t->key, TRUE);
$r = BackupTargetForm::apply($fresh, $post($fresh, array('bkt_enabled' => '')));
check(!$r['ok'] && strpos($r['message'], 'cannot be switched off') !== false, 'the form refuses to switch it off', $r['message']);
harness_set_setting_mem('backup_target_id', '0');
$fresh = new BackupTarget($t->key, TRUE);
$r = BackupTargetForm::apply($fresh, $post($fresh, array('bkt_enabled' => '')));
check($r['ok'], 'once new backups go elsewhere, it may be switched off', $r['message']);

// ── Nodes, on a management node ─────────────────────────────────────────
if (class_exists('ManagedNode')) {
	section('A target nodes back up to');

	$nt = $make_target('NodeTarget');
	$node = new ManagedNode(NULL);
	$node->set('mgn_name', 'HarnessTest Node ' . $suffix);
	$node->set('mgn_slug', 'harnesstest-' . $suffix);
	$node->set('mgn_host', '192.0.2.10');
	$node->set('mgn_bkt_backup_target_id', (int)$nt->key);
	$node->save();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);

	$why = $nt->disable_refusal();
	check(strpos($why, 'HarnessTest Node ' . $suffix) !== false && strpos($why, 'move it') !== false,
		'it is not switched off while a node backs up to it, and the refusal names the node', $why);
	check(strpos($nt->delete_refusal(), 'HarnessTest Node ' . $suffix) !== false, 'nor deleted');
	check(strpos($nt->location_refusal(), 'backs up to it') !== false, 'and its location is fixed');

	// Reads go on when it is switched off; writes stop.
	$db->prepare("UPDATE bkt_backup_targets SET bkt_enabled = false WHERE bkt_backup_target_id = ?")->execute(array((int)$nt->key));
	$node = new ManagedNode($node->key, TRUE);
	$read = JobCommandBuilder::get_target($node);
	check($read && (int)$read->key === (int)$nt->key, 'a switched-off target is still read for the node');
	check(JobCommandBuilder::write_target($node) === null, 'and takes no new backup');

	section('Nothing is inferred');
	$bare = new ManagedNode(NULL);
	$bare->set('mgn_name', 'HarnessTest Bare ' . $suffix);
	$bare->set('mgn_slug', 'harnesstest-bare-' . $suffix);
	$bare->set('mgn_host', '192.0.2.11');
	$bare->save();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $bare->key);
	check(JobCommandBuilder::get_target($bare) === null, 'a node naming no target has none, however many are enabled');
	check(JoineryServices::shelfTarget() === null, 'backup storage for customers has no target until Where new backups go names one');

	$def = $make_target('Default');
	harness_set_setting_mem('server_manager_backup_target_id', (string)$def->key);
	check(JoineryServices::shelfTarget() && (int)JoineryServices::shelfTarget()->key === (int)$def->key,
		'Where new backups go is the backup storage target');
	$born = new ManagedNode(NULL);
	$born->assign_default_backup_target();
	check((int)$born->get('mgn_bkt_backup_target_id') === (int)$def->key, 'a new node is given the target Where new backups go names');
	$named = new ManagedNode(NULL);
	$named->set('mgn_bkt_backup_target_id', (int)$nt->key);
	$named->assign_default_backup_target();
	check((int)$named->get('mgn_bkt_backup_target_id') === (int)$nt->key, 'a new node that already names a target keeps it');
	$db->prepare("UPDATE bkt_backup_targets SET bkt_enabled = false WHERE bkt_backup_target_id = ?")->execute(array((int)$def->key));
	$born = new ManagedNode(NULL);
	$born->assign_default_backup_target();
	check(!$born->get('mgn_bkt_backup_target_id'), 'a switched-off default is not given to a new node');
	check(JoineryServices::shelfTarget() === null, 'nor used for backup storage');
} else {
	harness_skip('A target nodes back up to', 'server_manager is not active here');
}

harness_finish();
