<?php
/** @joinery-test
 * name: backup_safety
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * The rules that stop retention deleting what a restore would need
 * (specs/storage_targets.md §9, F1 F2 F3), over the loopback S3 fixture:
 *
 *   - BackupSafety::confirm(): the newest point is never deleted; the newest
 *     verified point and everything newer stay; a point goes only once a pass
 *     CONFIRM_HOURS earlier found it surplus too
 *   - a site's chain retention: an old verified chain survives while newer
 *     unverified ones age; a first pass deletes nothing and the second deletes
 *     only what both found surplus, each from its bucket
 *   - the local sweep keeps backup files written after the newest run of
 *     their own kind that finished off-site, and every file when none ever did
 *   - what a site's Backups page and the customer incident say
 *   - the broker records a verified run only for a finished one
 *
 * Run: php tests/backups/backup_safety_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/s3_fixtures.php');

$H = 3600;
$D = 86400;

// ─────────────────────────────────────────────────────────────────────────
section('The decision: newest, verified, confirmed');

$now = 2000000000;
$pt = function ($item, $days_ago, $verified = false) use ($now, $D) {
	return array('item' => $item, 'time' => $now - $days_ago * $D, 'verified' => $verified);
};
$points = array($pt('p1', 1), $pt('p10', 10), $pt('p20', 20), $pt('p30', 30));

$d = BackupSafety::confirm($points, array('p20', 'p30'), array(), $now);
check($d['delete'] === array() && $d['listed'] === array('p20' => $now, 'p30' => $now),
	'a first pass deletes nothing and lists what it found surplus', json_encode($d));
check(($d['held']['p20'] ?? '') === 'confirming', 'each waits to be confirmed');

$d = BackupSafety::confirm($points, array('p20', 'p30'), array('p20' => $now - 19 * $H, 'p30' => $now - 21 * $H), $now);
check($d['delete'] === array('p30'), 'a point goes once it was found surplus at least 20 hours before', json_encode($d));
check(($d['listed']['p20'] ?? 0) === $now - 19 * $H, 'one found 19 hours ago keeps its first-found time and waits');

$d = BackupSafety::confirm($points, array('p1', 'p30'), array('p1' => $now - 5 * $D, 'p30' => $now - 5 * $D), $now);
check($d['delete'] === array('p30') && ($d['held']['p1'] ?? '') === 'newest',
	'the newest point is never deleted, whatever the rule hands in', json_encode($d));

$vpoints = array($pt('p1', 1), $pt('p10', 10), $pt('p20', 20, true), $pt('p30', 30));
$d = BackupSafety::confirm($vpoints, array('p10', 'p20', 'p30'), array('p10' => 0, 'p20' => 0, 'p30' => 0), $now);
check($d['delete'] === array('p30') && ($d['held']['p20'] ?? '') === 'verified' && ($d['held']['p10'] ?? '') === 'verified',
	'the newest verified point and everything newer stay, however old', json_encode($d));
check(!isset($d['listed']['p20']), 'and a kept point is not listed, so it starts over if it becomes surplus again');

$d = BackupSafety::confirm($points, array('p20', 'p30'), array('p20' => 0, 'p30' => 0), $now, $now - 25 * $D);
check($d['delete'] === array('p30'), 'a verified point of another family keeps everything newer than it here too', json_encode($d));
check(BackupSafety::protect_since($vpoints) === $now - 20 * $D, 'protect_since is the newest verified point\'s start');
check(BackupSafety::verify_alarm_days(7) === 8 && BackupSafety::verify_alarm_days(0) === 0, 'the verify alarm is a day past the interval, never when off');

// ─────────────────────────────────────────────────────────────────────────
section('What a site\'s Backups page says');

$w = BackupSafety::site_warnings($now - 10 * $D, $now - 3 * $D, $now - 2 * $D, 7, $now);
check(count($w) === 1 && $w[0]['kind'] === 'offsite' && $w[0]['since'] === $now - 3 * $D,
	'no run off-site for three nights is said, with when the last one was', json_encode($w));
$w = BackupSafety::site_warnings($now - 10 * $D, $now - 1 * $D, $now - 9 * $D, 7, $now);
check(count($w) === 1 && $w[0]['kind'] === 'verify', 'nothing verified for nine days on a weekly interval is said', json_encode($w));
$w = BackupSafety::site_warnings($now - 10 * $D, $now - 1 * $D, null, 0, $now);
check($w === array(), 'nothing is said about verification when it is switched off');
$w = BackupSafety::site_warnings($now - 1 * $D, null, null, 7, $now);
check($w === array(), 'a site whose first run was yesterday hears nothing yet');
check(BackupSafety::site_warnings(null, null, null, 7, $now) === array(), 'nor one that never ran');

$c = IncidentSourceCustomerBackups::condition(array('acme.example' => array(array('kind' => 'verify', 'since' => null))));
check($c !== null && strpos($c['title'], 'acme.example') === 0 && strpos($c['title'], 'no backup has passed verification yet') !== false,
	'a customer with nothing verified is an incident naming it', json_encode($c));
check(IncidentSourceCustomerBackups::condition(array()) === null, 'no gaps, no incident');

// ─────────────────────────────────────────────────────────────────────────
section('A site\'s chain retention, over a bucket');

$fx = s3fx_start();
if ($fx === null) {
	harness_skip('site retention', 'could not start a local PHP HTTP server on 127.0.0.1');
	harness_finish();
}
harness_defer(function() use ($fx) { s3fx_stop($fx); });

$work = sys_get_temp_dir() . '/jy_backup_safety_' . getmypid();
@mkdir($work . '/site', 0700, true);
harness_defer(function() use ($work) { exec('rm -rf ' . escapeshellarg($work)); });

$suffix = bin2hex(random_bytes(3));
$t = new BackupTarget(NULL);
$t->set('bkt_name', 'HarnessTest Safety ' . $suffix);
$t->set('bkt_provider', 'generic');
$t->set('bkt_bucket', 'bkt');
$t->set('bkt_path_prefix', 'jb');
$t->set('bkt_credentials', s3fx_creds($fx));
$t->set('bkt_enabled', true);
$t->save();
harness_register_row('bkt_backup_targets', 'bkt_backup_target_id', $t->key);
$target = new BackupTarget($t->key, TRUE);

$slug = 'safety-' . getmypid();
$creds = s3fx_creds($fx);
$real_now = time();
// One chain, one run, one object in the bucket; started $days ago.
$chain = function ($days, $verified = false) use ($target, $slug, $creds, $real_now, $D, $work) {
	$cid = 'chain-' . gmdate('Ymd_His', $real_now - $days * $D);
	$key = 'jb/' . $slug . '/site/' . $cid . '/files-0000.tar.gz.enc';
	$tmp = $work . '/obj';
	file_put_contents($tmp, 'x' . $days);
	S3Signer::put_file($creds, 'bkt', '/' . $key, $tmp);
	$h = new BackupHistory(NULL);
	$h->set('bkh_type', 'project');
	$h->set('bkh_outcome', 'success');
	$h->set('bkh_slug', $slug);
	$h->set('bkh_profile', 'site');
	$h->set('bkh_destination', 'target');
	$h->set('bkh_bkt_backup_target_id', $target->key);
	$h->set('bkh_target_name', $target->get('bkt_name'));
	$h->set('bkh_chain_id', $cid);
	$h->set('bkh_chain_seq', 0);
	$h->set('bkh_start_time', gmdate('Y-m-d H:i:s', $real_now - $days * $D));
	$h->set('bkh_upload_time', gmdate('Y-m-d H:i:s', $real_now - $days * $D + 600));
	$h->set_artifacts(array(array('name' => 'files-0000.tar.gz.enc', 'kind' => 'files', 'key' => $key)));
	if ($verified) {
		$h->set('bkh_verify_outcome', 'pass');
		$h->set('bkh_verify_time', gmdate('Y-m-d H:i:s', $real_now - $days * $D + 3600));
		$h->set('bkh_verify_level', 2);
	}
	$h->save();
	harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $h->key);
	return array('cid' => $cid, 'key' => $key, 'row' => (int)$h->key);
};
$in_bucket = function ($key) use ($fx) { return s3fx_object($fx, 'bkt', '/' . $key) !== null; };
$plan = array('slug' => $slug, 'profile' => 'site', 'keep_days' => 1, 'prunes_cloud' => true,
	'destination' => 'target', 'target' => $target, 'output_dir' => $work . '/site', 'objects' => false);

$c40 = $chain(40, true);
$c30 = $chain(30);
$c20 = $chain(20);
$c10 = $chain(10);
$idx = null;

$n = BackupRunner::enforce_chain_retention($plan, $idx);
check($n === 0, 'a first pass deletes nothing', (string)$n);
$n = BackupRunner::enforce_chain_retention($plan, $idx, $real_now + 21 * $H);
check($n === 0 && $in_bucket($c40['key']) && $in_bucket($c30['key']) && $in_bucket($c20['key']),
	'an old verified chain survives a day later, and so does everything newer', (string)$n);
check((string)(new BackupHistory($c30['row'], TRUE))->get('bkh_surplus_time') === '',
	'a chain the verified one protects is not marked surplus');

// A newer chain is verified: the two older ones are surplus now, found so now.
DbConnector::get_instance()->get_db_link()->prepare("UPDATE bkh_backup_history SET bkh_verify_outcome = 'pass',
	bkh_verify_time = now(), bkh_verify_level = 2 WHERE bkh_backup_history_id = ?")->execute(array($c20['row']));
$n = BackupRunner::enforce_chain_retention($plan, $idx, $real_now + 22 * $H);
check($n === 0 && $in_bucket($c40['key']) && $in_bucket($c30['key']), 'once a newer chain is verified, the older ones wait a day');
check((string)(new BackupHistory($c30['row'], TRUE))->get('bkh_surplus_time') !== '', 'and their rows say when they were found surplus');

// Before the confirming pass, the newest chain is verified too: the one between
// becomes surplus, found so only now.
DbConnector::get_instance()->get_db_link()->prepare("UPDATE bkh_backup_history SET bkh_verify_outcome = 'pass',
	bkh_verify_time = now(), bkh_verify_level = 2 WHERE bkh_backup_history_id = ?")->execute(array($c10['row']));
$n = BackupRunner::enforce_chain_retention($plan, $idx, $real_now + 43 * $H);
check($n === 2 && !$in_bucket($c40['key']) && !$in_bucket($c30['key']),
	'the second pass deletes the two both passes found surplus, from the bucket', (string)$n);
check($in_bucket($c20['key']) && $in_bucket($c10['key']), 'the chain found surplus only now stays, and the newest stays');
check((new BackupHistory($c40['row'], TRUE))->get('bkh_pruned_time') !== null, 'the deleted runs are recorded as pruned');

// ─────────────────────────────────────────────────────────────────────────
section('The local sweep keeps what never went offsite');

$sweep_dir = $work . '/sweep';
@mkdir($sweep_dir, 0700, true);
$file = function ($name, $days_ago) use ($sweep_dir, $real_now, $D) {
	$p = $sweep_dir . '/' . $name;
	file_put_contents($p, 'x');
	touch($p, $real_now - (int)round($days_ago * $D));
	return $p;
};
// The newest run that finished off-site uploaded 6 days ago (c10 is the
// newest off-site row of this slug, 10 days ago; this one is newer).
$up = new BackupHistory(NULL);
$up->set('bkh_type', 'project');
$up->set('bkh_outcome', 'success');
$up->set('bkh_slug', $slug);
$up->set('bkh_profile', 'site');
$up->set('bkh_destination', 'target');
$up->set('bkh_start_time', gmdate('Y-m-d H:i:s', $real_now - 6 * $D - 600));
$up->set('bkh_upload_time', gmdate('Y-m-d H:i:s', $real_now - 6 * $D));
$up->save();
harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $up->key);

$went  = $file('site-' . gmdate('Ymd_His', $real_now - 8 * $D) . '.tar.gz.enc', 8);
$stuck = $file('site-' . gmdate('Ymd_His', $real_now - 4 * $D) . '.tar.gz.enc', 4);
$dump  = $file('auto_pre_restore.sql.gz', 4);
$sweep_plan = array('slug' => $slug, 'profile' => 'site', 'destination' => 'target', 'keep_local' => 2,
	'output_dir' => $sweep_dir, 'base_dir' => $sweep_dir);
BackupRunner::sweep_local($sweep_plan);
check(!is_file($went), 'a backup file older than the newest off-site upload goes by age');
check(is_file($stuck), 'one written after it stays, whatever its age: it may be the only copy');
check(!is_file($dump), 'a pre-restore dump is not a backup and goes by age');

$never = $file('site-' . gmdate('Ymd_His', $real_now - 30 * $D) . '.tar.gz.enc', 30);
BackupRunner::sweep_local(array_merge($sweep_plan, array('slug' => 'safety-never-' . getmypid())));
check(is_file($never) && is_file($stuck), 'a plan that has never finished an upload sweeps no backup file');

// A database-only run uploading yesterday vouches for database files, never
// for a project archive that did not go.
$db_up = new BackupHistory(NULL);
$db_up->set('bkh_type', 'database');
$db_up->set('bkh_outcome', 'success');
$db_up->set('bkh_slug', $slug);
$db_up->set('bkh_profile', 'site');
$db_up->set('bkh_destination', 'target');
$db_up->set('bkh_start_time', gmdate('Y-m-d H:i:s', $real_now - $D - 600));
$db_up->set('bkh_upload_time', gmdate('Y-m-d H:i:s', $real_now - $D));
$db_up->save();
harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $db_up->key);
$old_dump = $file('db-' . gmdate('Ymd_His', $real_now - 3 * $D) . '.sql.gz.enc', 3);
BackupRunner::sweep_local($sweep_plan);
check(is_file($stuck), 'a newer database upload does not make an un-uploaded project archive sweepable');
check(!is_file($old_dump), 'a database file older than the newest database upload goes by age');

// ─────────────────────────────────────────────────────────────────────────
section('The broker records a verify only for a finished run');

$owner = make_user('Safety');
$tenant = new ServiceTenant(NULL);
$tenant->set('svt_usr_user_id', (int)$owner->key);
$tenant->set('svt_service', ServiceTenant::SERVICE_SHELF);
$tenant->set('svt_state', ServiceTenant::STATE_ACTIVE);
$tenant->set('svt_host', 'safety-' . $suffix . '.example');
$tenant->set('svt_slug', 'saf' . $suffix);
$tenant->save();
harness_register_row('svt_service_tenants', 'svt_service_tenant_id', $tenant->key);
$space = StorageSpace::open($target, StorageSpace::OWNER_TENANT, (int)$tenant->key, 't' . (int)$tenant->key);
harness_register_row('sps_storage_spaces', 'sps_storage_space_id', $space->key);
$run = new ShelfRun(NULL);
$run->set('svr_svt_service_tenant_id', (int)$tenant->key);
$run->set('svr_sps_storage_space_id', (int)$space->key);
$run->set('svr_chain', 'chain-20260101_000000');
$run->set('svr_base_key', $space->base() . 'site/');
$run->set('svr_state', ShelfRun::STATE_OPEN);
$run->save();
harness_register_row('svr_shelf_runs', 'svr_shelf_run_id', $run->key);
$why = '';
try { ShelfBroker::verifiedRun($tenant, (int)$run->key); } catch (ShelfBrokerException $e) { $why = $e->getMessage(); }
check(strpos($why, 'finished') !== false, 'an open run cannot be verified', $why);
$run->set('svr_state', ShelfRun::STATE_FINISHED);
$run->save();
$res = ShelfBroker::verifiedRun($tenant, (int)$run->key);
check(!empty($res['verified_time']) && (new ShelfRun($run->key, TRUE))->get('svr_verified_time') !== null, 'a finished one is stamped');

$gaps = IncidentSourceCustomerBackups::gaps($real_now + 10 * $D);
$label = 'safety-' . $suffix . '.example';
check(isset($gaps[$label]) && in_array('offsite', array_column($gaps[$label], 'kind'), true),
	'ten days on with no run finished since, the customer is named for the incident', json_encode($gaps[$label] ?? null));

harness_finish();
