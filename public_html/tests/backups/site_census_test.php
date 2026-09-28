<?php
/** @joinery-test
 * name: site_census
 * tier: db
 * env: any
 * needs: []
 * covers: [includes/SiteCensus.php, includes/SecretReconciler.php, includes/SecretBox.php, ../maintenance_scripts/sysadmin_tools/site_census.php]
 */
/**
 * The census (specs/site_copy.md WP3): what a site holds, counted so a copy can
 * be checked against its source.
 *
 * The file count runs on fixture trees; the table and secret counts run on
 * this site's own database, read-only. What is asserted:
 *
 *   * It counts what a backup chain carries and nothing else: the names the
 *     files engine leaves out, the site's backup_exclude names (patterns
 *     included), offloaded files, and each machine's own config, key and
 *     ledger.
 *   * Those excluded items can differ between the two sides without a
 *     difference being reported.
 *   * compare() reports a missing row, a missing file, a file changed in size,
 *     a renamed file and a dead sealed secret; a dead secret or a canary that
 *     does not open blocks even while the source is live.
 *   * It writes nothing.
 *
 * Run: php tests/backups/site_census_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$tmp = sys_get_temp_dir() . '/jy_census_' . getmypid();
register_shutdown_function(function () use ($tmp) {
	if (is_dir($tmp)) { exec('rm -rf ' . escapeshellarg($tmp)); }
});

/** Write $files (relative path => content) under $root. A null content is a directory. */
function sc_tree(string $root, array $files): void {
	foreach ($files as $rel => $content) {
		$path = $root . '/' . $rel;
		if ($content === null) { @mkdir($path, 0755, true); continue; }
		@mkdir(dirname($path), 0755, true);
		file_put_contents($path, $content);
	}
}

/** A site as a chain would carry it, plus everything each machine keeps as its own. */
function sc_site(string $root, array $own): void {
	sc_tree($root, array(
		'public_html/index.php'            => '<?php echo 1;',
		'public_html/theme/x/style.css'    => 'body{}',
		'config/relay_pull_key'            => 'relay identity',
		'uploads/a.jpg'                    => str_repeat('a', 100),
		'uploads/b.jpg'                    => str_repeat('b', 50),
		'storage/mail/1.eml'               => 'From: x',
		'static_files/kept.jpg'            => 'local',
		'static_files/offloaded.jpg'       => 'in the bucket',
		'RELEASE_MANIFEST'                 => 'm',
		'public_html/x/mylogs'             => 'k',        // a plain name matches a whole name only
		'public_html/x/joinery-core-2'     => null,       // tar keeps the directory of a file it leaves out
		'empty_dir'                        => null,
	) + $own);
	@symlink('uploads/a.jpg', $root . '/public_html/link.jpg');
}

$names = array('sync', 'joinery-core-*.tar.gz');
$paths = array('static_files/offloaded.jpg');

// ---------------------------------------------------------------- the count

section('The census counts what a chain carries');

$s = $tmp . '/source/site';
sc_site($s, array(
	'config/Globalvars_site.php'       => "<?php \$this->settings['dbpassword'] = 'source';",
	'config/backup_site_key'           => 'source site key',
	'config/backup-ledger/site.json'   => '{}',
	'uploads/upgrades/joinery-1.zip'   => 'upgrade',
	'logs/error.log'                   => 'x',
	'backups/chain/manifest.json'      => '{}',
	'vendor/autoload.php'              => '<?php',
	'cache/x'                          => 'x',
	'public_html/node_modules/a.js'    => 'x',
	'public_html/.git/HEAD'            => 'ref',
	'public_html_1.2.3/index.php'      => 'old code',
	'sync/worktree/file'               => 'x',
	'public_html/joinery-core-1.tar.gz' => 'x',
	'public_html/x/joinery-core-2/y.tar.gz' => 'x',   // tar's * crosses '/': left out too
));
$fs = SiteCensus::files($s, $names, $paths);

check(array_keys($fs) === array('.', 'config', 'empty_dir', 'public_html', 'static_files', 'storage', 'uploads'),
	'every top-level directory the chain carries, and nothing it leaves out', implode(',', array_keys($fs)));
check($fs['config']['files'] === 1, 'config/ counts the relay key only: Globalvars_site.php, backup_site_key and the ledger are this machine\'s',
	json_encode($fs['config']));
check($fs['uploads']['files'] === 2 && $fs['uploads']['bytes'] === 150, 'uploads/upgrades is left out', json_encode($fs['uploads']));
check($fs['static_files']['files'] === 1, 'an offloaded file\'s local copy is left out', json_encode($fs['static_files']));
check($fs['public_html']['files'] === 4, 'public_html: node_modules, .git and the backup_exclude pattern left out; a link counted',
	json_encode($fs['public_html']));

