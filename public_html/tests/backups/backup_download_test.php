<?php
/** @joinery-test
 * name: backup_download
 * tier: db
 * env: any
 * needs: []
 */
/**
 * BackupDownload: a whole-site backup built on this server and downloaded as
 * one tar (README, envelope, archive).
 *
 * Pinned: the tar is one a stock tar reads, its length is known before the
 * first byte, a size past 8 GiB and a long name are written the way GNU tar
 * reads them; a build seals the archive's key to the recovery key and the
 * engine encrypts with exactly that key; a build that fails keeps nothing but
 * its reason; a build whose process died reads as failed; a download past its
 * day is removed by any read of the state and by the sweep; one build at a
 * time, and none the disk cannot hold.
 *
 * The engine is a stand-in (backup_project.sh's stream mode has its own gate,
 * backup_project_stream_gate.sh): it records the key it was handed and writes
 * bytes and a report as the real one does.
 *
 * Run: php tests/backups/backup_download_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$work = sys_get_temp_dir() . '/jy_backup_download_' . getmypid();
@mkdir($work, 0700, true);
harness_defer(function () use ($work) { exec('rm -rf ' . escapeshellarg($work)); });
$dir = $work . '/download';
$capture = $work . '/engine_key';

$kp = random_bytes(32);   // an X-Wing seed
$recovery_pub = (new XWing())->publicKey($kp);
$alive = true;
$t0 = 1800000000;

$hooks = function (array $extra = array()) use ($dir, $recovery_pub, &$alive, $t0) {
	BackupDownload::$test_hooks = $extra + array(
		'dir'        => $dir,
		'now'        => $t0,
		'free_bytes' => 50 * 1000000000,
		'estimate'   => 1000000,
		'recipients' => array(array('kind' => 'recovery', 'pub' => $recovery_pub)),
		'launch'     => function () { return 4242; },
		'alive'      => function ($pid) use (&$alive) { return $alive; },
	);
};
$good_engine = 'cp "$1" ' . escapeshellarg($capture) . '; head -c 3000 /dev/urandom; printf "TAR_RC=1\nENC_RC=0\n" > "$2"';

section('The tar, pure');
$h = BackupDownload::tar_header('a/b.txt', 1234, 1700000000);
check(strlen($h) === 512, 'a header is one block');
$sum = 0;
for ($i = 0; $i < 512; $i++) { $sum += ($i >= 148 && $i < 156) ? 32 : ord($h[$i]); }
check(octdec(trim(substr($h, 148, 6))) === $sum, 'its checksum is the sum of its bytes with the checksum field as spaces');
check(octdec(substr($h, 124, 11)) === 1234, 'its size is in octal');
check(substr($h, 257, 6) === "ustar\0", 'it is ustar');
$big = 9 * 1073741824;
$h = BackupDownload::tar_header('big.enc', $big, 0);
$field = substr($h, 124, 12);
$n = 0;
for ($i = 1; $i < 12; $i++) { $n = $n * 256 + ord($field[$i]); }
check(ord($field[0]) === 0x80 && $n === $big, 'a size past 8 GiB is written in base-256');
$long = str_repeat('d', 80) . '/' . str_repeat('f', 90);
$h = BackupDownload::tar_header($long, 1, 0);
check(rtrim(substr($h, 345, 155), "\0") === str_repeat('d', 80) && rtrim(substr($h, 0, 100), "\0") === str_repeat('f', 90),
	'a name past 100 bytes is split into prefix and name at the slash');
check(BackupDownload::tar_length(array(1, 512, 0)) === 1024 + (512 + 512) + (512 + 512) + 512,
	'its length is a header and padded data per file, then two zero blocks');

section('What makes an archive, pure');
check(BackupDownload::verdict(0, array('TAR_RC' => '1', 'ENC_RC' => '0'), 3000) === '', 'tar 1 (a file changed while read) is an archive');
check(BackupDownload::verdict(0, array('TAR_RC' => '0', 'ENC_RC' => '1'), 3000) !== '', 'openssl failing is not');
check(BackupDownload::verdict(0, array('TAR_RC' => '2', 'ENC_RC' => '0'), 3000) !== '', 'tar 2 is not');
check(BackupDownload::verdict(0, array('TAR_RC' => '0', 'ENC_RC' => '0'), 32) !== '', 'an empty openssl envelope is not');
check(BackupDownload::verdict(3, array(), 3000) !== '', 'no report is not');

section('Building one');
$hooks(array('engine' => $good_engine));
$s = BackupDownload::start(7);
check($s['status'] === 'building' && $s['pid'] === 4242 && $s['by'] === 7, 'start records a build, its process and who asked', json_encode($s));
$refused = '';
try { BackupDownload::start(7); } catch (BackupDownloadException $e) { $refused = $e->getMessage(); }
check($refused !== '', 'a second start while one builds is refused', $refused);
$refused = '';
try { BackupDownload::discard(); } catch (BackupDownloadException $e) { $refused = $e->getMessage(); }
check($refused !== '', 'and so is deleting it');

$s = BackupDownload::build();
check($s['status'] === 'ready', 'the build finishes ready', json_encode($s));
$archive = $dir . '/' . $s['archive'];
check(is_file($archive) && filesize($archive) === 3000 && $s['bytes'] === 3000, 'the engine\'s output is the archive');
check($s['expires'] === $t0 + BackupDownload::KEEP_SECONDS, 'it is kept a day');
check(!glob($dir . '/.key-*'), 'the run key is gone');
$envelope = BackupEnvelope::read_sidecar($archive . BackupEnvelope::SIDECAR_SUFFIX);
$opened = BackupEnvelope::open($envelope, $kp);
check($envelope['version'] === BackupEnvelope::VERSION && $opened === file_get_contents($capture),
	'the envelope opens with the recovery key, to the key the engine encrypted with');
check(strpos(BackupDownload::download_name($s), BackupDownload::DOWNLOAD_SUFFIX) !== false, 'it downloads as one tar');

section('The file a person receives');
$tarfile = $work . '/received.tar';
$fh = fopen($tarfile, 'wb');
$rp = new ReflectionMethod('BackupDownload', 'entries');
$rp->setAccessible(true);
BackupDownload::write_tar($rp->invoke(null, $s), function ($b) use ($fh) { fwrite($fh, $b); });
fclose($fh);
check(filesize($tarfile) === $s['download_bytes'], 'it is exactly the length announced');
exec('tar -tf ' . escapeshellarg($tarfile) . ' 2>&1', $listing, $rc);
check($rc === 0 && $listing === array($s['name'] . '/README.txt', $s['name'] . '/' . $s['archive'] . '.keys.json', $s['name'] . '/' . $s['archive']),
	'tar reads it: README, envelope, archive, under one directory', implode(', ', $listing));
$out = $work . '/x';
@mkdir($out);
exec('tar -xf ' . escapeshellarg($tarfile) . ' -C ' . escapeshellarg($out) . ' 2>&1', $o, $rc);
check($rc === 0 && hash_file('sha256', $out . '/' . $s['name'] . '/' . $s['archive']) === hash_file('sha256', $archive),
	'the archive comes out of it byte for byte');
check(strpos((string)@file_get_contents($out . '/' . $s['name'] . '/README.txt'), 'restore_project.sh') !== false,
	'the README says how to restore it');

section('Its day');
$hooks(array('now' => $t0 + BackupDownload::KEEP_SECONDS - 1));
check(BackupDownload::state()['status'] === 'ready', 'it is there until its day is up');
$hooks(array('now' => $t0 + BackupDownload::KEEP_SECONDS));
$sweep = BackupDownload::sweep();
check(strpos($sweep['message'], 'removed') !== false, 'the sweep removes it once its day is up', $sweep['message']);
check(BackupDownload::state() === null && !is_file($archive) && !is_file($dir . '/' . BackupDownload::STATE_FILE),
	'nothing of it is left');

section('A build that fails');
$hooks(array('engine' => 'head -c 3000 /dev/urandom; printf "TAR_RC=0\nENC_RC=1\n" > "$2"; echo openssl broke >&2; exit 1'));
BackupDownload::start(7);
$s = BackupDownload::build();
check($s['status'] === 'failed' && strpos($s['message'], 'openssl exit 1') !== false && strpos($s['message'], 'openssl broke') !== false,
	'is recorded failed, with its reason and the engine\'s last words', $s['message'] ?? '');
check(count(glob($dir . '/*')) === 1, 'and keeps nothing but its state', implode(', ', glob($dir . '/*')));
BackupDownload::discard();
check(BackupDownload::state() === null, 'a failure can be deleted');

section('A build whose process died');
$hooks(array('engine' => $good_engine));
BackupDownload::start(7);
$alive = false;
// The moment inside start() between recording the build and launching it.
$st = json_decode(file_get_contents($dir . '/' . BackupDownload::STATE_FILE), true);
$st['pid'] = 0;
file_put_contents($dir . '/' . BackupDownload::STATE_FILE, json_encode($st));
$hooks(array('now' => $t0 + 30));
check(BackupDownload::state()['status'] === 'building', 'a build is given a minute to report its process', json_encode(BackupDownload::state()));
$hooks(array('now' => $t0 + 61));
$s = BackupDownload::state();
check($s['status'] === 'failed' && strpos($s['message'], 'stopped') !== false, 'one whose process is gone reads as failed', json_encode($s));
$alive = true;

section('Refusals');
$hooks(array('free_bytes' => 100000000, 'estimate' => 2000000000));
$refused = '';
try { BackupDownload::start(7); } catch (BackupDownloadException $e) { $refused = $e->getMessage(); }
check(strpos($refused, 'is free') !== false, 'a disk that cannot hold the archive is refused, with both figures', $refused);
check(BackupDownload::state()['status'] === 'failed', 'and the earlier download is left as it was');

BackupDownload::$test_hooks = array();
harness_finish();
