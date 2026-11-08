<?php
/** @joinery-test
 * name: vault_deferred_work
 * tier: db
 * env: dev-only
 * needs: []
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');
require_once(PathHelper::getIncludePath('includes/VaultDeferredWork.php'));

if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}

$user = make_user('VaultWork');
$uid = (int)$user->key;
$sid = session_id();
$secret = (new SealedBox())->generateKeypair()['secret'];
$key_id = vault_fixture_key($secret)->id();
$scope = 'user';
$meta_key = 'vaultmeta:' . $sid . ':' . $uid . ':' . $scope;
harness_defer(function () use ($uid) { VaultUnlock::lockAll($uid); });

/**
 * Register a fake consumer with a fixed amount of work. Returns a state array
 * the test can inspect: how many turns it got, how many items it completed.
 */
function fake_consumer(string $id, int $items, float $seconds_per_item = 0.0, bool $throws = false, array $options = array()): array {
	$state = array('turns' => 0, 'done' => 0, 'remaining' => $items, 'has_work_calls' => 0);
	$ref = new ArrayObject($state);
	VaultDeferredWork::register(
		$id,
		function (int $user_id) use ($ref): bool {
			$ref['has_work_calls'] = $ref['has_work_calls'] + 1;
			return $ref['remaining'] > 0;
		},
		function (int $user_id, VaultKey $key, float $deadline) use ($ref, $seconds_per_item, $throws): int {
			$ref['turns'] = $ref['turns'] + 1;
			if ($throws) {
				throw new RuntimeException('deliberate consumer failure');
			}
			$did = 0;
			while ($ref['remaining'] > 0 && microtime(true) < $deadline) {
				if ($seconds_per_item > 0) { usleep((int)($seconds_per_item * 1000000)); }
				$ref['remaining'] = $ref['remaining'] - 1;
				$ref['done'] = $ref['done'] + 1;
				$did++;
			}
			return $did;
		},
		$options
	);
	return array($ref);
}

section('Nothing runs while locked');
VaultDeferredWork::resetForTests();
list($idle) = fake_consumer('t_idle', 5);
$res = VaultDeferredWork::drain($uid, $scope, 1.0);
check($res['locked'] === true, 'a locked vault reports locked');
check($idle['done'] === 0, 'and no work was done');

