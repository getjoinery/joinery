<?php
/** @joinery-test
 * name: versioned_delete
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 180
 */
/**
 * A delete is a delete on a versioned bucket (specs/storage_targets.md S28),
 * over the loopback S3 fixture keeping versions as every Backblaze bucket does:
 *
 *   - S3Signer::delete() removes every version of the key and its delete
 *     marker, so nothing it deletes stays hidden; a key with nothing is 404
 *   - the file store's delete goes through it
 *   - HiddenVersionSweep finishes what earlier deletes only hid, within a
 *     budget and daily per folder, removing exactly those keys and never a
 *     key whose newest entry is a live object
 *
 * Run: php tests/backups/versioned_delete_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/s3_fixtures.php');

$fx = s3fx_start(array('FIXTURE_VERSIONED' => 1));
if ($fx === null) {
	harness_skip('versioned delete', 'could not start a local PHP HTTP server on 127.0.0.1');
	harness_finish();
}
harness_defer(function () use ($fx) { s3fx_stop($fx); });
$creds = s3fx_creds($fx);
$put = function ($key, $bytes) use ($creds) {
	$f = tempnam(sys_get_temp_dir(), 'vdl');
	file_put_contents($f, $bytes);
	S3Signer::put_file($creds, 'vb', '/' . $key, $f);
	@unlink($f);
};

section('A delete removes every version, not just the current one');
$put('p/a.bin', 'aaaa');
$r = S3Signer::delete($creds, 'vb', '/p/a.bin');
check((int)$r['status'] === 204 && s3fx_object($fx, 'vb', '/p/a.bin') === null && s3fx_hidden($fx) === array(),
	'the object is gone and nothing is left hidden behind a marker', json_encode(s3fx_hidden($fx)));
check((int)S3Signer::delete($creds, 'vb', '/p/a.bin')['status'] === 404, 'a key with nothing left answers 404, which every caller reads as gone');
$put('p/ab.bin', 'keep');
$put('p/a', 'short');
S3Signer::delete($creds, 'vb', '/p/a');
check(s3fx_object($fx, 'vb', '/p/ab.bin') === 'keep', 'only the exact key goes, not another sharing its prefix');
s3fx_hide($fx, 'vb', 'p/old.bin');
check(s3fx_hidden($fx) === array('vb/p/old.bin'), 'a key an earlier delete only hid');
check((int)S3Signer::delete($creds, 'vb', '/p/old.bin')['status'] === 204 && s3fx_hidden($fx) === array(),
	'deleting it again removes its hidden bytes and its marker');

section('The file store deletes for good');
$driver = new CloudStorageS3Driver(array('access_key' => $creds['access_key'], 'secret_key' => $creds['secret_key'],
	'region' => $creds['region'], 'endpoint' => $creds['endpoint'], 'bucket' => 'vb'));
$put('files/photo.jpg', 'private bytes');
$driver->delete('files/photo.jpg');
check(s3fx_object($fx, 'vb', '/files/photo.jpg') === null && s3fx_hidden($fx) === array(), 'a member\'s deleted file is not kept as a hidden version');

section('What earlier deletes only hid is swept, and nothing else');
$t = new BackupTarget(NULL);
$t->set('bkt_name', 'HarnessTest versioned ' . bin2hex(random_bytes(3)));
$t->set('bkt_provider', 's3');
$t->set('bkt_bucket', 'vb');
$t->set('bkt_path_prefix', 'harness/vd');
$t->set('bkt_credentials', $creds);
$t->set('bkt_enabled', false);
$t->save();
harness_register_row('bkt_backup_targets', 'bkt_backup_target_id', $t->key);
$t = new BackupTarget($t->key, TRUE);
$put('harness/vd/live.bin', 'live');
$put('harness/vd/gone1.bin', '123456');
$put('harness/vd/gone2.bin', '1234');
s3fx_hide($fx, 'vb', 'harness/vd/gone1.bin');
s3fx_hide($fx, 'vb', 'harness/vd/gone2.bin');
$put('outside/gone.bin', 'x');
s3fx_hide($fx, 'vb', 'outside/gone.bin');
$now = time();
$r = HiddenVersionSweep::run($t, 'harness/vd/', 0, $now);
check($r !== null && $r['keys'] === 0 && $r['left'] === 2 && count(s3fx_hidden($fx)) === 3,
	'a sweep whose budget is spent leaves its keys for the next call', json_encode($r));
$r = HiddenVersionSweep::run($t, 'harness/vd/', null, $now + 60);
check($r !== null && $r['keys'] === 2 && $r['versions'] === 4 && $r['bytes'] === 10 && $r['left'] === 0,
	'the next call takes them up: both hidden keys, every version and marker', json_encode($r));
check(s3fx_hidden($fx) === array('vb/outside/gone.bin') && s3fx_object($fx, 'vb', '/harness/vd/live.bin') === 'live',
	'the live object and anything outside the folder are untouched', json_encode(s3fx_hidden($fx)));
check(HiddenVersionSweep::run($t, 'harness/vd/', null, $now + 120) === null, 'a folder that finished clean is not swept again the same day');
check(HiddenVersionSweep::run($t, 'harness/vd/', null, $now + 86400 + 121) !== null, 'and is a day later');
$state = (new BackupTarget($t->key, TRUE))->get('bkt_hidden_sweep');
$state = is_string($state) ? json_decode($state, true) : $state;
check(isset($state['harness/vd/']) && (int)$state['harness/vd/']['keys'] === 0, 'what each folder found is kept on the target row', json_encode($state));
check(strpos(HiddenVersionSweep::sentence(array('keys' => 2, 'versions' => 4, 'bytes' => 10, 'left' => 0, 'refused' => 0, 'problem' => ''), 'X'),
	'removed 2 deleted objects still held as hidden versions in X') === 0 && HiddenVersionSweep::sentence(null, 'X') === '',
	'a pass says what it removed, and nothing when it did nothing');

harness_finish();
