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
 *  - The service account is found by its id: an account at the old
 *    provisioning@<host> address is adopted once only when it owns the
 *    configured key, and a squatted address is not.
 *  - ensureDomainQuestion: creates once, reuses thereafter.
 *  - activateTasks: creates missing rows, resumes paused, idempotent.
 *
 * All touched global state (settings, created user/keys/question/tasks) is
 * snapshotted up front and restored in cleanup, so the deployment's real
 * provisioning configuration is unchanged by a run.
 *
 * Run: php plugins/server_manager/tests/provisioning_setup_test.php
 *
 * @version 1.4 - the service account by id: a new one has a random address, a squatted old address is
 *                not adopted, an owner of the configured key is adopted once, the id is used thereafter
 * @version 1.3 - the resume check follows TASK_CLASSES instead of naming a phase class
 * @version 1.2
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
	ProvisioningSetup::SERVICE_USER_SETTING,
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

// Every active pipeline key before the run: setup retires keys, and whatever
// it retires of the deployment's own is reactivated in cleanup.
$preexisting_active_key_ids = array();
foreach ($db->query("SELECT apk_api_key_id FROM apk_api_keys WHERE apk_is_active = TRUE AND apk_name = "
		. $db->quote(ProvisioningSetup::SERVICE_KEY_NAME))->fetchAll(PDO::FETCH_COLUMN) as $kid) {
	$preexisting_active_key_ids[] = (int)$kid;
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
ProvisioningSetup::writeSetting(ProvisioningSetup::SERVICE_USER_SETTING, '');

$r1 = ProvisioningSetup::setupApiCredentials();
if (!empty($r1['user_created'])) $cleanup_user_ids[] = $r1['user_id'];
if (!empty($r1['api_key_id'])) $cleanup_key_ids[] = $r1['api_key_id'];

check($r1['ok'] === true, 'setup reports ok');
check(!empty($r1['api_key_id']), 'api key minted');
check(ProvisioningSetup::readSetting('server_manager_getjoinery_api_url') === ProvisioningSetup::selfApiUrl(),
	'api url setting is self');
$pub1 = ProvisioningSetup::readSetting('server_manager_getjoinery_api_public_key');
$sec1 = ProvisioningSetup::readApiSecret();
check(strpos($pub1, 'public_') === 0, 'public key setting written');
check(strpos($sec1, 'secret_') === 0, 'secret key readable (decrypted) via readApiSecret');

// The secret is SecretBox-encrypted at rest — the raw setting is a ciphertext
// blob, never the plaintext. Only assertable where a secret_box_key exists:
// without one, encryptSecret() deliberately falls back to plaintext (the
// zero-config path), so on such a site these two checks would fail by design.
require_once(PathHelper::getIncludePath('includes/SecretBox.php'));
$has_box_key = true;
try { new SecretBox(); } catch (\Throwable $e) { $has_box_key = false; }
if ($has_box_key) {
	$sec_raw = ProvisioningSetup::readSetting('server_manager_getjoinery_api_secret_key');
	check(SecretBox::looksEncrypted($sec_raw), 'secret stored encrypted at rest');
	check(strpos($sec_raw, 'secret_') !== 0, 'raw stored secret is not plaintext');
} else {
	section('secret at-rest encryption checks skipped: no secret_box_key configured (zero-config plaintext path)');
}

$user = ProvisioningSetup::serviceUser();
check($user !== NULL && (int)$user->key === (int)$r1['user_id'], 'service user exists, found by the recorded id');
check((int)ProvisioningSetup::readSetting(ProvisioningSetup::SERVICE_USER_SETTING) === (int)$r1['user_id'],
	'the id is recorded in the managed setting');
check($user->get('usr_email') !== ProvisioningSetup::legacyServiceUserEmail()
	&& preg_match('/^provisioning-[0-9a-f]{12}@/', (string)$user->get('usr_email')) === 1,
	'a new account has a random address, not provisioning@<host>', (string)$user->get('usr_email'));
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
section('A squatted address is not adopted');
// ---------------------------------------------------------------------------

// An account made at the old address by somebody else, owning no pipeline key;
// the configured key belongs to some other account. The first setup after
// this release finds no recorded id. It must not hand the pipeline to the
// address holder.
$pst_key = function ($owner_id) use (&$cleanup_key_ids) {
	$k = new ApiKey(NULL);
	$k->set('apk_usr_user_id', (int)$owner_id);
	$k->set('apk_name', ProvisioningSetup::SERVICE_KEY_NAME);
	$k->set('apk_public_key', 'public_' . LibraryFunctions::random_string(16));
	$k->set('apk_secret_key', ApiKey::GenerateKey('secret_' . LibraryFunctions::random_string(16)));
	$k->set('apk_permission', ProvisioningSetup::SERVICE_KEY_PERMISSION);
	$k->set('apk_is_active', TRUE);
	$k->save();
	$k->load();
	$cleanup_key_ids[] = (int)$k->key;
	return $k;
};
$pst_configure = function ($key) {
	ProvisioningSetup::writeSetting('server_manager_getjoinery_api_url', ProvisioningSetup::selfApiUrl());
	ProvisioningSetup::writeSetting('server_manager_getjoinery_api_public_key', (string)$key->get('apk_public_key'));
	ProvisioningSetup::writeSetting('server_manager_getjoinery_api_secret_key', 'x');
	ProvisioningSetup::writeSetting(ProvisioningSetup::SERVICE_USER_SETTING, '');
};

$squatter = make_user('PstSquatter');
$elsewhere = make_user('PstKeyOwner');
ProvisioningSetup::$legacy_service_email = (string)$squatter->get('usr_email');
$stranded_key = $pst_key($elsewhere->key);
$pst_configure($stranded_key);

check(ProvisioningSetup::adoptableServiceUser() === null, 'an account at the old address that owns no configured key is not adoptable');
$sq = ProvisioningSetup::setupApiCredentials();
if (!empty($sq['user_created'])) $cleanup_user_ids[] = $sq['user_id'];
if (!empty($sq['api_key_id'])) $cleanup_key_ids[] = $sq['api_key_id'];
check(empty($sq['adopted']) && !empty($sq['user_created']) && (int)$sq['user_id'] !== (int)$squatter->key,
	'setup makes a new account rather than adopting the address holder', $sq['message']);
check((int)ProvisioningSetup::readSetting(ProvisioningSetup::SERVICE_USER_SETTING) === (int)$sq['user_id'],
	'and records the new account');
check(!empty($sq['api_key_id']) && (int)(new ApiKey($sq['api_key_id'], TRUE))->get('apk_usr_user_id') === (int)$sq['user_id'],
	'a fresh key is minted for it, as a rotation does');
check(!(new ApiKey($stranded_key->key, TRUE))->get('apk_is_active'),
	'and the configured key, which no trusted account owned, is retired');
check(ProvisioningSetup::readSetting('server_manager_getjoinery_api_public_key') !== (string)$stranded_key->get('apk_public_key'),
	'the pipeline now uses the new key');

// ---------------------------------------------------------------------------
section('An existing account that owns the key is adopted once, then found by id');
// ---------------------------------------------------------------------------

// dev and getjoinery: a Provisioning Service account made at the old address
// before the id was recorded, owning the configured key.
$existing = make_user('PstExisting', ProvisioningSetup::SERVICE_USER_PERMISSION);
ProvisioningSetup::$legacy_service_email = (string)$existing->get('usr_email');
$live_key = $pst_key($existing->key);
$pst_configure($live_key);

check(ProvisioningSetup::status()['api']['service_user_exists'] === true
	&& ProvisioningSetup::readSetting(ProvisioningSetup::SERVICE_USER_SETTING) === '',
	'the status page shows the account setup will adopt, and records nothing on a view');
$ad = ProvisioningSetup::setupApiCredentials();
check(!empty($ad['adopted']) && (int)$ad['user_id'] === (int)$existing->key && empty($ad['api_key_id']),
	'the first setup adopts it and mints nothing: the pipeline key is not stranded', $ad['message']);
check((int)ProvisioningSetup::readSetting(ProvisioningSetup::SERVICE_USER_SETTING) === (int)$existing->key,
	'its id is recorded');
check((bool)(new ApiKey($live_key->key, TRUE))->get('apk_is_active')
	&& ProvisioningSetup::readSetting('server_manager_getjoinery_api_public_key') === (string)$live_key->get('apk_public_key'),
	'and the configured key stays live');

$again = ProvisioningSetup::setupApiCredentials();
check(empty($again['adopted']) && (int)($again['user_id'] ?? 0) === (int)$existing->key, 'a second setup does not adopt again');

// Somebody now holds the old address: with the id recorded it is never consulted.
ProvisioningSetup::$legacy_service_email = (string)$squatter->get('usr_email');
$rot = ProvisioningSetup::setupApiCredentials(true);
if (!empty($rot['api_key_id'])) $cleanup_key_ids[] = $rot['api_key_id'];
check(empty($rot['user_created']) && (int)$rot['user_id'] === (int)$existing->key
	&& (int)(new ApiKey($rot['api_key_id'], TRUE))->get('apk_usr_user_id') === (int)$existing->key,
	'a rotation mints for the recorded account, whoever holds the old address');
ProvisioningSetup::$legacy_service_email = null;

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
check(count($t1['results']) === count(ProvisioningSetup::TASK_CLASSES), 'every task class handled');
foreach (ProvisioningSetup::TASK_CLASSES as $class => $name) {
	$rows = new MultiScheduledTask(array('task_class' => $class, 'deleted' => false));
	$rows->load();
	$active = false;
	foreach ($rows as $row) { $active = (bool)$row->get('sct_is_active'); break; }
	check($active, $name . ' is active after run');
}

$t2 = ProvisioningSetup::activateTasks();
check(count(array_filter($t2['results'], fn($r) => $r === 'already active')) === count(ProvisioningSetup::TASK_CLASSES),
	'second run reports already active');

// Pause one and confirm resume. The class is taken from TASK_CLASSES rather
// than named here: the pipeline's phases are not tasks, and a hardcoded name
// would go stale the next time that list changes.
$paused_class = (string)array_key_first(ProvisioningSetup::TASK_CLASSES);
$rows = new MultiScheduledTask(array('task_class' => $paused_class, 'deleted' => false));
$rows->load();
foreach ($rows as $row) { $row->set('sct_is_active', FALSE); $row->save(); break; }
$t3 = ProvisioningSetup::activateTasks();
check($t3['results'][$paused_class] === 'resumed', 'paused task is resumed',
	'paused: ' . $paused_class);

// ---------------------------------------------------------------------------
section('Provisioning is keyless — the plane mints no SSH key');
// ---------------------------------------------------------------------------

// The customer-cloud key setting and its minting are gone: a machine we create
// receives no key of ours. A regression that re-introduces either fails here.
check(!method_exists('ProvisioningSetup', 'ensureSshKey'),
	'ProvisioningSetup no longer mints a provisioning keypair');
$plugin_json = json_decode(file_get_contents(
	PathHelper::getIncludePath('plugins/server_manager/plugin.json')), true);
$setting_names = array_column($plugin_json['settings'] ?? [], 'name');
check(!in_array('server_manager_customer_cloud_ssh_key_path', $setting_names, true),
	'the customer-cloud SSH key setting is no longer declared');

// ---------------------------------------------------------------------------
section('CustomerCloudFulfillment provider');
// ---------------------------------------------------------------------------

require_once(PathHelper::getIncludePath('plugins/server_manager/includes/fulfillment_providers/CustomerCloudFulfillment.php'));
$provider = new CustomerCloudFulfillment();
check($provider->key() === 'customer_cloud', 'provider key matches the poll-task fork value');
// Two references, and what they mean. Whose cloud account the server is born
// on is the PRODUCT's decision — a buyer never chooses between them, because
// they are two products with two prices and two arrangements.
$picker = $provider->options();
check(count($picker) === 2, 'two picker options, one per hosting mode', implode(' | ', $picker));
check(CustomerCloudFulfillment::mode_for_ref(0) === 'customer'
	&& CustomerCloudFulfillment::mode_for_ref(1) === 'operator',
	'reference 0 is the buyer\'s own account, 1 is the operator\'s');
check(CustomerCloudFulfillment::mode_for_ref(99) === 'customer',
	'an unrecognised reference falls back to the buyer\'s own account, never to ours');

// The one requirement the line carries: the id of the site the buyer
// configured beforehand. Contributed by the provider, never attached by hand,
// whatever the domain-question setting says.
require_once(PathHelper::getIncludePath('plugins/store/data/products_class.php'));
$fake_product = new Product(NULL);
$reqs = $provider->extraRequirements($fake_product, 0);
check(count($reqs) === 1 && $reqs[0] instanceof ManagedSiteRequirement,
	'contributes ManagedSiteRequirement as the checkout requirement');
$reqs = $provider->extraRequirements($fake_product, 1);
check(count($reqs) === 1 && $reqs[0] instanceof ManagedSiteRequirement,
	'for either hosting mode');

// fulfill(): activates the draft the paid line names. The whole path —
// draft, cart, activation, refusal — is managed_site_purchase_test; here only
// the contract that a line with no draft is refused loudly, never silently.
require_once(PathHelper::getIncludePath('plugins/store/data/orders_class.php'));
require_once(PathHelper::getIncludePath('plugins/store/data/order_items_class.php'));
require_once(PathHelper::getIncludePath('plugins/server_manager/data/customer_cloud_provisions_class.php'));

class PstFulfillment extends CustomerCloudFulfillment {
	public static $alerts = array();
	protected function alert_activation_problem(User $user, OrderItem $order_item, string $reason): void {
		self::$alerts[] = $reason;
	}
}
$buyer = make_user('CcfBuyer');
$odi = new OrderItem(NULL);
$odi->set('odi_ord_order_id', 999999901);
$odi->set('odi_pro_product_id', 999999901);
$odi->set('odi_usr_user_id', $buyer->key);
$odi->set('odi_product_info', base64_encode(serialize(array('product_version' => 1))));
$odi->save();
$odi->load();
harness_register_row('odi_order_items', 'odi_order_item_id', $odi->key);

$pst = new PstFulfillment();
$f1 = $pst->fulfill($buyer, $fake_product, $odi, new Order(NULL), 0);
check(($f1['ref_id'] ?? null) === null, 'a paid line naming no draft activates nothing');
check(count(PstFulfillment::$alerts) === 1 && stripos(PstFulfillment::$alerts[0], 'no site draft') !== false,
	'and the operator is alerted rather than the row being invented', var_export(PstFulfillment::$alerts, true));
$none = new MultiCustomerCloudProvision(array('external_order_item_id' => (int)$odi->key, 'deleted' => false));
check((int)$none->count_all() === 0, 'no provision row is created from a line with no draft');

// ---------------------------------------------------------------------------
section('status reflects state');
// ---------------------------------------------------------------------------

$status = ProvisioningSetup::status();
check($status['api']['configured'] === true, 'status: api configured');
check($status['api']['is_self'] === true, 'status: api is self-store');
check($status['api']['service_user_exists'] === true, 'status: service user exists');
check($status['question']['exists'] === true, 'status: question exists');
check(count(array_filter($status['tasks'], fn($t) => $t['state'] === 'active'))
		=== count(ProvisioningSetup::TASK_CLASSES),
	'status: all tasks active');

// ---------------------------------------------------------------------------
section('agentStatus');
// ---------------------------------------------------------------------------

// The job-executing agent is a hard pipeline requirement: without one, jobs
// sit pending forever (the getjoinery VPS-A stall). status() must expose it.
check(array_key_exists('agent', $status), 'status: agent key present');

$agent_status = ProvisioningSetup::agentStatus();
check(isset($agent_status['present'], $agent_status['online']),
	'agentStatus returns present/online flags');

// A fresh heartbeat must classify as present+online; a stale one as offline.
require_once(PathHelper::getIncludePath('plugins/server_manager/data/agent_heartbeats_class.php'));
$hb = new AgentHeartbeat(NULL);
$hb->set('ahb_agent_name', 'harnesstest-agent-' . substr(md5(uniqid('', true)), 0, 6));
$hb->set('ahb_agent_version', '9.9.9');
$hb->set('ahb_status', 'ok');
$hb->set('ahb_last_heartbeat', gmdate('Y-m-d H:i:s'));
$hb->save();
$hb->load();
harness_register_row('ahb_agent_heartbeats', 'ahb_agent_heartbeat_id', (int)$hb->key);

$fresh = ProvisioningSetup::agentStatus();
check($fresh['present'] === true && $fresh['online'] === true,
	'with a fresh heartbeat in the table, status is present and online',
	json_encode($fresh));

// Classification itself, on the row (agentStatus reads the globally newest
// heartbeat, so a live dev agent would mask a stale fixture there).
check($hb->is_online() === true, 'a just-written heartbeat classifies online');
$hb->set('ahb_last_heartbeat', gmdate('Y-m-d H:i:s', time() - 3600));
$hb->save();
$hb->load();
check($hb->is_online() === false, 'an hour-old heartbeat classifies offline');

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
// Every service account a run makes is new (a random address), so each goes.
foreach ($cleanup_user_ids as $uid) {
	$db->prepare('DELETE FROM apk_api_keys WHERE apk_usr_user_id = ?')->execute(array($uid));
	$db->prepare('DELETE FROM usr_users WHERE usr_user_id = ?')->execute(array($uid));
}
ProvisioningSetup::$legacy_service_email = null;

// Reactivate any real pipeline keys the setup rotation deactivated.
foreach ($preexisting_active_key_ids as $kid) {
	$db->prepare('UPDATE apk_api_keys SET apk_is_active = TRUE WHERE apk_api_key_id = ?')
		->execute(array($kid));
}

}

harness_finish();
