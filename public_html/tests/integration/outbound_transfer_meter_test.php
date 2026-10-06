<?php
/** @joinery-test
 * name: outbound_transfer_meter
 * tier: test-db
 * env: dev-only
 * needs: []
 */
/**
 * The site's own count of what its server sends (OutboundTransferMeter;
 * specs/node_outbound_and_transfer.md WP1).
 *
 *  - counters() reads only the interfaces carrying a default route, IPv4 and
 *    IPv6, from a fake /proc: not loopback, not a Docker bridge;
 *  - tick(): the first reading is a baseline; a reading adds what each
 *    interface sent since; a counter that went down, or a new boot or
 *    network namespace, counts what is on the counter as new; the month's
 *    turn keeps last month and starts again from the 1st;
 *  - past the owner's figure the notice shows and one email goes that
 *    month, to the superadmins; 0 turns it off; a site the operator hosts
 *    stays silent.
 *
 * Run: php tests/integration/outbound_transfer_meter_test.php
 *
 * @version 1.1 - an interface new or back under the same boot adds nothing; an empty reading changes nothing;
 *                a gap since early last month is not a turn (review R1, R6)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$root = harness_scratch_dir('outbound_meter');
$write = function (string $path, string $text) use ($root) {
	@mkdir(dirname($root . $path), 0700, true);
	file_put_contents($root . $path, $text);
};
$write('/proc/net/route', "Iface\tDestination\tGateway \tFlags\tRefCnt\tUse\tMetric\tMask\t\tMTU\tWindow\tIRTT\n"
	. "eth0\t00000000\t0102A8C0\t0003\t0\t0\t0\t00000000\t0\t0\t0\n"
	. "eth0\t0002A8C0\t00000000\t0001\t0\t0\t0\t00FFFFFF\t0\t0\t0\n"
	. "docker0\t000011AC\t00000000\t0001\t0\t0\t0\t0000FFFF\t0\t0\t0\n");
$write('/proc/net/ipv6_route', str_repeat('0', 32) . " 00 " . str_repeat('0', 32) . " 00 fe800000000000000000000000000001 00000400 00000001 00000000 00000003 eth1\n"
	. str_repeat('0', 32) . " 00 " . str_repeat('0', 32) . " 00 " . str_repeat('0', 32) . " ffffffff 00000001 00000000 00200200 lo\n");
$dev = function (int $eth0, int $eth1) {
	return "Inter-|   Receive                                                |  Transmit\n"
		. " face |bytes    packets errs drop fifo frame compressed multicast|bytes    packets errs drop fifo colls carrier compressed\n"
		. "    lo: 5000 10 0 0 0 0 0 0 999999999 10 0 0 0 0 0 0\n"
		. "  eth0: 100 1 0 0 0 0 0 0 {$eth0} 1 0 0 0 0 0 0\n"
		. "  eth1: 100 1 0 0 0 0 0 0 {$eth1} 1 0 0 0 0 0 0\n"
		. "docker0: 100 1 0 0 0 0 0 0 777777777 1 0 0 0 0 0 0\n";
};
$write('/proc/net/dev', $dev(1000, 2000));
$write('/proc/sys/kernel/random/boot_id', "boot-a\n");

$sent = array();
OutboundTransferMeter::$dispatch = function ($signal, $payload) use (&$sent) { $sent[] = array($signal, $payload); };
Setting::put(OutboundTransferMeter::STATE_SETTING, '');
harness_set_setting_mem(OutboundTransferMeter::NOTICE_SETTING, '100');
harness_set_setting_mem('hosted_plan_state', 'services');

// ---------------------------------------------------------------------------
section('The counters: default-route interfaces only');

$c = OutboundTransferMeter::counters($root);
check($c['ifaces'] === array('eth0' => 1000, 'eth1' => 2000), 'eth0 (IPv4 default) and eth1 (IPv6 default); not lo, not docker0');
check(strpos($c['identity'], 'boot-a') === 0, 'the identity carries the boot');

$counters = function (int $eth0, int $eth1, string $identity = 'boot-a|net:[1]') {
	return array('identity' => $identity, 'ifaces' => array('eth0' => $eth0, 'eth1' => $eth1));
};
$t = strtotime('2026-10-06 12:00:00 UTC');
$GB = 1000000000;

// ---------------------------------------------------------------------------
section('Counting');

$s = OutboundTransferMeter::tick($t, $counters(5 * $GB, 1 * $GB));
check($s['sent_bytes'] === 0 && $s['since'] === $t, 'the first reading is a baseline, counting from now');
$s = OutboundTransferMeter::tick($t + 300, $counters(6 * $GB, 2 * $GB));
check($s['sent_bytes'] === 2 * $GB, 'a reading adds what each interface sent since (1 GB + 1 GB)');
$s = OutboundTransferMeter::tick($t + 600, $counters(1 * $GB, 3 * $GB));
check($s['sent_bytes'] === 4 * $GB, 'a counter that went down started again: what is on it is new (1 GB + 1 GB)');
$s = OutboundTransferMeter::tick($t + 900, $counters(7 * $GB, 4 * $GB, 'boot-b|net:[2]'));
check($s['sent_bytes'] === 15 * $GB, 'a new boot or container: everything on the counters is new (7 GB + 4 GB)');
check(empty($sent), 'under the figure (100 GB) nothing is sent');
$state = OutboundTransferMeter::state();
check(OutboundTransferMeter::month($state, $t + 900)['sent_bytes'] === 15 * $GB, 'the month reads back from the stored state');
$b = 'boot-b|net:[2]';
$s = OutboundTransferMeter::tick($t + 901, array('identity' => $b, 'ifaces' => array('eth0' => 7 * $GB)));
$s = OutboundTransferMeter::tick($t + 902, array('identity' => $b, 'ifaces' => array('eth0' => 7 * $GB, 'eth1' => 4 * $GB, 'wg0' => 500 * $GB)));
check($s['sent_bytes'] === 15 * $GB, 'an interface that dropped out of the default route and came back, and one new under the same boot '
	. '(500 GB since boot), add nothing: a flap is not traffic');
$s = OutboundTransferMeter::tick($t + 903, array('identity' => $b, 'ifaces' => array()));
check($s['sent_bytes'] === 15 * $GB && $s['ifaces']['eth0'] === 7 * $GB && $s['identity'] === $b,
	'a reading that found no interface changes nothing');

// ---------------------------------------------------------------------------
section('Past the owner\'s figure');

harness_set_setting_mem(OutboundTransferMeter::NOTICE_SETTING, '10');
check(OutboundTransferMeter::over(null, $t + 900), '15 GB is past a 10 GB figure');
$s = OutboundTransferMeter::tick($t + 1200, $counters(8 * $GB, 4 * $GB, 'boot-b|net:[2]'));
check(count($sent) === 1 && $sent[0][0] === OutboundTransferMeter::SIGNAL, 'one email signal goes');
check(is_array($sent[0][1]['recipients']), 'it is addressed to the superadmins by id');
check(strpos($sent[0][1]['detail'], 'This server has sent 16.0 GB since October 6') === 0, 'in plain words: ' . $sent[0][1]['detail']);
OutboundTransferMeter::tick($t + 1500, $counters(9 * $GB, 4 * $GB, 'boot-b|net:[2]'));
check(count($sent) === 1, 'and only once that month');
$html = OutboundTransferNotice::html(OutboundTransferMeter::month(null, $t + 1500));
check(strpos($html, 'This server has sent 17.0 GB') !== false && strpos($html, 'Change the figure') !== false, 'the header notice says the same');
harness_set_setting_mem(OutboundTransferMeter::NOTICE_SETTING, '0');
check(!OutboundTransferMeter::over(null, $t + 1500), 'a figure of 0 turns the notice off');
harness_set_setting_mem(OutboundTransferMeter::NOTICE_SETTING, '10');
harness_set_setting_mem('hosted_plan_state', 'subscribed');
check(!OutboundTransferMeter::over(null, $t + 1500), 'a site the operator hosts stays silent: the management node watches it');
harness_set_setting_mem('hosted_plan_state', 'services');

// ---------------------------------------------------------------------------
section('The month\'s turn');

OutboundTransferMeter::tick(strtotime('2026-10-31 23:59:00 UTC'), $counters(9 * $GB, 4 * $GB, 'boot-b|net:[2]'));
$s = OutboundTransferMeter::tick(strtotime('2026-11-01 00:03:00 UTC'), $counters(10 * $GB, 4 * $GB, 'boot-b|net:[2]'));
check($s['period'] === '2026-11' && $s['previous'] === array('period' => '2026-10', 'sent_bytes' => 17 * $GB),
	'last month is kept');
check($s['sent_bytes'] === 1 * $GB && $s['since'] === strtotime('2026-11-01 00:00:00 UTC'),
	'counting ran through the turn, so the new month counts from the 1st');
check(count($sent) === 1, 'a new month under the figure sends nothing');
$s = OutboundTransferMeter::tick(strtotime('2027-01-15 09:00:00 UTC'), $counters(11 * $GB, 4 * $GB, 'boot-b|net:[2]'));
check($s['since'] === strtotime('2027-01-15 09:00:00 UTC'), 'after a gap of months, counting starts from the reading');
OutboundTransferMeter::tick(strtotime('2027-01-31 23:58:00 UTC'), $counters(12 * $GB, 4 * $GB, 'boot-b|net:[2]'));
$s = OutboundTransferMeter::tick(strtotime('2027-02-01 00:01:00 UTC'), $counters(13 * $GB, 4 * $GB, 'boot-b|net:[2]'));
check($s['since'] === strtotime('2027-02-01 00:00:00 UTC'), 'a reading minutes before the turn counts the new month from the 1st');
$s = OutboundTransferMeter::tick(strtotime('2027-03-15 09:00:00 UTC'), $counters(14 * $GB, 4 * $GB, 'boot-b|net:[2]'));
check($s['since'] === strtotime('2027-03-15 09:00:00 UTC'), 'a last reading early in the month before is a gap, not a turn: counting starts from the reading');

OutboundTransferMeter::$dispatch = null;
harness_finish();
