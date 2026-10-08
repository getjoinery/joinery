<?php
/** @joinery-test
 * name: storage_spaces
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * Storage spaces on the management node (specs/storage_targets.md §3, §5, WP4),
 * over the loopback S3 fixture with two buckets standing in for two providers:
 *
 *   - an owner's folder on a target is a space; two owners never share or nest
 *     a folder on one target (customer t5 and a node slugged t5)
 *   - Move one owner: the new target opens a space, the old one drains, and
 *     moving back gives the old space back; new backups go only to the active
 *     space, reads still reach the draining one
 *   - a node's retention runs across its spaces, each point deleted from its
 *     own bucket; a draining space is kept whole until the active space holds
 *     a verified chain, then ages out and is retired once empty
 *   - emptying a node's backup storage deletes only what this management node
 *     took (manager/), never the site's own backups beside them (S22)
 *   - Move everyone off moves every active owner; a switched-off target takes
 *     nobody
 *   - an unclaimed folder is adopted as a draining space, once
 *   - a customer's ledger is reconciled against each space's own target, and
 *     retention keeps a draining space until the active one holds a finished
 *     run, then prunes it and keeps the ledger rows, marked
 *   - a target's refusals name the owners of its spaces
 *
 * Run: php plugins/server_manager/tests/storage_spaces_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('tests/lib/s3_fixtures.php'));

$fx = s3fx_start();
if ($fx === null) {
	harness_skip('storage spaces', 'could not start a local PHP HTTP server on 127.0.0.1');
	harness_finish();
}
harness_defer(function () use ($fx) { s3fx_stop($fx); });
$creds = s3fx_creds($fx);
$suffix = bin2hex(random_bytes(3));
harness_set_setting_mem('server_manager_backup_target_id', '0');

$make_target = function (string $label, string $bucket, bool $enabled = true) use ($creds, $suffix) {
	$t = new BackupTarget(NULL);
	$t->set('bkt_name', 'HarnessTest ' . $label . ' ' . $suffix);
	$t->set('bkt_provider', 's3');
	$t->set('bkt_bucket', $bucket);
	$t->set('bkt_path_prefix', '/harness//spaces/');
	$t->set('bkt_credentials', $creds);
	$t->set('bkt_enabled', $enabled);
	$t->save();
	harness_register_row('bkt_backup_targets', 'bkt_backup_target_id', $t->key);
	return new BackupTarget($t->key, TRUE);
};
$make_node = function (string $label) use ($suffix) {
	$n = new ManagedNode(NULL);
	$n->set('mgn_name', 'HarnessTest Space ' . $label . ' ' . $suffix);
	$n->set('mgn_slug', 'harnesssp-' . strtolower($label) . '-' . $suffix);
	$n->set('mgn_host', '192.0.2.40');
	$n->save();
	harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $n->key);
	return new ManagedNode($n->key, TRUE);
};
$track = function (?StorageSpace $space) {
	if ($space && $space->key) {
		harness_register_row('sps_storage_spaces', 'sps_storage_space_id', $space->key);
	}
	return $space;
};
$put = function (string $bucket, string $key, string $bytes = 'x') use ($creds) {
	$f = tempnam(sys_get_temp_dir(), 'sps');
	file_put_contents($f, $bytes);
	S3Signer::put_file($creds, $bucket, '/' . $key, $f);
	@unlink($f);
};
$has = function (string $bucket, string $key) use ($fx) {
	return in_array($bucket . '/' . $key, s3fx_keys($fx), true);
};

$a = $make_target('A', 'spa');
$b = $make_target('B', 'spb');
check($a->get('bkt_path_prefix') === 'harness/spaces', 'a target\'s folder is stored in its one form (S11)', (string)$a->get('bkt_path_prefix'));

// ── Opening ─────────────────────────────────────────────────────────────
section('A space is one owner\'s folder on one target');
$node = $make_node('One');
$slug = (string)$node->get('mgn_slug');
$sa = $track(StorageSpace::open($a, StorageSpace::OWNER_NODE, (int)$node->key, $slug));
check($sa->base() === 'harness/spaces/' . $slug . '/' && $sa->folder() === $slug && $sa->prefix_part() === 'harness/spaces',
	'its base key is {target folder}/{owner folder}/', $sa->base());
check((int)JobCommandBuilder::get_target($node)->key === (int)$a->key, 'the node backs up to the target of its active space');

$owner = make_user('SpaceOwner');
$tenant = new ServiceTenant(NULL);
$tenant->set('svt_usr_user_id', (int)$owner->key);
$tenant->set('svt_service', ServiceTenant::SERVICE_SHELF);
$tenant->set('svt_state', ServiceTenant::STATE_ACTIVE);
$tenant->set('svt_paid_until', '2036-01-01 00:00:00');
$tenant->set('svt_slug', $slug);
$tenant->set('svt_host', 'spaces-' . $suffix . '.example.com');
$tenant->save();
harness_register_row('svt_service_tenants', 'svt_service_tenant_id', $tenant->key);
$why = '';
try { StorageSpace::open($a, StorageSpace::OWNER_TENANT, (int)$tenant->key, $slug); }
catch (StorageSpaceException $e) { $why = $e->getMessage(); }
check(strpos($why, 'Two owners never share a folder') !== false, 'a customer is refused a folder a node already has on the target', $why);
$tenant->set('svt_slug', 't' . (int)$tenant->key);
$tenant->save();

// ── Moving one owner ────────────────────────────────────────────────────
section('Move one owner');
$sb = $track(StorageSpace::move(StorageSpace::OWNER_NODE, (int)$node->key, $b));
$sa = new StorageSpace($sa->key, TRUE);
check($sb->is_active() && (int)$sb->get('sps_bkt_backup_target_id') === (int)$b->key, 'the new target opens an active space');
check($sa->is_draining() && $sa->get('sps_draining_time') !== null, 'the old space starts draining');
check((int)JobCommandBuilder::write_target($node)->key === (int)$b->key, 'new backups go only to the new target');
check(JobCommandBuilder::node_space($node, (int)$sa->key) !== null, 'the old space is still read for the node');
check(strpos($a->delete_refusal(), 'older backups of HarnessTest Space One') !== false,
	'the old target is not deleted while it keeps the node\'s older backups', $a->delete_refusal());
check($a->disable_refusal() === '', 'it may be switched off: nothing new goes there', $a->disable_refusal());
check(strpos($b->disable_refusal(), 'HarnessTest Space One') !== false, 'the new target is not switched off while the node backs up to it');
$back = StorageSpace::move(StorageSpace::OWNER_NODE, (int)$node->key, $a);
check((int)$back->key === (int)$sa->key && $back->is_active(), 'moving back gives the old space back, not a second record');
check((new StorageSpace($sb->key, TRUE))->is_draining(), 'and the other one drains');
StorageSpace::move(StorageSpace::OWNER_NODE, (int)$node->key, $b);
$sa = new StorageSpace($sa->key, TRUE);
$sb = new StorageSpace($sb->key, TRUE);

// A download is signed in the space the backup is in, and checked against it.
$dl = JobCommandBuilder::build_download_backup_primitive($node, array('filename' => 'db.sql.gz.enc', 'profile' => 'manager',
	'space_id' => (int)$sa->key, 'cloud_path' => $sa->base() . 'manager/db.sql.gz.enc'));
check(strpos((string)$dl['params']['url'], '/spa/' . $sa->base()) !== false, 'a backup in the old space is signed against the old bucket');
$mm = '';
try {
	JobCommandBuilder::build_download_backup_primitive($node, array('filename' => 'db.sql.gz.enc', 'profile' => 'manager',
		'space_id' => (int)$sb->key, 'cloud_path' => 'harness/spaces/someone-else/manager/db.sql.gz.enc'));
} catch (Exception $e) { $mm = $e->getMessage(); }
check(strpos($mm, 'not in node') !== false, 'a key outside the named space is refused', $mm);
$mm = '';
try {
	JobCommandBuilder::build_download_backup_primitive($node, array('filename' => 'db.sql.gz.enc', 'profile' => 'site',
		'space_id' => (int)$sb->key, 'cloud_path' => $sb->base() . 'manager/db.sql.gz.enc'));
} catch (Exception $e) { $mm = $e->getMessage(); }
check(strpos($mm, "'manager' backup storage") !== false && strpos($mm, "'site' one") !== false,
	'a profile that disagrees with the object\'s own backup storage is refused', $mm);
$other = $make_node('Other');
$mm = '';
try {
	JobCommandBuilder::build_download_backup_primitive($other, array('filename' => 'db.sql.gz.enc', 'profile' => 'manager',
		'space_id' => (int)$sa->key, 'cloud_path' => $sa->base() . 'manager/db.sql.gz.enc'));
} catch (Exception $e) { $mm = $e->getMessage(); }
check(strpos($mm, 'is not node') !== false, 'one node cannot name another node\'s space', $mm);

$off = $make_target('Off', 'spoff', false);
$why = '';
try { StorageSpace::move(StorageSpace::OWNER_NODE, (int)$node->key, $off); } catch (StorageSpaceException $e) { $why = $e->getMessage(); }
check(strpos($why, 'switched on') !== false, 'nobody is moved to a switched-off target', $why);

// ── A node's retention across its spaces ────────────────────────────────
section('Retention across spaces; the old space is kept until the new one verifies');
$old1 = 'chain-20200101_000000';
$old2 = 'chain-20200201_000000';
$new1 = 'chain-' . gmdate('Ymd_His', time() - 86400);
foreach (array($old1, $old2) as $c) {
	$put('spa', $sa->base() . 'manager/' . $c . '/manifest.json', '{}');
	$put('spa', $sa->base() . 'manager/' . $c . '/files-0000.tar.gz.enc');
}
$put('spa', $sa->base() . 'manager/objects/epoch-20200101_000000/envelope.json', '{}');
$put('spb', $sb->base() . 'manager/' . $new1 . '/manifest.json', '{}');

$r = FleetBackupRetention::prune($node, 7);
check($r['error'] === '' && $r['listed'] && (int)$r['space']->key === (int)$sb->key, 'the pass lists every space, the active one reported', $r['error']);
check($has('spa', $sa->base() . 'manager/' . $old1 . '/manifest.json'), 'before a verify, the draining space is kept whole');

$job = new ManagementJob(NULL);
$job->set('mjb_mgn_managed_node_id', (int)$node->key);
$job->set('mjb_job_type', 'verify_backup');
$job->set('mjb_status', 'completed');
$job->set('mjb_commands', array());
$job->set('mjb_parameters', json_encode(array('chain_id' => $new1, 'space_id' => (int)$sb->key, 'profile' => 'manager')));
$job->set('mjb_result', json_encode(array('verify_status' => 'pass', 'level' => 2)));
$job->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
$job->save();
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);
check(FleetBackupRetention::active_verified($node, $sb), 'a passed verify naming the active space counts');

$r = FleetBackupRetention::prune($node, 7);
check(!$has('spa', $sa->base() . 'manager/' . $old1 . '/manifest.json'), 'once verified, the old space ages out: its oldest chain is deleted from its own bucket');
check($has('spa', $sa->base() . 'manager/' . $old2 . '/manifest.json'), 'the newest point before the window is kept, wherever it is');
check($has('spb', $sb->base() . 'manager/' . $new1 . '/manifest.json'), 'the active space is untouched');
check((new StorageSpace($sa->key, TRUE))->is_draining(), 'a draining space that still holds a kept point is not retired');

$new0 = 'chain-' . gmdate('Ymd_His', time() - 10 * 86400);
$put('spb', $sb->base() . 'manager/' . $new0 . '/manifest.json', '{}');
$r = FleetBackupRetention::prune($node, 7);
check(!$has('spa', $sa->base() . 'manager/' . $old2 . '/manifest.json')
	&& !$has('spa', $sa->base() . 'manager/objects/epoch-20200101_000000/envelope.json'),
	'when none of its points is kept, the rest of the old space goes too', json_encode(s3fx_keys($fx)));
check((new StorageSpace($sa->key, TRUE))->is_retired() && (int)$r['retired'] === 1, 'and the empty space is retired');
check($a->delete_refusal() === '', 'the old target then holds nothing of the node\'s and may be deleted', $a->delete_refusal());

// ── Emptying a node's backup storage (S22) ──────────────────────────────
section('Emptying takes only what this management node took');
$put('spb', $sb->base() . 'site/chain-20260101_000000/manifest.json', '{}');
$deleted = NodeBackupShelf::prune(new ManagedNode($node->key, TRUE));
check($deleted >= 2 && !$has('spb', $sb->base() . 'manager/' . $new1 . '/manifest.json'), 'the manager backups go', (string)$deleted);
check($has('spb', $sb->base() . 'site/chain-20260101_000000/manifest.json'), 'the site\'s own backups beside them stay');
$count = StorageSpace::owner_object_count(StorageSpace::OWNER_NODE, (int)$node->key);
check($count['count'] === 1 && !$count['unchecked'], 'the delete guard counts what the node\'s spaces still hold', json_encode($count));

// ── Move everyone off; adopt ────────────────────────────────────────────
section('Move everyone off, and adopt an unclaimed folder');
$node2 = $make_node('Two');
$s2 = $track($node2->open_default_backup_space());
check($s2 === null, 'with nothing named as where new backups go, a new node gets no space');
harness_set_setting_mem('server_manager_backup_target_id', (string)$b->key);
$s2 = $track($node2->open_default_backup_space());
check($s2 && (int)$s2->get('sps_bkt_backup_target_id') === (int)$b->key, 'a new node opens its space where new backups go');
harness_set_setting_mem('server_manager_backup_target_id', (string)$a->key);
$moved = StorageSpace::move_everyone_off($b, $a);
check(count($moved['moved']) === 2 && !$moved['refused'], 'every owner backing up to the target is moved', json_encode($moved));
check(!StorageSpace::holdings_of((int)$b->key)['active'], 'nobody backs up to it any more');
$track(StorageSpace::active_for(StorageSpace::OWNER_NODE, (int)$node->key));
$track(StorageSpace::active_for(StorageSpace::OWNER_NODE, (int)$node2->key));

$put('spa', 'harness/spaces/gone-site-' . $suffix . '/manager/chain-20250101_000000/manifest.json',
	json_encode(array('chain_id' => 'chain-20250101_000000', 'runs' => array(array('seq' => 0, 'level' => 0, 'time' => '2025-01-01 00:00:00', 'artifacts' => array())))));
$put('spa', 'harness/spaces/' . $slug . '/manager/chain-20260301_000000/manifest.json', '{}');
$groups = FleetBackups::list_grouped($a)['groups'];
check(in_array($groups['gone-site-' . $suffix]['status'] ?? '', array('unclaimed', 'orphaned'), true), 'a folder no space claims is unclaimed');
check(($groups[$slug]['status'] ?? '') === 'live', 'a folder an active space claims is live');
$adopted = $track(StorageSpace::adopt($a, 'gone-site-' . $suffix, StorageSpace::OWNER_NODE, (int)$node2->key));
check($adopted->is_draining() && $adopted->owner_id() === (int)$node2->key, 'it is adopted as the owner\'s draining space');
check(FleetBackups::list_grouped($a)['groups']['gone-site-' . $suffix]['status'] === 'draining', 'and listed as kept, aging out');
$why = '';
try { StorageSpace::adopt($a, 'gone-site-' . $suffix, StorageSpace::OWNER_NODE, (int)$node->key); } catch (StorageSpaceException $e) { $why = $e->getMessage(); }
check(strpos($why, 'already belongs') !== false, 'a folder is adopted once', $why);
$chains = BackupChainListHelper::for_node(new ManagedNode($node2->key, TRUE));
$ids = array_column($chains['chains'], 'space_id', 'chain_id');
check(($ids['chain-20250101_000000'] ?? 0) === (int)$adopted->key,
	'the adopted folder\'s chains are the node\'s to list, naming their space', json_encode($chains));

// ── A customer's ledger across its spaces ───────────────────────────────
section('A customer\'s ledger: each space against its own target');
$ta = $track(StorageSpace::open($a, StorageSpace::OWNER_TENANT, (int)$tenant->key, 't' . (int)$tenant->key));
$x_key = $ta->base() . 'site/chain-20260101_000000/db';
$put('spa', $x_key, str_repeat('a', 11));
$x = new ShelfObject(NULL);
$x->set('svo_svt_service_tenant_id', (int)$tenant->key);
$x->set('svo_sps_storage_space_id', (int)$ta->key);
$x->set('svo_key', $x_key);
$x->set('svo_bytes', 11);
$x->set('svo_chain', 'chain-20260101_000000');
$x->set('svo_completed_time', gmdate('Y-m-d H:i:s'));
$x->save();
harness_register_row('svo_shelf_objects', 'svo_shelf_object_id', $x->key);

$tb = $track(StorageSpace::move(StorageSpace::OWNER_TENANT, (int)$tenant->key, $b));
$y_key = $tb->base() . 'site/chain-20260201_000000/db';
$put('spb', $y_key, str_repeat('b', 5));
$why = '';
try { ShelfBroker::beginRun($tenant, 'site', 'chain-20260101_000000', array(array('name' => 'chain-20260101_000000/more', 'bytes' => 1))); }
catch (ShelfBrokerException $e) { $why = $e->getMessage(); }
check(strpos($why, 'not extended') !== false, 'a chain left in the space it moved away from is not extended', $why);

$watch = new ServiceTenantWatch();
harness_set_setting_mem('server_manager_services_shelf_keep_chains', '1');
$watch->watch(new ServiceTenant($tenant->key, TRUE), gmdate('Y-m-d H:i:s'));
$xl = ShelfObject::forKey((int)$ta->key, $x_key);
$yl = ShelfObject::forKey((int)$tb->key, $y_key);
if ($yl) { harness_register_row('svo_shelf_objects', 'svo_shelf_object_id', $yl->key); }
check($xl !== null, 'the draining space\'s row is kept: its own bucket lists it');
check($yl !== null && (int)$yl->get('svo_bytes') === 5, 'an object in the new space is adopted there at its listed size');
check(ShelfObject::completedBytes((int)$tenant->key) === 16, 'the figure counts both spaces');
check($has('spa', $x_key), 'with no finished run in the new space, the old chain is kept whole');

$run = new ShelfRun(NULL);
$run->set('svr_svt_service_tenant_id', (int)$tenant->key);
$run->set('svr_sps_storage_space_id', (int)$tb->key);
$run->set('svr_chain', 'chain-20260201_000000');
$run->set('svr_base_key', $tb->base() . 'site/');
$run->set('svr_state', ShelfRun::STATE_FINISHED);
$run->save();
harness_register_row('svr_shelf_runs', 'svr_shelf_run_id', $run->key);
check(!ServiceTenantWatch::holds_finished_run(new StorageSpace($tb->key, TRUE)),
	'a finished run that stored nothing does not release the old space');
$yl->set('svo_svr_shelf_run_id', (int)$run->key);
$yl->save();
$tenant = new ServiceTenant($tenant->key, TRUE);
$tenant->set('svt_reconciled_time', gmdate('Y-m-d H:i:s'));
$tenant->save();
$watch->watch($tenant, gmdate('Y-m-d H:i:s'));
check(!$has('spa', $x_key), 'once the new space holds a finished run with something stored, the old chain is pruned from its own bucket');
check($has('spb', $y_key), 'the new chain stays');
$xr = new ShelfObject($x->key, TRUE);
check($xr->get('svo_pruned_time') !== null && (string)$xr->get('svo_pruned_cause') === 'retention', 'its ledger row is kept, marked pruned by retention');
check((new StorageSpace($ta->key, TRUE))->is_retired(), 'and the emptied space is retired');

// ── Review fixes (reviewer1, 10-08) ─────────────────────────────────────
section('A run opened before a move writes nothing into the old space');
$open_run = ShelfBroker::beginRun($tenant, 'site', 'chain-20260301_000000', array(array('name' => 'chain-20260301_000000/db', 'bytes' => 1)));
harness_register_row('svr_shelf_runs', 'svr_shelf_run_id', (int)$open_run['run_id']);
$tc = $track(StorageSpace::move(StorageSpace::OWNER_TENANT, (int)$tenant->key, $a));
$why = '';
try { ShelfBroker::sign($tenant, (int)$open_run['run_id'], 'chain-20260301_000000/db', 'put', array('bytes' => 1)); }
catch (ShelfBrokerException $e) { $why = $e->getMessage(); }
check(strpos($why, 'moved to another target') !== false, 'a write for a run whose space is now draining is refused', $why);
check(StorageSpace::active_for(StorageSpace::OWNER_TENANT, (int)$tenant->key)->get('sps_opened_time')
	>= (new StorageSpace($tb->key, TRUE))->get('sps_draining_time'),
	'a space given back to its owner is stamped opened again');

section('One space that cannot be read does not stop the listing');
$bad = $make_target('Bad', 'spbad');
DbConnector::get_instance()->get_db_link()->prepare("UPDATE bkt_backup_targets SET bkt_credentials = ? WHERE bkt_backup_target_id = ?")
	->execute(array(json_encode(array('enc' => 'v1.sodium.not-a-sealed-value')), (int)$bad->key));
$put('spb', $tb->base() . 'site/chain-20260201_000000/more', 'm');
$bad_space = $track(StorageSpace::adopt(new BackupTarget($bad->key, TRUE), 't' . (int)$tenant->key, StorageSpace::OWNER_TENANT, (int)$tenant->key));
$listed = ShelfBroker::listPrefix(new ServiceTenant($tenant->key, TRUE));
$errs = array_filter($listed['spaces'], function ($sp) { return !empty($sp['error']); });
$keys = array_column($listed['objects'], 'key');
check(count($errs) === 1 && in_array('site/chain-20260201_000000/more', $keys, true),
	'the unreadable space is named, and the others still list', json_encode($listed['spaces']));

section('Retention reads every space newest first');
$node3 = $make_node('Three');
$n3a = $track(StorageSpace::open($a, StorageSpace::OWNER_NODE, (int)$node3->key, (string)$node3->get('mgn_slug')));
$n3b = $track(StorageSpace::move(StorageSpace::OWNER_NODE, (int)$node3->key, $b));
$n3a = new StorageSpace($n3a->key, TRUE);
$a35 = 'chain-' . gmdate('Ymd_His', time() - 35 * 86400);
$b45 = 'chain-' . gmdate('Ymd_His', time() - 45 * 86400);
$b01 = 'chain-' . gmdate('Ymd_His', time() - 86400);
$put('spa', $n3a->base() . 'manager/' . $a35 . '/manifest.json', '{}');
$put('spb', $n3b->base() . 'manager/' . $b45 . '/manifest.json', '{}');
$put('spb', $n3b->base() . 'manager/' . $b01 . '/manifest.json', '{}');
$put('spa', $n3a->base() . 'site/chain-20260101_000000/manifest.json', '{}');
$v3 = new ManagementJob(NULL);
$v3->set('mjb_mgn_managed_node_id', (int)$node3->key);
$v3->set('mjb_job_type', 'verify_backup');
$v3->set('mjb_status', 'completed');
$v3->set('mjb_commands', array());
$v3->set('mjb_parameters', json_encode(array('chain_id' => $b01, 'space_id' => (int)$n3b->key, 'profile' => 'manager')));
$v3->set('mjb_result', json_encode(array('verify_status' => 'pass', 'level' => 2)));
$v3->set('mjb_completed_time', gmdate('Y-m-d H:i:s'));
$v3->save();
harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $v3->key);
$r3 = FleetBackupRetention::prune($node3, 30);
check($has('spa', $n3a->base() . 'manager/' . $a35 . '/manifest.json'), 'the newest point before the window is kept, though it is in the old space', $r3['error']);
check(!$has('spb', $n3b->base() . 'manager/' . $b45 . '/manifest.json'), 'an older one in the active space goes');

section('Evidence from before a space was given back does not release anything');
StorageSpace::move(StorageSpace::OWNER_NODE, (int)$node3->key, $a);
StorageSpace::move(StorageSpace::OWNER_NODE, (int)$node3->key, $b);
check(!FleetBackupRetention::active_verified($node3, StorageSpace::active_for(StorageSpace::OWNER_NODE, (int)$node3->key)),
	'a verify from an earlier time on the target does not count once the node comes back to it');

section('A space is retired only once its whole folder is empty');
$deleted3 = NodeBackupShelf::prune(new ManagedNode($node3->key, TRUE));
$n3a = new StorageSpace($n3a->key, TRUE);
check(!$has('spa', $n3a->base() . 'manager/' . $a35 . '/manifest.json') && $n3a->is_draining(),
	'emptying takes our backups but keeps the space while the site\'s own are beside them', (string)$deleted3);

section('A node removed from the dashboard takes no new backups');
$node2 = new ManagedNode($node2->key, TRUE);
$node2->soft_delete();
check(StorageSpace::active_for(StorageSpace::OWNER_NODE, (int)$node2->key) === null, 'its active space drains');
check(!in_array($node2->get('mgn_name'), StorageSpace::holdings_of((int)$a->key)['active'], true),
	'and no target names it as backing up there');

harness_finish();
