<?php
/** @joinery-test
 * name: person_credential
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A phone that holds a Fortress key (specs/fortress_mobile_apps.md § R2, R8).
 *
 *  - `requires_person_credential` admits an app session key and refuses a
 *    machine key, at every action that declares it, and the declared set is
 *    exactly the device-work list — nothing that unlocks, rotates or changes
 *    custody slipped in;
 *  - `device_key_enroll` opens a ceremony bound to the calling app's own key:
 *    approval mints nothing, only the bound user may act on it, the poll hands
 *    over the sealed keys once and no credential, a re-enroll reuses the
 *    device row;
 *  - `vault_client_probe` tells the device whether it still holds the scope,
 *    and a rotation's forgetting shows there;
 *  - a revoked app key takes its device row with it.
 */
require_once(__DIR__ . '/api_test_harness.php');

api_test_boot($argv);

require_once(PathHelper::getIncludePath('includes/LogicResult.php'));
require_once(PathHelper::getIncludePath('includes/VaultClientCustody.php'));
require_once(PathHelper::getIncludePath('logic/drive_device_link_approve_logic.php'));
require_once(PathHelper::getIncludePath('logic/drive_device_link_info_logic.php'));
require_once(PathHelper::getIncludePath('logic/device_key_enroll_logic.php'));
require_once(PathHelper::getIncludePath('tests/lib/vault_fixtures.php'));

$db = DbConnector::get_instance()->get_db_link();
$session = SessionControl::get_instance();

$owner = make_user('personcred');
$other = make_user('personcredother');
harness_defer(function () use ($db, $owner, $other) {
	foreach (array((int)$owner->key, (int)$other->key) as $uid) {
		$db->prepare("DELETE FROM dlk_device_links WHERE dlk_usr_user_id = ?")->execute(array($uid));
		$db->prepare("DELETE FROM sde_sync_devices WHERE sde_usr_user_id = ?")->execute(array($uid));
		$db->prepare("DELETE FROM apk_api_keys WHERE apk_usr_user_id = ?")->execute(array($uid));
		$db->prepare("DELETE FROM uev_user_encryption_vaults WHERE uev_usr_user_id = ?")->execute(array($uid));
	}
});

$phone = ApiKey::CreateSessionKey((int)$owner->key, 'Test iPhone');
$phone_h = key_headers($phone['api_key']->get('apk_public_key'), $phone['secret_key']);
$machine = make_machine_key((int)$owner->key, 'PersonCredMachine');
$machine_h = key_headers($machine['api_key']->get('apk_public_key'), $machine['secret_key']);

// ---------------------------------------------------------------------------
section('the declared set is exactly the device-work list');

$expected = array(
	'mailbox/ai_device_recipes', 'mailbox/ai_device_record', 'mailbox/device_ai_entries',
	'mailbox/device_ai_test_prompt', 'mailbox/device_ai_verdict', 'mailbox/device_rules',
	'mailbox/fortress_parse_store', 'mailbox/fortress_pending', 'mailbox/relay_pins',
	'mailbox/relay_seal_target', 'mailbox/rule_backlog', 'mailbox/rule_outcomes',
	'mailbox/search_entries',
	'device_key_enroll',
);
$declared = array();
$roots = array('logic' => '');
foreach (glob(PathHelper::getIncludePath('plugins') . '/*/logic', GLOB_ONLYDIR) as $dir) {
	$roots[$dir] = basename(dirname($dir)) . '/';
}
foreach ($roots as $dir => $prefix) {
	$dir = $prefix === '' ? PathHelper::getIncludePath('logic') : $dir;
	foreach (glob($dir . '/*_logic.php') as $f) {
		if (preg_match("/'requires_person_credential'\s*=>\s*true/", (string)file_get_contents($f))) {
			$declared[] = $prefix . basename($f, '_logic.php');
		}
	}
}
sort($declared); sort($expected);
check($declared === $expected, 'the actions a phone may call are exactly these',
	'declared: ' . implode(', ', $declared));

// ---------------------------------------------------------------------------
section('a session key reaches each one; a machine key is refused at each');

