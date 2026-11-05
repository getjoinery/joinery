<?php
/** @joinery-test
 * name: unseal_install
 * tier: safe
 * env: any
 * needs: []
 * timeout: 90
 * covers: [maintenance_scripts/install_tools/install_unseal_daemon.sh, maintenance_scripts/install_tools/joinery_unseal/launch.sh, maintenance_scripts/install_tools/_plugin_installers_start.sh]
 *
 * Installing and supervising the unseal daemon, without root
 * (specs/unseal_daemon.md WP3):
 *  - the launcher (joinery_unseal/launch.sh) under its unprivileged test hook:
 *    the three-crashes rule moving a site to the previous build, every start
 *    and stop recorded, hold and lift, its status line, and that it refuses
 *    an unprivileged run without the hook;
 *  - the installer as text: a core installer, a non-root run that changes
 *    nothing, and the unit's protections;
 *  - UnsealDaemon reading what root leaves (the install record, the
 *    launcher's state, the hold) under a stand-in root, its key derived as the
 *    installer derives it, and facts() asking the shipped binary over a real
 *    socket; UnsealClient's frames; the admin notice's wording.
 * tests/security/unseal_daemon_gate.sh checks a real installation.
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$tools = realpath(PathHelper::getSiteRoot() . '/maintenance_scripts/install_tools');
$launch = $tools . '/joinery_unseal/launch.sh';
$installer = $tools . '/install_unseal_daemon.sh';
$root = sys_get_temp_dir() . '/ui_' . getmypid() . '_' . bin2hex(random_bytes(3));
mkdir($root, 0700);
harness_defer(function () use ($root) {
	UnsealDaemon::$root_override = null;
	if (!empty($GLOBALS['ui_proc'])) {
		$s = proc_get_status($GLOBALS['ui_proc']);
		if ($s['running']) {
			posix_kill($s['pid'], 15);
			usleep(300000);
		}
		proc_close($GLOBALS['ui_proc']);
	}
	exec('rm -rf ' . escapeshellarg($root));
});

/** Run the launcher under the test hook; [exit code, output]. */
function ui_launch(string $launch, string $root, string $args, array $env = array()): array {
	$prefix = 'JOINERY_UNSEAL_TEST_ROOT=' . escapeshellarg($root);
	foreach ($env as $k => $v) {
		$prefix .= ' ' . $k . '=' . escapeshellarg($v);
	}
	exec($prefix . ' bash ' . escapeshellarg($launch) . ' ' . $args . ' 2>&1', $out, $rc);
	return array($rc, implode("\n", $out));
}

$key = 'site_one';
mkdir($root . '/etc/joinery-unseal/' . $key, 0755, true);
$state = $root . '/var/lib/joinery-unseal/' . $key;
mkdir($state, 0755, true);
file_put_contents($root . '/etc/joinery-unseal/' . $key . '/installed',
	"socket=/run/joinery-unseal/{$key}/unseal.sock\nallow_uid=" . posix_geteuid() . "\npool_user=www-data\ninit=systemd\n");
touch($state . '/joinery-unseal');
touch($state . '/joinery-unseal.previous');
chmod($state . '/joinery-unseal', 0755);
chmod($state . '/joinery-unseal.previous', 0755);

// ---------------------------------------------------------------------------
section('The launcher: the fallback to the previous build');

exec('bash ' . escapeshellarg($launch) . ' status ' . $key . ' 2>&1', $o, $rc);
check($rc !== 0 && strpos(implode(' ', $o), 'must run as root') !== false, 'an unprivileged run without the test hook is refused');
$binaries = array();
for ($i = 1; $i <= 4; $i++) {
	[$rc, $out] = ui_launch($launch, $root, 'prepare ' . $key);
	$binaries[] = trim((string)@file_get_contents($state . '/binary'));
}
check($binaries === array('current', 'current', 'current', 'previous'),
	'three starts with no run that stayed up stay on the current build; the fourth moves to the previous one', json_encode($binaries));
