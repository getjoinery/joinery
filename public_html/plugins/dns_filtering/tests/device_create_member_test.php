<?php
/** @joinery-test
 * name: dns_device_create_member
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * A member adds a device through the profile page's logic (device_edit_logic),
 * as a web POST under the model write-path check (spec declared_permissions
 * 3.2). A device is personal: a member may write only one that is theirs, so
 * the row must carry its owner at its first save. It once saved an empty row
 * first, which every member's add was refused at.
 *
 * Run: php tests/run.php --filter=dns_device_create_member
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();

$db = DbConnector::get_instance()->get_db_link();
$run_id = strtoupper(LibraryFunctions::random_string(6));
$member = make_user('SdDevMember' . $run_id, 0);

$tier = new SubscriptionTier(NULL);
$tier->set('sbt_name', 'SdDevice ' . $run_id);
$tier->set('sbt_display_name', 'SdDevice ' . $run_id);
$tier->set('sbt_tier_level', 1);
$tier->set('sbt_features', json_encode(array('dns_filtering_scrolldaddy_max_devices' => 2)));
$tier->set('sbt_is_active', true);
$tier->save();
$tier->load();
$group_id = (int)$tier->get('sbt_grp_group_id');
$tier_id  = (int)$tier->key;
harness_defer(function () use ($group_id, $tier_id, $member) {
	$db = DbConnector::get_instance()->get_db_link();
	$ids = $db->prepare('SELECT sdd_device_id FROM sdd_devices WHERE sdd_usr_user_id = ?');
	$ids->execute(array((int)$member->key));
	foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
		$db->prepare('DELETE FROM sdb_scheduled_blocks WHERE sdb_sdd_device_id = ?')->execute(array($id));
		$db->prepare('DELETE FROM sdd_devices WHERE sdd_device_id = ?')->execute(array($id));
	}
	$db->prepare('DELETE FROM grm_group_members WHERE grm_grp_group_id=?')->execute(array($group_id));
	$db->prepare('DELETE FROM sbt_subscription_tiers WHERE sbt_subscription_tier_id=?')->execute(array($tier_id));
	$db->prepare("DELETE FROM cht_change_tracking WHERE cht_entity_type='subscription_tier' AND cht_entity_id=?")->execute(array($tier_id));
	$db->prepare('DELETE FROM grp_groups WHERE grp_group_id=?')->execute(array($group_id));
});
$gm = new GroupMember(NULL);
$gm->set('grm_grp_group_id', $group_id);
$gm->set('grm_foreign_key_id', (int)$member->key);
$gm->save();
SubscriptionTier::clearUserCache((int)$member->key);

section('a member adds a device as a web POST');
$previous_method = $_SERVER['REQUEST_METHOD'] ?? null;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/profile/dns_filtering/device_edit';
Access::$sapi = 'fpm-fcgi';
$session = SessionControl::get_instance();
$session->set_api_user((int)$member->key, null, 0);
harness_defer(function () use ($previous_method, $session) {
	Access::$sapi = PHP_SAPI;
	if ($previous_method === null) unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD'] = $previous_method;
	$session->clear_api_user();
});
$_POST = array(
	'device_name' => 'Phone', 'device_type' => 'ios', 'sdd_timezone' => 'America/New_York',
	'sdd_allow_device_edits' => 1,
);
require_once(PathHelper::getIncludePath('plugins/dns_filtering/logic/device_edit_logic.php'));
$error = null;
try {
	$result = device_edit_logic($_POST);
} catch (Throwable $e) {
	$error = get_class($e) . ': ' . $e->getMessage();
}
check($error === null, 'the add is not refused', (string)$error);
$rows = $db->prepare('SELECT sdd_device_id, sdd_device_name, sdd_resolver_uid FROM sdd_devices WHERE sdd_usr_user_id = ?');
$rows->execute(array((int)$member->key));
$rows = $rows->fetchAll(PDO::FETCH_ASSOC);
check(count($rows) === 1, 'exactly one device row, owned by the member: no ownerless row left behind', json_encode($rows));
check(!empty($rows[0]['sdd_resolver_uid']) && strpos((string)($rows[0]['sdd_device_name'] ?? ''), 'Phone') !== false,
	'it carries its name and resolver id');
$orphans = (int)$db->query("SELECT COUNT(*) FROM sdd_devices WHERE sdd_usr_user_id IS NULL AND sdd_create_time > now() - interval '5 minutes'")->fetchColumn();
check($orphans === 0, 'and no ownerless device was saved', (string)$orphans);
$_POST = array();

harness_finish();
