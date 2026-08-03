<?php
/** @joinery-test
 * name: recovery_key_push
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The control plane pushes its recovery key to the sites it manages, and the
 * rule is that it fills an empty slot and never overwrites one.
 *
 * The case this file exists for is the last one in the table: a site already
 * holding a DIFFERENT key must be left exactly as it is. Archives on that site
 * open only with the private half of the key they were sealed to, so a push
 * that overwrote it would strand every backup already taken — and nothing would
 * look wrong until someone needed one of them.
 *
 * The decision is pure by design (no settings, no database), so every row of
 * the table can be exercised directly rather than by standing up sites in the
 * states it has to handle. What the CLI adds on top is refusing malformed
 * input, which it must do before touching anything.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));

$ours   = str_repeat('a1', 32);   // 64 hex — the fingerprint the manager pushes
$theirs = str_repeat('b2', 32);   // somebody else's key, already on the node

// ── The decision table ──────────────────────────────────────────────────────
section('Empty slots are filled');

check(BackupRecoveryKey::push_decision('unconfigured', '', $ours, true) === 'written',
	'nothing configured: the key is written');
check(BackupRecoveryKey::push_decision('unconfigured', '', $ours, false) === 'written',
	'nothing configured, no proof to offer: the key is still written (it just stays unproven)');
check(BackupRecoveryKey::push_decision('invalid', '', $ours, true) === 'written',
	'a value that is not a key at all is replaced — nothing can have been sealed to it');

section('A matching key is completed, never rewritten');

check(BackupRecoveryKey::push_decision('unproven', $ours, $ours, true) === 'proof_write',
	'the same key with no proof marker: the proof is written, the key is not');
check(BackupRecoveryKey::push_decision('unproven', $ours, $ours, false) === 'already',
	'the same key and no proof to offer: nothing to do');
check(BackupRecoveryKey::push_decision('proven', $ours, $ours, true) === 'already',
	'the same key, already proven: a clean no-op');

section('A different key is never touched');

check(BackupRecoveryKey::push_decision('proven', $theirs, $ours, true) === 'different',
	'a different PROVEN key is left alone — backups are sealed to it');
check(BackupRecoveryKey::push_decision('unproven', $theirs, $ours, true) === 'different',
	'a different UNPROVEN key is left alone too — someone may be setting it up by hand');
check(BackupRecoveryKey::push_decision('unproven', $theirs, $ours, false) === 'different',
	'and offering no proof does not turn that into permission to overwrite');

// Nothing in the table may ever write over a key that is already there.
$overwrites = 0;
foreach (array('proven', 'unproven') as $state) {
	foreach (array(true, false) as $have_proof) {
		if (BackupRecoveryKey::push_decision($state, $theirs, $ours, $have_proof) === 'written') {
			$overwrites++;
		}
	}
}
check($overwrites === 0, 'no combination of states produces an overwrite of a different key',
	$overwrites . ' did');

// ── Proof markers only ever complete the key that is here ───────────────────
section('A proof marker cannot be aimed at the wrong key');

$threw = false;
try {
	BackupRecoveryKey::accept_proven_fingerprint($theirs);
} catch (BackupRecoveryKeyException $e) {
	$threw = true;
} catch (Throwable $e) {
	$threw = true; // nothing configured on this site is also a refusal
}
check($threw, 'a fingerprint that is not this site\'s key is refused rather than recorded');

// ── The CLI refuses bad input before it reads anything ──────────────────────
section('The CLI refuses malformed input');

$cli = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/set_recovery_key.php';
if (!is_file($cli)) {
	harness_skip('set_recovery_key.php not present', $cli);
	harness_finish();
}

/** Run the CLI and return [exit code, combined output]. */
function rkp_run($cli, $args) {
	$out = [];
	$rc  = 0;
	exec('php ' . escapeshellarg($cli) . ' ' . $args . ' 2>&1', $out, $rc);
	return [$rc, implode(' | ', $out)];
}

list($rc, $out) = rkp_run($cli, '--public ' . escapeshellarg('not-a-key'));
check($rc !== 0 && strpos($out, 'Nothing was changed') !== false,
	'a value that is not a box public key is refused, saying nothing changed', "rc={$rc} {$out}");

// A truncated key is the realistic version of this: valid base64, wrong length.
list($rc, $out) = rkp_run($cli, '--public ' . escapeshellarg(base64_encode(random_bytes(31))));
check($rc !== 0, 'a key of the wrong length is refused', "rc={$rc} {$out}");

// A proof marker that belongs to some other key means one of the two arguments
// is wrong, and guessing which would be the worst possible response.
$pub = sodium_crypto_box_publickey(sodium_crypto_box_keypair());
list($rc, $out) = rkp_run($cli, '--public ' . escapeshellarg(base64_encode($pub))
	. ' --proven-fpr ' . escapeshellarg($theirs));
check($rc !== 0 && strpos($out, 'Nothing was changed') !== false,
	'a proof marker that is not the fingerprint of the pushed key is refused', "rc={$rc} {$out}");

list($rc, $out) = rkp_run($cli, '--report');
check($rc === 0 && strpos($out, 'RECOVERY_KEY=') === 0,
	'--report answers with the machine-readable token and changes nothing', "rc={$rc} {$out}");

harness_finish();
