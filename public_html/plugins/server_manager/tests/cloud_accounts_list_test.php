<?php
/** @joinery-test
 * name: cloud_accounts_list
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * Our cloud accounts as a list (the cloud_accounts_list spec, WP1-WP4), against
 * a fake provider in the test database:
 *
 *  - an account added by API key is checked with the provider first and saved
 *    sealed, with its name, company, scopes and test flag; a key the provider
 *    refuses saves nothing; the scopes it lacks are named with what each is for;
 *  - the default: never a Linode sign-in, never the test account while another
 *    is held, and the default cannot be removed; a removed entry keeps its row
 *    and loses its credential;
 *  - the account choice lists ours (the default first and chosen), then
 *    customers'; an expired sign-in is not offered and says why;
 *  - each server is reached with its own account's credential, a new server in
 *    our accounts that names none goes to the default, and one whose account
 *    was removed gets the reason instead;
 *  - a Linode sign-in adds an entry of ours only for a superadmin, and a
 *    reconnect refuses a different account;
 *  - the one operator token moves into the list (migration sm_019): an entry,
 *    the default, every server and transfer record pointed at it, the settings
 *    gone; run twice it moves nothing more; a token that cannot be unsealed is
 *    left in place.
 *
 * Run: php tests/run.php --only=plugins/server_manager/tests/cloud_accounts_list_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();

/** A provider account: what it says about itself, or a refusal. */
class FakeCloudAccountDriver {
	public $token;
	public $scopes;
	public $name;
	public $company;
	public $fail = 0;
	function __construct(string $token, array $scopes = array('*'), string $name = '', string $company = '') {
		$this->token = $token;
		$this->scopes = $scopes;
		$this->name = $name !== '' ? $name : 'user ' . $token;
		$this->company = $company;
	}
	public function tokenScopes(): array {
		if ($this->fail) { throw new CloudComputeException('unauthorized: Invalid Token', $this->fail); }
		return $this->scopes;
	}
	public function accountName(): string {
		if ($this->fail) { throw new CloudComputeException('unauthorized: Invalid Token', $this->fail); }
		return $this->name;
	}
	public function accountCompany(): string {
		return $this->company;
	}
}

$fakes = array();
OperatorCloudAccount::$driver_factory = function (string $token) use (&$fakes) {
	return $fakes[$token] ?? new FakeCloudAccountDriver($token);
};
harness_defer(function () { OperatorCloudAccount::$driver_factory = null; });

$db = DbConnector::get_instance()->get_db_link();
$tag = bin2hex(random_bytes(3));
// A clean slate in the test database: no account of ours, no default.
$db->exec("DELETE FROM oca_operator_cloud_accounts");
Setting::put(OperatorCloudAccount::DEFAULT_SETTING, '');
$super = make_user('Oca' . $tag, 10);
$member = make_user('OcaMember' . $tag, 0);
$uid = (int)$super->key;

$register = function (OperatorCloudAccount $oca) {
	harness_register_row('oca_operator_cloud_accounts', 'oca_operator_cloud_account_id', $oca->key);
	return $oca;
};
$provision = function (string $mode, ?OperatorCloudAccount $oca = null) use ($uid, $tag) {
	static $n = 0;
	$n++;
	$p = new CustomerCloudProvision(NULL);
	$p->set('cvp_origin', 'admin');
	$p->set('cvp_usr_user_id', $uid);
	$p->set('cvp_domain', 'oca-' . $tag . '-' . $n . '.example.com');
	$p->set('cvp_slug', 'oca-' . $tag . '-' . $n);
	$p->set('cvp_status', 'done');
	$p->set('cvp_provider', 'linode');
	$p->set('cvp_hosting_mode', $mode);
	$p->set('cvp_instance_id', '9' . $n);
	if ($oca) {
		$p->set('cvp_oca_operator_cloud_account_id', (int)$oca->key);
	}
	$p->save();
	$p->load();
	harness_register_row('cvp_customer_cloud_provisions', 'cvp_customer_cloud_provision_id', $p->key);
	return $p;
};
$raw_setting = function (string $name, ?string $value) use ($db) {
	$db->prepare("DELETE FROM stg_settings WHERE stg_name = ?")->execute(array($name));
	if ($value !== null) {
		$db->prepare("INSERT INTO stg_settings (stg_name, stg_value) VALUES (?, ?)")->execute(array($name, $value));
	}
};

// ---------------------------------------------------------------------------
section('An account added by its API key');

