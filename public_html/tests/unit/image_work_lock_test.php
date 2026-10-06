<?php
/** @joinery-test
 * name: image_work_lock
 * tier: safe
 * env: any
 * needs: []
 * timeout: 60
 */

/**
 * One image decode at a time on a site (ImageWorkLock;
 * specs/multi_tenant_docker_hosts.md WP2). A decoded photo costs its full pixel
 * size outside PHP's memory_limit, and two at once got one killed inside a
 * 256 MB site container. Pinned with real processes against a scratch lock file:
 *
 *   - the work runs and its value comes back; a nested run does not wait on itself
 *   - a decode waits while another process holds the lock, and runs once it lets go
 *   - a holder killed outright releases the lock (flock dies with the process)
 *   - a wait past the limit gives up, logs it, and does the work anyway
 *   - an unopenable lock file runs the work without the lock
 *   - the one decode path takes it: FileBlob::_render_sizes, once around a whole resize,
 *     and UploadHandler decodes nothing at all
 *
 * @version 1.1 - pins one decode under one hold per resize (specs/image_decode_memory.md)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$dir = harness_scratch_dir('image_work_lock');
$lock = $dir . '/image_work.lock';
ImageWorkLock::set_path_for_tests($lock);
harness_defer(function () {
	ImageWorkLock::set_path_for_tests(null);
	ImageWorkLock::set_wait_for_tests(null);
});

/** Another process that takes the lock, says so, and holds it for $seconds. */
function hold_lock_in_child(string $lock, string $ready, float $seconds) {
	@unlink($ready);   // a scratch dir is reused across runs: an old marker would say "held" too soon
	$code = '$h = fopen($argv[1], "c"); flock($h, LOCK_EX); touch($argv[2]); usleep((int)($argv[3] * 1000000));';
	$proc = proc_open(array('php', '-r', $code, '--', $lock, $ready, (string)$seconds),
		array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
	for ($i = 0; $i < 100 && !file_exists($ready); $i++) { usleep(50000); }
	return $proc;
}

section('the work runs, and a nested run does not wait on itself');
check(ImageWorkLock::run(function () { return 42; }) === 42, 'the work\'s value comes back');
$t = microtime(true);
$inner = ImageWorkLock::run(function () { return ImageWorkLock::run(function () { return 'inner'; }); });
check($inner === 'inner' && microtime(true) - $t < 1.0, 'a nested run goes straight through', round(microtime(true) - $t, 2) . ' s');

section('a decode waits while another process holds the lock');
$ready = $dir . '/ready1';
$child = hold_lock_in_child($lock, $ready, 1.5);
check(file_exists($ready), 'the other process holds the lock');
$t = microtime(true);
$ran_at = ImageWorkLock::run(function () { return microtime(true); });
$waited = $ran_at - $t;
check($waited >= 1.0, 'the work ran only after the holder let go', round($waited, 2) . ' s waited');
check($waited < 5.0, 'and soon after', round($waited, 2) . ' s');
proc_close($child);

section('a holder killed outright releases the lock');
$ready = $dir . '/ready2';
$child = hold_lock_in_child($lock, $ready, 30);
check(file_exists($ready), 'the other process holds the lock');
proc_terminate($child, 9);
proc_close($child);
$t = microtime(true);
ImageWorkLock::run(function () { return true; });
check(microtime(true) - $t < 2.0, 'the next decode runs at once', round(microtime(true) - $t, 2) . ' s');

section('a wait past the limit does the work anyway');
ImageWorkLock::set_wait_for_tests(0.6);
$ready = $dir . '/ready3';
$child = hold_lock_in_child($lock, $ready, 5);
$log = $dir . '/error.log';
@unlink($log);
$old_log = ini_set('error_log', $log);
$t = microtime(true);
$value = ImageWorkLock::run(function () { return 'done anyway'; });
ini_set('error_log', $old_log);
check($value === 'done anyway', 'the work ran');
check(microtime(true) - $t < 3.0, 'after the shortened wait, not the holder\'s 5 s', round(microtime(true) - $t, 2) . ' s');
check(strpos((string)@file_get_contents($log), 'decoding without it') !== false, 'and it was logged');
proc_terminate($child, 9);
proc_close($child);
ImageWorkLock::set_wait_for_tests(null);

section('an unopenable lock file runs the work without the lock');
ImageWorkLock::set_path_for_tests($dir . '/no/such/dir/image_work.lock');
$old_log = ini_set('error_log', $log);
check(ImageWorkLock::run(function () { return 'ran'; }) === 'ran', 'the work ran');
ini_set('error_log', $old_log);
ImageWorkLock::set_path_for_tests($lock);

section('the one decode path takes the lock, once around the whole resize');
// Every pixel the platform produces comes from ImageDecoder::open() inside
// FileBlob::_render_sizes(), which holds the lock from the decode until the
// decoded image is freed — one hold per resize, not one per size — and
// UploadHandler decodes nothing at all (specs/image_decode_memory.md).
$blob_src = file_get_contents(PathHelper::getIncludePath('data/file_blobs_class.php'));
check(preg_match('/function _render_sizes\(.*?\{\s*return ImageWorkLock::run\(function \(\) use .*?ImageDecoder::open\(/s', $blob_src) === 1,
	'FileBlob::_render_sizes decodes inside ImageWorkLock::run');
check(substr_count($blob_src, 'ImageDecoder::open(') === 1 && substr_count($blob_src, 'ImageWorkLock::run(') === 1,
	'and that is the only decode, under the only lock hold, in the blob class');
check(preg_match('/\$this->_render_size\(\$decoded, /', $blob_src) === 1
	&& strpos($blob_src, 'unset($decoded);') !== false,
	'every size is cut from the one decoded image, which is freed before the lock is');
$upload_src = file_get_contents(PathHelper::getIncludePath('includes/UploadHandler.php'));
check(!preg_match('/imagecreatefrom|imagerotate|image_objects|ImageWorkLock/', $upload_src),
	'UploadHandler decodes nothing: no reader, no rotation, no image cache, nothing to lock');

harness_finish();
