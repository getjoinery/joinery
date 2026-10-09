<?php
/** @joinery-test
 * name: broker_managed_run
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * A Managed node's backup through the management node's broker
 * (specs/storage_targets.md R4, WP5), over the loopback S3 fixture:
 *
 *   - a backup run and a re-upload carry a broker slot in the job, never a
 *     credential; a node below the broker's release is refused, naming it
 *   - the slot is a broker run opened when the agent claims the job: a token
 *     stored as its hash, expiring with the job, the run on the job row; a job
 *     naming a key slot is refused
 *   - the node writes on signed links only: a file, a multipart stream; a key
 *     outside the run's base key and any read are refused
 *   - finish records every object's size and sha256 and writes the run's
 *     ledger file; the token is spent
 *   - nothing is written twice: a later run asking for a completed key gets
 *     'exists' with what is there; a failed run's keys may be signed again
 *   - an expired token is refused and its run aborted; a moved node's old
 *     space takes nothing, and a chain stored there is not extended
 *   - the backup engine's manager plan takes the slot as a broker run, and a
 *     chain follows the space the broker answers
 *   - in a space whose target locks, every link the broker signs carries the
 *     lock, so what the node writes and the run's ledger file land locked; a
 *     node too old to send the lock is refused, naming the release
 *
 * Run: php plugins/server_manager/tests/broker_managed_run_test.php
 *
 * @version 1.2 - a locking space (F8): links sign the lock, the node's writes and the ledger file land locked
 * @version 1.1 - the review's pins: a lost finish reply at the engine, a manifest or envelope already in the
 *                bucket, an adopted row left as it was, the recorded manifest preferred, ledger-only spaces
 *                empty, a failed ledger file waiting a day, a re-claimed job replacing its run
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('tests/lib/s3_fixtures.php'));

$fx = s3fx_start(array('FIXTURE_LOCK' => 1));
if ($fx === null) {
	harness_skip('broker managed run', 'could not start a local PHP HTTP server on 127.0.0.1');
	harness_finish();
}
harness_defer(function () use ($fx) { s3fx_stop($fx); });
$creds = s3fx_creds($fx);
$suffix = bin2hex(random_bytes(3));
harness_set_setting_mem('server_manager_backup_target_id', '0');

// Every broker call goes straight to the endpoint's decision, as HTTP would.
BackupBroker::$transport_for_tests = function ($action, array $body, $token) {
	$r = BrokerEndpoint::handle($action, $body, (string)$token);
	return $r['status'] === 200 ? array('data' => $r['data']) : array('error' => $r['error'], 'status' => $r['status']);
};
harness_defer(function () { BackupBroker::$transport_for_tests = null; });

$make_target = function (string $label, string $bucket) use ($creds, $suffix) {
	$t = new BackupTarget(NULL);
	$t->set('bkt_name', 'HarnessTest Broker ' . $label . ' ' . $suffix);
	$t->set('bkt_provider', 's3');
	$t->set('bkt_bucket', $bucket);
	$t->set('bkt_path_prefix', 'harness/broker');
	$t->set('bkt_credentials', $creds);
	$t->set('bkt_enabled', true);
	$t->save();
	harness_register_row('bkt_backup_targets', 'bkt_backup_target_id', $t->key);
	return new BackupTarget($t->key, TRUE);
};
$track_runs = function (int $node_id) {
	foreach (new MultiShelfRun(array('node_id' => $node_id)) as $run) {
		harness_register_row('svr_shelf_runs', 'svr_shelf_run_id', $run->key);
		foreach (new MultiShelfObject(array('run_id' => (int)$run->key)) as $o) {
			harness_register_row('svo_shelf_objects', 'svo_shelf_object_id', $o->key);
		}
	}
};
$file = function (string $bytes) {
	$f = tempnam(sys_get_temp_dir(), 'brk');
	file_put_contents($f, $bytes);
	return $f;
};
$job_for = function (ManagedNode $node, string $primitive) {
	$job = new ManagementJob(NULL);
	$job->set('mjb_mgn_managed_node_id', (int)$node->key);
	$job->set('mjb_job_type', $primitive);
	$job->set('mjb_status', 'running');
	$job->set('mjb_commands', json_encode(array('primitive' => $primitive, 'params' => array())));
	$job->save();
	harness_register_row('mjb_management_jobs', 'mjb_management_job_id', $job->key);
	return $job;
};
$resolve = new ReflectionMethod('AgentChannelEndpoint', 'resolve_credential_slots');
$resolve->setAccessible(true);
$open_slot = function (ManagedNode $node, StorageSpace $space, string $kind) use ($resolve, $job_for) {
	$job = $job_for($node, $kind === 'upload' ? 'upload_backup' : 'backup_run');
	$params = $resolve->invoke(null, array('credentials_b64' => '__SM_BROKER_' . (int)$space->key . '_' . $kind . '__'), $job);
	return array(json_decode(base64_decode($params['credentials_b64']), true), new ManagementJob($job->key, TRUE));
};

$a = $make_target('A', 'bka');
$b = $make_target('B', 'bkb');
$node = new ManagedNode(NULL);
$node->set('mgn_name', 'HarnessTest Broker ' . $suffix);
$node->set('mgn_slug', 'harnessbrk-' . $suffix);
$node->set('mgn_host', '192.0.2.41');
$node->set('mgn_web_root', '/var/www/html/harnessbrk/public_html');
$node->set('mgn_joinery_version', JobCommandBuilder::BROKER_MIN_CORE_VERSION);
$node->save();
harness_register_row('mgn_managed_nodes', 'mgn_managed_node_id', $node->key);
$node = new ManagedNode($node->key, TRUE);
harness_defer(function () use ($track_runs, $node) { $track_runs((int)$node->key); });
$space = StorageSpace::open($a, StorageSpace::OWNER_NODE, (int)$node->key, (string)$node->get('mgn_slug'));
harness_register_row('sps_storage_spaces', 'sps_storage_space_id', $space->key);
$base = $space->base() . 'manager/';

// ── The job carries a broker slot, never a credential ───────────────────
section('A backup run and a re-upload carry a broker slot, never a key');
$up = JobCommandBuilder::build_upload_backup_primitive($node, array('filename' => 'site-20261009_040000.tar.gz.enc', 'profile' => 'manager'));
check($up['params']['credentials_b64'] === '__SM_BROKER_' . (int)$space->key . '_upload__',
	'a re-upload names a broker run of kind upload in the node\'s space', $up['params']['credentials_b64']);
check(strpos(json_encode($up), (string)$creds['secret_key']) === false && strpos(json_encode($up), (string)$creds['access_key']) === false,
	'and no part of the target\'s key is in the job');
$old = new ManagedNode($node->key, TRUE);
$old->set('mgn_joinery_version', '0.8.471');
$why = '';
try { JobCommandBuilder::build_upload_backup_primitive($old, array('filename' => 'x.tar.gz.enc')); } catch (Exception $e) { $why = $e->getMessage(); }
check(strpos($why, 'Upgrade it to release ' . JobCommandBuilder::BROKER_MIN_CORE_VERSION) !== false,
	'a node on an older release is refused, naming the release it needs', $why);

// ── Hand-out ────────────────────────────────────────────────────────────
section('The slot is a broker run opened when the agent claims the job');
list($slot, $job) = $open_slot($node, $space, 'backup');
check(BackupBroker::is_slot($slot) && preg_match('#/api/v1/broker$#', (string)$slot['broker']) && preg_match('/^[0-9a-f]{64}$/', (string)$slot['token']),
	'the node is handed the broker\'s address, a run and a token', json_encode(array_keys((array)$slot)));
$run = new ShelfRun((int)$slot['run_id'], TRUE);
check((int)$run->get('svr_mgn_managed_node_id') === (int)$node->key && (int)$run->get('svr_sps_storage_space_id') === (int)$space->key
	&& $run->get('svr_kind') === 'backup' && $run->get('svr_state') === 'open' && (string)$run->get('svr_base_key') === $base,
	'the run is the node\'s, in its space, open, writing under its manager folder', (string)$run->get('svr_base_key'));
check((string)$run->get('svr_token_hash') === hash('sha256', $slot['token']) && strpos(json_encode($run->export_as_array()), $slot['token']) === false,
	'the run keeps the token\'s hash, never the token');
$life = strtotime($run->get('svr_token_expires_time') . ' UTC') - time();
check($life > 15720 && $life <= 15720 + 3600 + 5, 'the token lives for the job\'s claim budget plus an hour', (string)$life);
check((int)$job->get('mjb_svr_shelf_run_id') === (int)$run->key, 'the job row names the run it took');
$why = '';
try { $resolve->invoke(null, array('credentials_b64' => '__SM_NODE_CREDS_' . (int)$a->key . '__'), $job); } catch (Exception $e) { $why = $e->getMessage(); }
check(strpos($why, 'no node is given any more') !== false, 'a job naming a key slot is refused, not sent', $why);
check(BrokerEndpoint::handle('begin', array('run_id' => (int)$run->key), str_repeat('0', 64))['status'] === 403
	&& BrokerEndpoint::handle('begin', array('run_id' => (int)$run->key + 100000), $slot['token'])['status'] === 403,
	'a wrong token, or the right token for another run, is one refusal');

// ── Writes on links ─────────────────────────────────────────────────────
section('The node writes on signed links only');
$broker = new BackupBroker($slot);
$begun = $broker->begin();
check($begun['space_id'] === (int)$space->key && $begun['base_key'] === $base && $begun['bucket'] === 'bka'
	&& $begun['target_name'] === (string)$a->get('bkt_name'), 'begin says where the run writes', json_encode($begun));
$chain = 'chain-20261009_040000';
$small = $file('manifest ' . $suffix);
$resp = S3Signer::put_file($broker, 'ignored', '/' . $base . $chain . '/manifest-0000.json', $small);
check((int)$resp['status'] === 200 && s3fx_object($fx, 'bka', '/' . $base . $chain . '/manifest-0000.json') === 'manifest ' . $suffix,
	'a file goes up on a signed link', (string)$resp['status']);
$stream_bytes = str_repeat('a', 700) . str_repeat('b', 700) . str_repeat('c', 300);
$fh = fopen('php://memory', 'w+'); fwrite($fh, $stream_bytes); rewind($fh);
$sresp = S3Signer::put_stream($broker, 'ignored', '/' . $base . $chain . '/data-0000.tar.gz.enc', $fh, 'application/octet-stream', true, 700);
fclose($fh);
check((int)$sresp['status'] === 200 && s3fx_object($fx, 'bka', '/' . $base . $chain . '/data-0000.tar.gz.enc') === $stream_bytes
	&& s3fx_count($fx, 'complete') >= 1, 'a stream goes up in parts, each on a link the broker signed', (string)$sresp['status']);
$why = '';
try { S3Signer::put_file($broker, 'ignored', '/' . $space->base() . 'site/elsewhere.bin', $small); } catch (Exception $e) { $why = $e->getMessage(); }
check($why !== '' && s3fx_object($fx, 'bka', '/' . $space->base() . 'site/elsewhere.bin') === null, 'a key outside the run\'s folder is refused', $why);
$why = '';
try { S3Signer::get($broker, 'ignored', '/' . $base . $chain . '/manifest-0000.json'); } catch (Exception $e) { $why = $e->getMessage(); }
check(strpos($why, 'only writes') !== false, 'a read is refused: the node reads back only through links signed into its jobs', $why);
check(count($broker->written()) === 2, 'the client kept both finished writes with their hashes', json_encode($broker->written()));

// ── Finish ──────────────────────────────────────────────────────────────
section('Finish records each object\'s hash and writes the run\'s ledger file');
$done = $broker->finish($chain);
check((int)$done['completed'] === 2 && (int)$done['cancelled'] === 0, 'both objects are completed', json_encode($done));
$run = new ShelfRun((int)$run->key, TRUE);
$rows = array();
foreach (new MultiShelfObject(array('run_id' => (int)$run->key, 'completed' => true)) as $o) {
	$rows[(string)$o->get('svo_key')] = $o;
}
$data_row = $rows[$base . $chain . '/data-0000.tar.gz.enc'] ?? null;
check($data_row && $data_row->get('svo_sha256') === hash('sha256', $stream_bytes) && (int)$data_row->get('svo_bytes') === strlen($stream_bytes)
	&& $data_row->get('svo_chain') === $chain && $data_row->get('svo_svt_service_tenant_id') === null,
	'the ledger holds the streamed archive\'s size and sha256, in its chain, owned through the node\'s space');
check($run->get('svr_state') === 'finished' && $run->get('svr_chain') === $chain && $run->get('svr_ledger_time') !== null,
	'the run is finished, names its chain, and its ledger file is written');
$ledger = json_decode((string)s3fx_object($fx, 'bka', '/' . $space->base() . 'ledger/' . (int)$run->key . '.json'), true);
check(is_array($ledger) && (int)$ledger['run_id'] === (int)$run->key && count($ledger['objects']) === 2
	&& in_array(array('key' => $base . $chain . '/data-0000.tar.gz.enc', 'bytes' => strlen($stream_bytes), 'sha256' => hash('sha256', $stream_bytes)), $ledger['objects'], true),
	'the ledger file in the bucket lists every object with key, bytes and sha256', json_encode($ledger));
check(strpos(ShelfBroker::ledgerKey($run), $space->base() . 'ledger/') === 0 && strpos(ShelfBroker::ledgerKey($run), $base) !== 0,
	'and sits outside every run\'s base key');
check(BrokerEndpoint::handle('sign', array('run_id' => (int)$run->key, 'name' => 'x', 'operation' => 'put'), $slot['token'])['status'] === 403,
	'the token is spent once the run is finished');

// ── Write-once ──────────────────────────────────────────────────────────
section('Nothing in backup storage is written twice');
list($slot2) = $open_slot($node, $space, 'backup');
$broker2 = new BackupBroker($slot2);
$broker2->begin();
$answer = NodeBroker::sign(new ShelfRun((int)$slot2['run_id'], TRUE), $chain . '/data-0000.tar.gz.enc', 'put');
check(!empty($answer['exists']) && $answer['sha256'] === hash('sha256', $stream_bytes) && empty($answer['url']),
	'a later run asking for a completed key gets what is there, and no link', json_encode($answer));
$other = $file('a forged archive');
$why = '';
try { S3Signer::put_file($broker2, 'ignored', '/' . $base . $chain . '/data-0000.tar.gz.enc', $other); } catch (S3ObjectExistsException $e) { $why = $e->getMessage(); }
check($why !== '' && s3fx_object($fx, 'bka', '/' . $base . $chain . '/data-0000.tar.gz.enc') === $stream_bytes,
	'the node cannot replace it: the archive in the bucket is the one recorded', $why);
$tenant_style = ShelfBroker::signWrite(new ShelfRun((int)$slot2['run_id'], TRUE), $chain . '/meta-0000.tar.gz.enc', 'put');
check(!empty($tenant_style['url']), 'a key never completed is signed');
$broker2->abort('the run failed on the node');
check((new ShelfRun((int)$slot2['run_id'], TRUE))->get('svr_state') === 'aborted', 'an aborted run is closed');
list($slot3) = $open_slot($node, $space, 'backup');
$again = NodeBroker::sign(new ShelfRun((int)$slot3['run_id'], TRUE), $chain . '/meta-0000.tar.gz.enc', 'put');
check(!empty($again['url']), 'a failed run\'s key is signed again for the run that retries it');
NodeBroker::abort(new ShelfRun((int)$slot3['run_id'], TRUE), 'test');

// ── Expiry, moves and chains ────────────────────────────────────────────
section('An expired token, a moved node and a chain left behind');
list($slot4) = $open_slot($node, $space, 'upload');
$r4 = new ShelfRun((int)$slot4['run_id'], TRUE);
$r4->set('svr_token_expires_time', gmdate('Y-m-d H:i:s', time() - 60));
$r4->save();
check(BrokerEndpoint::handle('begin', array('run_id' => (int)$r4->key), $slot4['token'])['status'] === 403, 'an expired token is refused');
NodeBroker::abortExpired();
check((new ShelfRun((int)$r4->key, TRUE))->get('svr_state') === 'aborted', 'and the pass aborts its run');
list($slot5) = $open_slot($node, $space, 'backup');
$moved = StorageSpace::move(StorageSpace::OWNER_NODE, (int)$node->key, $b);
harness_register_row('sps_storage_spaces', 'sps_storage_space_id', $moved->key);
$r5 = BrokerEndpoint::handle('sign', array('run_id' => (int)$slot5['run_id'], 'name' => $chain . '/files-0009.tar.gz.enc', 'operation' => 'put'), $slot5['token']);
check($r5['status'] === 409 && strpos($r5['error'], 'moved') !== false, 'a run opened before a move writes nothing more in the old space', (string)($r5['error'] ?? ''));
list($slot6) = $open_slot($node, $moved, 'backup');
$r6 = BrokerEndpoint::handle('sign', array('run_id' => (int)$slot6['run_id'], 'name' => $chain . '/data-0001.tar.gz.enc', 'operation' => 'put'), $slot6['token']);
check($r6['status'] === 409 && strpos($r6['error'], 'not extended') !== false, 'a chain stored in the old space is not extended in the new one', (string)($r6['error'] ?? ''));
$r7 = BrokerEndpoint::handle('sign', array('run_id' => (int)$slot6['run_id'], 'name' => 'chain-20261010_040000/data-0000.tar.gz.enc', 'operation' => 'put'), $slot6['token']);
check($r7['status'] === 200 && !empty($r7['data']['url']), 'a new chain there is signed');
NodeBroker::abort(new ShelfRun((int)$slot6['run_id'], TRUE), 'test');
NodeBroker::abort(new ShelfRun((int)$slot5['run_id'], TRUE), 'test');

// ── The engine's plan ───────────────────────────────────────────────────
section('The backup engine takes the slot as a broker run');
list($slot8) = $open_slot($node, $moved, 'backup');
try {
	$plan = BackupRunner::plan(array('profile' => 'manager', 'manager' => array(
		'bucket' => 'bkb', 'credentials' => $slot8, 'slug' => (string)$node->get('mgn_slug'), 'path_prefix' => 'harness/broker',
		'target_name' => (string)$b->get('bkt_name'), 'type' => 'project', 'mode' => 'chain')));
	$plan_error = '';
} catch (Exception $e) {
	$plan = null;
	$plan_error = $e->getMessage();
}
if ($plan === null && strpos($plan_error, 'recovery key') !== false) {
	harness_skip('engine plan', 'this machine has no proven recovery key: ' . $plan_error);
} else {
	check($plan !== null && $plan['broker'] instanceof BackupBroker && $plan['target']->get_credentials() === $plan['broker'],
		'a manager plan whose slot is a broker run signs every request through it', $plan_error);
	$dest = new ReflectionMethod('BackupRunner', 'destination_of_plan');
	$dest->setAccessible(true);
	check($dest->invoke(null, $plan) === 'space:' . (int)$moved->key, 'its chain follows the space the broker answers',
		(string)$dest->invoke(null, $plan));
	$row = new BackupHistory(NULL);
	$row->set('bkh_profile', 'manager');
	$row->set('bkh_remote_space_id', (int)$space->key);
	$row->set('bkh_target_name', (string)$a->get('bkt_name'));
	$by_row = new ReflectionMethod('BackupRunner', 'destination_of_row');
	$by_row->setAccessible(true);
	check($by_row->invoke(null, $plan, $row) === 'space:' . (int)$space->key
		&& BackupChain::should_start_new(array('runs' => array(array('seq' => 0))), true, 7, 30, null, null, 1,
			$by_row->invoke(null, $plan, $row), $dest->invoke(null, $plan)) === 'destination_changed',
		'a chain taken in the old space is not extended: the next run starts one in the new space');
}
NodeBroker::abort(new ShelfRun((int)$slot8['run_id'], TRUE), 'test');

// ── Write-once holds for objects written before the broker ──────────────
section('A manifest or envelope already in backup storage is never signed over, recorded or not');
list($slot9) = $open_slot($node, $moved, 'backup');
$r9 = new ShelfRun((int)$slot9['run_id'], TRUE);
$mbase = $moved->base() . 'manager/';
$pre = $file('a version-2 manifest written before the broker');
S3Signer::put_file($creds, 'bkb', '/' . $mbase . 'chain-20250101_000000/manifest.json', $pre);
S3Signer::put_file($creds, 'bkb', '/' . $mbase . 'objects/epoch-20250101_000000/envelope.json', $pre);
$a9 = NodeBroker::sign($r9, 'chain-20250101_000000/manifest.json', 'put');
$e9 = NodeBroker::sign($r9, 'objects/epoch-20250101_000000/envelope.json', 'put');
check(!empty($a9['exists']) && empty($a9['url']) && !empty($e9['exists']) && empty($e9['url']),
	'an old chain\'s manifest.json and an old epoch\'s envelope.json are found in the bucket and refused', json_encode(array($a9, $e9)));
check(!empty(NodeBroker::sign($r9, 'chain-20250101_000000/files-0003.tar.gz.enc', 'put')['url']),
	'an archive name is signed: its hash is in the manifest it is read against');
$adopt = function (string $key, int $bytes) use ($moved) {
	$o = new ShelfObject(NULL);
	$o->set('svo_sps_storage_space_id', (int)$moved->key);
	$o->set('svo_key', $key);
	$o->set('svo_bytes', $bytes);
	$o->set('svo_completed_time', gmdate('Y-m-d H:i:s'));
	$o->save();
	harness_register_row('svo_shelf_objects', 'svo_shelf_object_id', $o->key);
	return $o;
};
$adopt($mbase . 'chain-20250102_000000/manifest-0000.json', 40);
check(!empty(NodeBroker::sign($r9, 'chain-20250102_000000/manifest-0000.json', 'put')['exists']),
	'a manifest the reconcile adopted, with no hash, is refused too');
$taken = $adopt($mbase . 'chain-20250102_000000/meta-0000.tar.gz.enc', 77);
check(!empty(NodeBroker::sign($r9, 'chain-20250102_000000/meta-0000.tar.gz.enc', 'put')['url']), 'an adopted archive is taken by the run that signs it');
NodeBroker::abort($r9, 'test');
$taken = new ShelfObject($taken->key, TRUE);
check($taken->get('svo_completed_time') !== null && $taken->get('svo_pruned_time') === null && (int)$taken->get('svo_bytes') === 77,
	'and when that run aborts it is left as it was, completed at its adopted size');

// ── Readers prefer what was recorded ────────────────────────────────────
section('A manifest nothing recorded is not read while a recorded one is there');
$dir = $base . $chain . '/';
S3Signer::put_file($creds, 'bka', '/' . $dir . 'manifest-0007.json', $pre);
check(ShelfObject::preferredManifestName((int)$space->key, $dir, array('manifest-0000.json', 'manifest-0007.json', 'data-0000.tar.gz.enc')) === 'manifest-0000.json',
	'the newest recorded manifest is read, not a newer one a failed run left');
check(ShelfObject::preferredManifestName((int)$space->key, 'harness/none/', array('manifest-0002.json', 'manifest-0010.json')) === 'manifest-0010.json',
	'with none recorded, the newest there');
JobCommandBuilder::set_shelf_listing_for_tests(array(
	array('key' => $dir . 'manifest-0000.json', 'size' => 1), array('key' => $dir . 'manifest-0007.json', 'size' => 1),
	array('key' => $dir . 'data-0000.tar.gz.enc', 'size' => 1)));
try {
	$staged = JobCommandBuilder::build_stage_chain_primitive($node, array('chain_id' => $chain, 'space_id' => (int)$space->key));
} finally {
	JobCommandBuilder::set_shelf_listing_for_tests(null);
}
check(strpos((string)$staged['params']['manifest_url'], '/manifest-0000.json?') !== false
	&& array_keys($staged['params']['artifact_urls']) === array('data-0000.tar.gz.enc'),
	'staging a version-3 chain sends its recorded manifest and no manifest as an artifact', json_encode(array_keys($staged['params']['artifact_urls'])));

// ── Housekeeping ────────────────────────────────────────────────────────
section('A space holding only ledger files is empty; a ledger file that fails waits a day');
$c = $make_target('C', 'bkc');
$sc = StorageSpace::open($c, StorageSpace::OWNER_NODE, (int)$node->key, (string)$node->get('mgn_slug'));
harness_register_row('sps_storage_spaces', 'sps_storage_space_id', $sc->key);
S3Signer::put_file($creds, 'bkc', '/' . $sc->base() . 'ledger/1.json', $pre);
check($sc->is_empty(), 'a space whose folder holds only run ledger files counts as empty, so a drained one retires');
S3Signer::put_file($creds, 'bkc', '/' . $sc->base() . 'manager/x.tar.gz.enc', $pre);
check(!$sc->is_empty(), 'one backup in it, and it is not');
$dead = new ShelfRun(NULL);
$dead->set('svr_mgn_managed_node_id', (int)$node->key);
$dead->set('svr_sps_storage_space_id', (int)$sc->key);
$dead->set('svr_base_key', $sc->base() . 'manager/');
$dead->set('svr_state', 'finished');
$dead->save();
harness_register_row('svr_shelf_runs', 'svr_shelf_run_id', $dead->key);
$c->set('bkt_credentials', array_merge($creds, array('endpoint' => 'http://127.0.0.1:1')));
$c->save();
$w1 = ShelfBroker::writeMissingLedgerFiles(500);
$dead = new ShelfRun($dead->key, TRUE);
check($w1['stuck'] >= 1 && (string)$dead->get('svr_ledger_problem') !== '' && $dead->get('svr_ledger_time') === null,
	'a ledger file that cannot be written records why, and the pass is told how many are stuck', json_encode($w1));
$tried = (string)$dead->get('svr_ledger_tried_time');
sleep(1);
ShelfBroker::writeMissingLedgerFiles(500);
check($tried !== '' && (string)(new ShelfRun($dead->key, TRUE))->get('svr_ledger_tried_time') === $tried, 'it is not tried again on the next pass, but a day later');
$c->set('bkt_credentials', $creds);
$c->save();

section('In a space that locks, everything the node writes is locked');
$lk = $make_target('Lk', 'bkl');
$lk->set('bkt_lock_days', 3);
$lk->save();
$lk = new BackupTarget($lk->key, TRUE);
$sl = StorageSpace::open($lk, StorageSpace::OWNER_NODE, (int)$node->key, (string)$node->get('mgn_slug'));
harness_register_row('sps_storage_spaces', 'sps_storage_space_id', $sl->key);
$takes_lock = new ReflectionMethod('JobCommandBuilder', 'assert_node_takes_lock');
$takes_lock->setAccessible(true);
$why = '';
try { $takes_lock->invoke(null, $node, $sl, 'back up'); } catch (Exception $e) { $why = $e->getMessage(); }
check(strpos($why, 'locks every backup') !== false && strpos($why, JobCommandBuilder::LOCK_MIN_CORE_VERSION) !== false,
	'a node too old to send the lock is refused, naming the release', $why);
$newer = new ManagedNode($node->key, TRUE);
$newer->set('mgn_joinery_version', JobCommandBuilder::LOCK_MIN_CORE_VERSION);
$why = '';
try { $takes_lock->invoke(null, $newer, $sl, 'back up'); $takes_lock->invoke(null, $node, $space, 'back up'); } catch (Exception $e) { $why = $e->getMessage(); }
check($why === '', 'one on that release is not, and a space that does not lock asks nothing', $why);
list($slot11) = $open_slot($node, $sl, 'backup');
$broker11 = new BackupBroker($slot11);
$broker11->begin();
$lbase = $sl->base() . 'manager/';
$lchain = 'chain-20261009_050000';
$link = $broker11->link('PUT', '/' . $lbase . $lchain . '/probe.bin', array(), 10);
check(is_array($link) && ($link['headers']['x-amz-object-lock-mode'] ?? '') === 'COMPLIANCE'
	&& strpos((string)$link['url'], 'x-amz-object-lock-mode') !== false, 'the broker\'s link signs the lock and names it', json_encode($link));
$resp = S3Signer::put_file($broker11, 'ignored', '/' . $lbase . $lchain . '/manifest-0000.json', $small);
check((int)$resp['status'] === 200 && s3fx_lock($fx, 'bkl', '/' . $lbase . $lchain . '/manifest-0000.json') !== null,
	'a file the node sends lands locked', (string)$resp['status']);
$fh = fopen('php://memory', 'w+'); fwrite($fh, $stream_bytes); rewind($fh);
$sresp = S3Signer::put_stream($broker11, 'ignored', '/' . $lbase . $lchain . '/data-0000.tar.gz.enc', $fh, 'application/octet-stream', true, 700);
fclose($fh);
check((int)$sresp['status'] === 200 && s3fx_lock($fx, 'bkl', '/' . $lbase . $lchain . '/data-0000.tar.gz.enc') !== null,
	'and so does a stream sent in parts', (string)$sresp['status']);
$broker11->finish($lchain);
check(s3fx_lock($fx, 'bkl', '/' . $sl->base() . 'ledger/' . (int)$slot11['run_id'] . '.json') !== null,
	'the run\'s ledger file is locked too, so nobody can replace it');
$d = S3Signer::delete($creds, 'bkl', '/' . $lbase . $lchain . '/data-0000.tar.gz.enc');
check((int)$d['status'] === S3Signer::LOCKED, 'and the management node\'s own key cannot delete what was written', (string)$d['status']);

section('A job handed out again replaces the run it opened');
list($slot10, $job10) = $open_slot($node, $moved, 'backup');
$resolve->invoke(null, array('credentials_b64' => '__SM_BROKER_' . (int)$moved->key . '_backup__'), $job10);
check((new ShelfRun((int)$slot10['run_id'], TRUE))->get('svr_state') === 'aborted'
	&& (int)(new ManagementJob($job10->key, TRUE))->get('mjb_svr_shelf_run_id') !== (int)$slot10['run_id'],
	'the earlier run is aborted and the job names the new one');

// ── A lost finish reply, at the engine ──────────────────────────────────
section('A lost finish reply does not stop backups');
$work = harness_scratch_dir('broker_engine');
$tree = $work . '/site';
@mkdir($tree . '/public_html', 0755, true);
@mkdir($tree . '/config', 0755, true);
file_put_contents($tree . '/public_html/index.php', '<?php echo 1;');
file_put_contents($tree . '/config/site.txt', 'config travels');
$dbname = 'jy_broker_' . getmypid();
$pdo = DbConnector::get_instance()->get_db_link();
$pdo->exec('CREATE DATABASE "' . $dbname . '" TEMPLATE template0');
harness_defer(function () use ($pdo, $dbname) { $pdo->exec('DROP DATABASE IF EXISTS "' . $dbname . '" WITH (FORCE)'); });
putenv('PGPASSWORD=' . (string)Globalvars::get_instance()->get_setting('dbpassword', true, true));
harness_defer(function () { putenv('PGPASSWORD'); });
$execute = new ReflectionMethod('BackupRunner', 'execute_chain');
$execute->setAccessible(true);
$engine_run = function ($transport) use ($open_slot, $node, $moved, $work, $tree, $dbname, $execute) {
	list($slot) = $open_slot($node, $moved, 'backup');
	BackupBroker::$transport_for_tests = $transport;
	$plan = BackupRunner::plan(array('profile' => 'manager', 'manager' => array(
		'bucket' => 'bkb', 'credentials' => $slot, 'slug' => (string)$node->get('mgn_slug'), 'path_prefix' => 'harness/broker',
		'target_name' => 'B', 'type' => 'project', 'mode' => 'chain', 'keep_local_days' => 0)));
	$plan['base_dir'] = $work . '/backups';
	$plan['output_dir'] = $work . '/backups/manager';
	$plan['project'] = 'site';
	$plan['project_dir'] = $tree;
	$plan['database'] = $dbname;
	$history = new BackupHistory(NULL);
	$history->set('bkh_type', 'project'); $history->set('bkh_outcome', 'running'); $history->set('bkh_slug', $plan['slug']);
	$history->set('bkh_profile', 'manager'); $history->set('bkh_recovery_fpr', $plan['recovery_fpr']); $history->set('bkh_encrypted', true);
	$history->set('bkh_destination', 'service');
	$history->save();
	harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $history->key);
	$error = null;
	try {
		$plan['broker']->begin();
		$history->set('bkh_remote_run_id', $plan['broker']->run_id());
		$history->set('bkh_remote_space_id', $plan['broker']->space_id());
		$history->save();
		$execute->invoke(null, $plan, $history);
	} catch (\Throwable $e) {
		$error = $e->getMessage();
		$plan['broker']->abort($error);
		$history->set('bkh_outcome', 'failed');
		$history->save();
	}
	return array($error, new BackupHistory($history->key, TRUE), (int)$slot['run_id']);
};
$pass = function ($action, array $body, $token) {
	$r = BrokerEndpoint::handle($action, $body, (string)$token);
	return $r['status'] === 200 ? array('data' => $r['data']) : array('error' => $r['error'], 'status' => $r['status']);
};
$lost_once = 0;
list($err_a, $hist_a, $run_a) = $engine_run(function ($action, array $body, $token) use ($pass, &$lost_once) {
	$answer = $pass($action, $body, $token);
	if ($action === 'finish' && $lost_once++ === 0) {
		throw new Exception('the reply was lost');
	}
	return $answer;
});
if ($err_a !== null && strpos($err_a, 'recovery key') !== false) {
	harness_skip('engine lost reply', 'this machine has no proven recovery key: ' . $err_a);
} else {
	check($err_a === null && $hist_a->get('bkh_outcome') === 'success' && (new ShelfRun($run_a, TRUE))->get('svr_state') === 'finished',
		'a finish whose reply was lost is asked again and answered: the run is committed on both sides', (string)$err_a);
	$chain_a = (string)$hist_a->get('bkh_chain_id');
	list($err_b, $hist_b, $run_b) = $engine_run(function ($action, array $body, $token) use ($pass) {
		$answer = $pass($action, $body, $token);
		if ($action === 'finish') {
			throw new Exception('the node died before it heard back');
		}
		return $answer;
	});
	check($err_b !== null && (new ShelfRun($run_b, TRUE))->get('svr_state') === 'finished',
		'a node that never hears its finish fails the run, though the broker recorded it', (string)$err_b);
	list($err_c, $hist_c) = $engine_run($pass);
	check($err_c !== null && strpos($err_c, 'already in backup storage with other bytes') !== false,
		'the next run finds its run number taken and stops, saying so', (string)$err_c);
	list($err_d, $hist_d) = $engine_run($pass);
	$chain_d = (string)$hist_d->get('bkh_chain_id');
	$m_d = BackupChain::read($work . '/backups/manager/' . $chain_d . '/manifest.json');
	check($err_d === null && $chain_d !== '' && $chain_d !== $chain_a && ($m_d['started_because'] ?? '') === 'name_taken',
		'and the run after that starts a new chain: backups do not stop', (string)$err_d . ' ' . $chain_d);
}
BackupBroker::$transport_for_tests = $pass;

@unlink($small);
@unlink($other);
@unlink($pre);
harness_finish();
