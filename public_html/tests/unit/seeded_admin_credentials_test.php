<?php
/** @joinery-test
 * name: seeded_admin_credentials
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Migration 223 (seeded_accounts_usernames) turns a seeded admin still at
 * admin@example.com into the username admin. A site installed before then
 * keeps config/admin_credentials.txt saying 'Email: admin@example.com', which
 * no longer signs in (found upgrading a 0.8.535 test install, 2026-10-25), so
 * the migration rewrites that line while the file is still there:
 *
 *   - the Email line becomes 'Username: admin', the rest of the file kept;
 *   - a file naming another address is left alone;
 *   - no file, or one this run cannot write, is said in words, never an error.
 *
 * Works on temporary files only.
 *
 * Run: php tests/run.php safe --filter=seeded_admin_credentials
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('migrations/seeded_accounts_usernames.php'));

$dir = sys_get_temp_dir() . '/seeded_admin_credentials_' . bin2hex(random_bytes(4));
mkdir($dir, 0700);
$file = $dir . '/admin_credentials.txt';
$old = "Joinery admin login for site\n\nURL:      http://example.test/login\nEmail:    admin@example.com\nPassword: kept-as-is\n\nDelete this file once you have signed in.\n";

section('The old file names the username instead');

file_put_contents($file, $old);
$said = seeded_accounts_rewrite_credentials($file, 'admin@example.com', 'admin');
$now = file_get_contents($file);
check(strpos($said, 'now names the username admin') !== false, 'it says what it did', $said);
check(strpos($now, "Username: admin\n") !== false, 'the file says Username: admin', $now);
check(stripos($now, 'admin@example.com') === false, 'and no longer names the old address');
check(strpos($now, 'Password: kept-as-is') !== false && strpos($now, 'Delete this file') !== false, 'the rest of the file is kept');

section('A file with both lines keeps one Username line');

file_put_contents($file, "URL: x\nUsername: admin\nEmail:    ADMIN@example.com\n");
seeded_accounts_rewrite_credentials($file, 'admin@example.com', 'admin');
$now = file_get_contents($file);
check(substr_count($now, 'Username:') === 1 && stripos($now, 'example.com') === false, 'one Username line, no address', $now);

section('Another address is left alone');

$other = "Email:    owner@real.test\n";
file_put_contents($file, $other);
seeded_accounts_rewrite_credentials($file, 'admin@example.com', 'admin');
check(file_get_contents($file) === $other, 'a file naming a real address is unchanged');

section('No file, or one it cannot write, is said in words');

$said = seeded_accounts_rewrite_credentials($dir . '/missing.txt', 'admin@example.com', 'admin');
check(strpos($said, 'sign in as admin') !== false, 'a missing file: it says to sign in as admin', $said);
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
	harness_skip('an unwritable file is reported', 'running as root, which can write any file');
} else {
	file_put_contents($file, $old);
	chmod($file, 0400);
	$said = seeded_accounts_rewrite_credentials($file, 'admin@example.com', 'admin');
	check(strpos($said, 'not writable here') !== false && file_get_contents($file) === $old,
		'an unwritable file is left as it was, and the line says so', $said);
	chmod($file, 0600);
}

@unlink($file);
@rmdir($dir);
harness_finish();
