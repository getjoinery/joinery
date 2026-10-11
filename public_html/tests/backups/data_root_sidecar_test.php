<?php
/** @joinery-test
 * name: data_root_sidecar
 * tier: safe
 * env: any
 * needs: []
 */
/**
 * The encrypted data root's break-glass copy (specs/hosted_data_promise_disk_encryption.md
 * D2) is the volume key sealed to the recovery key by the joinery-agent binary, in the
 * backup envelope's version-1 format, so the owner opens it with the tool they already
 * hold: backup_envelope.php open --sidecar ... --private recovery.key. This pins that
 * promise from this side, with a copy the agent sealed (its own test pins the other
 * direction): the CLI opens it, and the core class decodes and opens it too.
 *
 * The keypair below is a TEST key made for this fixture; it guards nothing.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

const DATA_ROOT_TEST_SECRET = 'oQPzooLmSXpB1fa9huP2sWYl0yBUmYzC9vG9iDKdC9E=';
const DATA_ROOT_AGENT_SIDECAR = <<<'JSON'
{
    "version": 1,
    "artifact": "data-root",
    "cipher": "luks2-volume-key",
    "created": "2026-10-11T17:42:57Z",
    "recipients": [
        {
            "kind": "recovery",
            "fingerprint": "c707c5c392f898b1e3ba7dc1ddeb3fd640e83aeca7199d2d21d49b332a4413db",
            "sealed": "0viM7Vb+qKI71sk1tN2Bzfj5J18nz/pHKcf9/XC0MBRFiJZ+V7jviy9SymI3cz5NVMviyRio4ehoZ3wTs8h3LnCe5/ungsM7C/krYO0EtY8wQUCpcj7YgPEdwuRVjHyDDkG5sCdTMSBaXdW3uYNrkQ=="
        }
    ]
}
JSON;

$want = '';
for ($i = 0; $i < 64; $i++) { $want .= chr(($i * 3) % 256); }

$work = sys_get_temp_dir() . '/jy_data_root_sidecar_' . getmypid();
@mkdir($work, 0700, true);
register_shutdown_function(function () use ($work) {
	foreach (glob($work . '/*') ?: [] as $f) { @unlink($f); }
	@rmdir($work);
});
file_put_contents($work . '/joinery.img.recovery', DATA_ROOT_AGENT_SIDECAR);
file_put_contents($work . '/recovery.key', DATA_ROOT_TEST_SECRET);

section('the core class reads the agent\'s envelope');
$env = BackupEnvelope::decode(DATA_ROOT_AGENT_SIDECAR);
check($env['version'] === 1 && count($env['recipients']) === 1 && $env['recipients'][0]['kind'] === 'recovery', 'version 1, one recovery recipient');
check(BackupEnvelope::open($env, DATA_ROOT_TEST_SECRET) === $want, 'opens to the 64-byte volume key it was given');

section('backup_envelope.php open, the owner\'s tool, opens it');
$cli = PathHelper::getSiteRoot() . '/maintenance_scripts/sysadmin_tools/backup_envelope.php';
if (!is_file($cli)) {
	harness_skip('node envelope CLI not present', $cli);
	harness_finish();
}
$out = [];
exec('php ' . escapeshellarg($cli) . ' open --sidecar ' . escapeshellarg($work . '/joinery.img.recovery')
	. ' --private ' . escapeshellarg($work . '/recovery.key') . ' --key-out ' . escapeshellarg($work . '/volume.key') . ' 2>&1', $out, $rc);
check($rc === 0, 'exit 0', implode(' ', $out));
check(is_file($work . '/volume.key') && file_get_contents($work . '/volume.key') === $want,
	'the key file holds the volume key, raw, as cryptsetup --volume-key-file reads it');

harness_finish();