$fakes['tok-main'] = new FakeCloudAccountDriver('tok-main', array('linodes:read_write'), 'Main Co (user main)', 'Main Co');
$main = $register(OperatorCloudAccount::add_key('tok-main', $uid));
check($main->key > 0 && (string)$main->get('oca_method') === OperatorCloudAccount::METHOD_KEY, 'it is saved, by key');
$q = $db->prepare("SELECT oca_credential FROM oca_operator_cloud_accounts WHERE oca_operator_cloud_account_id = ?");
$q->execute(array($main->key));
$raw = (string)$q->fetchColumn();
check(strpos($raw, 'tok-main') === false && SecretBox::looksEncrypted($raw), 'the key is sealed at rest');
$main = new OperatorCloudAccount($main->key, TRUE);
check($main->token() === 'tok-main', 'and opens back to the key');
check($main->get('oca_account_name') === 'Main Co (user main)' && $main->get('oca_company') === 'Main Co'
	&& !$main->get('oca_is_test') && (int)$main->get('oca_added_by_usr_user_id') === $uid,
	'the provider\'s name and company are recorded, it is not the test account, and who added it');
$missing = $main->missing_scopes();
check(is_array($missing) && array_keys($missing) === array('account:read_write', 'firewall:read_only', 'volumes:read_only', 'ips:read_write', 'events:read_only')
	&& strpos($missing['ips:read_write'], 'swapping') !== false && strpos($missing['events:read_only'], 'Tier 2') !== false,
	'the scopes it lacks are named, each with what needs it', json_encode($missing));
check($main->can_create(), 'it can create servers');

$fakes['tok-bad'] = new FakeCloudAccountDriver('tok-bad');
$fakes['tok-bad']->fail = 401;
$before = (int)$db->query("SELECT count(*) FROM oca_operator_cloud_accounts")->fetchColumn();
$said = '';
try {
	OperatorCloudAccount::add_key('tok-bad', $uid);
} catch (OperatorCloudAccountException $e) {
	$said = $e->getMessage();
}
check(strpos($said, 'did not accept') !== false && (int)$db->query("SELECT count(*) FROM oca_operator_cloud_accounts")->fetchColumn() === $before,
	'a key the provider refuses is said and saves nothing: ' . $said);
$said = '';
try {
	OperatorCloudAccount::add_key('  ', $uid);
} catch (OperatorCloudAccountException $e) {
	$said = $e->getMessage();
}
check($said !== '', 'an empty key is refused');

$fakes['tok-test'] = new FakeCloudAccountDriver('tok-test', array('*'), 'user joinery-test', TestCloudCleanup::COMPANY);
$test = $register(OperatorCloudAccount::add_key('tok-test', $uid));
check((bool)$test->get('oca_is_test') && $test->missing_scopes() === array(), 'the disposable company marks the test account; "*" lacks nothing');
check(strpos($test->label(), '(test)') !== false, 'and its name says test');

// ---------------------------------------------------------------------------
section('The default');

check(OperatorCloudAccount::default_account() === null, 'none is set yet');
$conn = new OperatorCloudAccount(NULL);
$conn->set('oca_method', OperatorCloudAccount::METHOD_CONNECT);
$conn->set('oca_account_name', 'Somebody (user someone)');
$conn->set('oca_scopes', '*');
$conn->store_credential('tok-conn', gmdate('Y-m-d H:i:s', time() + 7200));
$conn->save();
$conn->load();
$register($conn);
check(strpos($conn->default_refusal(), 'two hours') !== false, 'a Linode sign-in cannot be the default: ' . $conn->default_refusal());
$said = '';
try {
	$conn->make_default();
} catch (OperatorCloudAccountException $e) {
	$said = $e->getMessage();
}
check($said !== '' && OperatorCloudAccount::default_id() === 0, 'and making it the default is refused');
check(strpos($test->default_refusal(), 'test account') !== false,
	'the test account cannot be the default while another account is held: ' . $test->default_refusal());
$main->make_default();
check(OperatorCloudAccount::default_id() === (int)$main->key && $main->is_default(), 'a main account by key can');
$said = '';
try {
	$main->remove();
} catch (OperatorCloudAccountException $e) {
	$said = $e->getMessage();
}
check(strpos($said, 'default') !== false && !(new OperatorCloudAccount($main->key, TRUE))->removed(), 'the default cannot be removed');

$spare = $register(OperatorCloudAccount::add_key('tok-spare', $uid));
$spare->remove();
$spare = new OperatorCloudAccount($spare->key, TRUE);
check($spare->removed() && trim((string)$spare->get('oca_credential')) === '' && !$spare->usable(),
	'a removed entry keeps its row and loses its credential');