foreach ($expected as $action) {
	$r = api_request('POST', '/api/v1/action/' . $action, $machine_h, array());
	check($r['status'] === 403 && strpos($r['raw'], 'signed-in person') !== false,
		$action . ': machine key refused', 'status ' . $r['status'] . ' ' . substr($r['raw'], 0, 200));
	$r = api_request('POST', '/api/v1/action/' . $action, $phone_h, array());
	check($r['status'] !== 403 && $r['status'] !== 401,
		$action . ': session key admitted (answer is the action\'s own)', 'status ' . $r['status'] . ' ' . substr($r['raw'], 0, 200));
}
// One that stays browser-only, as a control: the unlock material.
$r = api_request('POST', '/api/v1/action/vault_client_status', $phone_h, array('scope' => 'mail'));
check($r['status'] === 403, 'vault_client_status (unlock material) still refuses the session key', 'status ' . $r['status']);

// ---------------------------------------------------------------------------
section('enrollment binds to the calling app\'s key');

$mail_pair = sodium_crypto_box_keypair();
vault_fixture_client_vault((int)$owner->key, base64_encode(sodium_crypto_box_publickey($mail_pair)), 'mail');
$device_pub = base64_encode(random_bytes(32));

$r = api_request('POST', '/api/v1/action/device_key_enroll', $machine_h,
	array('device_pubkey' => $device_pub, 'platform' => 'ios', 'device_name' => 'Test iPhone'));
check($r['status'] === 403, 'a machine key cannot enroll', 'status ' . $r['status']);

$r = api_request('POST', '/api/v1/action/device_key_enroll', $phone_h,
	array('device_pubkey' => $device_pub, 'platform' => 'nokia', 'device_name' => 'Test iPhone'));
check(($r['json']['success'] ?? true) === false || $r['status'] >= 400, 'a made-up platform is refused', substr($r['raw'], 0, 200));
$r = api_request('POST', '/api/v1/action/device_key_enroll', $phone_h,
	array('device_pubkey' => 'not-a-key', 'platform' => 'ios', 'device_name' => 'Test iPhone'));
check(($r['json']['success'] ?? true) === false || $r['status'] >= 400, 'a malformed device key is refused', substr($r['raw'], 0, 200));

$r = api_request('POST', '/api/v1/action/device_key_enroll', $phone_h,
	array('device_pubkey' => $device_pub, 'platform' => 'ios', 'device_name' => 'Test iPhone'));
$enroll = $r['json']['data'] ?? array();
check($r['status'] === 200 && !empty($enroll['link_code']) && !empty($enroll['poll_token']),
	'the phone gets a code and a poll token', substr($r['raw'], 0, 300));
check(array_key_exists('device_id', $enroll) && $enroll['device_id'] === null, 'no device row exists before the first handover');
$link = DeviceLink::load_open_by_code((string)($enroll['link_code'] ?? ''));
check($link && $link->is_bound() && (int)$link->get('dlk_apk_api_key_id') === (int)$phone['api_key']->key
	&& (int)$link->get('dlk_usr_user_id') === (int)$owner->key, 'the ceremony is bound to the phone\'s key and user');

// Browser side, as another account: the code names nothing for them.
$session->set_api_user((int)$other->key);
vault_fixture_client_vault((int)$other->key, base64_encode(random_bytes(32)), 'mail');
$info = drive_device_link_info_logic(array('code' => $enroll['link_code']));
check($info->error !== null, 'another account cannot see a bound ceremony');
$stolen = drive_device_link_approve_logic(array('code' => $enroll['link_code'], 'sealed_vault_keys' => array('mail' => 'x')));
check($stolen->error !== null, 'nor approve it (its keys would go to someone else\'s phone)');
$session->clear_api_user();

// Browser side, as the owner.
$session->set_api_user((int)$owner->key);
$info = drive_device_link_info_logic(array('code' => $enroll['link_code']));
check(!$info->error && !empty($info->data['bound']) && $info->data['platform_label'] === 'iPhone',
	'the owner sees a signed-in iPhone asking', (string)$info->error);