check(readlink($state . '/active') === $state . '/joinery-unseal.previous', 'and the unit\'s active link points at the site\'s own previous build');
check(is_dir($root . '/run/joinery-unseal/' . $key), 'prepare makes the socket\'s directory');
ui_launch($launch, $root, 'prepare ' . $key);
check(trim((string)file_get_contents($state . '/binary')) === 'previous', 'the site stays on the previous build until an install moves it back');

// A run that stayed up five minutes clears the count, however long ago the
// starts before it were: a hang (start timeout, watchdog) counts like a crash.
file_put_contents($state . '/binary', "current\n");
file_put_contents($state . '/starts', (time() - 3000) . "\n" . (time() - 2000) . "\n" . (time() - 400) . "\n");
ui_launch($launch, $root, 'stopped ' . $key, array('SERVICE_RESULT' => 'success'));
ui_launch($launch, $root, 'prepare ' . $key);
check(trim((string)file_get_contents($state . '/starts')) !== '' && count(file($state . '/starts')) === 1
	&& trim((string)file_get_contents($state . '/binary')) === 'current',
	'a stop after a run of over five minutes clears the count: the next start is the first');
file_put_contents($state . '/starts', (time() - 3000) . "\n" . (time() - 2000) . "\n" . (time() - 100) . "\n");
ui_launch($launch, $root, 'stopped ' . $key, array('SERVICE_RESULT' => 'watchdog'));
ui_launch($launch, $root, 'prepare ' . $key);
check(trim((string)file_get_contents($state . '/binary')) === 'previous',
	'starts spread over an hour with no run that stayed up (a build that hangs) still fall back');
file_put_contents($state . '/binary', "current\n");
file_put_contents($state . '/starts', '');
unlink($state . '/joinery-unseal.previous');
for ($i = 1; $i <= 5; $i++) {
	ui_launch($launch, $root, 'prepare ' . $key);
}
check(trim((string)file_get_contents($state . '/binary')) === 'current', 'with no previous build kept, a crash loop stays on the current one');

// ---------------------------------------------------------------------------
section('The launcher: the record the agent reads, the hold, the status');

ui_launch($launch, $root, 'stopped ' . $key, array('SERVICE_RESULT' => 'signal', 'EXIT_CODE' => 'killed', 'EXIT_STATUS' => 'SEGV'));
ui_launch($launch, $root, 'stopped ' . $key, array('SERVICE_RESULT' => 'success', 'EXIT_CODE' => 'exited', 'EXIT_STATUS' => '0'));
$events = array_map(function ($l) { return json_decode($l, true); }, file($state . '/events', FILE_IGNORE_NEW_LINES));
$kinds = array_column($events, 'event');
check(in_array('moved_to_previous', $kinds, true) && count(array_keys($kinds, 'start')) >= 9,
	'every start and the move to the previous build are recorded');
$last2 = array_slice($events, -2);
check(($last2[0]['event'] ?? '') === 'crash' && ($last2[0]['status'] ?? '') === 'SEGV' && ($last2[1]['event'] ?? '') === 'stop',
	'a stop that was not a success is a crash, with how it ended; a clean stop is a stop', json_encode($last2));
check(count(array_filter($events, function ($e) { return !is_array($e) || !isset($e['time'], $e['event']); })) === 0,
	'every line is a JSON object with a time and an event');

[$rc, $out] = ui_launch($launch, $root, 'hold --all');
check($rc === 0 && file_exists($root . '/run/joinery-unseal/' . $key . '.hold'), 'hold --all writes each site\'s hold file', $out);
[, $st] = ui_launch($launch, $root, 'status ' . $key);
$st = json_decode($st, true);
check(is_array($st) && $st['held'] === true && $st['running'] === false && $st['crashes_last_hour'] >= 1,
	'status says held, not running, and counts the crash', json_encode($st));
[$rc, ] = ui_launch($launch, $root, 'lift ' . $key);
check($rc === 0 && !file_exists($root . '/run/joinery-unseal/' . $key . '.hold'), 'lift removes it');
[$rc, $out] = ui_launch($launch, $root, 'prepare ../etc');
check($rc !== 0, 'a key that is not a site key is refused', $out);

// ---------------------------------------------------------------------------
section('The installer');

