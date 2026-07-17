<?php
/** @joinery-test
 * name: vault_recovery_concurrency
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 */

/**
 * Recovery-code replay under real concurrency.
 *
 * unlockWithRecoveryCode consumes a code with a conditional
 * `UPDATE ... SET uew_is_used = true WHERE id = ? AND uew_is_used = false`
 * and treats a rowCount other than 1 as an already-used code. The sequential
 * "a consumed code never unlocks again" case lives in vault_ceremonies_test;
 * this one proves the guard holds when requests genuinely overlap.
 *
 * Several worker processes — each its own PHP process with its own DB
 * connection — load the same (user, vault, code) and, released together by a
 * shared wall-clock barrier, all call unlockWithRecoveryCode at once. Every
 * worker unwraps the secret (the crypto is read-only), so the ONLY thing that
 * can stop two of them from both unlocking is the atomic consume. Exactly one
 * worker must win; the rest must be refused as already-used, and exactly one
 * recovery wrapping may end up consumed. A load-then-save consume (the race the
 * fix removed) would let several win — that regression fails this test.
 *
 * @version 1.0.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(__DIR__ . '/../lib/vault_fixtures.php');

if (!extension_loaded('sodium')) {
	harness_skip('sodium extension unavailable');
	harness_finish();
}
if (!function_exists('proc_open')) {
	harness_skip('proc_open disabled — cannot spawn concurrent workers');
	harness_finish();
}

section('Concurrent use of one recovery code cannot double-unlock');

// A fresh vault: all recovery codes unused. No passphrase needed here.
$fx = vault_fixture_vault('RaceA', '', 8);
$user = $fx['user'];
$vault = $fx['vault'];
$code = $fx['recovery_codes'][0];
$vault_id = (int)$vault->key;

// The fixture registers the vault row for teardown, but wrappings are not
// cascaded with it — remove them explicitly. Registered after the fixture, so
// LIFO runs it before the vault delete.
harness_defer(function () use ($vault_id) {
	$db = DbConnector::get_instance()->get_db_link();
	try {
		$db->prepare('DELETE FROM uew_user_encryption_wrappings WHERE uew_uev_user_encryption_vault_id = ?')
			->execute(array($vault_id));
	} catch (\Throwable $e) {
		echo "  WARNING: wrapping cleanup failed: " . $e->getMessage() . "\n";
	}
});

// The worker: bootstrap the framework, load the pair, wait on the barrier, then
// attempt the unlock. The code arrives via the environment, never argv, so it
// cannot surface in a process listing. __ROOT__ is substituted below.
$worker_src = <<<'WORKER'
<?php
$root = '__ROOT__';
require_once($root . '/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/SessionControl.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SealedBox.php'));
require_once(PathHelper::getIncludePath('includes/VaultCrypto.php'));
require_once(PathHelper::getIncludePath('includes/VaultUnlock.php'));
require_once(PathHelper::getIncludePath('includes/VaultCeremonies.php'));
require_once(PathHelper::getIncludePath('data/users_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_vaults_class.php'));
require_once(PathHelper::getIncludePath('data/user_encryption_wrappings_class.php'));

$user_id  = (int)($argv[1] ?? 0);
$vault_id = (int)($argv[2] ?? 0);
$start    = (float)($argv[3] ?? 0);
$code     = (string)getenv('VAULT_RC');

$user  = new User($user_id, TRUE);
$vault = new UserEncryptionVault($vault_id, TRUE);
$ceremonies = new VaultCeremonies();

// Barrier: every worker spins until the same instant, so the conditional
// UPDATEs contend instead of running one after another.
while (microtime(true) < $start) { /* spin */ }

try {
	$ceremonies->unlockWithRecoveryCode($user, $vault, $code, false);
	fwrite(STDOUT, "RESULT:OK\n");
} catch (\Throwable $e) {
	fwrite(STDOUT, "RESULT:FAIL:" . $e->getMessage() . "\n");
}
WORKER;

$worker_src = str_replace('__ROOT__', PathHelper::getRootDir(), $worker_src);
$worker_path = tempnam(sys_get_temp_dir(), 'vrc_') . '.php';
file_put_contents($worker_path, $worker_src);
@chmod($worker_path, 0666);
harness_defer(function () use ($worker_path) { @unlink($worker_path); });

$N = 8;
$start = microtime(true) + 1.5; // enough lead for every worker to boot and reach the barrier
$php = PHP_BINARY;
$descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$env = $_ENV;
$env['VAULT_RC'] = $code;
$env['PATH'] = getenv('PATH');

$procs = array();
$pipes = array();
for ($i = 0; $i < $N; $i++) {
	$cmd = escapeshellarg($php) . ' -d apc.enable_cli=1 ' . escapeshellarg($worker_path)
		. ' ' . (int)$user->key . ' ' . $vault_id . ' ' . sprintf('%.6f', $start);
	$p = proc_open($cmd, $descriptors, $pipes[$i], null, $env);
	$procs[$i] = $p;
}

$oks = 0;
$fails = 0;
$already_used = 0;
$got_result = 0;
$other_errors = array();
for ($i = 0; $i < $N; $i++) {
	if (!is_resource($procs[$i])) { $other_errors[] = "worker $i failed to start"; continue; }
	$out = stream_get_contents($pipes[$i][1]);
	fclose($pipes[$i][1]);
	$errout = stream_get_contents($pipes[$i][2]);
	fclose($pipes[$i][2]);
	proc_close($procs[$i]);

	if (preg_match('/RESULT:OK/', $out)) {
		$got_result++;
		$oks++;
	} elseif (preg_match('/RESULT:FAIL:(.*)/', $out, $m)) {
		$got_result++;
		$fails++;
		if (strpos($m[1], 'already-used') !== false) { $already_used++; }
		else { $other_errors[] = "worker $i unexpected failure: " . trim($m[1]); }
	} else {
		$other_errors[] = "worker $i produced no RESULT line" . ($errout !== '' ? " (stderr: " . trim(substr($errout, 0, 200)) . ")" : '');
	}
}

check($got_result === $N, "all $N workers ran to a verdict", "got $got_result of $N");
check(empty($other_errors), 'no worker hit an unexpected error', implode(' | ', $other_errors));
check($oks === 1, 'exactly one concurrent worker unlocked', "winners: $oks");
check($fails === $N - 1, 'every other worker was refused', "refused: $fails of " . ($N - 1));
check($already_used === $N - 1, 'each refusal was specifically an already-used code', "already-used: $already_used");

// The database itself must show exactly one recovery wrapping consumed — the
// invariant a double-unlock would violate regardless of what the workers print.
$consumed = new MultiUserEncryptionWrapping(array(
	'vault_id'      => $vault_id,
	'unlocker_type' => UserEncryptionWrapping::TYPE_RECOVERY,
	'is_used'       => true,
));
check($consumed->count_all() === 1, 'exactly one recovery wrapping is marked used', 'used: ' . $consumed->count_all());

// And the winning code is spent: a further use, now sequential, is refused.
$threw = false;
try { (new VaultCeremonies())->unlockWithRecoveryCode($user, $vault, $code, false); }
catch (VaultCeremonyException $e) { $threw = true; }
check($threw, 'the raced code is spent — a later use is refused');

harness_finish();
?>