$none = drive_device_link_approve_logic(array('code' => $enroll['link_code']));
check($none->error !== null, 'a bound ceremony handed no vault is refused');
$keys_before = (int)$db->query("SELECT COUNT(*) FROM apk_api_keys WHERE apk_usr_user_id = " . (int)$owner->key)->fetchColumn();
$approved = drive_device_link_approve_logic(array('code' => $enroll['link_code'], 'sealed_vault_keys' => array('mail' => 'mail-sealed-blob')));
$session->clear_api_user();
check(!$approved->error && ($approved->data['vault_scopes'] ?? null) === array('mail'), 'approval hands over the mail key', (string)$approved->error);
$keys_after = (int)$db->query("SELECT COUNT(*) FROM apk_api_keys WHERE apk_usr_user_id = " . (int)$owner->key)->fetchColumn();
check($keys_after === $keys_before, 'and mints no credential');
$dev = SyncDevice::for_api_key((int)$phone['api_key']->key);
check($dev && $dev->get('sde_platform') === 'ios' && $dev->vault_scopes() === array('mail')
	&& $dev->get('sde_device_pubkey') === $device_pub, 'the phone\'s key now has a device row holding mail under its device key');

// ---------------------------------------------------------------------------
section('the poll hands over the sealed keys once, and no credential');

$r = api_request('GET', '/api/v1/auth/device_link/' . $enroll['poll_token']);
$claim = $r['json']['data'] ?? array();
check($r['status'] === 200 && ($claim['status'] ?? '') === 'approved', 'the poll reports approval', substr($r['raw'], 0, 300));
check(($claim['sealed_vault_keys'] ?? null) === array('mail' => 'mail-sealed-blob'), 'with the sealed mail key');
check(!array_key_exists('secret_key', $claim) && !array_key_exists('public_key', $claim), 'and no credential');
check((int)($claim['device_id'] ?? 0) === (int)$dev->key, 'naming the device row');
$r = api_request('GET', '/api/v1/auth/device_link/' . $enroll['poll_token']);
check($r['status'] === 409, 'a second poll finds it claimed', 'status ' . $r['status']);

// ---------------------------------------------------------------------------
section('the probe tells the phone what it holds');

$r = api_request('POST', '/api/v1/action/vault_client_probe', $phone_h, array('scope' => 'mail'));
$p = $r['json']['data'] ?? array();
check($r['status'] === 200 && ($p['held_by_this_device'] ?? null) === true && array_key_exists('pending_public_key', $p)
	&& $p['pending_public_key'] === null && ($p['device_linked'] ?? null) === true, 'held, linked, no rotation pending', substr($r['raw'], 0, 300));

$forget = new ReflectionMethod('VaultClientRotation', 'forgetScopeOnDevices');
$forget->setAccessible(true);
$forget->invoke(null, (int)$owner->key, 'mail');
$r = api_request('POST', '/api/v1/action/vault_client_probe', $phone_h, array('scope' => 'mail'));
check(($r['json']['data']['held_by_this_device'] ?? null) === false, 'after a rotation forgets the scope, the phone learns it no longer holds it');

// ---------------------------------------------------------------------------
section('re-enrollment reuses the device row');

$r = api_request('POST', '/api/v1/action/device_key_enroll', $phone_h,
	array('device_pubkey' => $device_pub, 'platform' => 'ios', 'device_name' => 'Test iPhone'));
$again = $r['json']['data'] ?? array();
check((int)($again['device_id'] ?? 0) === (int)$dev->key, 'the new ceremony names the same device');
$session->set_api_user((int)$owner->key);
$re = drive_device_link_approve_logic(array('code' => $again['link_code'], 'sealed_vault_keys' => array('mail' => 'mail-sealed-blob-2')));
$session->clear_api_user();
check(!$re->error && (int)$re->data['device_id'] === (int)$dev->key, 'approval updates that row', (string)$re->error);
$live = (int)$db->query("SELECT COUNT(*) FROM sde_sync_devices WHERE sde_delete_time IS NULL AND sde_usr_user_id = " . (int)$owner->key)->fetchColumn();
check($live === 1, 'one device row for one phone');
$r = api_request('POST', '/api/v1/action/vault_client_probe', $phone_h, array('scope' => 'mail'));
check(($r['json']['data']['held_by_this_device'] ?? null) === true, 'and it holds mail again');

// ---------------------------------------------------------------------------
section('revoking the app key unlinks the phone');

$k = new ApiKey((int)$phone['api_key']->key, TRUE);
$k->soft_delete();
check(SyncDevice::for_api_key((int)$phone['api_key']->key) === null, 'the device row goes with its key');
$r = api_request('POST', '/api/v1/action/vault_client_probe', $phone_h, array('scope' => 'mail'));
check($r['status'] === 401 || $r['status'] === 403, 'and the phone reaches nothing', 'status ' . $r['status']);

harness_finish();