$src = (string)file_get_contents($installer);
$runner = (string)file_get_contents($tools . '/_plugin_installers_start.sh');
check((bool)preg_match('/^CORE_INSTALLERS="[^"]*\binstall_unseal_daemon\.sh\b/m', $runner), 'it is a core installer, run at every root moment');
exec('bash ' . escapeshellarg($installer) . ' 2>&1', $o2, $rc2);
check($rc2 === 0 && strpos(implode(' ', $o2), 'not root - skipping') !== false, 'a non-root run changes nothing and exits 0');
foreach (array('Type=notify', 'WatchdogSec=30', 'Restart=always', 'RestartSec=5', 'OOMScoreAdjust=-900', 'LimitMEMLOCK=infinity',
		'LimitCORE=0', 'PrivateNetwork=yes', 'MemoryDenyWriteExecute=yes', 'NoNewPrivileges=yes', 'ProtectSystem=strict',
		'RestrictAddressFamilies=AF_UNIX', 'StartLimitIntervalSec=0', 'ExecStartPre=+', 'ExecStopPost=+') as $directive) {
	check(strpos($src, $directive) !== false, 'the unit carries ' . $directive);
}
check(strpos($src, 'runuser -u "${DAEMON_USER}" -- "${TMP_DEST}" --selftest') !== false && strpos($src, 'joinery-unseal.previous') !== false,
	'a new build is installed only after its selftest, run as the daemon\'s user, and the one it replaces is kept as the previous build');
check(strpos($src, 'DEST="${STATE_DIR}/joinery-unseal"') !== false && strpos($src, 'LAUNCH="${STATE_DIR}/launch"') !== false,
	'each site keeps its own builds and launcher, so sites on different releases share nothing to swap');
check(strpos($src, 'TimeoutStartSec=15') !== false && strpos($src, 'Description=Joinery unseal daemon (${KEY})') !== false,
	'a start that hangs is ended in 15 s, and the unit names the key, never the caller\'s site name');
check(strpos($src, 'reason=memlock') !== false, 'a container that cannot lock memory leaves its reason');
$lsrc = (string)file_get_contents($launch);
check(strpos($lsrc, '--kill-who=main') !== false && strpos($lsrc, '--kill-whom') === false,
	'the hold signals with systemctl kill --kill-who, which systemd 249 knows');
check(strpos($lsrc, '${exe% (deleted)}') !== false, 'a daemon whose build was replaced is still found by its executable');
check(strpos($src, '@reboot root') !== false && strpos($src, 'keepalive') !== false, 'without systemd, a keepalive starts it at boot and every minute');

// ---------------------------------------------------------------------------
section('UnsealDaemon: what the site reads');

foreach (array('joinerytest', 'my site.example', 'a/b', str_repeat('x', 70)) as $name) {
	// The installer's own line, run on the name.
	$line = '';
	foreach (explode("\n", (string)file_get_contents($installer)) as $l) {
		if (strpos($l, 'KEY="$(') === 0) { $line = $l; break; }
	}
	$bash = trim((string)shell_exec('SITE_ROOT=' . escapeshellarg('/var/www/html/' . $name) . '; ' . $line . '; printf %s "$KEY"'));
	check(UnsealDaemon::key('/var/www/html/' . $name) === $bash, 'the site derives the installer\'s key for ' . json_encode($name), $bash);
}

UnsealDaemon::$root_override = $root;
$rec = UnsealDaemon::installRecord($key);
check($rec !== null && $rec['allow_uid'] === posix_geteuid() && $rec['socket'] === $root . '/run/joinery-unseal/' . $key . '/unseal.sock',
	'the install record reads back', json_encode($rec));
check(UnsealDaemon::installRecord('no_such_site') === null, 'no record: not installed');
@mkdir($root . '/etc/joinery-unseal/bad', 0755, true);
file_put_contents($root . '/etc/joinery-unseal/bad/installed', "socket=/tmp/elsewhere.sock\nallow_uid=33\n");
check(UnsealDaemon::installRecord('bad') === null, 'a record naming a socket outside its own directory is refused');

