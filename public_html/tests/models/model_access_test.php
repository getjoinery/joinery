<?php
/** @joinery-test
 * name: model_access
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * Models declare a class, and the class decides who may read, change or delete
 * their rows (spec declared_permissions 3.2, 3.3; docs/permissions.md).
 *
 * - access_level() reads the class and the per-verb exceptions; undeclared is keys.
 * - can_read() / can_write() / can_delete(): the owner, else the level.
 * - $owner_column = false makes a model level-only.
 * - A column stricter than its model refuses a change below its level; an
 *   unchanged value and the owner pass.
 * - The write paths check on a web request: a refusal throws, records
 *   access_refused and logs a [WRITE_REFUSED] line naming the code that wrote.
 *   CLI, server_initiated_write() and rule_checked_write() are exempt.
 * - updateColumns() checks the owner column and the stricter columns.
 *
 * Run: php tests/run.php --filter=model_access
 *
 * @version 1.4 - the CLI rule as it is: checked while a person is signed in, under the harness
 * @version 1.3 - a refused write always throws: the log mode is gone
 * @version 1.2 - the surfaces: the REST shape, FormWriter, the admin table, the buttons
 * @version 1.1 - updateColumns() asks the row's own rule; a collection's assert_can_read()
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

/** A session stand-in: the checks ask only these two. */
class ModelAccessSession {
	public $permission; public $user_id;
	function __construct($permission, $user_id) { $this->permission = $permission; $this->user_id = $user_id; }
	function get_permission() { return $this->permission; }
	function get_user_id() { return $this->user_id; }
}

/** A model that declares nothing. */
class ModelAccessUndeclared extends SystemBase {
	public static $prefix = 'mau';
	public static $tablename = 'mau_none';
	public static $pkey_column = 'mau_none_id';
	public static $field_specifications = array('mau_none_id' => array('type' => 'int8'));
}

$db = DbConnector::get_instance()->get_db_link();
$owner = make_user(strtoupper(LibraryFunctions::random_string(6)) . 'MO', 0);
$other = make_user(strtoupper(LibraryFunctions::random_string(6)) . 'MT', 0);
$admin = make_user(strtoupper(LibraryFunctions::random_string(6)) . 'MA', 8);

$address = new Address(NULL);
$address->set('usa_usr_user_id', $owner->key);
$address->set('usa_address1', '1 Test Way');
$address->save();
harness_register_model('Address', $address->key);
$address = new Address($address->key, TRUE);

section('levels come from the class and its exceptions');
check(Address::access_level('read') === 6 && Address::access_level('write') === 8
	&& Address::access_level('soft_delete') === 8 && Address::access_level('permanent_delete') === 9,
	'Address is personal: 6, 8, 8, 9');
check(Message::access_level('read') === 8 && Message::access_level('write') === 8, 'Message reads another person\'s at 8 (Q13)');
check(AdminMenu::access_level('read') === 8 && AdminMenu::access_level('write') === 10, 'AdminMenu is config with a write exception of 10');
check(ModelAccessUndeclared::access_level('read') === 10, 'a model with no class behaves as keys');
$threw = false;
try { Address::access_level('delete'); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, 'an unknown verb throws');

section('the owner, else the level');
check($address->can_read(new ModelAccessSession(0, $owner->key)) && $address->can_write(new ModelAccessSession(0, $owner->key))
	&& $address->can_delete(new ModelAccessSession(0, $owner->key)), 'the owner reads, changes and deletes their own row');
check(!$address->can_read(new ModelAccessSession(0, $other->key)), 'another member does not read it');
check(!$address->can_read(new ModelAccessSession(5, $other->key)), 'a 5 does not read personal data');
check($address->can_read(new ModelAccessSession(6, $other->key)) && !$address->can_write(new ModelAccessSession(6, $other->key)),
	'a 6 reads it and does not change it');
check($address->can_write(new ModelAccessSession(8, $admin->key)) && $address->can_delete(new ModelAccessSession(8, $admin->key))
	&& !$address->can_delete(new ModelAccessSession(8, $admin->key), true), 'an 8 changes and soft-deletes it, and does not erase it');
check($address->can_delete(new ModelAccessSession(9, $admin->key), true), 'a 9 erases it');

