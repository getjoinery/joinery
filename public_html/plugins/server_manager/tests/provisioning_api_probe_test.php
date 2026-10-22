<?php
/** @joinery-test
 * name: provisioning_api_probe
 * tier: db
 * env: dev-only
 * needs: [dev-web]
 * covers: [plugins/server_manager/includes/ProvisioningSetup.php, plugins/server_manager/includes/GetJoineryApiClient.php, api/apiv1.php]
 */
/**
 * The store API connection's check on the Provisioning page, over real
 * requests to this site's own API (B21). The check asked for one row with
 * limit, which the API has never had: it refuses an unknown filter with a
 * 400, so every site said "API not responding" whatever its key. It asks
 * with numperpage, the API's paging parameter, and says why it failed.
 *
 * A throwaway admin account and key are made and removed by the harness;
 * the site's own provisioning settings are not read or written.
 *
 * Run: php plugins/server_manager/tests/provisioning_api_probe_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();

section('The probe answers with a working key, and says why it fails');

$user = new User(NULL);
$user->set('usr_email', 'harness-probe-' . bin2hex(random_bytes(4)) . '@example.com');
$user->set('usr_first_name', 'Harness');
$user->set('usr_last_name', 'Probe');
$user->set('usr_permission', ProvisioningSetup::SERVICE_USER_PERMISSION);
$user->save();
$user->load();
harness_register_row('usr_users', 'usr_user_id', $user->key);

$public = 'public_' . LibraryFunctions::random_string(16);
$secret = 'secret_' . LibraryFunctions::random_string(16);
$key = new ApiKey(NULL);
$key->set('apk_usr_user_id', $user->key);
$key->set('apk_name', 'Harness probe');
$key->set('apk_public_key', $public);
$key->set('apk_secret_key', ApiKey::GenerateKey($secret));
$key->set('apk_permission', ProvisioningSetup::SERVICE_KEY_PERMISSION);
$key->set('apk_is_active', TRUE);
$key->save();
$key->load();
harness_register_row('apk_api_keys', 'apk_api_key_id', $key->key);

$url = ProvisioningSetup::selfApiUrl();
$problem = ProvisioningSetup::probeClientProblem(new GetJoineryApiClient($url, $public, $secret));
check($problem === '', 'a service-level key on this site: the probe answers', $problem);

$client = new GetJoineryApiClient($url, $public, $secret);
check($client->get('OrderItemRequirements', array('limit' => 1)) === null
		&& strpos($client->last_error, 'HTTP 400') === 0 && strpos($client->last_error, "'limit'") !== false,
	'limit is not a parameter of this API: refused as an unknown filter, and last_error says so (what the probe sent, B21)',
	$client->last_error);

$problem = ProvisioningSetup::probeClientProblem(new GetJoineryApiClient($url, $public, 'secret_wrong'));
check(preg_match('/^HTTP 4\d\d/', $problem) === 1, 'a wrong secret: the probe names the refusal, not just "not responding"', $problem);

$problem = ProvisioningSetup::probeClientProblem(new GetJoineryApiClient('https://127.0.0.1:1', $public, $secret));
check(strpos($problem, 'no answer from 127.0.0.1') === 0, 'nothing listening: the probe says there was no answer', $problem);

harness_finish();
