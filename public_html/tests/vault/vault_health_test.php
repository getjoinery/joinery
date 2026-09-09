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
 * @version 1.1 - branch coverage for core_pattern, exception args, and the swap device types
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/VaultHealth.php'));

section('Report shape');
$checks = VaultHealth::runAll();
check(count($checks) === 4, 'runAll reports the four host facts');
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

harness_finish();
?>
