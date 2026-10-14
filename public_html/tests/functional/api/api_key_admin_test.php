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
 *    saved), and a scoped key cannot be opened for editing.
 *
 * Run: php tests/run.php db --filter=api_key_admin
 *
 * @version 1.0
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
		if ((int)($entry['meta']['auth']['min_user_permission'] ?? 0) > 8 && ApiLogicEndpoint::machineKeyCanCall($entry['meta'])) {
			$above_8[] = $name;
		}
	}
	check(count(array_intersect($above_8, array_keys($offered_to_8))) === 0,
		'A permission-8 admin is offered none of the actions that need more', implode(', ', array_intersect($above_8, array_keys($offered_to_8))));
	check(count($above_8) === 0 || count(array_intersect($above_8, array_keys($offered_to_10))) === count($above_8),
		'A permission-10 admin is offered them');

	section('Making a scoped key');
	$admin = make_user('KeyAdmin', 8);
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

} catch (\Throwable $e) {
	check(false, 'suite ran to completion', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

unset($_SESSION['usr_user_id'], $_SESSION['loggedin'], $_SESSION['permission']);
harness_finish();