section('a model whose user column records who acted is level-only');
$log = new EventLog(NULL);
$log->set('evl_usr_user_id', $owner->key);
check(EventLog::access_owner_column() === null, 'EventLog has no owner column');
check(!$log->can_read(new ModelAccessSession(0, $owner->key)), 'the user it names does not read it');
check(Address::access_owner_column() === 'usa_usr_user_id', 'the conventional column is the owner otherwise');

section('a column stricter than its model');
$target = new User($other->key, TRUE);
check(User::column_access_class('usr_password') === 'restricted' && User::column_access_level('usr_password', 'write') === 9,
	'usr_password is restricted: 9 to change');
check(User::column_access_class('usr_totp_secret') === 'keys', 'a sealed column is keys');
$as8 = array('current_user_id' => $admin->key, 'current_user_permission' => 8);
$target->set('usr_password', $target->get('usr_password'));
$refused = null;
try { $target->authenticate_columns($as8); } catch (SystemAuthenticationError $e) { $refused = $e->getMessage(); }
check($refused === null, 'an unchanged value is ignored (a stale form)');
$target->set('usr_password', 'not-a-real-hash');
try { $target->authenticate_columns($as8); } catch (SystemAuthenticationError $e) { $refused = $e->getMessage(); }
check($refused !== null && strpos($refused, 'usr_password') !== false && strpos($refused, '9') !== false,
	'an 8 changing it is refused, naming the column and the level', (string)$refused);
$refused = null;
try { $target->authenticate_columns(array('current_user_id' => $other->key, 'current_user_permission' => 0)); }
catch (SystemAuthenticationError $e) { $refused = $e->getMessage(); }
check($refused === null, 'the owner changes their own');

// ---- the write paths, as a web request ----
$log_file = harness_scratch_dir('model_access') . '/error.log';
$previous_log = ini_set('error_log', $log_file);
$previous_method = $_SERVER['REQUEST_METHOD'] ?? null;
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/test/model_access?secret=1';
Access::$sapi = 'fpm-fcgi';
$session = SessionControl::get_instance();
harness_defer(function () use ($previous_log, $previous_method, $session) {
	Access::$sapi = PHP_SAPI;
	if ($previous_method === null) unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD'] = $previous_method;
	$session->clear_api_user();
	ini_set('error_log', $previous_log);
});
function ma_log_lines($file) {
	clearstatcache();
	return is_file($file) ? array_values(array_filter(explode("\n", (string)file_get_contents($file)), function ($l) {
		return strpos($l, '[WRITE_REFUSED]') !== false; })) : array();
}
function ma_refusals($user_id, $after) {
	global $db;
	$q = $db->prepare("SELECT evl_event_log_id, evl_note FROM evl_event_logs WHERE evl_usr_user_id = ? AND evl_event = 'access_refused' AND evl_event_log_id > ?");
	$q->execute(array($user_id, $after));
	$rows = $q->fetchAll(PDO::FETCH_ASSOC);
	foreach ($rows as $r) harness_register_row('evl_event_logs', 'evl_event_log_id', $r['evl_event_log_id']);
	return $rows;
}
$mark = (int)$db->query('SELECT COALESCE(MAX(evl_event_log_id), 0) FROM evl_event_logs')->fetchColumn();

section('a refused write throws, and its line names it');
$session->set_api_user($other->key, null, 0);
$city_before = (new Address($address->key, TRUE))->get('usa_city');
$a = new Address($address->key, TRUE);
$a->set('usa_city', 'Refused first');
$threw = null;
try { $a->save(); } catch (SystemAuthenticationError $e) { $threw = $e->getMessage(); }
check($threw !== null && strpos($threw, 'needs level 8') !== false, 'save() throws', (string)$threw);
check((new Address($address->key, TRUE))->get('usa_city') === $city_before, 'and the row is unchanged');
$lines = ma_log_lines($log_file);
check(count($lines) === 1, 'one [WRITE_REFUSED] line', implode("\n", $lines));
check(isset($lines[0]) && strpos($lines[0], 'write Address#' . $address->key . ' for user ' . $other->key . ' (level 0, needs 8)') !== false
	&& strpos($lines[0], 'POST /test/model_access ') !== false && strpos($lines[0], 'secret') === false
	&& strpos($lines[0], 'tests/models/model_access_test.php:') !== false,
	'it names the write, the user, both levels, the path without its query, and the code that wrote', $lines[0] ?? '');

