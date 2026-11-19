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
// A run whose stop hook never ran (systemctl kill reaches the whole unit,
// the hook with it; a power cut) is recorded as a crash at the next start.
ui_launch($launch, $root, 'prepare ' . $key);
$before = count(file($state . '/events'));
ui_launch($launch, $root, 'prepare ' . $key);
$events = array_map(function ($l) { return json_decode($l, true); }, file($state . '/events', FILE_IGNORE_NEW_LINES));
$tail = array_slice($events, $before - count($events));
check(count($tail) === 2 && ($tail[0]['event'] ?? '') === 'crash' && ($tail[0]['result'] ?? '') === 'unrecorded'
	&& ($tail[1]['event'] ?? '') === 'start',
	'a start after a run that left no stop or crash records that crash first', json_encode($tail));
ui_launch($launch, $root, 'stopped ' . $key, array('SERVICE_RESULT' => 'success'));
$before = count(file($state . '/events'));
ui_launch($launch, $root, 'prepare ' . $key);
$events = array_map(function ($l) { return json_decode($l, true); }, file($state . '/events', FILE_IGNORE_NEW_LINES));
check(count($events) === $before + 1 && end($events)['event'] === 'start',
	'a start after a recorded stop adds only the start', json_encode(array_slice($events, -2)));

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
section('The keepalive: a disk swap is a refusal, never a failed start');

$shipped = $tools . '/joinery_unseal/bin/joinery-unseal-' . php_uname('m');
if (!is_file($shipped)) {
	check(true, 'no prebuilt for ' . php_uname('m') . ': the swap gate is not exercised here');
} else {
	$k2 = 'site_two';
	$s2 = $root . '/var/lib/joinery-unseal/' . $k2;
	mkdir($root . '/etc/joinery-unseal/' . $k2, 0755, true);
	mkdir($s2, 0755, true);
	file_put_contents($root . '/etc/joinery-unseal/' . $k2 . '/installed',
		"socket=/run/joinery-unseal/{$k2}/unseal.sock\nallow_uid=" . posix_geteuid() . "\npool_user=www-data\ninit=cron\n");
	copy($shipped, $s2 . '/joinery-unseal');
	chmod($s2 . '/joinery-unseal', 0755);
	touch($s2 . '/joinery-unseal.previous');
	chmod($s2 . '/joinery-unseal.previous', 0755);
	touch($s2 . '/running'); // it was serving
	$swaps = $root . '/swaps';
	$block = $root . '/block';
	mkdir($block . '/zram0', 0755, true);
	file_put_contents($block . '/zram0/backing_dev', "none\n");
	$hdr = "Filename\t\t\t\tType\t\tSize\t\tUsed\t\tPriority\n";
	file_put_contents($swaps, $hdr . "/dev/zram0  partition\t2004476\t0\t100\n/swapfile  file\t1048572\t0\t-2\n");
	$env = array('JOINERY_UNSEAL_TEST_BOUNDED' => '1', 'JOINERY_UNSEAL_TEST_PROC_SWAPS' => $swaps,
		'JOINERY_UNSEAL_TEST_SYS_BLOCK' => $block, 'JOINERY_UNSEAL_TEST_NO_START' => '1');
	$ev = function () use ($s2) {
		return array_map(function ($l) { return json_decode($l, true)['event'] ?? ''; }, @file($s2 . '/events', FILE_IGNORE_NEW_LINES) ?: array());
	};
	for ($i = 0; $i < 5; $i++) {
		[$rc, $out] = ui_launch($launch, $root, 'keepalive ' . $k2, $env);
	}
	$events = $ev();
	check($rc === 0 && file_exists($s2 . '/swap_refused') && strpos((string)file_get_contents($s2 . '/swap_refused'), '/swapfile') !== false,
		'five keepalives on a disk swap leave swap_refused naming the device', $out);
	check($events === array('swap_exit', 'swap_refused'),
		'the serving daemon its guard ended is recorded swap_exit, then one swap_refused, and no crash or start, however many keepalives',
		json_encode($events));
	check(trim((string)@file_get_contents($s2 . '/starts')) === '' && trim((string)@file_get_contents($s2 . '/binary') ?: 'current') === 'current',
		'no start is counted, so the site never moves to its previous build');
	check(!file_exists($s2 . '/running'), 'and it is no longer marked running, so its stop is not read as found dead');
	[, $st] = ui_launch($launch, $root, 'status ' . $k2, $env);
	$st = json_decode($st, true);
	check(($st['swap_refused'] ?? null) === true && ($st['binary'] ?? '') === 'current', 'status says swap_refused, on the current build', json_encode($st));

	file_put_contents($swaps, $hdr . "/dev/zram0  partition\t2004476\t0\t100\n");
	ui_launch($launch, $root, 'keepalive ' . $k2, $env);
	$events = $ev();
	check(!file_exists($s2 . '/swap_refused') && end($events) === 'swap_cleared'
		&& !in_array('crash', $events, true) && trim((string)@file_get_contents($s2 . '/starts')) === '',
		'RAM-only swap again: swap_cleared, the start goes ahead on the current build, nothing counted against it', json_encode($events));

	// A container rebuilt with a memory budget over the kept state directory: it locks now.
	file_put_contents($swaps, $hdr . "/swapfile  file\t1048572\t0\t-2\n");
	ui_launch($launch, $root, 'keepalive ' . $k2, $env);
	$lockable = $env;
	unset($lockable['JOINERY_UNSEAL_TEST_BOUNDED']);
	$lockable['JOINERY_UNSEAL_TEST_LOCKABLE'] = '1';
	ui_launch($launch, $root, 'keepalive ' . $k2, $lockable);
	$events = $ev();
	check(!file_exists($s2 . '/swap_refused') && end($events) === 'swap_cleared',
		'where the lock limit can be raised, an old swap refusal is cleared (the daemon locks; swap no longer matters)', json_encode(array_slice($events, -3)));
	file_put_contents($swaps, $hdr . "/dev/zram0  partition\t2004476\t0\t100\n");

	// The guard ended a running daemon, and swap was RAM-only again by the next keepalive.
	touch($s2 . '/running');
	file_put_contents($root . '/joinery-unseal-' . $k2 . '.log', "joinery-unseal: swap can reach a disk now (swap /swapfile is a file, not a block device): dropped 2 windows, exiting\n");
	ui_launch($launch, $root, 'keepalive ' . $k2, $env);
	$events = $ev();
	check(end($events) === 'swap_exit' && !in_array('crash', $events, true), 'a daemon its swap guard ended is recorded as swap_exit, not a crash', json_encode($events));
	file_put_contents($root . '/joinery-unseal-' . $k2 . '.log', "joinery-unseal: something else\n");
	touch($s2 . '/running');
	ui_launch($launch, $root, 'keepalive ' . $k2, $env);
	$events = $ev();
	check(end($events) === 'crash', 'any other death found is still a crash', json_encode($events));
}

