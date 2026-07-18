<?php
/** @joinery-test
 * name: provisioning_setup
 * tier: db
 * env: any
 * needs: []
 */
/**
 * ProvisioningSetup engine tests — the one-click activation surface behind
 * /admin/server_manager/provisioning_setup:
 *
 *  - writeSetting/readSetting round-trip (create + update).
 *  - setupApiCredentials: mints service user + key + settings; idempotent
 *    when configured; rotation retires the old key and updates settings.
 *  - ensureDomainQuestion: creates once, reuses thereafter.
 *  - activateTasks: creates missing rows, resumes paused, idempotent.
 *
 * All touched global state (settings, created user/keys/question/tasks) is
 * snapshotted up front and restored in cleanup, so the deployment's real
 * provisioning configuration is unchanged by a run.
 *
 * Run: php plugins/server_manager/tests/provisioning_setup_test.php
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('plugins/server_manager/includes/ProvisioningSetup.php'));

$db = DbConnector::get_instance()->get_db_link();

// ---------------------------------------------------------------------------
// Snapshot global state we will touch
// ---------------------------------------------------------------------------

$snapshot_settings = array(
	'server_manager_getjoinery_api_url',
	'server_manager_getjoinery_api_public_key',
	'server_manager_getjoinery_api_secret_key',
	'server_manager_provisioning_domain_question_id',
);
$saved = array();
foreach ($snapshot_settings as $name) {
	$saved[$name] = ProvisioningSetup::readSetting($name);
}

// Task rows state before the test: id => was_active, plus which exist at all.
$task_state_before = array();
foreach (array_keys(ProvisioningSetup::TASK_CLASSES) as $class) {
	$rows = new MultiScheduledTask(array('task_class' => $class, 'deleted' => false));
	$rows->load();
	foreach ($rows as $row) {
		$task_state_before[$class] = array('id' => (int)$row->key, 'active' => (bool)$row->get('sct_is_active'));
		break;
	}
}

$service_user_before = User::GetByEmail(ProvisioningSetup::serviceUserEmail());

// Any pre-existing active pipeline keys — setup rotation deactivates them, so
// remember them for reactivation in cleanup.
$preexisting_active_key_ids = array();
if ($service_user_before !== NULL) {
	$rows = new MultiApiKey(array('user_id' => (int)$service_user_before->key));
	$rows->load();
	foreach ($rows as $row) {
		if ($row->get('apk_name') === ProvisioningSetup::SERVICE_KEY_NAME && $row->get('apk_is_active')) {
			$preexisting_active_key_ids[] = (int)$row->key;
		}
	}
}

$cleanup_user_ids = array();
$cleanup_key_ids = array();
$cleanup_question_ids = array();

try {

// ---------------------------------------------------------------------------
section('writeSetting / readSetting round-trip');
// ---------------------------------------------------------------------------

$test_setting = 'server_manager_zz_test_setting';
$db->prepare('DELETE FROM stg_settings WHERE stg_name = ?')->execute(array($test_setting));

ProvisioningSetup::writeSetting($test_setting, 'first');
check(ProvisioningSetup::readSetting($test_setting) === 'first', 'create writes a new row');

ProvisioningSetup::writeSetting($test_setting, 'second');
check(ProvisioningSetup::readSetting($test_setting) === 'second', 'update overwrites in place');

$stmt = $db->prepare('SELECT COUNT(*) FROM stg_settings WHERE stg_name = ?');
$stmt->execute(array($test_setting));
check((int)$stmt->fetchColumn() === 1, 'no duplicate rows created');

$db->prepare('DELETE FROM stg_settings WHERE stg_name = ?')->execute(array($test_setting));

// ---------------------------------------------------------------------------
section('setupApiCredentials');
// ---------------------------------------------------------------------------

// Start from an unconfigured state regardless of the deployment's real state.
ProvisioningSetup::writeSetting('server_manager_getjoinery_api_url', '');
ProvisioningSetup::writeSetting('server_manager_getjoinery_api_public_key', '');
ProvisioningSetup::writeSetting('server_manager_getjoinery_api_secret_key', '');

$r1 = ProvisioningSetup::setupApiCredentials();
if (!empty($r1['user_created'])) $cleanup_user_ids[] = $r1['user_id'];
if (!empty($r1['api_key_id'])) $cleanup_key_ids[] = $r1['api_key_id'];

check($r1['ok'] === true, 'setup reports ok');
check(!empty($r1['api_key_id']), 'api key minted');
check(ProvisioningSetup::readSetting('server_manager_getjoinery_api_url') === ProvisioningSetup::selfApiUrl(),
	'api url setting is self');
$pub1 = ProvisioningSetup::readSetting('server_manager_getjoinery_api_public_key');
$sec1 = ProvisioningSetup::readSetting('server_manager_getjoinery_api_secret_key');
check(strpos($pub1, 'public_') === 0, 'public key setting written');
check(strpos($sec1, 'secret_') === 0, 'secret key setting written');

$user = User::GetByEmail(ProvisioningSetup::serviceUserEmail());
check($user !== NULL, 'service user exists');
check((int)$user->get('usr_permission') === 5, 'service user permission is 5 (cross-user API read)');
check((bool)$user->get('usr_password_recovery_disabled'), 'service user password recovery disabled');

$key = new ApiKey($r1['api_key_id'], TRUE);
check((int)$key->get('apk_usr_user_id') === (int)$user->key, 'key belongs to service user');
check((int)$key->get('apk_permission') === 3, 'key capability is read+write (3)');
check($key->get('apk_secret_key') !== $sec1, 'stored secret is hashed, not plaintext');
check($key->check_secret_key($sec1) === true, 'key verifies the plaintext secret');

$r2 = ProvisioningSetup::setupApiCredentials();
check(empty($r2['api_key_id']), 'second run is a no-op');
check(ProvisioningSetup::readSetting('server_manager_getjoinery_api_public_key') === $pub1,
	'settings unchanged on no-op');

$r3 = ProvisioningSetup::setupApiCredentials(true);
if (!empty($r3['api_key_id'])) $cleanup_key_ids[] = $r3['api_key_id'];
check(!empty($r3['api_key_id']) && $r3['api_key_id'] !== $r1['api_key_id'], 'rotation mints a new key');
check(ProvisioningSetup::readSetting('server_manager_getjoinery_api_public_key') !== $pub1,
	'rotation updates the public key setting');
$old_key = new ApiKey($r1['api_key_id'], TRUE);
check(!$old_key->get('apk_is_active'), 'rotation deactivates the old key');

// ---------------------------------------------------------------------------
section('ensureDomainQuestion');
// ---------------------------------------------------------------------------

ProvisioningSetup::writeSetting('server_manager_provisioning_domain_question_id', '');

$q1 = ProvisioningSetup::ensureDomainQuestion();
if (!empty($q1['created'])) $cleanup_question_ids[] = $q1['question_id'];
check($q1['created'] === true && $q1['question_id'] > 0, 'question created');
check((int)ProvisioningSetup::readSetting('server_manager_provisioning_domain_question_id') === $q1['question_id'],
	'setting holds the question id');

$question = new Question($q1['question_id'], TRUE);
check((int)$question->get('qst_type') === Question::TYPE_SHORT_TEXT && (bool)$question->get('qst_is_required'),
	'question is required short-text');

$q2 = ProvisioningSetup::ensureDomainQuestion();
check($q2['created'] === false && $q2['question_id'] === $q1['question_id'], 'second run reuses the question');

check(ProvisioningSetup::attachedProducts($q1['question_id']) === array(), 'no products attached to a fresh question');

// ---------------------------------------------------------------------------
section('activateTasks');
// ---------------------------------------------------------------------------

$t1 = ProvisioningSetup::activateTasks();
check(count($t1['results']) === 3, 'all three tasks handled');
foreach (ProvisioningSetup::TASK_CLASSES as $class => $name) {
	$rows = new MultiScheduledTask(array('task_class' => $class, 'deleted' => false));
	$rows->load();
	$active = false;
	foreach ($rows as $row) { $active = (bool)$row->get('sct_is_active'); break; }
	check($active, $name . ' is active after run');
}

$t2 = ProvisioningSetup::activateTasks();
check(count(array_filter($t2['results'], fn($r) => $r === 'already active')) === 3,
	'second run reports already active');

// Pause one and confirm resume.
$rows = new MultiScheduledTask(array('task_class' => 'PollHostingOrders', 'deleted' => false));
$rows->load();
foreach ($rows as $row) { $row->set('sct_is_active', FALSE); $row->save(); break; }
$t3 = ProvisioningSetup::activateTasks();
check($t3['results']['PollHostingOrders'] === 'resumed', 'paused task is resumed');

// ---------------------------------------------------------------------------
section('status reflects state');
// ---------------------------------------------------------------------------

$status = ProvisioningSetup::status();
check($status['api']['configured'] === true, 'status: api configured');
check($status['api']['is_self'] === true, 'status: api is self-store');
check($status['api']['service_user_exists'] === true, 'status: service user exists');
check($status['question']['exists'] === true, 'status: question exists');
check(count(array_filter($status['tasks'], fn($t) => $t['state'] === 'active')) === 3,
	'status: all tasks active');

} finally {

// ---------------------------------------------------------------------------
// Restore global state
// ---------------------------------------------------------------------------

foreach ($saved as $name => $value) {
	ProvisioningSetup::writeSetting($name, $value);
}

// Remove task rows the test created; restore prior active flags on the rest.
foreach (array_keys(ProvisioningSetup::TASK_CLASSES) as $class) {
	$rows = new MultiScheduledTask(array('task_class' => $class, 'deleted' => false));
	$rows->load();
	foreach ($rows as $row) {
		if (!isset($task_state_before[$class])) {
			$db->prepare('DELETE FROM sct_scheduled_tasks WHERE sct_scheduled_task_id = ?')
				->execute(array((int)$row->key));
		} elseif ((bool)$row->get('sct_is_active') !== $task_state_before[$class]['active']) {
			$row->set('sct_is_active', $task_state_before[$class]['active']);
			$row->save();
		}
		break;
	}
}

foreach ($cleanup_question_ids as $qid) {
	$db->prepare('DELETE FROM qst_questions WHERE qst_question_id = ?')->execute(array($qid));
}
foreach ($cleanup_key_ids as $kid) {
	$db->prepare('DELETE FROM apk_api_keys WHERE apk_api_key_id = ?')->execute(array($kid));
}
// Only delete the service user if this run created it.
if ($service_user_before === NULL) {
	foreach ($cleanup_user_ids as $uid) {
		$db->prepare('DELETE FROM usr_users WHERE usr_user_id = ?')->execute(array($uid));
	}
}

// Reactivate any real pipeline keys the setup rotation deactivated.
foreach ($preexisting_active_key_ids as $kid) {
	$db->prepare('UPDATE apk_api_keys SET apk_is_active = TRUE WHERE apk_api_key_id = ?')
		->execute(array($kid));
}

}

harness_finish();