// facts() against the shipped binary, at the path the record names.
$shipped = realpath($tools . '/joinery_unseal/bin/joinery-unseal-' . php_uname('m'));
$sock = $rec['socket'];
if ($shipped && posix_geteuid() !== 0) {
	$bin = $root . '/joinery-unseal';
	copy($shipped, $bin);
	chmod($bin, 0700);
	$GLOBALS['ui_proc'] = proc_open(array($bin, '--socket', $sock, '--allow-uid', (string)posix_geteuid(),
		'--hold-file', $root . '/run/joinery-unseal/' . $key . '.hold', '--unlocked-memory', '--socket-mode', '0666'),
		array(0 => array('file', '/dev/null', 'r'), 1 => array('file', $root . '/d.log', 'a'), 2 => array('file', $root . '/d.log', 'a')), $pipes);
	for ($i = 0; $i < 60 && !file_exists($sock); $i++) {
		usleep(50000);
	}
	check((fileperms($sock) & 0777) === 0666, '--socket-mode 0666 gives the socket that mode (the directory is the gate)');
	$client = new UnsealClient($sock);
	$a = $client->status();
	$b = $client->status(42);
	check(($a['fields']['ok'] ?? false) === true && ($b['fields']['open'] ?? null) === false,
		'UnsealClient asks status twice on one connection', json_encode($a['fields']));
	$own = UnsealDaemon::facts(true, 1.0);
	check($own['installed'] === false, 'facts() reads this site\'s own key, which the stand-in root does not hold');
	// Point the stand-in at this site's key and ask again.
	$mine = UnsealDaemon::key();
	@mkdir($root . '/etc/joinery-unseal/' . $mine, 0755, true);
	file_put_contents($root . '/etc/joinery-unseal/' . $mine . '/installed',
		"socket=/run/joinery-unseal/{$mine}/unseal.sock\nallow_uid=" . posix_geteuid() . "\npool_user=www-data\ninit=cron\n");
	@mkdir($root . '/run/joinery-unseal/' . $mine, 0755, true);
	symlink($sock, $root . '/run/joinery-unseal/' . $mine . '/unseal.sock');
	$f = UnsealDaemon::facts(true, 1.0);
	check($f['asked'] === true && $f['running'] === null, 'this user (the record\'s uid) asks it, and the process table is not scanned');
	UnsealDaemon::$root_override = null; // this daemon was started on the stand-in's own path
	check(UnsealDaemon::running($sock) === true, 'and the process table has it');
	UnsealDaemon::$root_override = $root;
	check($f['installed'] && $f['answering'] === true && $f['memory_locked'] === false && $f['daemon'] !== '',
		'facts() asks the daemon: answering, its version, and that its memory is not locked here', json_encode($f));
	$row = VaultHealth::checkUnsealDaemon($f);
	check($row['state'] === 'unmet' && strpos($row['reason'], 'locked memory') !== false,
		'a daemon run without locked memory is not verified');
	touch($root . '/run/joinery-unseal/' . $mine . '.hold');
	check(UnsealDaemon::facts(true, 1.0)['held'] === true, 'the hold file reads as held');
	unlink($root . '/run/joinery-unseal/' . $mine . '.hold');
	unlink($root . '/run/joinery-unseal/' . $mine . '/unseal.sock');
	$f = UnsealDaemon::facts(true, 0.5);
	check($f['installed'] && $f['asked'] && $f['answering'] === false && $f['error'] === UnsealClient::ERR_CONNECT,
		'installed with nothing at the socket: not answering, connect', json_encode($f));
} else {
	harness_skip('no shipped daemon for this machine, or running as root', (string)$shipped);
}
UnsealDaemon::$root_override = null;

// ---------------------------------------------------------------------------
section('The admin notice');

check(UnsealDaemonNotice::forRow(array('state' => 'verified', 'reason' => 'Version 1.1.0')) === '', 'silent while the row is verified');
$n = UnsealDaemonNotice::forRow(array('state' => 'unmet', 'reason' => 'held <by> a security case'));
check(strpos($n, 'held &lt;by&gt; a security case') !== false && strpos($n, 'role="status"') !== false,
	'otherwise it says the row\'s reason, escaped');

harness_finish();