section('The launcher: a running daemon whose /proc/PID/exe does not resolve (a site container)');

// Root in a site container holds no CAP_SYS_PTRACE, so the daemon's exe link
// does not resolve for it. The launcher must still find the daemon by its
// command line: a keepalive that missed it started a second one every 5 s and
// counted each as a crash, and the sentinel's hold never reached it.
$python = trim((string)shell_exec('command -v python3 2>/dev/null'));
if ($python === '') {
	check(true, 'no python3 here: the stand-in that hides its exe is not exercised');
} else {
	$k3 = 'site_three';
	$s3 = $root . '/var/lib/joinery-unseal/' . $k3;
	mkdir($root . '/etc/joinery-unseal/' . $k3, 0755, true);
	mkdir($s3, 0755, true);
	$sock3 = '/run/joinery-unseal/' . $k3 . '/unseal.sock';
	file_put_contents($root . '/etc/joinery-unseal/' . $k3 . '/installed',
		"socket={$sock3}\nallow_uid=" . posix_geteuid() . "\npool_user=www-data\ninit=cron\n");
	$me = (string)(posix_getpwuid(posix_geteuid())['name'] ?? '');
	$sig = $root . '/site_three_signals';
	$fixture = PathHelper::getIncludePath('tests/fixtures/unseal/nondumpable_standin.py');
	$start = function (string $argv0) use ($python, $fixture, $sig, $sock3) {
		$p = proc_open(array('bash', '-c', 'exec -a "$0" "$1" "$2" "$3" --socket "$4"', $argv0, $python, $fixture, $sig, $sock3),
			array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
		usleep(600000);
		return $p;
	};
	$env3 = array('JOINERY_UNSEAL_TEST_DAEMON_USER' => $me);

	$p = $start($s3 . '/active');
	$pid = (int)proc_get_status($p)['pid'];
	check(@readlink('/proc/' . $pid . '/exe') === false, 'the stand-in hides its exe, as a container daemon does from root', (string)$pid);
	@unlink($sig);
	ui_launch($launch, $root, 'hold ' . $k3, $env3);
	ui_launch($launch, $root, 'lift ' . $k3, $env3);
	usleep(300000);
	check(trim((string)@file_get_contents($sig)) === "USR1\nUSR2", 'the hold and the lift reach it, found by its command line', (string)@file_get_contents($sig));
	proc_terminate($p, 9);
	proc_close($p);

	$p = $start($root . '/somewhere_else/active');
	@unlink($sig);
	ui_launch($launch, $root, 'hold ' . $k3, $env3);
	usleep(300000);
	check(!file_exists($sig), 'a process naming another program is never taken for the daemon', (string)@file_get_contents($sig));
	proc_terminate($p, 9);
	proc_close($p);
	@unlink($root . '/run/joinery-unseal/' . $k3 . '.hold');
}

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
check(strpos($src, 'refuse disk_swap') !== false && strpos($src, 'refuse check_failed') !== false && strpos($src, 'swapcheck') !== false
	&& strpos($src, 'trap \'rm -f "${CHECK_BIN}"\' EXIT') !== false,
	'a container that cannot lock memory asks the shipped build whether swap reaches a disk: exit 3 is disk_swap, any other failure check_failed, and the copy it asks is removed on every exit');
check(preg_match('/prlimit "\$\{memlock\}" true 2>\/dev\/null \|\| memlock=/', (string)file_get_contents($launch)) === 1,
	'the launcher raises the memory-lock limit only where it can, so a container\'s daemon still starts');
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
	// The live daemon reports whether this machine's swap stays in RAM, which
	// differs by box (localdev has a disk swap file, dev only zram). Each
	// branch is checked with the fact stated, whatever this machine has.
	$row = VaultHealth::checkUnsealDaemon(array_merge($f, array('swap_ram_only' => false)));
	check($row['state'] === 'unmet' && strpos($row['reason'], 'locked memory') !== false,
		'a daemon run without locked memory, where swap can reach a disk, is not verified', json_encode($row));
	$row = VaultHealth::checkUnsealDaemon(array_merge($f, array('swap_ram_only' => true)));
	check($row['state'] === 'verified' && strpos($row['reason'], 'swap never reaches a disk') !== false,
		'and one run unlocked because swap stays in RAM is verified, saying why', json_encode($row));
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
