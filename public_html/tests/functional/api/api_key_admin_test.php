<?php
/** @joinery-test
 * name: api_key_admin
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The Add/Edit API key page's logic (adm/logic/admin_api_key_edit_logic.php).
 *
 * What is pinned here:
 *  - a scope is built only from what the page offers: an action not offered,
 *    a name that is not an exposed model, a model offered for one verb but
 *    sent for another are all dropped;
 *  - an action needing more than the admin's own permission is not offered
 *    (the key acts as them, so it would always be refused);
 *  - a scoped key made here is a machine key owned by the admin who made it,
 *    Active, with its permission derived from its scope;
 *  - saving an edit leaves the key's owner alone (it used to become whoever
 *    saved), and a scoped key cannot be opened for editing;
 *  - the address restriction refuses a malformed entry at save, naming it,
 *    and matches as addresses and prefixes of either family
 *    (ApiAuth::restrictionAllows);
 *  - a user's allowed sign-in addresses refuse a malformed entry the same way;
 *  - the page is level 10: a permission-8 admin is refused.
 *
 * Run: php tests/run.php db --filter=api_key_admin
 *
 * @version 1.3 - an action's level is read through Access::allows(); a user's level is one permissions.json names
 * @version 1.2 - the key page is level 10 (B16); keys are made by a 10
 * @version 1.1
 */

require_once(__DIR__ . '/../../lib/harness.php');
require_once(__DIR__ . '/../../lib/logic.php');
harness_boot();
require_once(PathHelper::getIncludePath('adm/logic/admin_api_key_edit_logic.php'));

$sign_in = function (User $user) {
	$_SESSION['usr_user_id'] = (int)$user->key;
	$_SESSION['loggedin'] = true;
	$_SESSION['permission'] = (int)$user->get('usr_permission');
};

