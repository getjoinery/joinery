<?php
/** @joinery-test
 * name: object_lock
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * Object lock on a backup target (specs/storage_targets.md F8), over the
 * loopback S3 fixture with object lock on (FIXTURE_LOCK) and versions kept:
 *
 *   - b2, s3 and linode take a lock, the others do not; a file store never
 *     locks; lock days are saved only where a lock can be taken
 *   - every write through a target's write credential lands locked in
 *     COMPLIANCE mode until its lock days from now — a file, a short stream,
 *     a multipart stream — and a bucket without lock refuses such a write
 *   - a delete of a locked object answers LOCKED with the date, and deletes
 *     once that date has passed
 *   - a link that signs the lock is refused when the request leaves it out
 *   - retention leaves a surplus chain the lock still holds whole, and
 *     deletes it once the lock has passed; a pruned chain takes everything
 *     in its folder, a failed run's leftover included
 *   - an offloaded file or a re-sealed envelope the lock still holds is
 *     remembered and deleted by a later pass once its date has passed
 *   - the connection test passes a bucket with lock on and refuses one
 *     without, for a target set to lock
 *   - a bucket that locks every new object by default is refused as a backup
 *     target that does not lock and as a file store, saying why
 *
 * Run: php tests/backups/object_lock_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/s3_fixtures.php');

$fx = s3fx_start(array('FIXTURE_LOCK' => 1, 'FIXTURE_VERSIONED' => 1));
$plain = s3fx_start();
$by_default = s3fx_start(array('FIXTURE_LOCK' => 1, 'FIXTURE_LOCK_DEFAULT' => 1));
if ($fx === null || $plain === null || $by_default === null) {
	harness_skip('object lock', 'could not start a local PHP HTTP server on 127.0.0.1');
	harness_finish();
}
harness_defer(function () use ($fx, $plain, $by_default) { s3fx_stop($fx); s3fx_stop($plain); s3fx_stop($by_default); });
$creds = s3fx_creds($fx);
$suffix = bin2hex(random_bytes(3));
$D = 86400;
$H = 3600;

$make_target = function (string $label, array $c, string $bucket, int $days, string $purpose = 'backups') use ($suffix) {
	$t = new BackupTarget(NULL);
	$t->set('bkt_name', 'HarnessTest Lock ' . $label . ' ' . $suffix);
	$t->set('bkt_provider', 's3');
	$t->set('bkt_bucket', $bucket);
	$t->set('bkt_path_prefix', 'harness/lock-' . strtolower($label));
	$t->set('bkt_credentials', $c);
	$t->set('bkt_enabled', false);
	$t->set('bkt_purpose', $purpose);
	$t->set('bkt_lock_days', $days);
	$t->save();
	harness_register_row('bkt_backup_targets', 'bkt_backup_target_id', $t->key);
	return new BackupTarget($t->key, TRUE);
};
$file = function (string $bytes) {
	$f = tempnam(sys_get_temp_dir(), 'lck');
	file_put_contents($f, $bytes);
	return $f;
};
// The fixture keeps an object's lock date beside it; moving it into the past
// stands for the days going by.
$expire = function ($bucket, $key) use ($fx) {
	file_put_contents(s3fx_object_file($fx['dir'], $bucket, '/' . ltrim($key, '/')) . '.lock', gmdate('Y-m-d\TH:i:s\Z', time() - 60));
};

section('Which targets lock');
check(StorageProvider::object_lock('b2') && StorageProvider::object_lock('s3') && StorageProvider::object_lock('linode')
	&& !StorageProvider::object_lock('r2') && !StorageProvider::object_lock('generic') && !StorageProvider::object_lock('hetzner'),
	'b2, s3 and linode take object lock; the providers not checked do not');
$t = new BackupTarget(NULL);
$t->set('bkt_provider', 'r2');
$t->set('bkt_lock_days', 5);
check(strpos($t->lock_refusal(), 'not checked for object lock') !== false, 'lock days on a provider without a lock are refused', $t->lock_refusal());
$t->set('bkt_provider', 's3');
$t->set('bkt_purpose', 'files');
check(strpos($t->lock_refusal(), 'never locked') !== false && $t->lock_days() === 0, 'a file store never locks', $t->lock_refusal());
$t->set('bkt_purpose', 'backups');
$t->set('bkt_lock_days', 4000);
check($t->lock_refusal() !== '', 'lock days stay within ten years');
$t->set('bkt_lock_days', 5);
check($t->lock_refusal() === '' && $t->lock_days() === 5, 'five days on an S3 backup target is fine');
$why = '';
$bad = new BackupTarget(NULL);
foreach (array('bkt_name' => 'HarnessTest Lock bad ' . $suffix, 'bkt_provider' => 'r2', 'bkt_bucket' => 'x', 'bkt_lock_days' => 3) as $k => $v) { $bad->set($k, $v); }
try { $bad->save(); harness_register_row('bkt_backup_targets', 'bkt_backup_target_id', $bad->key); } catch (BackupTargetException $e) { $why = $e->getMessage(); }
check($why !== '', 'and a target saved with a lock its provider cannot take is refused', $why);
check(BackupTarget::suggested_lock_days() >= 2, 'the suggested lock covers retention plus the full-backup interval', (string)BackupTarget::suggested_lock_days());

$L = $make_target('L', $creds, 'lk', 3);
$t0 = time();
$held = $L->held_until($t0);
check($held >= $t0 + 3 * $D && $held <= $t0 + 3 * $D + 3600, 'what is written to a three-day target is held three days, with a margin for the clocks', (string)($held - $t0));
check($make_target('U', $creds, 'lk', 0)->held_until($t0) === 0, 'and nothing is held on a target that does not lock');
$wc = $L->write_credentials();
check(S3Signer::locks($wc) && !S3Signer::locks($L->get_credentials()), 'the write credential locks; the one that reads and deletes does not');

section('Every write through the write credential lands locked');
$f = $file('locked file ' . $suffix);
$r = S3Signer::put_file($wc, 'lk', '/w/file.bin', $f);
$until = strtotime((string)s3fx_lock($fx, 'lk', '/w/file.bin'));
check((int)$r['status'] === 200 && $until >= time() + 3 * $D - 120 && $until <= time() + 3 * $D + 5,
	'a file is locked three days from now', (int)$r['status'] . ' ' . s3fx_lock($fx, 'lk', '/w/file.bin'));
$fh = fopen('php://memory', 'w+'); fwrite($fh, 'short stream'); rewind($fh);
$r = S3Signer::put_stream($wc, 'lk', '/w/short.bin', $fh);
fclose($fh);
check((int)$r['status'] === 200 && s3fx_lock($fx, 'lk', '/w/short.bin') !== null, 'a short stream is locked, its MD5 sent as a locked PUT needs', (string)$r['status']);
$bytes = str_repeat('a', 700) . str_repeat('b', 700) . str_repeat('c', 300);
$fh = fopen('php://memory', 'w+'); fwrite($fh, $bytes); rewind($fh);
$r = S3Signer::put_stream($wc, 'lk', '/w/multi.bin', $fh, 'application/octet-stream', true, 700);
fclose($fh);
check((int)$r['status'] === 200 && s3fx_object($fx, 'lk', '/w/multi.bin') === $bytes && s3fx_lock($fx, 'lk', '/w/multi.bin') !== null,
	'a stream sent in parts is locked by its upload', (string)$r['status']);
$r = S3Signer::put_file($L->get_credentials(), 'lk', '/w/unlocked.bin', $f);
check((int)$r['status'] === 200 && s3fx_lock($fx, 'lk', '/w/unlocked.bin') === null, 'a write through the plain credential is not locked');
$r = S3Signer::put_file(S3Signer::with_lock(s3fx_creds($plain), 3), 'nl', '/w/file.bin', $f);
check((int)$r['status'] === 400 && s3fx_object($plain, 'nl', '/w/file.bin') === null,
	'a bucket without object lock refuses a locked write, so nothing is stored unlocked by mistake', (string)$r['status']);

section('A locked object cannot be deleted before its date');
$d = S3Signer::delete($L->get_credentials(), 'lk', '/w/file.bin');
check((int)$d['status'] === S3Signer::LOCKED && (int)($d['locked_until'] ?? 0) === $until && s3fx_object($fx, 'lk', '/w/file.bin') !== null,
	'a delete answers LOCKED with the date, and the object stays', (int)$d['status'] . ' ' . ($d['locked_until'] ?? ''));
check(strpos((string)S3Signer::extract_error($d['body']), 'Locked until ' . gmdate('Y-m-d', $until)) === 0, 'and says so in words',
	(string)S3Signer::extract_error($d['body']));
$expire('lk', 'w/file.bin');
$d = S3Signer::delete($L->get_credentials(), 'lk', '/w/file.bin');
check((int)$d['status'] === 204 && s3fx_object($fx, 'lk', '/w/file.bin') === null, 'once the date has passed, it deletes', (string)$d['status']);

section('A link that signs the lock cannot be used without it');
$lock = ShelfBroker::lockHeaders($L);
check(($lock['x-amz-object-lock-mode'] ?? '') === 'COMPLIANCE' && strtotime($lock['x-amz-object-lock-retain-until-date'] ?? '') > time() + 3 * $D - 120,
	'the broker\'s lock for a three-day target is COMPLIANCE, three days out', json_encode($lock));
check(ShelfBroker::lockHeaders($make_target('V', $creds, 'lk', 0)) === array(), 'and is nothing for a target that does not lock');
$url = S3Signer::presign($creds, 'lk', 'w/linked.bin', 'PUT', array(), 600, $lock);
check(strpos($url, 'X-Amz-SignedHeaders=host%3Bx-amz-object-lock-mode%3Bx-amz-object-lock-retain-until-date') !== false,
	'the link signs both lock headers', $url);
$send = function (array $headers) use ($url) {
	$body = 'linked';
	$h = array('Content-MD5: ' . base64_encode(md5($body, true)));
	foreach ($headers as $k => $v) { $h[] = $k . ': ' . $v; }
	$ch = curl_init($url);
	curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h));
	curl_exec($ch);
	return (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
};
check($send(array()) === 403 && s3fx_object($fx, 'lk', '/w/linked.bin') === null, 'a write on it that leaves the lock out is refused');
check($send($lock) === 200 && s3fx_lock($fx, 'lk', '/w/linked.bin') !== null, 'and one that carries it lands locked');

section('Retention leaves a chain the lock holds whole, and deletes it after');
$slug = 'zz-lock-' . $suffix;
$plan_rows = function (BackupTarget $target) use ($slug, $creds, $file, $D, $fx) {
	$base = BackupTarget::normalise_prefix((string)$target->get('bkt_path_prefix')) . '/' . $slug . '/site/';
	$chains = array();
	foreach (array(40 => false, 30 => true, 20 => false, 10 => false) as $days => $verified) {
		$cid = 'chain-' . gmdate('Ymd_His', time() - $days * $D);
		$keys = array();
		foreach (array('files-0000.tar.gz.enc', 'manifest-0000.json') as $name) {
			$f = $file($cid . ' ' . $name);
			S3Signer::put_file($target->write_credentials(), 'lk', '/' . $base . $cid . '/' . $name, $f);
			@unlink($f);
			// The provider's LastModified is when the run wrote it.
			touch(s3fx_object_file($fx['dir'], 'lk', '/' . $base . $cid . '/' . $name), time() - $days * $D + 300);
			$keys[] = $base . $cid . '/' . $name;
		}
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
		$h->set('bkh_start_time', gmdate('Y-m-d H:i:s', time() - $days * $D));
		$h->set('bkh_upload_time', gmdate('Y-m-d H:i:s', time() - $days * $D + 600));
		$h->set_artifacts(array(
			array('name' => 'files-0000.tar.gz.enc', 'kind' => 'files', 'key' => $keys[0]),
			array('name' => 'manifest-0000.json', 'kind' => 'manifest', 'key' => $keys[1]),
		));
		if ($verified) {
			$h->set('bkh_verify_outcome', 'pass');
			$h->set('bkh_verify_level', 2);
			$h->set('bkh_verify_time', gmdate('Y-m-d H:i:s', time() - $days * $D + 3600));
		}
		$h->save();
		harness_register_row('bkh_backup_history', 'bkh_backup_history_id', $h->key);
		$chains[$days] = array('cid' => $cid, 'keys' => $keys, 'row' => (int)$h->key);
	}
	return $chains;
};
$work = harness_scratch_dir('object_lock');
// Locked 45 days: the 40-day-old chain is surplus but still held.
$long = $make_target('Long', $creds, 'lk', 45);
$chains = $plan_rows($long);
// A run that failed in the oldest chain, after its last success, left an
// object no row names — written just now on the provider's clock.
$stray = dirname($chains[40]['keys'][0]) . '/files-0001.tar.gz.enc';
$f3 = $file('a failed run\'s leftover');
S3Signer::put_file($long->get_credentials(), 'lk', '/' . $stray, $f3);
@unlink($f3);
$plan = array('slug' => $slug, 'profile' => 'site', 'keep_days' => 1, 'prunes_cloud' => true,
	'destination' => 'target', 'target' => $long, 'output_dir' => $work . '/site', 'objects' => false);
$idx = null;
$now = time();
BackupRunner::enforce_chain_retention($plan, $idx, $now);
$n = BackupRunner::enforce_chain_retention($plan, $idx, $now + 21 * $H);
$row40 = new BackupHistory($chains[40]['row'], TRUE);
check($n === 0 && s3fx_object($fx, 'lk', '/' . $chains[40]['keys'][0]) !== null && s3fx_object($fx, 'lk', '/' . $chains[40]['keys'][1]) !== null
	&& $row40->get('bkh_pruned_time') === null && $row40->get('bkh_surplus_time') !== null,
	'a surplus chain still held is left whole: both objects and its row, still marked surplus', $n . ' ' . $row40->get('bkh_surplus_time'));
// Six days later on the test clock the lock has passed (40 + 45 days, and the margin).
foreach ($chains[40]['keys'] as $k) { $expire('lk', $k); }
$n = BackupRunner::enforce_chain_retention($plan, $idx, $now + 6 * $D);
check($n === 0 && s3fx_object($fx, 'lk', '/' . $chains[40]['keys'][0]) !== null && s3fx_object($fx, 'lk', '/' . $stray) !== null,
	'while the lock may still hold a failed run\'s later leftover in its folder, the chain waits whole, quietly', (string)$n);
touch(s3fx_object_file($fx['dir'], 'lk', '/' . $stray), $now - 40 * $D + 900);
$n = BackupRunner::enforce_chain_retention($plan, $idx, $now + 6 * $D);
check($n === 1 && s3fx_object($fx, 'lk', '/' . $chains[40]['keys'][0]) === null && s3fx_object($fx, 'lk', '/' . $chains[40]['keys'][1]) === null
	&& (new BackupHistory($chains[40]['row'], TRUE))->get('bkh_pruned_time') !== null,
	'once the lock has passed, the next pass deletes it', (string)$n);
check(s3fx_object($fx, 'lk', '/' . $stray) === null, 'and a failed run\'s leftover in its folder, which no row names, goes with it');
check(s3fx_object($fx, 'lk', '/' . $chains[30]['keys'][0]) !== null && s3fx_object($fx, 'lk', '/' . $chains[10]['keys'][0]) !== null,
	'and the chains retention keeps are untouched');

section('The offloaded-files prune remembers what it could not delete yet');
$oplan = array('slug' => $slug . '-o', 'profile' => 'site', 'target' => $L, 'output_dir' => $work . '/objects-site');
list(, , $obase) = BackupObjects::destination($oplan);
$okey = $obase . BackupObjects::object_relname('epoch-20260101_000000', 'aa.bin');
$ekey = $obase . BackupObjects::envelope_relname('epoch-20260101_000000', 'envelope-0123456789abcdef.json');
$f2 = $file('offloaded');
S3Signer::put_file($L->write_credentials(), 'lk', '/' . $okey, $f2);
S3Signer::put_file($L->write_credentials(), 'lk', '/' . $ekey, $f2);
@unlink($f2);
$cand = array('epoch-20260101_000000/aa.bin' => array('name' => 'aa.bin', 'epoch' => 'epoch-20260101_000000'));
$n = BackupObjects::prune_site($oplan, $cand, array());
check($n === 0 && s3fx_object($fx, 'lk', '/' . $okey) !== null && BackupObjects::unpruned_targets($oplan) === array((int)$L->key),
	'a file the lock holds is not deleted, and is remembered for this target', json_encode(BackupObjects::unpruned_targets($oplan)));
check(BackupObjects::prune_site($oplan, array(), array()) === 0 && s3fx_object($fx, 'lk', '/' . $okey) !== null,
	'a pass before its date leaves it');
$expire('lk', $okey);
$n = BackupObjects::prune_site($oplan, array(), array(), time() + 4 * $D);
check($n === 1 && s3fx_object($fx, 'lk', '/' . $okey) === null && s3fx_object($fx, 'lk', '/' . $ekey) !== null
	&& BackupObjects::unpruned_targets($oplan) === array((int)$L->key),
	'a pass after its date deletes it, with nothing new to prune; the epoch\'s re-sealed envelope, still locked, is remembered');
$expire('lk', $ekey);
BackupObjects::prune_site($oplan, array(), array(), time() + 4 * $D);
check(s3fx_object($fx, 'lk', '/' . $ekey) === null && BackupObjects::unpruned_targets($oplan) === array(),
	'and goes once its own date has passed, leaving nothing owed');
$bkey = $obase . BackupObjects::object_relname('epoch-20260101_000000', 'bb.bin');
$f4 = $file('unlocked offloaded');
S3Signer::put_file($L->get_credentials(), 'lk', '/' . $bkey, $f4);
@unlink($f4);
$cand = array('epoch-20260101_000000/bb.bin' => array('name' => 'bb.bin', 'epoch' => 'epoch-20260101_000000'));
$n = BackupObjects::prune_site($oplan, $cand, array($obase . 'no-such-index.json'));
check($n === 0 && s3fx_object($fx, 'lk', '/' . $bkey) !== null && BackupObjects::unpruned_targets($oplan) === array((int)$L->key),
	'a pass that cannot read a retained index deletes nothing, and remembers what its pruned runs named');
$n = BackupObjects::prune_site($oplan, array(), array());
check($n === 1 && s3fx_object($fx, 'lk', '/' . $bkey) === null && BackupObjects::unpruned_targets($oplan) === array(),
	'so the next pass that can read them deletes it');

section('The connection test proves the lock');
$probe = new BackupTarget(NULL);
foreach (array('bkt_name' => 'HarnessTest Lock probe ' . $suffix, 'bkt_provider' => 's3', 'bkt_bucket' => 'lkp',
	'bkt_path_prefix' => 'harness/probe', 'bkt_lock_days' => 2) as $k => $v) { $probe->set($k, $v); }
$probe->set('bkt_credentials', $creds);
$r = TargetTester::test($probe);
$steps = array();
foreach ($r['steps'] as $s) { $steps[$s['label']] = $s; }
check($r['success'] && ($steps['Lock']['status'] ?? '') === 'pass' && ($steps['Prune']['status'] ?? '') === 'pass',
	'a bucket with lock on passes, its probe locked and refused a delete', $r['message']);
$probes = S3Signer::list($creds, 'lkp', 'harness/probe/' . TargetTester::LOCK_PROBE_DIR);
check(count($probes) === 1 && s3fx_lock($fx, 'lkp', '/' . $probes[0]['key']) !== null, 'the probe stays, locked', json_encode($probes));
$expire('lkp', $probes[0]['key']);
TargetTester::test($probe);
$after = S3Signer::list($creds, 'lkp', 'harness/probe/' . TargetTester::LOCK_PROBE_DIR);
check(count($after) === 1 && $after[0]['key'] !== $probes[0]['key'], 'the next test deletes the probe whose lock has passed', json_encode($after));
$probe->set('bkt_credentials', s3fx_creds($plain));
$probe->set('bkt_bucket', 'nlp');
$r = TargetTester::test($probe);
$steps = array();
foreach ($r['steps'] as $s) { $steps[$s['label']] = $s; }
check(!$r['success'] && strpos($steps['Lock']['message'] ?? '', 'does not have object lock on') !== false,
	'a bucket without lock fails a target set to lock, saying it can only be turned on at creation', $r['message']);
$probe->set('bkt_lock_days', 0);
$r = TargetTester::test($probe);
check($r['success'] && !in_array('Lock', array_column($r['steps'], 'label'), true), 'and a target that does not lock is not asked about it', $r['message']);

section('A bucket that locks every new object by default');
$probe->set('bkt_credentials', s3fx_creds($by_default));
$probe->set('bkt_bucket', 'dfl');
$r = TargetTester::test($probe);
$steps = array();
foreach ($r['steps'] as $s) { $steps[$s['label']] = $s; }
check(!$r['success'] && strpos($steps['Prune']['message'] ?? '', 'locks every new object') !== false,
	'is refused as a backup target that does not lock: retention could not prune on its schedule', $r['message']);
$r = CloudStorageLifecycle::testConnection(s3fx_creds($by_default) + array('bucket' => 'dfs'));
$del = null;
foreach ($r['steps'] as $s) { if ($s['label'] === CloudStorageLifecycle::STEP_DELETE) { $del = $s; } }
check(!$r['ok'] && $del && $del['status'] === 'fail' && strpos($del['message'], 'locks every new object') !== false,
	'and as a file store: a member\'s deleted file could not be deleted', json_encode($del));

@unlink($f);
harness_finish();
