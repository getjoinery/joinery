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
 * backup envelope's format (version 2, an X-Wing recipient), so the owner opens it with the tool they already
 * hold: backup_envelope.php open --sidecar ... --private recovery.key. This pins that
 * promise from this side, with a copy the agent sealed (its own test pins the other
 * direction): the CLI opens it, and the core class decodes and opens it too.
 *
 * The keypair below is a TEST key made for this fixture; it guards nothing.
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

const DATA_ROOT_TEST_SECRET = 'yMfGxcTDwsHAv769vLu6ubi3trW0s7KxsK+urayrqqk=';
const DATA_ROOT_AGENT_SIDECAR = <<<'JSON'
{
    "version": 2,
    "artifact": "data-root",
    "cipher": "luks2-volume-key",
    "created": "2026-11-02T22:00:00Z",
    "recipients": [
        {
            "kind": "recovery",
            "fingerprint": "156f9ca4f83b478c7de3a4c3ff6b04fca136ae1923c8552fa3dfdeb1c5eca09a",
            "sealed": "rjyhNv3icwGWe2zeUcg6FBTxQxqPQeXrOkRw/lHHXMBzZDcKz18EeBuSibiahn7Nlr9zql4f6UbW9OWP1NExjepKcJtNWkQl6Jrz3otTwkVi18diFyTfyYQbaeVUj6dT+ACzyYrgpyTTRkQGohK4ZUjRls6IMBfgKkkh+d6MLvKQa0Nyx927Fkmqvx6q50Q5mhl20SYLAC9lTRQwsey34iJ/KjYtwIY3SSAiJeE5++C0IBGVOcpiMP8wzNq2QcoS99/VK7b6MSFJ3JyFsyqQiWZUfjh/Jok5eAUhChGpJ3qxMa7LwLURJz8bAmmcdteQ4X5v2mX0mfFleJEgjKW4HAp4ILR8fdswY1kOuM7nehmWCS0nVrwucKuiHMXCdVhwNVxff87nYnOXrd28WQjTPLHhatJjyMh4YQl9BjPmJvTiF/8hG07PTRvmDlAxEj5M7VvxGcrbUCeMdVsIN9+idL9PlJ/pdKjGS6p5/Hue61RsSFZxZb339mf9rP97cGoB+MuREsmwiV7HQMOWbNZql72p/+09es5DFv3Lcj5sI7g3qFbvrN1anASm5LbxSuAZLUM0Q9pUe42H6LzLEUFCtwc5KCK6wLpiMUcgOwKVOGxequiRSO3PHzuqCbBe8eN+J6llHOc+qf0k5sfNP2eF9vWv9DpLxtts9fIv+EVhTBMTMMs+i4+L7bys3/KYNEIbn7j0ydWELj/8mRWJLC8z87Cl7tVxYL6xGfBZIKosbLGtqr02/37psGrNGKKPJidHuyXVwb06QO9uPk+2aJ1aupJLQ+7orPUW0HGIFYWBQ71FdpBkUzJbuKFmWEYb0Xw6igBnsdJWlnmdr4nz9i5LmS+KKUr0e4pOxfTRMusH8XCa4/GMnEaWyR7CP1bS1J0y2qWMWjQnpSh0sJZ/Pq0lLBt3nuYh+K9DEVAdSnkneQeAOw22dKiRVHVYY2TU7/H1vC4yWadz64ILKppf+y4HDQTgshQE9Q9Jczflh0vsGyascVA6IOxAFceww6xDvmf0JvAoY2HycAwSFkwYV9fKU179otLth3b/SdvE+Mh2ACmazeeWPHm88IZ34O+ov+vy83fsBAquQd8G6pZRls4s6oZmkfYslTkxRwXn1QuOrWwvKTJfmiElUidySHiKPwSdjixjmpkUoteBxgZhfeWFLmQaMW3U4EQ4KRjjgtkmiqYEAKcEI1h2ymGqPQNuoDXNej52nDTcAv1TdNTY4GVzP/f9Tn2pPFQvY6dnvRc/ldDICXGAEzQuv8gAyIq8eLhAiRGVwjQHpdhBGwj76i9J4u2FJpTTTmTXvTPMOuZzLjjtVetEOIGz9g0SRQzJsVejSc9rHCRaYqj05M2dV0skTY/JIzzTjv0ezLdlsgf7K3rGHwas++cHPbHg5uFOdx0UeClpEVjfgAZ1IbmN9rpLJ4yyQHBK2HpWc/c+rgC87KEB6yQuW4bgFHNrWtsx0O4NrJWLN2Fw6PY2SqNGpGNSSAUZ788v1T4S4yt4RGH5OFKgjol7m/YWVlUzW+QdKnDsjxk94MbFRewe+0wvufsYSWwf6obdsfOXQwv69ZvNOpmaHRAvOWj0dE7jB2gtLA6d3bwS8BpRedPysJtd",
            "alg": "xwing"
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
check($env['version'] === 2 && count($env['recipients']) === 1 && $env['recipients'][0]['kind'] === 'recovery'
	&& $env['recipients'][0]['alg'] === BackupEnvelope::ALG_XWING, 'version 2, one X-Wing recovery recipient');
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