try {
	section('A scope keeps only what was offered');
	$choices = array(
		'actions' => array('reaction_count' => 'reaction_count'),
		'action_capabilities' => array('reaction_count' => 'read'),
		'records' => array('read' => array('Post'), 'create' => array('Post'), 'update' => array(), 'delete' => array()),
	);
	$entries = admin_api_key_scope_from_input(array(
		'scope_actions' => array('reaction_count', 'marketplace_install', array('nested')),
		'scope_read'    => array('Post', 'Auth', 'User'),
		'scope_create'  => array('Post'),
		'scope_delete'  => array('Post'),
	), $choices);
	check($entries === array('reaction_count', 'Post:read', 'Post:create'),
		'An action or model not on offer, or offered for another verb, is dropped', json_encode($entries));

	section('Actions beyond the admin\'s own permission are not offered');
	$offered_to_8 = admin_api_key_scope_choices(8)['actions'];
	$offered_to_10 = admin_api_key_scope_choices(10)['actions'];
	$above_8 = array();
	foreach (ApiLogicEndpoint::actionCatalog() as $name => $entry) {
		if (!Access::allows($entry['meta']['auth']['min_user_permission'] ?? 0, 8) && ApiLogicEndpoint::machineKeyCanCall($entry['meta'])) {
			$above_8[] = $name;
		}
	}
	check(count(array_intersect($above_8, array_keys($offered_to_8))) === 0,
		'A permission-8 admin is offered none of the actions that need more', implode(', ', array_intersect($above_8, array_keys($offered_to_8))));
	check(count($above_8) === 0 || count(array_intersect($above_8, array_keys($offered_to_10))) === count($above_8),
		'A permission-10 admin is offered them');

	section('Only a level 10 opens the page');
	// Keys are 10 to read and change (spec declared_permissions 3.1, B16).
	$sign_in(make_user('KeyAdmin8', 8));
	$refused = false;
	try {
		harness_call_logic('adm/logic/admin_api_key_edit_logic.php', 'admin_api_key_edit_logic', array(), 'GET');
	} catch (SystemAuthenticationError $e) {
		$refused = true;
	}
	check($refused, 'A permission-8 admin is refused');

	section('Making a scoped key');
	$admin = make_user('KeyAdmin', 10);
	$sign_in($admin);
	$result = harness_call_logic('adm/logic/admin_api_key_edit_logic.php', 'admin_api_key_edit_logic', array(
		'apk_name'          => 'HarnessTest scoped',
		'reach'             => 'scoped',
		'scope_read'        => array('Post'),
		'apk_permission'    => 4,
		'apk_ip_restriction'=> '',
	), 'POST');
	check(!$result->error, 'The key is made', (string)$result->error);
	$made = new MultiApiKey(array('user_id' => (int)$admin->key));
	$key = null;
	foreach ($made as $candidate) {
		$key = $candidate;
		harness_register_key_id((int)$candidate->key);
	}
	check($key !== null && $key->get('apk_type') === ApiKey::TYPE_MACHINE, 'It is a machine key');
	check($key !== null && (int)$key->get('apk_usr_user_id') === (int)$admin->key, 'owned by the admin who made it');
	check($key !== null && $key->get('apk_scope') === 'Post:read', 'scoped to what was chosen', $key ? (string)$key->get('apk_scope') : '');
	check($key !== null && (int)$key->get('apk_permission') === 1, 'with the permission its scope needs, not the one posted', $key ? (string)$key->get('apk_permission') : '');
	check($key !== null && (bool)$key->get('apk_is_active'), 'and Active');

	$result = harness_call_logic('adm/logic/admin_api_key_edit_logic.php', 'admin_api_key_edit_logic',
		array('apk_api_key_id' => $key ? (int)$key->key : 0), 'GET');
	check((bool)$result->error && strpos((string)$result->error, 'fixed') !== false,
		'A scoped key cannot be opened for editing', (string)$result->error);

	section('Saving an edit keeps the owner');
	$owner = make_user('KeyOwner', 8);
	$plain = make_machine_key((int)$owner->key, 'HarnessTest plain', 3);
	$editor = make_user('KeyEditor', 10);
	$sign_in($editor);
	$result = harness_call_logic('adm/logic/admin_api_key_edit_logic.php', 'admin_api_key_edit_logic', array(
		'edit_primary_key_value' => (int)$plain['api_key']->key,
		'apk_name'               => 'HarnessTest renamed',
		'apk_is_active'          => 1,
		'apk_permission'         => 3,
		'apk_ip_restriction'     => '',
	), 'POST');
	check(!$result->error, 'The edit saves', (string)$result->error);
	$reloaded = new ApiKey((int)$plain['api_key']->key, TRUE);
	check($reloaded->get('apk_name') === 'HarnessTest renamed', 'The edit took');
	check((int)$reloaded->get('apk_usr_user_id') === (int)$owner->key,
		'and the key still acts as its owner, not as the admin who saved it');

	$result = harness_call_logic('adm/logic/admin_api_key_edit_logic.php', 'admin_api_key_edit_logic', array(
		'edit_primary_key_value' => (int)$plain['api_key']->key,
		'apk_name'               => 'HarnessTest renamed',
		'apk_is_active'          => 1,
		'apk_permission'         => 99,
		'apk_ip_restriction'     => '',
	), 'POST');
	check((bool)$result->error, 'A permission outside 1-4 is refused with a message', (string)$result->error);

	section('The address restriction');
	foreach (array('2001:db8::/129', 'fe80::1%eth0', '[2001:db8::1]', '10.0.0.300') as $bad) {
		$result = harness_call_logic('adm/logic/admin_api_key_edit_logic.php', 'admin_api_key_edit_logic', array(
			'edit_primary_key_value' => (int)$plain['api_key']->key,
			'apk_name'               => 'HarnessTest renamed',
			'apk_is_active'          => 1,
			'apk_permission'         => 3,
			'apk_ip_restriction'     => '198.51.100.7, ' . $bad,
		), 'POST');
		$reloaded = new ApiKey((int)$plain['api_key']->key, TRUE);
		check((bool)$result->error && strpos((string)$result->error, $bad) !== false && !$reloaded->get('apk_ip_restriction'),
			"a restriction holding '$bad' is refused, naming it, and nothing is saved", (string)$result->error);
	}
	$result = harness_call_logic('adm/logic/admin_api_key_edit_logic.php', 'admin_api_key_edit_logic', array(
		'edit_primary_key_value' => (int)$plain['api_key']->key,
		'apk_name'               => 'HarnessTest renamed',
		'apk_is_active'          => 1,
		'apk_permission'         => 3,
		'apk_ip_restriction'     => ' 198.51.100.7 ,, 2001:db8:abcd:1::/64, ',
	), 'POST');
	$reloaded = new ApiKey((int)$plain['api_key']->key, TRUE);
	check(!$result->error && $reloaded->get('apk_ip_restriction') === '198.51.100.7,2001:db8:abcd:1::/64',
		'a good restriction saves, tidied', (string)$result->error . ' / ' . (string)$reloaded->get('apk_ip_restriction'));

	$restriction = (string)$reloaded->get('apk_ip_restriction');
	check(ApiAuth::restrictionAllows($restriction, '2001:db8:abcd:1:aaaa::9'), 'an IPv6 caller anywhere in its /64 is accepted');
	check(!ApiAuth::restrictionAllows($restriction, '2001:db8:abcd:2::9'), 'a caller outside the /64 is refused');
	check(ApiAuth::restrictionAllows($restriction, '::ffff:198.51.100.7') && !ApiAuth::restrictionAllows($restriction, '198.51.100.8'),
		'an IPv4 entry matches its address in any spelling, and only it');
	check(ApiAuth::restrictionAllows('2001:DB8::1', '2001:db8:0:0::1'), 'an address entry compares as an address, not as text');
	check(ApiAuth::restrictionAllows('', '203.0.113.9') && ApiAuth::restrictionAllows(' , ', '203.0.113.9'),
		'a restriction with no entries admits everyone');
	check(ApiAuth::restrictionAllows('127.0.0.1,::1', '::1') && !ApiAuth::restrictionAllows('127.0.0.1,::1', '203.0.113.9'),
		'the sandbox restriction (loopback, both families) still works');

	section('A user\'s allowed sign-in addresses');
	$member = make_user('KeyAllowlist');
	$result = harness_call_logic('adm/logic/admin_users_edit_logic.php', 'admin_users_edit_logic', array(
		'usr_user_id'     => (int)$member->key,
		'usr_first_name'  => $member->get('usr_first_name'),
		'usr_last_name'   => $member->get('usr_last_name'),
		'usr_password_recovery_disabled' => 0,
		'usr_timezone'    => $member->get('usr_timezone') ?: 'America/New_York',
		'usr_nickname'    => '',
		'usr_organization_name' => '',
		'usr_permission'  => (int)$member->get('usr_permission'),
		'usr_allowed_ips' => "198.51.100.7\n2001:db8::/129",
	), 'POST');
	$member_reloaded = new User((int)$member->key, TRUE);
	check((bool)$result->error && strpos((string)$result->error, '2001:db8::/129') !== false && !$member_reloaded->get('usr_allowed_ips'),
		'a malformed entry is refused, naming it, and nothing is saved', (string)$result->error);
	$result = harness_call_logic('adm/logic/admin_users_edit_logic.php', 'admin_users_edit_logic', array(
		'usr_user_id'     => (int)$member->key,
		'usr_first_name'  => $member->get('usr_first_name'),
		'usr_last_name'   => $member->get('usr_last_name'),
		'usr_password_recovery_disabled' => 0,
		'usr_timezone'    => $member->get('usr_timezone') ?: 'America/New_York',
		'usr_nickname'    => '',
		'usr_organization_name' => '',
		'usr_permission'  => (int)$member->get('usr_permission'),
		'usr_allowed_ips' => "198.51.100.7\n2001:db8:abcd::/48\n104.23.*",
	), 'POST');
	$member_reloaded = new User((int)$member->key, TRUE);
	check(!$result->error && $member_reloaded->is_ip_allowed('2001:db8:abcd:9::1') && $member_reloaded->is_ip_allowed('104.23.1.1')
		&& !$member_reloaded->is_ip_allowed('2001:db8:abce::1'),
		'good entries of both families and a wildcard save and match', (string)$result->error);

	section('A user\'s level is one permissions.json names');
	$edit_level = function ($level) use ($member) {
		return harness_call_logic('adm/logic/admin_users_edit_logic.php', 'admin_users_edit_logic', array(
			'usr_user_id'     => (int)$member->key,
			'usr_first_name'  => $member->get('usr_first_name'),
			'usr_last_name'   => $member->get('usr_last_name'),
			'usr_password_recovery_disabled' => 0,
			'usr_timezone'    => $member->get('usr_timezone') ?: 'America/New_York',
			'usr_nickname'    => '',
			'usr_organization_name' => '',
			'usr_permission'  => $level,
			'usr_allowed_ips' => '',
		), 'POST');
	};
	$result = $edit_level('7');
	check(!$result->error && (int)(new User((int)$member->key, TRUE))->get('usr_permission') === 7,
		'a Manager (7) saves', (string)$result->error);
	foreach (array('4', '99', 'abc', '') as $bad) {
		$result = $edit_level($bad);
		check((bool)$result->error && (int)(new User((int)$member->key, TRUE))->get('usr_permission') === 7,
			"level '$bad' is refused and nothing changes", (string)$result->error);
	}
	$edit_level('0');

} catch (\Throwable $e) {
	check(false, 'suite ran to completion', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

unset($_SESSION['usr_user_id'], $_SESSION['loggedin'], $_SESSION['permission']);
harness_finish();
