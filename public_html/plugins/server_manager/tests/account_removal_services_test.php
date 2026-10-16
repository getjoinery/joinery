<?php
/** @joinery-test
 * name: account_removal_services
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * An account is never removed while one of its service tenants still holds
 * something at a provider (ServiceTenant::provider_holding()):
 *
 *   - a mail tenant with a subaccount the release has not closed;
 *   - a backup storage tenant with a storage space whose copies are not yet
 *     pruned, whether still running or released and inside its retention.
 *
 * The removal refuses whole (the account, its tenants and their spaces all
 * stay), the dry run names the reason, and once the subaccount is closed and
 * the copies pruned the same removal takes the tenants and their spaces with
 * the account. No provider is called: the refusal reads the rows only.
 *
 * Run: php tests/run.php test-db --only=plugins/server_manager/tests/account_removal_services_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();
$_SERVER['REQUEST_METHOD'] = 'POST'; // permanent_delete refuses on GET

$db = DbConnector::get_instance()->get_db_link();
if (!$db->query("SELECT to_regclass('public.svt_service_tenants') IS NOT NULL")->fetchColumn()) {
	section('schema');
	harness_skip('svt_service_tenants is not in the test database yet — sync the server_manager plugin, then copy live to test');
	harness_finish();
	exit;
}

$target = new BackupTarget(NULL);
$target->set('bkt_name', 'harnesstest account removal storage');
$target->set('bkt_provider', 's3');
$target->set('bkt_bucket', 'harness-removal');
$target->set('bkt_path_prefix', 'harness-backups');
$target->set('bkt_credentials', json_encode(array('access_key' => 'AKIA-harness', 'secret_key' => 'harness-secret',
	'region' => 'us-east-1', 'endpoint' => 'https://s3.example')));
$target->save();
$target_id = (int)$target->key;

$owner = make_user('RemovalOwner');
$owner_id = (int)$owner->key;
$host = 'removal-' . substr(md5(uniqid('', true)), 0, 6) . '.example.com';
// Last-first teardown: this runs before the user's, and leaves nothing that
// would hold the account (a removal the test refused leaves its rows).
harness_defer(function () use ($db, $owner_id, $target_id) {
	$db->exec("DELETE FROM sps_storage_spaces WHERE sps_svt_service_tenant_id IN
		(SELECT svt_service_tenant_id FROM svt_service_tenants WHERE svt_usr_user_id = {$owner_id})");
	$db->exec("DELETE FROM svt_service_tenants WHERE svt_usr_user_id = {$owner_id}");
	$db->exec("DELETE FROM bkt_backup_targets WHERE bkt_backup_target_id = {$target_id}");
});

$tenant = function (string $service, array $values) use ($owner_id, $host) {
	$row = new ServiceTenant(NULL);
	$row->set('svt_usr_user_id', $owner_id);
	$row->set('svt_service', $service);
	$row->set('svt_host', $host);
	foreach ($values as $k => $v) { $row->set($k, $v); }
	$row->save();
	$row->load();
	return $row;
};
$count = function (string $sql) use ($db, $owner_id) {
	$q = $db->prepare($sql);
	$q->execute(array($owner_id));
	return (int)$q->fetchColumn();
};
$tenants_left = function () use ($count) {
	return $count("SELECT COUNT(*) FROM svt_service_tenants WHERE svt_usr_user_id = ?");
};
$user_left = function () use ($count) {
	return $count("SELECT COUNT(*) FROM usr_users WHERE usr_user_id = ?");
};
/** Try the removal; the refusal's message, or null when it went through. */
$remove = function () use ($owner_id, $db) {
	$user = new User($owner_id, TRUE);
	try {
		$user->permanent_delete();
		return null;
	} catch (SystemDisplayableError $e) {
		if ($db->inTransaction()) { $db->rollBack(); }
		return $e->getMessage();
	}
};

// ---------------------------------------------------------------------------
section('nothing at a provider: a row that minted nothing holds nothing');
$unpaid = $tenant('mail', array('svt_state' => 'unpaid'));
check($unpaid->provider_holding() === null, 'an unpaid mail row with no subaccount holds nothing');
$orphan_link = $tenant('shelf', array('svt_state' => 'active', 'svt_mgn_managed_node_id' => 999999999));
check($orphan_link->provider_holding() === null, 'a node-linked row whose node is gone holds nothing');

// ---------------------------------------------------------------------------
section('an open mail subaccount refuses the removal');
$mail = $tenant('mail', array('svt_state' => 'active', 'svt_provider_subaccount_id' => 'harness-sub-1',
	'svt_paid_until' => '2030-01-01 00:00:00'));
$why = $remove();
check($why !== null && strpos($why, $host) !== false && strpos($why, 'mail subaccount') !== false,
	'the removal refuses, naming the site and the subaccount', (string)$why);
check($user_left() === 1 && $tenants_left() === 3, 'the account and every tenant row are still there');
$dry = (new User($owner_id, TRUE))->permanent_delete_dry_run();
check($dry['can_delete'] === false && strpos(implode(' ', $dry['blocking_reasons']), 'mail subaccount') !== false,
	'the dry run says the same');

$mail->set('svt_state', 'released');
$mail->set('svt_revoked_time', gmdate('Y-m-d H:i:s'));
$mail->save();
check($mail->provider_holding() === null, 'released (the subaccount closed): mail holds nothing');

// ---------------------------------------------------------------------------
section('backup copies not yet pruned refuse the removal');
$shelf = $tenant('shelf', array('svt_state' => 'active', 'svt_paid_until' => '2030-01-01 00:00:00'));
$space = new StorageSpace(NULL);
$space->set('sps_bkt_backup_target_id', $target_id);
$space->set('sps_svt_service_tenant_id', (int)$shelf->key);
$space->set('sps_base_key', 'harness-backups/t' . (int)$shelf->key . '/');
$space->set('sps_state', 'active');
$space->save();
$why = $remove();
check($why !== null && strpos($why, 'backup copies') !== false && strpos($why, 'Release it') !== false,
	'a running backup storage service refuses, and says to release it first', (string)$why);

$shelf->set('svt_state', 'released');
$shelf->set('svt_revoked_time', '2026-10-01 00:00:00');
$shelf->set('svt_prune_after_time', '2026-12-30 12:00:00');
$shelf->save();
$why = $remove();
check($why !== null && strpos($why, 'kept until Dec 30, 2026') !== false,
	'released and inside its retention: refuses, naming the prune day', (string)$why);
check($user_left() === 1 && $count("SELECT COUNT(*) FROM sps_storage_spaces s JOIN svt_service_tenants t
	ON t.svt_service_tenant_id = s.sps_svt_service_tenant_id WHERE t.svt_usr_user_id = ?") === 1,
	'the account and its storage space are still there');

// ---------------------------------------------------------------------------
section('pruned: the removal takes the tenants and their spaces');
$shelf->set('svt_pruned_time', gmdate('Y-m-d H:i:s'));
$shelf->save();
$why = $remove();
check($why === null, 'nothing held: the account is removed', (string)$why);
check($user_left() === 0 && $tenants_left() === 0, 'the account and all four tenant rows are gone');
$q = $db->prepare("SELECT COUNT(*) FROM sps_storage_spaces WHERE sps_storage_space_id = ?");
$q->execute(array((int)$space->key));
check((int)$q->fetchColumn() === 0, 'the tenant\'s storage space went with it');

harness_finish();
