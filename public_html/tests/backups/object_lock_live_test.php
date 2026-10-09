<?php
/** @joinery-test
 * name: object_lock_live
 * tier: live
 * env: dev-only
 * needs: []
 * timeout: 300
 */
/**
 * Object lock against real buckets (specs/storage_targets.md F8). For each
 * provider that takes a lock — Backblaze B2, Linode, Amazon S3 — with a backup
 * target on this site whose bucket has object lock on, it proves:
 *
 *   - the connection test's Lock step passes for the target set to lock
 *   - a file and a stream sent in parts through a locking write credential
 *     land in COMPLIANCE mode, held one day
 *   - a delete of one answers LOCKED with that date, and the object stays
 *   - a link signing the lock is refused when the request leaves it out, and
 *     what is sent with it lands locked
 *
 * What it writes is locked for one day, so it cannot be removed at the end:
 * it goes under zz-object-lock-live/ beside the target's prefix, and each run
 * first deletes what earlier runs left whose lock has passed. The connection
 * test's probes tidy themselves the same way. Credentials are read from the
 * target rows and never printed. A provider with no such target is a named
 * skip.
 *
 * Run: php tests/backups/object_lock_live_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$labels = array('b2' => 'Backblaze B2', 'linode' => 'Linode', 's3' => 'Amazon S3');
$found = array();
foreach (new MultiBackupTarget(array('deleted' => false, 'purpose' => MultiBackupTarget::ANY_PURPOSE), array('bkt_backup_target_id' => 'ASC')) as $t) {
	$p = (string)$t->get('bkt_provider');
	if (!isset($labels[$p]) || isset($found[$p]) || $t->is_file_store()) {
		continue;
	}
	try {
		$conf = S3Signer::get($t->get_credentials(), trim((string)$t->get('bkt_bucket')), '/', array('object-lock' => ''));
	} catch (Exception $e) {
		continue;
	}
	if ((int)$conf['status'] === 200 && preg_match('#<ObjectLockEnabled>\s*Enabled#', (string)$conf['body'])) {
		$found[$p] = $t;
	}
}

foreach ($labels as $provider => $label) {
	section('Object lock in a real ' . $label . ' bucket');
	if (!isset($found[$provider])) {
		harness_skip($label . ' object lock', 'no backup target here on a bucket with object lock on (' . $label . ')');
		continue;
	}
	$target = $found[$provider];
	$creds  = $target->get_credentials();
	$bucket = trim((string)$target->get('bkt_bucket'));
	$dir    = BackupTarget::normalise_prefix((string)$target->get('bkt_path_prefix')) . '/zz-object-lock-live/';
	$run    = $dir . gmdate('Ymd_His') . '-' . bin2hex(random_bytes(3)) . '/';
	$work   = harness_scratch_dir('object_lock_live_' . $provider);

	$tidied = 0;
	foreach (S3Signer::list($creds, $bucket, $dir) as $o) {
		if ((int)S3Signer::delete($creds, $bucket, '/' . ltrim((string)$o['key'], '/'))['status'] !== S3Signer::LOCKED) { $tidied++; }
	}
	check(true, 'what earlier runs left and is no longer locked is deleted first', $tidied . ' deleted');

	$copy = new BackupTarget(NULL);
	foreach (array('bkt_name', 'bkt_provider', 'bkt_bucket', 'bkt_path_prefix', 'bkt_purpose') as $col) { $copy->set($col, $target->get($col)); }
	$copy->set('bkt_credentials', $creds);
	$copy->set('bkt_lock_days', 1);
	$r = TargetTester::test($copy);
	$lock_step = null;
	foreach ($r['steps'] as $s) { if ($s['label'] === 'Lock') { $lock_step = $s; } }
	check($r['success'] && $lock_step && $lock_step['status'] === 'pass', 'the connection test proves the lock for a target set to lock', $r['message']);

	$wc = S3Signer::with_lock($creds, 1);
	$f = $work . '/file.bin';
	file_put_contents($f, 'object lock live ' . $run);
	$put = S3Signer::put_file($wc, $bucket, '/' . $run . 'file.bin', $f);
	$fh = fopen('php://temp', 'w+');
	fwrite($fh, str_repeat('L', 11 * 1048576));
	rewind($fh);
	$multi = S3Signer::put_stream($wc, $bucket, '/' . $run . 'multi.bin', $fh, 'application/octet-stream', true, 5 * 1048576);
	fclose($fh);
	$locked_for_a_day = function ($key) use ($creds, $bucket) {
		$h = S3Signer::head($creds, $bucket, '/' . $key);
		$until = strtotime((string)($h['headers']['x-amz-object-lock-retain-until-date'] ?? ''));
		return ($h['headers']['x-amz-object-lock-mode'] ?? '') === 'COMPLIANCE' && $until > time() + 86400 - 600 && $until <= time() + 86400 + 60;
	};
	check((int)$put['status'] === 200 && $locked_for_a_day($run . 'file.bin'), 'a file lands in COMPLIANCE mode for one day', (string)$put['status']);
	check((int)$multi['status'] === 200 && $locked_for_a_day($run . 'multi.bin'), 'and so does a stream sent in parts', (string)$multi['status']);

	$d = S3Signer::delete($creds, $bucket, '/' . $run . 'file.bin');
	$still = S3Signer::head($creds, $bucket, '/' . $run . 'file.bin');
	check((int)$d['status'] === S3Signer::LOCKED && (int)($d['locked_until'] ?? 0) > time() && (int)$still['status'] === 200,
		'a delete answers LOCKED with the date, and the object is still there', (int)$d['status'] . ' ' . S3Signer::extract_error((string)$d['body']));

	$lock = array('x-amz-object-lock-mode' => 'COMPLIANCE', 'x-amz-object-lock-retain-until-date' => S3Signer::lock_until(1));
	$url = S3Signer::presign($creds, $bucket, $run . 'linked.bin', 'PUT', array(), 600, $lock);
	$send = function (array $headers) use ($url) {
		$body = 'linked';
		$h = array('Content-MD5: ' . base64_encode(md5($body, true)));
		foreach ($headers as $k => $v) { $h[] = $k . ': ' . $v; }
		$ch = curl_init($url);
		curl_setopt_array($ch, array(CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 60));
		curl_exec($ch);
		return (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
	};
	$without = $send(array());
	check($without >= 400 && (int)S3Signer::head($creds, $bucket, '/' . $run . 'linked.bin')['status'] === 404,
		'a write on a link that signed the lock is refused without it', (string)$without);
	$with = $send($lock);
	check($with === 200 && $locked_for_a_day($run . 'linked.bin'), 'and lands locked with it', (string)$with);
}

harness_finish();