check(strpos($spare->unusable_reason(), 'removed') !== false, 'and says it was removed');
check(!in_array((int)$spare->key, array_map(function ($a) { return (int)$a->key; }, OperatorCloudAccount::held()), true),
	'it is no longer held');

// ---------------------------------------------------------------------------
section('Choosing the account');

$cca = new CustomerCloudAccount(NULL);
$cca->set('cca_usr_user_id', (int)$member->key);
$cca->set('cca_provider', 'linode');
$cca->set('cca_status', 'active');
$cca->set('cca_account_name', 'Customer (user cust)');
$cca->storeToken(new OAuth2Token('tok-cust', null, gmdate('Y-m-d H:i:s', time() + 7200), 'linodes:read_write'));
$cca->save();
$cca->load();
harness_register_row('cca_customer_cloud_accounts', 'cca_customer_cloud_account_id', $cca->key);

$targets = HeldCloudAccounts::targets();
$keys = array_keys($targets['options']);
check(($keys[0] ?? '') === $main->choice_key() && $targets['default'] === $main->choice_key(),
	'ours come first, the default first of all, and it is the one chosen', json_encode($keys));
check(strpos($targets['options'][$main->choice_key()], '(ours, default)') !== false
	&& strpos($targets['options'][$test->choice_key()] ?? '', '(test) (ours)') !== false,
	'each of ours is named as the provider names it, and says it is ours', json_encode($targets['options']));
check(strpos($targets['options'][$conn->choice_key()] ?? '', 'signed in until') !== false, 'a sign-in says until when');
$cust_at = array_search('cca:' . $cca->key, $keys, true);
check($cust_at !== false && $cust_at > array_search($conn->choice_key(), $keys, true)
	&& strpos($targets['options']['cca:' . $cca->key], 'the customer\'s') !== false,
	'a customer\'s connected account comes after ours, and says whose');
check(!isset($targets['options'][$spare->choice_key()]), 'a removed entry is not offered');

$conn->set('oca_token_expires', gmdate('Y-m-d H:i:s', time() - 60));
$conn->save();
$targets = HeldCloudAccounts::targets();
$said = array_filter($targets['unusable'], function ($w) { return strpos($w, 'has expired') !== false; });
check(!isset($targets['options'][$conn->choice_key()]) && $said, 'an expired sign-in is not offered, and says why', json_encode($targets));

$r = HeldCloudAccounts::resolve($main->choice_key());
check($r['hosting_mode'] === 'operator' && (int)$r['oca']->key === (int)$main->key && $r['token'] === 'tok-main' && $r['owner_id'] === 0,
	'choosing ours is a server of ours in that account, made with its key');
$r = HeldCloudAccounts::resolve('cca:' . $cca->key);
check($r['hosting_mode'] === 'customer' && (int)$r['cca']->key === (int)$cca->key && $r['owner_id'] === (int)$member->key,
	'choosing a customer\'s is the customer\'s server');
foreach (array($conn->choice_key() => 'expired', 'operator' => 'Choose a cloud account', 'oca:999999999' => 'not on record') as $key => $want) {
	$said = '';
	try {
		HeldCloudAccounts::resolve($key);
	} catch (DisplayableUserException $e) {
		$said = $e->getMessage();
	}
	check(strpos($said, $want) !== false, 'choosing ' . $key . ' is refused: ' . $said);
}

// ---------------------------------------------------------------------------
section('Each server uses its own account');

$p_main = $provision('operator', $main);
$p_test = $provision('operator', $test);
check(HeldCloudAccounts::provision_token($p_main)['token'] === 'tok-main' && HeldCloudAccounts::provision_token($p_test)['token'] === 'tok-test',
	'each server is reached with its own account\'s key');
check(NodeReverseDns::driverForProvision($p_test)->token === 'tok-test', 'reverse DNS too');

$p_new = $provision('operator');
check((int)$p_new->get('cvp_oca_operator_cloud_account_id') === (int)$main->key, 'a new server of ours that names no account goes to the default');
$p_cust = $provision('customer');
check(!(int)$p_cust->get('cvp_oca_operator_cloud_account_id'), 'a customer\'s server is never put in one of ours');
$said = '';
try {
	$p_cust->set('cvp_oca_operator_cloud_account_id', (int)$main->key);
	$p_cust->set('cvp_cca_customer_cloud_account_id', (int)$cca->key);
	$p_cust->save();
} catch (CustomerCloudProvisionException $e) {
	$said = $e->getMessage();
}
check(strpos($said, 'one cloud account') !== false, 'a server is never in two accounts at once');