// The files engine is the authority: what tar leaves out, the census leaves out.
$tar_list = array();
exec('tar -C ' . escapeshellarg(dirname($s)) . ' --exclude=' . escapeshellarg('joinery-core-*.tar.gz')
	. ' --exclude=logs -cf - ' . escapeshellarg(basename($s) . '/public_html') . ' | tar -tf -', $tar_list);
$tar_files = count(array_filter($tar_list, function ($l) { return substr($l, -1) !== '/' && strpos($l, '/node_modules/') === false && strpos($l, '/.git/') === false; }));
check($tar_files === $fs['public_html']['files'], 'and tar, given the same pattern, carries the same number of files in public_html',
	"tar $tar_files, census {$fs['public_html']['files']}: " . implode(' ', $tar_list));
check($fs['.']['files'] === 1 && $fs['.']['bytes'] === 1, 'a loose file at the root is counted under .', json_encode($fs['.']));
check($fs['empty_dir']['dirs'] === 1 && $fs['empty_dir']['files'] === 0, 'an empty directory is counted', json_encode($fs['empty_dir']));

// ---------------------------------------------------------------- the copy

section('What each machine keeps as its own differs without a difference');

$c = $tmp . '/copy/site';
sc_site($c, array(
	'config/Globalvars_site.php'       => "<?php \$this->settings['dbpassword'] = 'the copy has a longer one';",
	'config/backup_site_key'           => 'copy site key, another length',
	'logs/other.log'                   => 'yz',
	'backups/restore_chain-1/manifest.json' => '{"x":1}',
	'vendor/other.php'                 => '<?php //',
));
@unlink($c . '/static_files/offloaded.jpg');   // the copy reads it from the bucket
$fc = SiteCensus::files($c, $names, $paths);

$census = function (array $files, array $tables = array('usr_users' => 3), array $secrets = array(), array $offloaded = array()) {
	return array('version' => SiteCensus::VERSION, 'tables' => $tables, 'files' => $files,
		'secrets' => $secrets + array('canary' => SecretBox::OPEN_OK, 'present' => 4, 'dead' => 0, 'dead_locators' => array()),
		'offloaded' => $offloaded + array('total' => 2, 'sampled' => 2, 'answered' => 2, 'missing' => array(), 'error' => ''));
};
$r = SiteCensus::compare($census($fs), $census($fc), true);
check($r['match'] && $r['blocking'] === 0, 'the exact comparison matches', json_encode($r['differences']));

// ---------------------------------------------------------------- differences

section('A missing file, a file changed in size, and a renamed file are found');

$diff = function (array $r, string $what) {
	foreach ($r['differences'] as $d) { if (strpos($d['what'], $what) === 0) { return $d; } }
	return null;
};

unlink($c . '/uploads/b.jpg');
$r = SiteCensus::compare($census($fs), $census(SiteCensus::files($c, $names, $paths)), true);
$d = $diff($r, 'files in uploads/');
check(!$r['match'] && $d && $d['source'] === 2 && $d['copy'] === 1 && $d['blocking'], 'a missing file blocks the exact comparison', json_encode($r['differences']));

file_put_contents($c . '/uploads/b.jpg', str_repeat('b', 49));
$r = SiteCensus::compare($census($fs), $census(SiteCensus::files($c, $names, $paths)), true);
$d = $diff($r, 'bytes in uploads/');
check($d && $d['source'] === 150 && $d['copy'] === 149, 'a file changed in size is found', json_encode($r['differences']));

$r2 = SiteCensus::compare($census($fs), $census(SiteCensus::files($c, $names, $paths)), false);
check(!$r2['match'] && $r2['blocking'] === 0, 'while the source is live, it is reported and does not block', json_encode($r2));

unlink($c . '/uploads/b.jpg');
file_put_contents($c . '/uploads/c.jpg', str_repeat('b', 50));
$r = SiteCensus::compare($census($fs), $census(SiteCensus::files($c, $names, $paths)), true);
check($diff($r, 'paths and sizes in uploads/') !== null && count($r['differences']) === 1,
	'a renamed file, same count and bytes, is found by the digest', json_encode($r['differences']));
rename($c . '/uploads/c.jpg', $c . '/uploads/b.jpg');

$r = SiteCensus::compare($census($fs), $census(SiteCensus::files($c, $names, $paths)), true);
check($r['match'], 'put back, the two match again', json_encode($r['differences']));

section('A missing row, a missing table, and dead secrets are found');