section('the owner, the server and a rule that already decided are not refused');
$session->set_api_user($owner->key, null, 0);
$a = new Address($address->key, TRUE);
$a->set('usa_city', 'Owner');
$a->save();
$session->set_api_user($other->key, null, 0);
SystemBase::server_initiated_write(function () use ($address) {
	$b = new Address($address->key, TRUE); $b->set('usa_city', 'Server'); $b->save();
});
SystemBase::rule_checked_write(function () use ($address) {
	$b = new Address($address->key, TRUE); $b->set('usa_city', 'Rule'); $b->save();
});
check((new Address($address->key, TRUE))->get('usa_city') === 'Rule' && count(ma_log_lines($log_file)) === 1,
	'all three write, and none is refused');

section('every write path refuses, and each is recorded');
$a = new Address($address->key, TRUE);
$a->set('usa_city', 'Refused');
$threw = null;
try { $a->save(); } catch (SystemAuthenticationError $e) { $threw = $e->getMessage(); }
check($threw !== null && strpos($threw, 'needs level 8') !== false, 'save() throws', (string)$threw);
check((new Address($address->key, TRUE))->get('usa_city') === 'Rule', 'the row is unchanged');
$threw = null;
try { (new User($owner->key, TRUE))->soft_delete(); } catch (SystemAuthenticationError $e) { $threw = $e->getMessage(); }
check($threw !== null && !(new User($owner->key, TRUE))->get('usr_delete_time'), 'soft_delete() of another user throws and deletes nothing');
$threw = null;
try { Address::updateColumns($address->key, array('usa_city' => 'Columns')); } catch (SystemAuthenticationError $e) { $threw = $e->getMessage(); }
check($threw !== null && (new Address($address->key, TRUE))->get('usa_city') === 'Rule', 'updateColumns() throws and writes nothing');
$threw = null;
try { (new Address($address->key, TRUE))->permanent_delete(); } catch (SystemAuthenticationError $e) { $threw = $e->getMessage(); }
check($threw !== null && (new Address($address->key, TRUE))->key, 'permanent_delete() throws and erases nothing');
$rows = ma_refusals($other->key, $mark);
check(count($rows) === 5, 'five access_refused events, the first section\'s among them', json_encode($rows));

section('updateColumns() reads the owner and the stricter columns');
$session->set_api_user($owner->key, null, 0);
Address::updateColumns($address->key, array('usa_city' => 'Owner columns'));
check((new Address($address->key, TRUE))->get('usa_city') === 'Owner columns', 'the owner\'s write goes through');
$session->set_api_user($admin->key, null, 8);
$threw = null;
try { User::updateColumns($other->key, array('usr_password' => 'still-not-a-hash')); } catch (SystemAuthenticationError $e) { $threw = $e->getMessage(); }
check($threw !== null && strpos($threw, 'usr_password') !== false, 'an 8 changing a restricted column is refused', (string)$threw);
$stored = (new User($other->key, TRUE))->get('usr_password');
User::updateColumns($other->key, array('usr_password' => $stored, 'usr_first_name' => 'Allowed'));
check((new User($other->key, TRUE))->get('usr_first_name') === 'Allowed', 'the same value of it, beside a column the 8 may change, goes through');

section('updateColumns() asks the row\'s own rule');
$session->set_api_user($other->key, null, 0);
$threw = null;
try { User::updateColumns($other->key, array('usr_first_name' => 'Own row')); } catch (SystemAuthenticationError $e) { $threw = $e->getMessage(); }
check($threw === null && (new User($other->key, TRUE))->get('usr_first_name') === 'Own row',
	'a member changes their own User row: its owner is the key, not a column (access_owner_is())', (string)$threw);
$threw = null;
try { User::updateColumns($owner->key, array('usr_first_name' => 'Not mine')); } catch (SystemAuthenticationError $e) { $threw = $e->getMessage(); }
check($threw !== null, 'and not another member\'s');