$fakes['tok-gone'] = new FakeCloudAccountDriver('tok-gone', array('*'), 'Gone Co (user gone)');
$gone = $register(OperatorCloudAccount::add_key('tok-gone', $uid));
$p_gone = $provision('operator', $gone);
$gone->remove();
$held = HeldCloudAccounts::provision_token($p_gone);
check($held['token'] === '' && strpos($held['reason'], 'removed') !== false, 'a server whose account was removed gets the reason, never another key');
$said = '';
try {
	NodeReverseDns::driverForProvision($p_gone);
} catch (NodeReverseDnsException $e) {
	$said = $e->getMessage();
}
check(strpos($said, 'removed') !== false && strpos($said, 'tok-') === false, 'reverse DNS says so too, and shows no key');

$second = $register(OperatorCloudAccount::add_key('tok-second', $uid));
$p_second = $provision('operator', $second);
check(CloudAccounts::for_provision($p_test) === CloudAccounts::TEST && CloudAccounts::for_provision($p_main) === CloudAccounts::MAIN
	&& CloudAccounts::for_provision($p_second) === CloudAccounts::MAIN,
	'the dashboard tab follows the account: test on Test, a main and a second main on Main');

// ---------------------------------------------------------------------------
section('A Linode sign-in adds an entry of ours');

$consumer = new OperatorCloudConsumer();
$grant = new OAuth2Token('tok-signin', null, gmdate('Y-m-d H:i:s', time() + 7200), 'linodes:read_write');
$fakes['tok-signin'] = new FakeCloudAccountDriver('tok-signin', array('linodes:read_write'), 'Friend (user friend)');
$back = $consumer->onTokenGranted($grant, array('user_id' => (int)$member->key, 'account_id' => 0));
check(strpos($back, 'connect_error=permission') !== false, 'only for a superadmin');
$back = $consumer->onTokenGranted($grant, array('user_id' => $uid, 'account_id' => 0));
check(preg_match('/connected=(\d+)/', $back, $m) === 1, 'a superadmin\'s sign-in is saved: ' . $back);
$signin = $register(new OperatorCloudAccount((int)($m[1] ?? 0), TRUE));
check((string)$signin->get('oca_method') === OperatorCloudAccount::METHOD_CONNECT && $signin->token() === 'tok-signin'
	&& trim((string)$signin->get('oca_token_expires')) !== '' && $signin->get('oca_account_name') === 'Friend (user friend)'
	&& $signin->usable(), 'as a sign-in of ours, sealed, with its two hours and its name');
$fakes['tok-other'] = new FakeCloudAccountDriver('tok-other', array('*'), 'Stranger (user stranger)');
$back = $consumer->onTokenGranted(new OAuth2Token('tok-other', null, gmdate('Y-m-d H:i:s', time() + 7200), ''),
	array('user_id' => $uid, 'account_id' => (int)$signin->key));
check(strpos($back, 'connect_error=other_account') !== false && (new OperatorCloudAccount($signin->key, TRUE))->token() === 'tok-signin',
	'a reconnect to a different account is refused and changes nothing');

// ---------------------------------------------------------------------------
section('The one operator token moves into the list');

Setting::put(OperatorCloudAccount::DEFAULT_SETTING, '');
$legacy_sealed = (new SecretBox())->seal(OperatorCloudAccount::LOCATOR, 'tok-legacy');
$raw_setting('server_manager_operator_cloud_token', $legacy_sealed);
$raw_setting('server_manager_operator_cloud_token_scopes', json_encode(array(
	'token' => substr(hash('sha256', 'tok-legacy'), 0, 16), 'scopes' => array('linodes:read_write', 'ips:read_write'))));
$raw_setting('server_manager_operator_cloud_account', 'Legacy Co (user legacy)');
$raw_setting('server_manager_operator_cloud_company', 'Legacy Co');
$p_legacy = $provision('operator');
$p_handed = $provision('transferred');
check(!(int)$p_legacy->get('cvp_oca_operator_cloud_account_id'), 'before: a server of ours names no account (no default to stamp)');
$mtr = new MachineTransfer(NULL);
$mtr->set('mtr_provider', 'linode');
$mtr->set('mtr_instance_id', 'oca-' . $tag);
$mtr->set('mtr_account', 'operator');
$mtr->save();
$mtr->load();
harness_register_row('mtr_machine_transfers', 'mtr_machine_transfer_id', $mtr->key);