$r = SiteCensus::compare($census($fs, array('usr_users' => 3, 'evt_events' => 1)), $census($fs, array('usr_users' => 2, 'evt_events' => 1)), true);
$d = $diff($r, 'rows in usr_users');
check($d && $d['source'] === 3 && $d['copy'] === 2 && $r['blocking'] === 1, 'a missing row blocks the exact comparison', json_encode($r));
$r = SiteCensus::compare($census($fs, array('usr_users' => 3)), $census($fs, array('usr_users' => 2)), false);
check(!$r['match'] && $r['blocking'] === 0, 'and is informational while the source is live', json_encode($r));
$r = SiteCensus::compare($census($fs, array('usr_users' => 3, 'evt_events' => 0)), $census($fs, array('usr_users' => 3)), true);
$d = $diff($r, 'rows in evt_events');
check($d && $d['source'] === 0 && $d['copy'] === null, 'a table missing on the copy is found, even an empty one', json_encode($r));

$r = SiteCensus::compare($census($fs), $census($fs, array('usr_users' => 3), array('dead' => 1, 'dead_locators' => array('x' => 1))), false);
$d = $diff($r, 'dead sealed secrets');
check($d && $d['blocking'], 'a dead secret on the copy blocks even while the source is live', json_encode($r));
$r = SiteCensus::compare($census($fs), $census($fs, array('usr_users' => 3), array('canary' => SecretBox::OPEN_DEAD, 'dead' => 4)), false);
check($diff($r, 'key canary') && $diff($r, 'key canary')['blocking'], 'a canary that does not open on the copy blocks', json_encode($r));
$r = SiteCensus::compare($census($fs), $census($fs, array('usr_users' => 3), array('canary' => 'nokey')), false);
check($r['blocking'] >= 1, 'a copy with no key blocks', json_encode($r));
$r = SiteCensus::compare($census($fs, array('usr_users' => 3), array('dead' => 1)), $census($fs, array('usr_users' => 3), array('dead' => 1)), true);
check($r['match'], 'a secret already dead on the source, and dead on the copy, is no difference', json_encode($r));

section('Offloaded files: the count, and a sample that must answer from the bucket');

/** A bucket that holds the named keys and nothing else. */
function sc_bucket(array $held) {
	return new class($held) implements CloudStorageDriver {
		public $asked = 0;
		private $held;
		public function __construct(array $held) { $this->held = array_fill_keys($held, true); }
		public function head(string $remote_key): ?array { $this->asked++; return isset($this->held[$remote_key]) ? array('size' => 1, 'etag' => 'e') : null; }
		public function put(string $local_path, string $remote_key, string $content_type): void { throw new Exception('the census never writes'); }
		public function get(string $remote_key, string $local_path): void { throw new Exception('the census never downloads'); }
		public function get_range(string $remote_key, string $local_path, int $start, int $end): void { throw new Exception('the census never downloads'); }
		public function delete(string $remote_key): void { throw new Exception('the census never deletes'); }
		public function url(string $remote_key): string { return ''; }
		public function ping(): array { return array(); }
	};
}
$objs = array();
for ($i = 0; $i < 30; $i++) { $objs[] = array('name' => "f$i.jpg", 'remote_key' => "k$i"); }
$all = sc_bucket(array_column($objs, 'remote_key'));
$o = SiteCensus::offloaded($objs, $all);
check($o['total'] === 30 && $o['sampled'] === SiteCensus::OFFLOAD_SAMPLE && $o['answered'] === $o['sampled'] && $all->asked === $o['sampled'],
	'thirty offloaded files: a sample of them is asked about, one request each, and all answer', json_encode($o));
$o = SiteCensus::offloaded(array_slice($objs, 0, 3), sc_bucket(array('k0', 'k2')));
check($o['sampled'] === 3 && $o['answered'] === 2 && $o['missing'] === array('f1.jpg'), 'one the bucket does not hold is named', json_encode($o));
$r = SiteCensus::compare($census($fs), $census($fs, array('usr_users' => 3), array(), array('answered' => 1, 'missing' => array('f1.jpg'))), false);
$d = $diff($r, 'offloaded files the copy reaches');
check($d && $d['blocking'] && strpos((string)$d['copy'], 'f1.jpg') !== false, 'which blocks even while the source is live', json_encode($r));
$o = SiteCensus::offloaded($objs, null);
check($o['error'] !== '' && $o['sampled'] === 0, 'offloaded files with no file store to reach them is an error, not a pass', json_encode($o));
$r = SiteCensus::compare($census($fs), $census($fs, array('usr_users' => 3), array(), array('sampled' => 0, 'answered' => 0, 'error' => $o['error'])), false);
check($r['blocking'] === 1, 'and blocks', json_encode($r));
check(SiteCensus::offloaded(array(), null) === array('total' => 0, 'sampled' => 0, 'answered' => 0, 'missing' => array(), 'error' => ''),
	'a site with nothing offloaded asks nothing');
$r = SiteCensus::compare($census($fs, array('usr_users' => 3), array(), array('total' => 3)), $census($fs), true);
check($diff($r, 'offloaded files') && $r['blocking'] === 1, 'a different number offloaded blocks the exact comparison', json_encode($r));

