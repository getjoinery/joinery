<?php
/** @joinery-test
 * name: file_serve_unbuffered
 * tier: safe
 * env: any
 * needs: []
 * timeout: 120
 */
/**
 * A served file is streamed, not held in memory.
 *
 * The router buffers what a route handler writes, and for a signed-out GET it
 * opens a second buffer for the page cache. File::serve_from_path() wrote a
 * download into both, so the file counted against memory_limit twice: on a
 * 128 MB server a 63 MB Drive file could not be downloaded at all. Here a
 * child process with a 32 MB limit serves a 48 MB file from inside two
 * buffers, and every byte has to come out.
 *
 * Run: php tests/unit/file_serve_unbuffered_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$root = realpath(__DIR__ . '/../..');
$big = tempnam(sys_get_temp_dir(), 'serve_big');
$size = 48 * 1024 * 1024;
$fh = fopen($big, 'wb');
$block = str_repeat('j', 1024 * 1024);
for ($i = 0; $i < 48; $i++) { fwrite($fh, $block); }
fclose($fh);

$child = tempnam(sys_get_temp_dir(), 'serve_child');
file_put_contents($child, '<?php
require_once ' . var_export($root . '/includes/PathHelper.php', true) . ';
require_once(PathHelper::getIncludePath("includes/Globalvars.php"));
$file = new File(NULL);
$file->set("fil_type", "application/octet-stream");
$file->set("fil_name", "big.bin");
ob_start();   // the page cache buffer, for a signed-out GET
ob_start();   // the router empty-output guard
$file->serve_from_path(' . var_export($big, true) . ', "private, no-store");
fwrite(STDERR, "served, " . ob_get_level() . " buffer(s) left\n");
');

section('A file larger than the memory limit');

$out = array();
exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=32M ' . escapeshellarg($child) . ' 2>&1 | wc -c', $out);
$bytes = (int)trim(implode('', $out));
$said = shell_exec(escapeshellarg(PHP_BINARY) . ' -d memory_limit=32M ' . escapeshellarg($child) . ' 2>&1 >/dev/null');
check(strpos((string)$said, 'Allowed memory size') === false, 'serving it does not run out of memory', trim((string)$said));
check(strpos((string)$said, 'served, 0 buffer(s) left') !== false, 'the body is written past every buffer', trim((string)$said));
check($bytes >= $size && $bytes < $size + 4096, 'and every byte comes out', $bytes . ' bytes of ' . $size);

@unlink($child);
@unlink($big);

harness_finish();
