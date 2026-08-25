<?php
/** @joinery-test
 * name: node_pinned_recovery_key
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * Nobody hands this machine the key that opens its backups.
 *
 * Encrypting to a public key succeeds whether or not anyone holds the private
 * half. So a backup sealed to a substituted key uploads normally, reports itself
 * encrypted, and shows green on every dashboard, while only whoever substituted
 * the key can read it — and the first sign of trouble is a restore that cannot
 * be done. A key arriving over a wire is a key this machine cannot verify,
 * whatever sent it.
 *
 * Which is why the capability is gone rather than guarded, and why refusal is
 * the behaviour rather than falling back: a run carrying key material fails and
 * says so, so a stale control plane — or one that has been tampered with — is
 * discovered instead of obeyed. Silently ignoring the key would look identical
 * from the far end to accepting it.
 *
 * The other half of the property, that a machine with no proven key of its own
 * refuses to back up rather than downgrading, needs settings to move and lives
 * in recovery_one_screen_flow.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/BackupEnvelope.php'));
require_once(PathHelper::getIncludePath('includes/BackupRecoveryKey.php'));
require_once(PathHelper::getIncludePath('includes/BackupRunner.php'));

// ── There is no code path that takes a key from a caller ────────────────────
section('The capability is absent, not merely unused');

check(!method_exists('BackupEnvelope', 'recipients_for_foreign_recovery'),
	'no way to build a recipient set from a supplied recovery key');
check(!method_exists('BackupRecoveryKey', 'push_decision'),
	'no rule for what to do with a key pushed from elsewhere');
check(!method_exists('BackupRecoveryKey', 'accept_proven_fingerprint'),
	'no way to accept a possession proof established somewhere else');

// ── A run carrying key material is refused ──────────────────────────────────
section('A manager-profile run that carries a key is refused, not ignored');

$pub = base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair()));

$carrying = array(
	'recovery_public_key' => $pub,
	'recovery_fpr'        => hash('sha256', 'anything'),
	'recipients'          => array(array('kind' => 'recovery', 'pub' => $pub)),
);

foreach ($carrying as $field => $value) {
	$message = '';
	try {
		BackupRunner::plan(array('profile' => 'manager', 'manager' => array(
			'bucket'      => 'a-bucket',
			'credentials' => array('key_id' => 'x', 'application_key' => 'y'),
			$field        => $value,
		)));
	} catch (Throwable $e) {
		$message = $e->getMessage();
	}
	check(strpos($message, 'refused') !== false && strpos($message, $field) !== false,
		"a run supplying {$field} is refused, and the refusal names the field", $message);
}

// The refusal has to come first. Reaching it only after the bucket and
// credentials happened to be well-formed would mean a run with a substituted
// key and a typo'd bucket reported the typo — and the operator fixed the typo.
$message = '';
try {
	BackupRunner::plan(array('profile' => 'manager', 'manager' => array(
		'recovery_public_key' => $pub,
	)));
} catch (Throwable $e) {
	$message = $e->getMessage();
}
check(strpos($message, 'refused') !== false,
	'and it is refused before anything else about the run is judged', $message);

// ── A run that supplies no key is judged on its own terms ───────────────────
section('A run with no key material gets past this check');

$message = '';
try {
	BackupRunner::plan(array('profile' => 'manager', 'manager' => array()));
} catch (Throwable $e) {
	$message = $e->getMessage();
}
check(strpos($message, 'refused') === false && strpos($message, 'bucket') !== false,
	'a keyless run is refused for what it is actually missing, not for carrying a key', $message);

// ── The node-side tools refuse the same thing ───────────────────────────────
// The classes above are the refusal a control plane meets. These two are what a
// control plane that predates the removal actually invokes, over SSH, so they
// are where an out-of-date plane finds out.
section('The node-side CLI tools refuse a supplied key');

/** Run a CLI and return [exit code, combined output]. */
function npr_run($cli, $args) {
	$out = array();
	$rc  = 0;
	exec('php ' . escapeshellarg($cli) . ' ' . $args . ' 2>&1', $out, $rc);
	return array($rc, implode(' | ', $out));
}

$tools = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools';

if (!is_file($tools . '/set_recovery_key.php') || !is_file($tools . '/backup_envelope.php')) {
	harness_skip('sysadmin_tools not present beside this site', $tools);
	harness_finish();
}

list($rc, $out) = npr_run($tools . '/set_recovery_key.php',
	'--public ' . escapeshellarg($pub) . ' --proven-fpr ' . escapeshellarg(str_repeat('a1', 32)));
check($rc !== 0 && strpos($out, 'refused') !== false && strpos($out, 'Nothing was changed') !== false,
	'set_recovery_key.php refuses to write a key from outside, saying nothing changed', "rc={$rc} {$out}");

list($rc, $out) = npr_run($tools . '/set_recovery_key.php', '--report');
check($rc === 0 && strpos($out, 'RECOVERY_KEY=') === 0,
	'and still reports what this site holds — asking is what a control plane may do', "rc={$rc} {$out}");

$scratch = sys_get_temp_dir() . '/jy_npr_' . bin2hex(random_bytes(4));
list($rc, $out) = npr_run($tools . '/backup_envelope.php',
	'mint --recovery-pub ' . escapeshellarg($pub)
	. ' --artifact pending --key-out ' . escapeshellarg($scratch . '.key')
	. ' --sidecar-out ' . escapeshellarg($scratch . '.json'));
check($rc !== 0 && strpos($out, 'refused') !== false,
	'backup_envelope.php mint refuses --recovery-pub', "rc={$rc} {$out}");
check(!file_exists($scratch . '.key') && !file_exists($scratch . '.json'),
	'and writes nothing when it refuses');
@unlink($scratch . '.key');
@unlink($scratch . '.json');

harness_finish();