$other = $census($fs); $other['version'] = 99;
$r = SiteCensus::compare($census($fs), $other, false);
check($r['blocking'] === 1 && $diff($r, 'census format'), 'two census formats do not compare', json_encode($r));

// ---------------------------------------------------------------- this site

section('This site\'s census, read-only');

// In one snapshot, so the counts compared below are of the same moment as the
// census's, whatever else runs on this database meanwhile.
$db = DbConnector::get_instance()->get_db_link();
$db->beginTransaction();
$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$checked_before = $db->query('SELECT max(ssr_checked_time) FROM ssr_sealed_secret_registry')->fetchColumn();

$here = SiteCensus::take();
check(isset($here['tables']['usr_users']) && $here['tables']['usr_users'] > 0, 'every table is counted', (string)count($here['tables']));
check(!array_key_exists('bkh_backup_history', $here['tables']), 'the rows a backup run writes after its dump are left out');
$real = (int)$db->query('SELECT count(*) FROM usr_users')->fetchColumn();
check($here['tables']['usr_users'] === $real, 'a table\'s count is its row count', $here['tables']['usr_users'] . ' vs ' . $real);
check(isset($here['files']['public_html']) && !isset($here['files']['vendor']) && !isset($here['files']['logs']),
	'the site\'s files are counted by top-level directory', implode(',', array_keys($here['files'])));
check(in_array($here['secrets']['canary'], array(SecretBox::OPEN_OK, SecretBox::OPEN_ABSENT), true),
	'this site\'s key opens its canary', json_encode(array_diff_key($here['secrets'], array('dead_locators' => 1))));
check($here['secrets']['dead'] === array_sum($here['secrets']['dead_locators']), 'the dead secrets are named by where they live');

// The same count with a key that is not this site's: what a copy that did not
// take its source's key would see.
$rc = new ReflectionClass('SecretBox');
$wrong = $rc->newInstanceWithoutConstructor();
$prop = $rc->getProperty('key'); $prop->setAccessible(true); $prop->setValue($wrong, random_bytes(SecretBox::KEY_BYTES));
$dead = SecretReconciler::census($wrong);
check($dead['present'] === $here['secrets']['present'] && ($dead['present'] === 0 || $dead['dead'] > 0),
	'with another key, the stored secrets read dead', json_encode(array_diff_key($dead, array('dead_locators' => 1))));
if ($here['secrets']['canary'] === SecretBox::OPEN_OK) {
	check($dead['canary'] === SecretBox::OPEN_DEAD, 'and the canary does not open');
	$r = SiteCensus::compare($here, array('secrets' => $dead) + $here, false);
	check($r['blocking'] >= 1, 'which compare() blocks on', json_encode($r['differences']));
}

$checked_after = $db->query('SELECT max(ssr_checked_time) FROM ssr_sealed_secret_registry')->fetchColumn();
check($checked_before === $checked_after, 'the census writes no verdict back to the registry', "$checked_before / $checked_after");
$db->rollBack();
check(!$db->inTransaction(), 'and it leaves no transaction open');

section('The census line');

$line = SiteCensus::format_output($here);
check(SiteCensus::parse_output("PHP Warning: something\n" . $line . "more\n") === json_decode(json_encode($here), true),
	'it reads back through warnings around it');
check(SiteCensus::parse_output("ERROR: the census could not be taken\n") === null, 'an error is no census');
check(SiteCensus::parse_output('CENSUS={"version":1}') === null, 'a line missing its parts is no census');
check(is_array($here['offloaded']) && $here['offloaded']['error'] === '' && $here['offloaded']['answered'] === $here['offloaded']['sampled'],
	'this site\'s offloaded files answer from its bucket', json_encode($here['offloaded']));

$script = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/site_census.php';
$args_out = array();
exec('php ' . escapeshellarg($script) . ' --root /tmp 2>&1', $args_out, $rc_args);
check($rc_args === 2, 'the script takes no arguments', implode(' ', $args_out));

section('One list with the files engine');

$bf = file_get_contents(PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/backup_files.sh');
preg_match('/^NAMED_EXCLUDES=\(([^)]*)\)/m', $bf, $m);
$engine = preg_split('/\s+/', trim($m[1] ?? ''));
check($engine === SiteCensus::NAMED_EXCLUDES, 'the census leaves out exactly the names backup_files.sh leaves out',
	implode(' ', $engine) . ' vs ' . implode(' ', SiteCensus::NAMED_EXCLUDES));
check(strpos($bf, '--exclude="${BASE}/uploads/upgrades"') !== false && strpos($bf, '--exclude="${BASE}/public_html_*"') !== false,
	'and the files engine still leaves out uploads/upgrades and public_html_*, as the census does');

harness_finish();
