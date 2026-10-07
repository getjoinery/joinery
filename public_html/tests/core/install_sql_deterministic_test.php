<?php
/** @joinery-test
 * name: install_sql_deterministic
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 240
 */
/**
 * The install SQL a release ships is a function of the schema and the version
 * and nothing else (specs/release_transparency.md D2, O1): two runs of
 * utils/create_install_sql.php against the same database are the same bytes.
 *
 * The three things that used to differ between runs — a timestamp line, pg_dump's
 * random \restrict token, and a fresh bcrypt salt on the seed admin row — are
 * each pinned by name below, so a regression says which one came back.
 *
 * Reads the database through pg_dump; writes only its own versioned output
 * under uploads/, which it removes. Run: php tests/core/install_sql_deterministic_test.php
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$site_root = dirname(PathHelper::getRootDir());
$script = PathHelper::getRootDir() . '/utils/create_install_sql.php';
$version = '0.0.' . (900000 + (getmypid() % 90000));   // never a real release number
$out = $site_root . '/uploads/joinery-install-' . $version . '.sql.gz';

function isd_run($script, $version) {
	$lines = array();
	$exit = 0;
	exec('php ' . escapeshellarg($script) . ' ' . escapeshellarg($version) . ' 2>&1', $lines, $exit);
	return array('exit' => $exit, 'out' => implode("\n", $lines));
}

section('Two runs, one byte string');

$first = isd_run($script, $version);
check($first['exit'] === 0, 'first run exits 0', substr($first['out'], -400));
check(is_file($out), 'first run wrote its output', $out);
$a = is_file($out) ? file_get_contents($out) : '';
$a_sql = $a !== '' ? gzdecode($a) : '';

sleep(2);   // a timestamp with second resolution would differ from here on

$second = isd_run($script, $version);
check($second['exit'] === 0, 'second run exits 0', substr($second['out'], -400));
$b = is_file($out) ? file_get_contents($out) : '';
$b_sql = $b !== '' ? gzdecode($b) : '';

check($a !== '' && $a === $b, 'the compressed files are byte-identical',
	'sha256 ' . substr(hash('sha256', $a), 0, 16) . ' vs ' . substr(hash('sha256', $b), 0, 16));
check($a_sql !== '' && $a_sql === $b_sql, 'the SQL inside is byte-identical');

section('Each former source of difference, by name');

check(strpos($a_sql, 'Generated at') === false, 'no timestamp line');
check(preg_match('/^\\\\restrict joineryinstall$/m', $a_sql) === 1, 'pg_dump restrict token is the fixed one',
	'first restrict line: ' . (preg_match('/^\\\\restrict .*$/m', $a_sql, $m) ? $m[0] : 'none'));
check(preg_match("/VALUES \\(1, 'Admin', '', 'admin@example.com', 10, true, true, '!'/", $a_sql) === 1,
	'the seed admin row carries the locked marker, not a salted hash');
check(substr($a, 0, 2) === "\x1f\x8b" && ord($a[9]) !== 0 || substr($a, 4, 4) === "\0\0\0\0",
	'gzip header carries no modification time (gzip -n)', 'mtime bytes: ' . bin2hex(substr($a, 4, 4)));

@unlink($out);
harness_finish();