section('a collection is asked through its rows');
$users = new MultiUser(array('user_id' => $owner->key));
$users->load();
$threw = null;
try { $users->assert_can_read(new ModelAccessSession(0, $other->key)); } catch (SystemAuthenticationError $e) { $threw = $e->getMessage(); }
check($threw !== null, 'assert_can_read() on a collection refuses a row the session may not read');

section('the surfaces leave out what the reader may not see');
$target = new User($other->key, TRUE);
$session->set_api_user($admin->key, null, 6);
$out = $target->export_for_api();
check(!array_key_exists('usr_allowed_ips', $out) && array_key_exists('usr_first_name', $out),
	'at 6 the REST shape of another user leaves out usr_allowed_ips (config, 8 to read)', implode(',', array_keys($out)));
$fw = new FormWriterV2HTML5('ma_form', array('model' => $target, 'action' => '/x'));
ob_start();
$fw->begin_form();
$fw->textinput('usr_allowed_ips', 'Allowed IPs');
$fw->textinput('usr_first_name', 'First name');
$fw->submitbutton('go', 'Save');
$fw->end_form();
$html = ob_get_clean();
check(strpos($html, 'usr_allowed_ips') === false, 'FormWriter does not draw the column, nor its value');
check(strpos($html, 'jy-form-locked') !== false && strpos($html, 'needs level 8') !== false,
	'and the form is read only, saying the level changing a user needs');
check((bool)preg_match('/name="usr_first_name"[^>]*disabled|disabled[^>]*name="usr_first_name"/', $html), 'its inputs are disabled');
$page = new AdminPage();
ob_start();
$page->tableheader(array('Name', 'Allowed IPs'), array('columns' => array(1 => 'User.usr_allowed_ips')));
$page->disprow(array('Someone', '10.0.0.1'));
$table = ob_get_clean();
check(strpos($table, 'Allowed IPs') === false && strpos($table, '10.0.0.1') === false && strpos($table, 'Someone') !== false,
	'the admin table leaves out the column, header and cell');
// A page object may start the real PHP session, which replaces the simulated one.
$session->set_api_user($admin->key, null, 6);
$button = AdminPage::action_button('Rotate', '/x', array('requires' => 10));
check(strpos($button, 'disabled') !== false && strpos($button, 'Needs level 10') !== false, 'a control below its level is drawn disabled, saying why');
check(strpos(AdminPage::action_button('Edit', '/x', array('model' => $target, 'verb' => 'write')), 'disabled') !== false,
	'a control on a row the reader may not change is disabled');
$look = AdminPage::api_action_button('Look', 'user_search');
check(strpos($look, 'data-jy-api-action="user_search"') !== false,
	'an API action button the reader may use carries the action (user_search, personal:read)', $look);
$session->set_api_user($admin->key, null, 8);
check(array_key_exists('usr_allowed_ips', $target->export_for_api()), 'at 8 the column is there');
$session->set_api_user($other->key, null, 0);
check(array_key_exists('usr_allowed_ips', (new User($other->key, TRUE))->export_for_api()), 'and the owner sees their own');

section('the CLI: checked only while a person is signed in, and only in tests');
Access::$sapi = 'cli';
$enforced = new ReflectionMethod('SystemBase', 'access_enforced');
$enforced->setAccessible(true);
$session->set_api_user($other->key, null, 0);
check(Access::$check_cli_persons && $enforced->invoke(null) === true,
	'under the harness, a CLI write while a member is signed in is checked');
$session->clear_api_user();
unset($_SESSION['usr_user_id'], $_SESSION['loggedin']);
check($enforced->invoke(null) === false, 'with no one signed in (cron, root, update_database) it is not');
Access::$check_cli_persons = false;
$session->set_api_user($other->key, null, 0);
check($enforced->invoke(null) === false, 'and outside the harness the CLI is never checked');
Access::$check_cli_persons = true;
$a = new Address($address->key, TRUE);
$a->set('usa_city', 'Cli');
$a->save();
check((new Address($address->key, TRUE))->get('usa_city') === 'Cli', 'a test file\'s own write to a model is a fixture: not checked');

harness_finish();