section('Work drains inside an open window');
VaultDeferredWork::resetForTests();
list($a) = fake_consumer('t_a', 3);
vault_fixture_open_window($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
$res = VaultDeferredWork::drain($uid, $scope, 2.0);
check($res['locked'] === false, 'an open window is not locked');
check($a['done'] === 3, 'all available items were completed');
check(($res['done']['t_a'] ?? 0) === 3, 'the tally reports what the consumer did');
check($res['more'] === false, 'nothing remains afterwards');

section('Registration order is execution order');
VaultDeferredWork::resetForTests();
$order = new ArrayObject(array());
foreach (array('t_first', 't_second') as $name) {
	VaultDeferredWork::register($name,
		function () { return true; },
		function (int $u, VaultKey $k, float $d) use ($order, $name): int {
			$order[] = $name;
			return 0;   // no progress, so one pass then stop
		});
}
VaultDeferredWork::drain($uid, $scope, 2.0);
check($order->count() >= 2 && $order[0] === 't_first' && $order[1] === 't_second',
	'consumers run in the order they registered');

section('One consumer cannot starve another');
// The budget must clear MIN_TURN_SECONDS (1.0): below it the first
// consumer's turn floor legally spans the whole slice and nothing about
// starvation is observable. 1.2s gives the slow consumer its 1.0s floor
// (100 of its 200 items) and still leaves the fast one a turn.
VaultDeferredWork::resetForTests();
list($slow) = fake_consumer('t_slow', 200, 0.01);
list($fast) = fake_consumer('t_fast', 3);
VaultDeferredWork::drain($uid, $scope, 1.2);
check($slow['done'] > 0, 'the slow consumer made progress');
check($fast['done'] === 3, 'and the fast one still finished its work');

section('A foreground consumer leads a background drain');
// Registered second and given work that would fill the slice: it still goes
// first and takes the whole slice, where an equal share would leave it half.
VaultDeferredWork::resetForTests();
$ran = new ArrayObject(array());
VaultDeferredWork::register('t_plain',
	function () { return true; },
	function (int $u, VaultKey $k, float $d) use ($ran): int { $ran[] = 't_plain'; return 0; });
list($lead) = fake_consumer('t_lead', 10000, 0.005, false, array('foreground' => true));
$res = VaultDeferredWork::drain($uid, $scope, 1.0);
check($lead['done'] > 0 && $ran->count() === 0, 'the lead ran ahead of one registered before it, for the whole slice');
check(($res['done']['t_lead'] ?? 0) === $lead['done'], 'the tally reports it');
check($res['foreground_more'] === true && $res['more'] === true, 'and the answer says foreground work remains');

section('A lead with time left hands the slice on');
VaultDeferredWork::resetForTests();
list($small) = fake_consumer('t_small_plain', 2);
list($quick) = fake_consumer('t_quick_lead', 2, 0.0, false, array('foreground' => true));
$res = VaultDeferredWork::drain($uid, $scope, 1.0);
check($quick['done'] === 2 && $small['done'] === 2, 'the lead finished and the other consumer still ran');
check($res['foreground_more'] === false && $res['more'] === false, 'and nothing remains of either');

section('A foreground consumer that completes nothing shares like any other');
VaultDeferredWork::resetForTests();
$stuck_turns = new ArrayObject(array('n' => 0));
VaultDeferredWork::register('t_stuck',
	function () { return true; },
	function (int $u, VaultKey $k, float $d) use ($stuck_turns): int {
		$stuck_turns['n'] = $stuck_turns['n'] + 1;
		usleep(30000);
		return 0;   // every pending row failed to parse
	},
	array('foreground' => true));
list($other) = fake_consumer('t_other', 3);
$res = VaultDeferredWork::drain($uid, $scope, 1.0);
check($other['done'] === 3, 'the stuck lead did not starve the other consumer');
check($stuck_turns['n'] >= 2, 'and it was still offered a later turn as an ordinary consumer');
check($res['foreground_more'] === true, 'the answer still says foreground work remains');

section('A lead that fails slowly does not spend the slice');
// Every pending row failing to parse looks like this: the turn works right up
// to its end and completes nothing. It gets its share, like anyone else, and
// the other consumer still runs in this same drain.
VaultDeferredWork::resetForTests();
VaultDeferredWork::register('t_failing',
	function () { return true; },
	function (int $u, VaultKey $k, float $d): int {
		while (microtime(true) < $d) { usleep(10000); }
		return 0;
	},
	array('foreground' => true));
list($waiting) = fake_consumer('t_waiting', 3);
VaultDeferredWork::drain($uid, $scope, 1.5);
check($waiting['done'] === 3, 'the other consumer finished in the same drain (' . $waiting['done'] . ' of 3)');

section('A lead that makes progress keeps the slice after its share');
// An equal share of a 2.5 s slice is 1.25 s. Only a lead whose turn reopens to
// the deadline can still be working past it, and the other consumer waits.
VaultDeferredWork::resetForTests();
$other_ran = new ArrayObject(array('n' => 0));
VaultDeferredWork::register('t_other_plain',
	function () { return true; },
	function (int $u, VaultKey $k, float $d) use ($other_ran): int { $other_ran['n'] = $other_ran['n'] + 1; return 0; });
list($busy) = fake_consumer('t_busy', 100000, 0.005, false, array('foreground' => true));
$res = VaultDeferredWork::drain($uid, $scope, 2.5);
check(($res['ms']['t_busy'] ?? 0) > 1800 && $other_ran['n'] === 0,
	'the lead worked past its share and nothing else ran (' . ($res['ms']['t_busy'] ?? 0) . ' ms)');

section('A foreground drain runs foreground consumers only');
VaultDeferredWork::resetForTests();
list($bg_only) = fake_consumer('t_bg_only', 5);
list($fg_only) = fake_consumer('t_fg_only', 2, 0.0, false, array('foreground' => true));
$res = VaultDeferredWork::drain($uid, $scope, 1.0, true);
check($fg_only['done'] === 2, 'the foreground consumer ran');
check($bg_only['turns'] === 0, 'the background consumer did not');
check($res['foreground_more'] === false, 'no foreground work remains');
check($res['more'] === true, 'but the answer still says background work does');

section('A foreground drain with no foreground work runs nothing');
$res = VaultDeferredWork::drain($uid, $scope, 1.0, true);
check($bg_only['turns'] === 0 && $res['done'] === array(), 'nothing ran');
check($res['foreground_more'] === false && $res['more'] === true, 'and the answer sends the client to background pacing');

section('A foreground slice is short whatever the site allows');
VaultDeferredWork::resetForTests();
list($long) = fake_consumer('t_long', 100000, 0.005, false, array('foreground' => true));
$started = microtime(true);
VaultDeferredWork::drain($uid, $scope, 60.0, true);
$elapsed = microtime(true) - $started;
check($elapsed < VaultDeferredWork::FOREGROUND_SLICE_SECONDS + 1, 'the cap holds (' . round($elapsed, 2) . 's)');
check($long['remaining'] > 0, 'and it stopped with work left over');

section('The answer times each consumer');
VaultDeferredWork::resetForTests();
list($timed) = fake_consumer('t_timed', 4, 0.05);
$res = VaultDeferredWork::drain($uid, $scope, 2.0);
check(is_int($res['ms']['t_timed'] ?? null) && $res['ms']['t_timed'] >= 150,
	'ms reports the consumer\'s time (' . ($res['ms']['t_timed'] ?? 'none') . ')');

section('A slow has_work answer is logged, a quick one is not');
VaultDeferredWork::resetForTests();
VaultDeferredWork::register('t_slow_check',
	function () { usleep(300000); return false; },
	function (int $u, VaultKey $k, float $d): int { return 0; });
VaultDeferredWork::register('t_quick_check',
	function () { return false; },
	function (int $u, VaultKey $k, float $d): int { return 0; });
$slow_log = tempnam(sys_get_temp_dir(), 'vdw_slow_');
harness_defer(function () use ($slow_log) { @unlink($slow_log); });
$previous_log = ini_set('error_log', $slow_log);
try {
	$answer = VaultDeferredWork::hasWork($uid);
} finally {
	ini_set('error_log', $previous_log);
}
$logged = (string)file_get_contents($slow_log);
check($answer === false, 'the slow consumer still answers');
check(strpos($logged, 'consumer t_slow_check took ') !== false
	&& strpos($logged, ' ms to answer whether it has work (user ' . $uid . ')') !== false,
	'the slow one is logged by name');
check(strpos($logged, 't_quick_check') === false, 'the quick one is not');

section('The deadline bounds the slice');
VaultDeferredWork::resetForTests();
list($greedy) = fake_consumer('t_greedy', 10000, 0.005);
$started = microtime(true);
VaultDeferredWork::drain($uid, $scope, 0.4);
$elapsed = microtime(true) - $started;
check($elapsed < 1.2, 'a slice with unlimited work still returns near its budget (' . round($elapsed, 2) . 's)');
check($greedy['remaining'] > 0, 'and it stopped with work left over');

section('A broken consumer is skipped, not fatal');
VaultDeferredWork::resetForTests();
list($broken) = fake_consumer('t_broken', 5, 0.0, true);
list($healthy) = fake_consumer('t_healthy', 2);
$res = VaultDeferredWork::drain($uid, $scope, 1.5);
check($broken['turns'] > 0, 'the broken consumer was given a turn');
check($healthy['done'] === 2, 'and the healthy one still completed its work');

section('Background work is not user activity');
// The hardened idle cap measures from the last content decrypt. If a drain's
// reads counted as activity, an abandoned tab would hold a window open for
// ever and the cap would stop existing.
//
// The assertion has to be that a drain leaves the clock UNTOUCHED while the
// window is still live. Rewinding PAST the cap and checking the window ends
// would prove nothing: the policy check runs before any stamping, so that
// passes whether suppression works or not.
VaultDeferredWork::resetForTests();
list($bg) = fake_consumer('t_bg', 2);
vault_fixture_open_window($uid, $secret, $scope, ['idle' => 600, 'absolute' => null]);

// Age the clock to well inside the cap: still open, but clearly not "now".
$aged = time() - 500;
$meta = vault_store_get($meta_key);
$meta['content'] = $aged;
vault_store_put($meta_key, $meta);

VaultDeferredWork::drain($uid, $scope, 1.0);
check($bg['done'] === 2, 'the drain read the key and did its work');

$meta = vault_store_get($meta_key);
check((int)$meta['content'] === $aged,
	'the drain did NOT refresh the idle clock (' . (time() - (int)$meta['content']) . 's old, expected ~500s)');

// And the cap still bites on schedule afterwards.
$meta['content'] = time() - 1200;
vault_store_put($meta_key, $meta);
$res = VaultDeferredWork::drain($uid, $scope, 1.0);
check($res['locked'] === true, 'once the cap is exceeded a drain reports locked');
check(!VaultUnlock::isOpen($uid), 'and the window really ended');

section('A slice drain never runs a long consumer');
vault_fixture_open_window($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
VaultDeferredWork::resetForTests();
list($s_slice) = fake_consumer('t_s_slice', 2);
list($s_long) = fake_consumer('t_s_long', 2, 0.0, false, array('lane' => 'long'));
$res = VaultDeferredWork::drain($uid, $scope, 1.0);
check($s_slice['done'] === 2, 'the slice consumer ran');
check($s_long['turns'] === 0, 'the long consumer was not given a turn');
check($res['more'] === false, 'more answers for the slice lane only');
check($res['long_more'] === true, 'long_more says the long lane still has work');

section('A long drain runs only long consumers, one turn each');
$res = VaultDeferredWork::drain($uid, $scope, 1.0, false, 'long');
check($s_long['turns'] === 1 && $s_long['done'] === 2, 'the long consumer had one turn and finished');
check(($res['done']['t_s_long'] ?? 0) === 2, 'the tally reports it');
check($res['long_more'] === false && $res['more'] === false, 'and neither lane has work left');
VaultDeferredWork::resetForTests();
list($l_slice) = fake_consumer('t_l_slice', 3);
list($l_long) = fake_consumer('t_l_long', 1000, 0.01, false, array('lane' => 'long'));
$started = microtime(true);
$res = VaultDeferredWork::drain($uid, $scope, 0.3, false, 'long');
$elapsed = microtime(true) - $started;
check($l_slice['turns'] === 0, 'a long drain never runs a slice consumer');
check($l_long['turns'] === 1, 'a long drain is one pass, not a loop (' . $l_long['turns'] . ' turns)');
check($elapsed < 1.0 && $l_long['remaining'] > 0, 'the turn stops starting items at the slice end (' . round($elapsed, 2) . 's)');
check($res['more'] === true && $res['long_more'] === true, 'both lanes still report their work');

section('Each lane answers hasWork for itself');
check(VaultDeferredWork::hasWork($uid) === true, 'the slice lane has work');
check(VaultDeferredWork::hasWork($uid, 'long') === true, 'the long lane has work');
$p = VaultDeferredWork::pendingByLane($uid);
check($p['slice'] === true && $p['long'] === true, 'pendingByLane answers both');
VaultDeferredWork::resetForTests();
list($o_long) = fake_consumer('t_o_long', 1, 0.0, false, array('lane' => 'long'));
check(VaultDeferredWork::hasWork($uid) === false, 'a long consumer alone is no slice work');
check(VaultDeferredWork::hasWork($uid, 'long') === true, 'but it is long work');

section('Parsing leads: a long consumer waits for foreground work');
VaultDeferredWork::resetForTests();
list($pl_parse) = fake_consumer('t_pl_parse', 2, 0.0, false, array('foreground' => true));
list($pl_plain) = fake_consumer('t_pl_plain', 2);
list($pl_long) = fake_consumer('t_pl_long', 2, 0.0, false, array('lane' => 'long'));
check(VaultDeferredWork::hasWork($uid, 'long') === false, 'the long lane reports no work while foreground work waits');
$p = VaultDeferredWork::pendingByLane($uid);
check($p['slice'] === true && $p['long'] === false, 'pendingByLane agrees');
$res = VaultDeferredWork::drain($uid, $scope, 1.0, false, 'long');
check($pl_long['turns'] === 0, 'a long drain runs nothing while foreground work waits');
check($res['foreground_more'] === true && $res['long_more'] === false, 'and its answer says why');
VaultDeferredWork::drain($uid, $scope, 1.0, true);
check($pl_parse['remaining'] === 0, 'once the foreground work is done');
check(VaultDeferredWork::hasWork($uid, 'long') === true, 'the long lane has work again');
check($pl_plain['remaining'] === 2 && VaultDeferredWork::hasWork($uid, 'long') === true,
	'an ordinary slice consumer with work does not hold the long lane off');

section('Lanes are checked');
$refused = 0;
try { VaultDeferredWork::register('t_bad', function () { return false; }, function () { return 0; }, array('lane' => 'other')); }
catch (InvalidArgumentException $e) { $refused++; }
try { VaultDeferredWork::register('t_bad2', function () { return false; }, function () { return 0; }, array('lane' => 'long', 'foreground' => true)); }
catch (InvalidArgumentException $e) { $refused++; }
try { VaultDeferredWork::drain($uid, $scope, 1.0, true, 'long'); }
catch (InvalidArgumentException $e) { $refused++; }
check($refused === 3, 'an unknown lane, a foreground long consumer and a foreground long drain are refused');

section('A long turn past its slice is not an overrun, a slice turn is');
VaultDeferredWork::resetForTests();
VaultDeferredWork::register('t_ov_slice',
	function () { static $n = 0; return $n++ < 1; },
	function (int $u, VaultKey $k, float $d): int { usleep(2600000); return 1; });
VaultDeferredWork::register('t_ov_long',
	function () { static $n = 0; return $n++ < 2; },
	function (int $u, VaultKey $k, float $d): int { usleep(2600000); return 1; },
	array('lane' => 'long'));
$ov_log = tempnam(sys_get_temp_dir(), 'vdw_ov_');
harness_defer(function () use ($ov_log) { @unlink($ov_log); });
$previous_log = ini_set('error_log', $ov_log);
try {
	VaultDeferredWork::drain($uid, $scope, 0.2);
	VaultDeferredWork::drain($uid, $scope, 0.2, false, 'long');
} finally {
	ini_set('error_log', $previous_log);
}
$logged = (string)file_get_contents($ov_log);
check(strpos($logged, 'consumer t_ov_slice overran') !== false, 'the slice turn is logged');
check(strpos($logged, 't_ov_long') === false, 'the long turn, a model call long, is not');

section('The heartbeat answers each lane');
require_once(PathHelper::getIncludePath('logic/vault_heartbeat_logic.php'));
vault_fixture_open_window($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
$hb_session = SessionControl::get_instance();
$hb_session->set_api_user($uid);
harness_defer(function () use ($hb_session) { $hb_session->clear_api_user(); });
VaultDeferredWork::resetForTests();
list($hb_long) = fake_consumer('t_hb_long', 1, 0.0, false, array('lane' => 'long'));
$hb = vault_heartbeat_logic(array());
check(!$hb->error && $hb->data['alive'] === true, 'the beat finds the window open', (string)$hb->error);
check($hb->data['work_pending'] === false && $hb->data['long_pending'] === true,
	'long work alone is long_pending, not work_pending', json_encode($hb->data));
list($hb_parse) = fake_consumer('t_hb_parse', 1, 0.0, false, array('foreground' => true));
$hb = vault_heartbeat_logic(array());
check($hb->data['work_pending'] === true && $hb->data['long_pending'] === false,
	'foreground work is work_pending and holds long_pending off', json_encode($hb->data));
$hb_session->clear_api_user();

section('A long drain is not user activity');
VaultDeferredWork::resetForTests();
list($la) = fake_consumer('t_la', 2, 0.0, false, array('lane' => 'long'));
vault_fixture_open_window($uid, $secret, $scope, ['idle' => 600, 'absolute' => null]);
$aged = time() - 500;
$meta = vault_store_get($meta_key);
$meta['content'] = $aged;
vault_store_put($meta_key, $meta);
VaultDeferredWork::drain($uid, $scope, 1.0, false, 'long');
check($la['done'] === 2, 'the long drain read the key and did its work');
$meta = vault_store_get($meta_key);
check((int)$meta['content'] === $aged, 'and did NOT refresh the idle clock');

section('Suppression is scoped and restored');
vault_fixture_open_window($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
check(!VaultUnlock::isActivitySuppressed(), 'suppression is off outside a drain');
$seen = null;
VaultDeferredWork::withBackgroundWork(function () use (&$seen) {
	$seen = VaultUnlock::isActivitySuppressed();
});
check($seen === true, 'suppression is on inside withBackgroundWork');
check(!VaultUnlock::isActivitySuppressed(), 'and off again afterwards');

try {
	VaultDeferredWork::withBackgroundWork(function () { throw new RuntimeException('boom'); });
} catch (RuntimeException $e) { /* expected */ }
check(!VaultUnlock::isActivitySuppressed(), 'suppression is restored even when the body throws');

section('An ordinary read still counts as activity');
vault_fixture_open_window($uid, $secret, $scope, ['idle' => 600, 'absolute' => null]);
$meta = vault_store_get($meta_key);
$meta['content'] = time() - 300;
vault_store_put($meta_key, $meta);
$got = VaultUnlock::secretKey($uid, $scope);
check($got instanceof VaultKey && $got->id() === $key_id, 'a normal read succeeds');
$meta = vault_store_get($meta_key);
check(time() - (int)$meta['content'] < 5, 'and it refreshed the content stamp');

harness_finish();
?>
