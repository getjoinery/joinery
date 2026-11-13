<?php
/** @joinery-test
 * name: vault_host_health
 * tier: safe
 * env: any
 * needs: []
 * covers: [includes/VaultHostHealth.php, includes/VaultHostNotice.php, includes/management_api/stats_handler.php]
 */
/**
 * The host checks that keep an open vault window's key off the disk reach a
 * person (spec security_weak_spots_2026_11 WP3): an admin notice on the site,
 * and counts with check names, never reasons, for the management node.
 *
 * Run: php tests/run.php --only=tests/vault/vault_host_health_test.php
 *
 * @version 1.1
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$rows = array(
	array('key' => 'coredumps_disabled', 'label' => 'PHP worker core dumps are disabled', 'state' => 'verified', 'reason' => ''),
	array('key' => 'swap_off_or_encrypted', 'label' => 'Swap is off, or every active swap device is encrypted', 'state' => 'unmet',
		'reason' => 'Active swap device "/swap.img" is a plain device <b>here</b>.'),
	array('key' => 'ram_store', 'label' => 'The RAM store is on tmpfs', 'state' => 'unknown', 'reason' => 'Could not read it.'),
);

// ---------------------------------------------------------------------------
section('The summary');
// ---------------------------------------------------------------------------
$sum = VaultHostHealth::summarize($rows);
check($sum['checked'] === 3, 'counts every row checked');
check(count($sum['unmet']) === 1 && $sum['unmet'][0]['key'] === 'swap_off_or_encrypted', 'names the unmet one');
check($sum['unknown'] === array('ram_store'), 'and keeps one that could not be read apart from unmet');

$live = VaultHostHealth::rows();
check(array_column($live, 'key') === array('apcu_anonymous_shm', 'ram_store', 'coredumps_disabled',
	'exception_args_ignored', 'swap_off_or_encrypted'), 'the live checks are the five disk-exposure rows');
check(VaultHostHealth::summary() === VaultHostHealth::summarize(VaultHostHealth::rows()),
	'from the shell, the summary is a live run of them');

// ---------------------------------------------------------------------------
section('What goes to the management node');
// ---------------------------------------------------------------------------
$stats = VaultHostHealth::forStats($sum);
check($stats === array('checked' => 3, 'unmet' => 1, 'unmet_checks' => array('swap_off_or_encrypted'),
	'unknown' => 1, 'unknown_checks' => array('ram_store')),
	'counts and check names, the unread ones named too', json_encode($stats));
$wire = json_encode($stats);
check(strpos($wire, 'swap.img') === false && strpos($wire, 'plain device') === false && strpos($wire, '/') === false,
	'and never a reason, which can name a path or device');

check(VaultHostHealth::fromWebProcess() === false, 'a shell is not the web process, so stats send no vault_host from here');

// ---------------------------------------------------------------------------
section('Swap seen from inside a container');
// ---------------------------------------------------------------------------
// A container's /proc/swaps lists the host's devices, which its /dev lacks.
$fx = sys_get_temp_dir() . '/vhh-' . bin2hex(random_bytes(4));
mkdir($fx . '/block/dm-3/dm', 0755, true);
file_put_contents($fx . '/block/dm-3/dm/name', "cryptswap\n");
file_put_contents($fx . '/block/dm-3/dm/uuid', "CRYPT-PLAIN-cryptswap\n");
$swaps = function (string $device) use ($fx) {
	file_put_contents($fx . '/swaps', "Filename Type Size Used Priority\n" . $device . " partition 1048572 0 -2\n");
	return VaultHealth::checkSwapSafe($fx . '/swaps', $fx . '/block');
};
check($swaps('/dev/mapper/cryptswap')['state'] === 'verified',
	'a host dm-crypt swap the container cannot open is found by its device-mapper name');
$r = $swaps('/dev/mapper/notthere');
check($r['state'] === 'unknown', 'a host device not found in sysfs is unknown, not plain', $r['reason']);
check($swaps('/swap.img')['state'] === 'unmet', 'a swap file is still unmet: its pages reach disk in the clear');
array_map('unlink', array($fx . '/block/dm-3/dm/name', $fx . '/block/dm-3/dm/uuid', $fx . '/swaps'));
rmdir($fx . '/block/dm-3/dm'); rmdir($fx . '/block/dm-3'); rmdir($fx . '/block'); rmdir($fx);

// ---------------------------------------------------------------------------
section('The admin notice');
// ---------------------------------------------------------------------------
check(VaultHostNotice::forSummary(VaultHostHealth::summarize(array($rows[0], $rows[2]))) === '',
	'silent when nothing is unmet, an unreadable check included');
$html = VaultHostNotice::forSummary($sum);
check(strpos($html, 'Swap is off, or every active swap device is encrypted') !== false
	&& strpos($html, '/swap.img') !== false, 'names the check with its reason');
check(strpos($html, '<b>here</b>') === false && strpos($html, '&lt;b&gt;here') !== false, 'and escapes the reason');
check(strpos($html, 'RAM store') === false, 'an unreadable check is not shown as a problem');

$notices = new ReflectionMethod('AdminNotices', 'coreRenderers');
check(($notices->invoke(null)['vault_host'] ?? null) === array('VaultHostNotice', 'render'),
	'the notice is one of the core admin notices');

harness_finish();