$said = OperatorCloudAccount::migrate_operator_token($db);
$q = $db->prepare("SELECT oca_operator_cloud_account_id FROM oca_operator_cloud_accounts WHERE oca_account_name = ?");
$q->execute(array('Legacy Co (user legacy)'));
$ids = $q->fetchAll(PDO::FETCH_COLUMN);
$legacy = $register(new OperatorCloudAccount((int)($ids[0] ?? 0), TRUE));
check(count($ids) === 1 && $legacy->token() === 'tok-legacy' && (string)$legacy->get('oca_method') === OperatorCloudAccount::METHOD_KEY,
	'the token is an entry by key: ' . $said);
check($legacy->get('oca_company') === 'Legacy Co' && !$legacy->get('oca_is_test')
	&& $legacy->granted_scopes() === array('linodes:read_write', 'ips:read_write'),
	'with the name, company and scopes the settings held');
check(OperatorCloudAccount::default_id() === (int)$legacy->key, 'it is the default');
check((int)(new CustomerCloudProvision($p_legacy->key, TRUE))->get('cvp_oca_operator_cloud_account_id') === (int)$legacy->key
	&& (int)(new CustomerCloudProvision($p_handed->key, TRUE))->get('cvp_oca_operator_cloud_account_id') === (int)$legacy->key,
	'every server of ours that named no account, and one handed on from it, points at it');
check((int)(new CustomerCloudProvision($p_main->key, TRUE))->get('cvp_oca_operator_cloud_account_id') === (int)$main->key,
	'a server that already names an account keeps it');
check((string)(new MachineTransfer($mtr->key, TRUE))->get('mtr_account') === 'oca:' . (int)$legacy->key, 'and so does the transfer watch\'s record');
$left = $db->prepare("SELECT count(*) FROM stg_settings WHERE stg_name = ANY (string_to_array(?, ','))");
$left->execute(array(implode(',', OperatorCloudAccount::RETIRED_SETTINGS)));
check((int)$left->fetchColumn() === 0, 'the four settings are gone');

$said = OperatorCloudAccount::migrate_operator_token($db);
$q->execute(array('Legacy Co (user legacy)'));
check(strpos($said, 'no operator cloud token was set') !== false && count($q->fetchAll(PDO::FETCH_COLUMN)) === 1,
	'run again, it moves nothing more: ' . $said);

// A token whose scopes record describes another token: the scopes are unknown, not borrowed.
$raw_setting('server_manager_operator_cloud_token', (new SecretBox())->seal(OperatorCloudAccount::LOCATOR, 'tok-legacy-2'));
$raw_setting('server_manager_operator_cloud_token_scopes', json_encode(array('token' => 'ffffffffffffffff', 'scopes' => array('*'))));
$raw_setting('server_manager_operator_cloud_account', 'Second legacy');
$raw_setting('server_manager_operator_cloud_company', TestCloudCleanup::COMPANY);
OperatorCloudAccount::migrate_operator_token($db);
$q->execute(array('Second legacy'));
$legacy2 = $register(new OperatorCloudAccount((int)($q->fetchColumn() ?: 0), TRUE));
check($legacy2->key && $legacy2->granted_scopes() === null && (bool)$legacy2->get('oca_is_test'),
	'a scopes record for another token is not borrowed, and the test company marks the test account');
check(OperatorCloudAccount::default_id() === (int)$legacy->key, 'the default already held stays the default');

// A token that cannot be unsealed is left in place.
$dead = substr($legacy_sealed, 0, -6) . (substr($legacy_sealed, -6, 1) === 'A' ? 'B' : 'A') . substr($legacy_sealed, -5);
$raw_setting('server_manager_operator_cloud_token', $dead);
$said = OperatorCloudAccount::migrate_operator_token($db);
$still = $db->prepare("SELECT count(*) FROM stg_settings WHERE stg_name = 'server_manager_operator_cloud_token'");
$still->execute();
check(strpos($said, 'cannot be unsealed') !== false && (int)$still->fetchColumn() === 1,
	'a token that cannot be unsealed is left where it is for the health page: ' . $said);
foreach (OperatorCloudAccount::RETIRED_SETTINGS as $name) {
	$raw_setting($name, null);
}

// ---------------------------------------------------------------------------
section('A test account is the default only where nothing else is held');

Setting::put(OperatorCloudAccount::DEFAULT_SETTING, '');
foreach (OperatorCloudAccount::held() as $oca) {
	if (!$oca->get('oca_is_test')) {
		$oca->remove();
	}
}
$test = new OperatorCloudAccount($test->key, TRUE);
check($test->default_refusal() === '', 'with no other account held, the test account may be the default (dev)');
$test->make_default();
check(CloudAccounts::plane_account() === CloudAccounts::TEST, 'and a box made by hand is then a test box');
Setting::put(OperatorCloudAccount::DEFAULT_SETTING, '');

harness_finish();
