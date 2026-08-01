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

if (!vault_apcu_usable()) {
	harness_skip('APCu unavailable in this process', 'run manually: php -d apc.enable_cli=1 tests/vault/vault_deferred_work_test.php');
	harness_finish();
}
if (!vault_ensure_session()) {
	harness_skip('could not start a CLI session');
	harness_finish();
}

$user = make_user('VaultWork');
$uid = (int)$user->key;
$sid = session_id();
$secret = random_bytes(32);
$scope = 'user';
$meta_key = 'vaultmeta:' . $sid . ':' . $uid . ':' . $scope;
harness_defer(function () use ($uid) { VaultUnlock::lockAll($uid); });

/**
 * Register a fake consumer with a fixed amount of work. Returns a state array
 * the test can inspect: how many turns it got, how many items it completed.
 */
function fake_consumer(string $id, int $items, float $seconds_per_item = 0.0, bool $throws = false): array {
	$state = array('turns' => 0, 'done' => 0, 'remaining' => $items, 'has_work_calls' => 0);
	$ref = new ArrayObject($state);
	VaultDeferredWork::register(
		$id,
		function (int $user_id) use ($ref): bool {
			$ref['has_work_calls'] = $ref['has_work_calls'] + 1;
			return $ref['remaining'] > 0;
		},
		function (int $user_id, string $secret_key, float $deadline) use ($ref, $seconds_per_item, $throws): int {
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
		}
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
VaultUnlock::open($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
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
		function (int $u, string $k, float $d) use ($order, $name): int {
			$order[] = $name;
			return 0;   // no progress, so one pass then stop
		});
}
VaultDeferredWork::drain($uid, $scope, 2.0);
check($order->count() >= 2 && $order[0] === 't_first' && $order[1] === 't_second',
	'consumers run in the order they registered');

section('One consumer cannot starve another');
VaultDeferredWork::resetForTests();
list($slow) = fake_consumer('t_slow', 100, 0.05);
list($fast) = fake_consumer('t_fast', 3);
VaultDeferredWork::drain($uid, $scope, 1.5);
check($slow['done'] > 0, 'the slow consumer made progress');
check($fast['done'] === 3, 'and the fast one still finished its work');

section('The deadline bounds the slice');
VaultDeferredWork::resetForTests();
list($greedy) = fake_consumer('t_greedy', 10000, 0.02);
$started = microtime(true);
VaultDeferredWork::drain($uid, $scope, 1.0);
$elapsed = microtime(true) - $started;
check($elapsed < 3.0, 'a slice with unlimited work still returns near its budget (' . round($elapsed, 2) . 's)');
check($greedy['remaining'] > 0, 'and it stopped with work left over');

section('A broken consumer is skipped, not fatal');
VaultDeferredWork::resetForTests();
list($broken) = fake_consumer('t_broken', 5, 0.0, true);
list($healthy) = fake_consumer('t_healthy', 2);
$res = VaultDeferredWork::drain($uid, $scope, 1.5);
check($broken['turns'] > 0, 'the broken consumer was given a turn');
check($healthy['done'] === 2, 'and the healthy one still completed its work');

section('Background work is not user activity');
// The Fortress idle cap measures from the last content decrypt. If a drain's
// reads counted as activity, an abandoned tab would hold a window open for
// ever and the cap would stop existing.
//
// The assertion has to be that a drain leaves the clock UNTOUCHED while the
// window is still live. Rewinding PAST the cap and checking the window ends
// would prove nothing: the policy check runs before any stamping, so that
// passes whether suppression works or not.
VaultDeferredWork::resetForTests();
list($bg) = fake_consumer('t_bg', 2);
VaultUnlock::open($uid, $secret, $scope, ['idle' => 600, 'absolute' => null]);

// Age the clock to well inside the cap: still open, but clearly not "now".
$aged = time() - 500;
$meta = apcu_fetch($meta_key);
$meta['content'] = $aged;
apcu_store($meta_key, $meta, 3600);

VaultDeferredWork::drain($uid, $scope, 1.0);
check($bg['done'] === 2, 'the drain read the key and did its work');

$meta = apcu_fetch($meta_key);
check((int)$meta['content'] === $aged,
	'the drain did NOT refresh the idle clock (' . (time() - (int)$meta['content']) . 's old, expected ~500s)');

// And the cap still bites on schedule afterwards.
$meta['content'] = time() - 1200;
apcu_store($meta_key, $meta, 3600);
$res = VaultDeferredWork::drain($uid, $scope, 1.0);
check($res['locked'] === true, 'once the cap is exceeded a drain reports locked');
check(!VaultUnlock::isOpen($uid), 'and the window really ended');

section('Suppression is scoped and restored');
VaultUnlock::open($uid, $secret, $scope, ['idle' => null, 'absolute' => null]);
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
VaultUnlock::open($uid, $secret, $scope, ['idle' => 600, 'absolute' => null]);
$meta = apcu_fetch($meta_key);
$meta['content'] = time() - 300;
apcu_store($meta_key, $meta, 3600);
check(VaultUnlock::secretKey($uid, $scope) === $secret, 'a normal read succeeds');
$meta = apcu_fetch($meta_key);
check(time() - (int)$meta['content'] < 5, 'and it refreshed the content stamp');

harness_finish();
?>
