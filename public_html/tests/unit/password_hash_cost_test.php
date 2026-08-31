<?php
/** @joinery-test
 * name: password_hash_cost
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Password hashing runs at test cost inside a harness process, and at
 * production cost everywhere else.
 *
 * Argon2id at PHP's production parameters is 64 MB and roughly half a second
 * PER HASH on the dev box. Every fixture user a suite creates, every sign-in
 * it attempts, and every 2FA backup-code set (ten hashes) paid that, which is
 * where whole seconds of the gate went. harness_boot() therefore marks the
 * process (JOINERY_TEST_FAST_HASH, CLI only), and User::password_hash_options()
 * answers with cheap Argon2id parameters — same algorithm, and the hash string
 * carries its own parameters, so password_verify() needs nothing special.
 *
 * The dangerous edge is rehash-on-login: check_password() silently upgrades a
 * hash that does not match current parameters. In a test process EVERY
 * production hash "needs" a rehash by the cheap parameters, and writing that
 * rehash would put a weak hash on a real row. So rehash-on-login is disabled
 * outright in a test process, and this suite pins that.
 *
 * Run: php tests/unit/password_hash_cost_test.php
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

section('Test processes hash cheaply');

check(defined('JOINERY_TEST_FAST_HASH'), 'harness_boot marked this process for fast hashing');
$opts = User::password_hash_options();
check($opts !== array(), 'User::password_hash_options() answers with test parameters here');

$t = microtime(true);
$hash = User::GeneratePassword('correct-horse-battery');
$ms = (int)round((microtime(true) - $t) * 1000);
check($ms < 100, 'one hash costs test money, not production money', $ms . 'ms');
check(password_verify('correct-horse-battery', $hash), 'and still verifies — the hash carries its parameters');
check(strpos($hash, '$argon2id$') === 0, 'the algorithm is unchanged, only its cost', substr($hash, 0, 20));

section('Production processes are untouched');

// A process without the harness marker must hash at production parameters.
// Asked in a subprocess, because a constant cannot be undefined in this one.
$probe = 'require "' . dirname(__DIR__, 2) . '/tests/lib/harness.php";'
	. 'echo json_encode(User::password_hash_options());';
$out = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($probe) . ' 2>/dev/null'));
check($out === '[]', 'without the harness marker the options are production defaults', $out);

section('Rehash-on-login never fires in a test process');

// A production-cost hash "needs" a rehash by the test parameters. If
// check_password acted on that here, it would overwrite a real user's strong
// hash with a weak one the first time a suite signed in as them.
$prod_hash = password_hash('SomePassword123', PASSWORD_ARGON2ID); // production defaults, ~0.5s once
$u = new User(NULL);
$u->set('usr_password', $prod_hash);
check($u->check_password('SomePassword123'), 'a production-cost hash verifies in a test process');
check($u->get('usr_password') === $prod_hash,
	'and is left exactly as it was — no rehash, no save, no weakened row');
check(!$u->check_password('WrongPassword'), 'a wrong password still fails');

harness_finish();
