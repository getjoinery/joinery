<?php
/** @joinery-test
 * name: vault_health
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * VaultHealth - the host facts that keep an unwrapped vault key off disk.
 *
 * Every check takes its facts through a parameter with a live default, so the
 * branches are driven here with fixtures rather than with whatever this box
 * happens to be (specs/vault_exposure_quick_fixes.md Q2-Q4).
 *
 * @version 1.6 - the unsigned-extensions row (specs/package_signing.md WP6)
 * @version 1.5 - the release-key row counts among the host facts, and is
 *   driven from a fixture key file (specs/package_signing.md WP1)
 * @version 1.4 - the certificate row counts among the host facts
 * @version 1.3 - the host converger check
 * @version 1.2 - the parser jail check
 * @version 1.1 - branch coverage for core_pattern, exception args, and the swap device types
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/VaultHealth.php'));

section('Report shape');
$checks = VaultHealth::runAll();
check(count($checks) === 9, 'runAll reports the nine host facts');
$valid_states = ['verified', 'unmet', 'unknown'];
$all_valid = true;
$has_fields = true;
foreach ($checks as $c) {
	if (!in_array($c['state'] ?? '', $valid_states, true)) { $all_valid = false; }
	if (!isset($c['key'], $c['label'], $c['reason'])) { $has_fields = false; }
}
check($all_valid, 'every check reports verified, unmet, or unknown - never a bare pass');
check($has_fields, 'every check carries key, label, and reason');

section('Never a false pass');
// An unverifiable fact must be unknown, not verified: every unmet/unknown
// check must explain itself.
$silent = 0;
foreach ($checks as $c) {
	if ($c['state'] !== 'verified' && trim((string)$c['reason']) === '') { $silent++; }
}
check($silent === 0, 'no unmet/unknown check is silent about why');

// ---------------------------------------------------------------------------
section('Core dumps: the rlimit is the whole story only for a file pattern');

$cores = function ($rlimit, $pattern, $apport = null) {
	return VaultHealth::checkCoredumpsDisabled(['rlimit' => $rlimit, 'core_pattern' => $pattern, 'apport_enabled' => $apport]);
};
$apport_pipe = '|/usr/share/apport/apport -p%p -s%s -c%c -d%d -P%P -u%u -g%g -F%F -- %E';

check($cores('0', 'core')['state'] === 'verified', 'a file pattern with rlimit 0 is verified');
check($cores('unlimited', 'core')['state'] === 'unmet', 'a file pattern with an unlimited rlimit is unmet');
check($cores(null, 'core')['state'] === 'unknown', 'an unreadable rlimit is unknown, not a pass');

$r = $cores('0', $apport_pipe, true);
check($r['state'] === 'unmet', 'apport enabled is unmet even with rlimit 0 - the kernel ignores the rlimit for a pipe', $r['reason']);
check(strpos($r['reason'], 'systemctl disable --now apport') !== false, 'and the remediation names the command');
check($cores('0', $apport_pipe, false)['state'] === 'verified', 'apport disabled falls through to the rlimit, which passes');
check($cores('unlimited', $apport_pipe, false)['state'] === 'unmet', 'apport disabled with an unlimited rlimit is still unmet');
check($cores('0', $apport_pipe, null)['state'] === 'unknown', 'apport whose state cannot be read is unknown');

check($cores('0', '|/usr/lib/systemd/systemd-coredump %P %u %g %s %t %c %h')['state'] === 'verified',
	'systemd-coredump honours the rlimit, so rlimit 0 is verified');
check($cores('unlimited', '|/usr/lib/systemd/systemd-coredump %P')['state'] === 'unmet',
	'and an unlimited rlimit under systemd-coredump is unmet');

$r = $cores('0', '|/opt/custom/handler %p');
check($r['state'] === 'unknown', 'an unfamiliar pipe handler is unknown', $r['reason']);
check(strpos($r['reason'], '/opt/custom/handler') !== false, 'and is named in the reason');

$live = VaultHealth::coredumpFacts();
check(array_key_exists('rlimit', $live) && array_key_exists('core_pattern', $live) && array_key_exists('apport_enabled', $live),
	'the live fact reader returns the three facts the check decides on');

// ---------------------------------------------------------------------------
section('Exception arguments stay out of the log');

$was = ini_get('zend.exception_ignore_args');
ini_set('zend.exception_ignore_args', '1');
check(VaultHealth::checkExceptionArgs()['state'] === 'verified', 'ignore_args on is verified');
ini_set('zend.exception_ignore_args', '0');
$r = VaultHealth::checkExceptionArgs();
check($r['state'] === 'unmet', 'ignore_args off is unmet');
check(strpos($r['reason'], 'zend.exception_ignore_args = On') !== false, 'and the remediation is the ini line');
ini_set('zend.exception_ignore_args', $was);

// ---------------------------------------------------------------------------
section('Parser jail: installed or named');

$r = VaultHealth::checkParserJail(true);
check($r['state'] === 'verified', 'an installed launcher is verified');
$r = VaultHealth::checkParserJail(false);
check($r['state'] === 'unmet', 'a missing launcher is unmet', $r['reason']);
check(strpos($r['reason'], 'install_parser_jail.sh') !== false, 'and the remediation names the installer');
check(strpos($r['reason'], DocumentText::JAIL_LAUNCHER) !== false, 'and the path the launcher is missing from');
$live = VaultHealth::checkParserJail();
check($live['state'] === (DocumentText::jailAvailable() ? 'verified' : 'unmet'),
	'the live check agrees with DocumentText about this box', $live['state']);

// ---------------------------------------------------------------------------
section('Host converger: installed boxes must have run within a day');

$now = 1800000000;
$hc = function ($installed, $last, $outcome = 'converged') use ($now) {
	return VaultHealth::checkHostConverger(['installed' => $installed, 'last_run' => $last, 'outcome' => $outcome], $now);
};
check($hc(false, null)['state'] === 'unknown', 'no converger installed is unknown, not a failure (containers and managed nodes converge otherwise)');
check(strpos($hc(false, null)['reason'], 'install_host_converger.sh') !== false, 'and names the installer for a box that needs one');
check($hc(true, $now - 300)['state'] === 'verified', 'a run five minutes ago is verified');
check($hc(true, null)['state'] === 'unmet', 'installed and never run is unmet');
$r = $hc(true, $now - 2 * 86400);
check($r['state'] === 'unmet', 'a run two days ago is unmet', $r['reason']);
check(strpos($r['reason'], 'install_host_converger.sh') !== false, 'and the remediation is the installer');
check($hc(true, $now - 300, 'installer-failed')['state'] === 'unmet', 'a fresh run that reported a failed installer is unmet');
check(HostConvergerNotice::forState(true, $now - 300, 'converged', $now, 'cmd') === '', 'the notice is silent while the converger runs');
check(HostConvergerNotice::forState(false, null, '', $now, 'cmd') === '', 'and silent on a box with no converger');
check(strpos(HostConvergerNotice::forState(true, $now - 2 * 86400, 'converged', $now, 'sudo bash x'), 'sudo bash x') !== false, 'a stale converger shows the command');
check(strpos(HostConvergerNotice::forState(true, null, '', $now, 'cmd'), 'never run') !== false, 'a converger that never ran says so');
$facts = HostConvergerNotice::facts();
check(array_key_exists('installed', $facts) && array_key_exists('last_run', $facts) && array_key_exists('outcome', $facts),
	'the live fact reader returns the three facts the check decides on');

// The converger also carries out root requests (specs/read_only_tree.md), so a
// queue that is not moving is the same machine failing in a way the timer's own
// heartbeat cannot show: it ticks, and every request it picks up fails.
$hcq = function ($outcome, $oldest) use ($now) {
	return VaultHealth::checkHostConverger(
		['installed' => true, 'last_run' => $now - 300, 'outcome' => $outcome,
		 'oldest_request_age' => $oldest], $now);
};
check($hcq('converged', null)['state'] === 'verified', 'a fresh converger with an empty queue is verified');
check($hcq('converged', 600)['state'] === 'verified', 'and one with a request queued ten minutes ago is still verified');
$stalled = $hcq('converged', 2 * 86400);
check($stalled['state'] === 'unmet', 'a request queued two days ago is unmet even though the timer is running');
check(strpos($stalled['reason'], 'logs/root_requests/') !== false,
	'and the reason says where the transcripts are', $stalled['reason']);
check($hcq('installer-refused', null)['state'] === 'unmet',
	'an installer the runner refused to attribute is unmet');
check(strpos($hcq('installer-refused', null)['reason'], 'attribute') !== false,
	'and says what refusing meant');

check(array_key_exists('pending_requests', $facts) && array_key_exists('oldest_request_age', $facts),
	'the live fact reader also reports the request queue');
check(HostConvergerNotice::forState(true, $now - 300, 'converged', $now, 'cmd', 0, null) === '',
	'the notice stays silent when the queue is empty');
check(HostConvergerNotice::forState(true, $now - 300, 'converged', $now, 'cmd', 1, 600) === '',
	'and while a request is merely recent');
$queue_notice = HostConvergerNotice::forState(true, $now - 300, 'converged', $now, 'cmd', 3, 4 * 3600);
check(strpos($queue_notice, '3 root requests') !== false,
	'a stalled queue is named on the page, with its depth', $queue_notice);
check(strpos($queue_notice, '4 hours') !== false, 'and how long the oldest has waited');
check(strpos(HostConvergerNotice::forState(true, $now - 300, 'installer-refused', $now, 'cmd'), 'attribute') !== false,
	'a refused installer is reported on the page too');

// ---------------------------------------------------------------------------
section('Swap: the device type decides, read from sysfs');

$fx = sys_get_temp_dir() . '/vault_health_' . bin2hex(random_bytes(4));
mkdir($fx . '/dev/mapper', 0700, true);
mkdir($fx . '/sys/block/dm-0/dm', 0700, true);
mkdir($fx . '/sys/block/dm-1/dm', 0700, true);
mkdir($fx . '/sys/block/dm-2/dm', 0700, true);
touch($fx . '/dev/dm-0');
touch($fx . '/dev/dm-1');
touch($fx . '/dev/dm-2');
touch($fx . '/dev/zram0');
touch($fx . '/dev/sdb');
symlink('../dm-0', $fx . '/dev/mapper/cryptswap');
symlink('../dm-1', $fx . '/dev/mapper/vg0-swap');
symlink('../dm-2', $fx . '/dev/mapper/mystery');
file_put_contents($fx . '/sys/block/dm-0/dm/uuid', "CRYPT-PLAIN-cryptswap\n");
file_put_contents($fx . '/sys/block/dm-1/dm/uuid', "LVM-abc123def456-swap\n");
// dm-2 has no uuid file: a mapper device whose type cannot be read.

$swaps = function (array $devices) use ($fx) {
	$path = $fx . '/swaps_' . bin2hex(random_bytes(3));
	$lines = "Filename\t\t\t\tType\t\tSize\t\tUsed\t\tPriority\n";
	foreach ($devices as $d) { $lines .= $d . "                             partition\t2097148\t\t0\t\t-2\n"; }
	file_put_contents($path, $lines);
	return VaultHealth::checkSwapSafe($path, $fx . '/sys/block');
};

check($swaps([])['state'] === 'verified', 'no swap is verified');
$r = $swaps([$fx . '/dev/mapper/cryptswap']);
check($r['state'] === 'verified', 'a dm-crypt mapping is verified - not merely encrypted-looking', $r['reason']);
$r = $swaps([$fx . '/dev/dm-0']);
check($r['state'] === 'verified', 'the same device named as /dev/dm-N is verified too', $r['reason']);
$r = $swaps([$fx . '/dev/mapper/vg0-swap']);
check($r['state'] === 'unmet', 'an LVM volume under /dev/mapper is unmet - a mapper path is not encryption', $r['reason']);
check(strpos($r['reason'], 'LVM') !== false, 'and the reason names the type');
$r = $swaps([$fx . '/dev/mapper/mystery']);
check($r['state'] === 'unknown', 'a mapper device with no readable type is unknown', $r['reason']);
$r = $swaps([$fx . '/dev/zram0']);
check($r['state'] === 'verified', 'zram is verified - compressed RAM, no disk without writeback', $r['reason']);
$r = $swaps([$fx . '/dev/sdb']);
check($r['state'] === 'unmet', 'a plain partition is unmet', $r['reason']);
$r = $swaps([$fx . '/dev/mapper/cryptswap', $fx . '/dev/sdb']);
check($r['state'] === 'unmet', 'one plain device among encrypted ones fails the whole check');
check(VaultHealth::checkSwapSafe($fx . '/nope', $fx . '/sys/block')['state'] === 'unknown', 'an unreadable /proc/swaps is unknown');

foreach (glob($fx . '/dev/mapper/*') as $f) { unlink($f); }
foreach (glob($fx . '/dev/*') as $f) { if (is_file($f)) unlink($f); }
foreach (glob($fx . '/swaps_*') as $f) { unlink($f); }
foreach (['dm-0', 'dm-1', 'dm-2'] as $d) { @unlink($fx . "/sys/block/$d/dm/uuid"); rmdir($fx . "/sys/block/$d/dm"); rmdir($fx . "/sys/block/$d"); }
rmdir($fx . '/sys/block'); rmdir($fx . '/sys'); rmdir($fx . '/dev/mapper'); rmdir($fx . '/dev'); rmdir($fx);

section('Release verification key (specs/package_signing.md WP1)');
// Root installs nothing it cannot verify, and it verifies against this file;
// with no usable key every install is refused, which is safe and useless, so
// the row says so and names the converger that writes it.
$kf = harness_scratch_dir('vault_health') . '/release_verify_keys';
@unlink($kf);
$r = VaultHealth::checkReleaseVerifyKeys($kf);
check($r['state'] === 'unmet', 'no key file is unmet', $r['reason']);
check(strpos($r['reason'], 'install_host_converger.sh') !== false, 'and the reason names the converger that writes it');
file_put_contents($kf, "# no keys\n");
check(VaultHealth::checkReleaseVerifyKeys($kf)['state'] === 'unmet', 'a key file with no usable key is unmet');
file_put_contents($kf, base64_encode(str_repeat("\x01", SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)) . "\n");
check(VaultHealth::checkReleaseVerifyKeys($kf)['state'] === 'verified', 'one well-formed key is verified');
@unlink($kf);

section('Unsigned plugins and themes present (specs/package_signing.md WP6)');
// Advice, never a gate: the row names what the owner acknowledged and
// nothing else happens.
check(VaultHealth::checkUnsignedExtensions(['plugins' => [], 'themes' => []])['state'] === 'verified',
	'none present is verified');
$r = VaultHealth::checkUnsignedExtensions(['plugins' => ['stranger'], 'themes' => ['odd_theme', 'other']]);
check($r['state'] === 'unmet', 'any present is unmet', $r['reason']);
check(strpos($r['reason'], '1 unsigned plugin (stranger)') !== false
	&& strpos($r['reason'], '2 unsigned themes (odd_theme, other)') !== false,
	'and the reason names each one');
check(strpos($r['reason'], 'all mail') !== false, 'and repeats what the owner gave away');
check(strpos($r['reason'], 'never run as root') !== false, 'and says their host installers never run');

harness_finish();
?>
