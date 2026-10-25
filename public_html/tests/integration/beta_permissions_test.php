<?php
/** @joinery-test
 * name: beta_permissions
 * tier: test-db
 * env: any
 * needs: []
 * covers: [maintenance_scripts/install_tools/install.sh, maintenance_scripts/install_tools/_site_init.sh]
 */
/**
 * The site's side of specs/implemented/beta_break_glass_root_access.md (4.1, 4.2): what
 * the owner lets a management node keep while Joinery is in beta.
 *
 *   - beta_root_access (on) and beta_root_access_offered (empty) are declared
 *     managed; the page reads them with the agent's spellings
 *   - the Beta permissions step is a site step after the welcome, with no
 *     "Not now", green once answered
 *   - its save writes log access, and emergency root access only where the
 *     install offered it; off is permanent, and a save asking for it back is
 *     refused
 *   - the Management Node page's root_access_off turns it off, and nothing
 *     there turns it on
 *   - install.sh site --beta-root-access reaches _site_init.sh on Docker and
 *     on bare metal, which writes beta_root_access_offered
 *
 * Rows go to the test database and are deleted.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/logic.php');
harness_boot();
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
harness_test_mode();
require_once(PathHelper::getIncludePath('includes/BetaPermissions.php'));
require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));

$db = DbConnector::get_instance()->get_db_link();
$stored = function (string $name) use ($db) {
	$q = $db->prepare('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
	$q->execute(array($name));
	$v = $q->fetchColumn();
	return $v === false ? null : (string)$v;
};
$before = array();
foreach (array('beta_root_access', 'beta_root_access_offered', 'agent_log_access', 'agent_log_access_notice_seen') as $name) {
	$before[$name] = $stored($name);
}
harness_defer(function () use ($before) {
	foreach ($before as $name => $value) {
		Setting::put($name, $value ?? '');
	}
	$q = DbConnector::get_instance()->get_db_link()->prepare("DELETE FROM sud_setup_decisions WHERE sud_step_key = 'beta_permissions'");
	$q->execute();
});
// A run starts from the factory state: no answer yet.
$db->prepare("DELETE FROM sud_setup_decisions WHERE sud_step_key = 'beta_permissions'")->execute();
Setting::put('beta_root_access', '1');
Setting::put('beta_root_access_offered', '');
Setting::put('agent_log_access', '1');

// ======================================================================
section('The settings are declared managed, on and not offered by default');
// ======================================================================
$d = SettingsDeclarations::get('beta_root_access');
check($d && !empty($d['managed']) && (string)$d['default'] === '1', 'beta_root_access is managed and on by default');
$d = SettingsDeclarations::get('beta_root_access_offered');
check($d && !empty($d['managed']) && (string)$d['default'] === '', 'beta_root_access_offered is managed and empty by default');
foreach (array('1', 'true', 'yes', 'on', ' On ') as $v) {
	check(BetaPermissions::switch_on($v), "'$v' reads on, as the agent reads it");
}
foreach (array('', '0', 'off', 'no', 'false') as $v) {
	check(!BetaPermissions::switch_on($v), "'$v' reads off, as the agent reads it");
}

// ======================================================================
section('The Beta permissions step');
// ======================================================================
$step = SetupSteps::get('beta_permissions');
check($step && ($step['scope'] ?? '') === 'site', 'a site step, for the owner only');
$welcome = SetupSteps::get('welcome');
$https = SetupSteps::get('https');
check($step && (int)$step['order'] > (int)$welcome['order'] && (int)$step['order'] < (int)$https['order'], 'right after the welcome');
check($step && empty($step['decision']), 'with no "Not now": the step is itself the question');
check($step && call_user_func($step['status'], null) === SetupSteps::STATUS_NONE, 'not green before it is answered');

$owner = make_user('BetaPerm', 10);
$_SESSION['usr_user_id'] = (int)$owner->key;
$_SESSION['loggedin'] = true;
$_SESSION['permission'] = 10;
SetupSteps::resetViewer();

// ======================================================================
section('A self-hosted site: log access only');
// ======================================================================
$res = harness_call_logic('logic/setup_logic.php', 'setup_logic', array('action' => 'beta_permissions_save'), 'POST');
check(is_string($res->redirect) && strpos($res->redirect, '/setup?step=') === 0, 'the save moves to the next step',
	'redirect: ' . var_export($res->redirect, true) . ' error: ' . var_export($res->error, true));
check($stored('agent_log_access') === '' && $stored('agent_log_access_notice_seen') === '1',
	'an unchecked log access turns it off, and counts as having seen its notice');
check($stored('beta_root_access') === '1', 'emergency root access is not touched where the install did not offer it');
check(call_user_func($step['status'], null) === SetupSteps::STATUS_GREEN, 'the step is green once answered');

// ======================================================================
section('Offered: on until the owner unchecks it, and off for good');
// ======================================================================
Setting::put('beta_root_access_offered', '1');
check(BetaPermissions::root_access_offered(), 'the install offered it');
$res = harness_call_logic('logic/setup_logic.php', 'setup_logic',
	array('action' => 'beta_permissions_save', 'keep_root_access' => '1', 'allow_log_access' => '1'), 'POST');
check($stored('beta_root_access') === '1' && $stored('agent_log_access') === '1', 'kept checked, both stay on',
	'error: ' . var_export($res->error, true));
$res = harness_call_logic('logic/setup_logic.php', 'setup_logic',
	array('action' => 'beta_permissions_save', 'allow_log_access' => '1'), 'POST');
check($stored('beta_root_access') === '0' && !BetaPermissions::root_access_on(), 'unchecked, emergency root access is off');
$res = harness_call_logic('logic/setup_logic.php', 'setup_logic',
	array('action' => 'beta_permissions_save', 'keep_root_access' => '1', 'allow_log_access' => '1'), 'POST');
check($stored('beta_root_access') === '0', 'a save asking for it back leaves it off');
check(BetaPermissions::set_root_access(true) !== null, 'and the writer refuses it, saying it cannot be undone');

// ======================================================================
section('The Management Node page turns it off, and nothing there turns it on');
// ======================================================================
Setting::put('beta_root_access', '1');
$_SERVER['REQUEST_URI'] = '/admin/admin_management_node';   // the page remembers where it was
$res = harness_call_logic('adm/logic/admin_management_node_logic.php', 'admin_management_node_logic',
	array('action' => 'root_access_off'), 'POST');
check($res->redirect === '/admin/admin_management_node?root=off' && $stored('beta_root_access') === '0',
	'root_access_off turns it off', 'redirect: ' . var_export($res->redirect, true));
$logic_src = (string)file_get_contents(PathHelper::getIncludePath('adm/logic/admin_management_node_logic.php'));
check(strpos($logic_src, "'root_access_on'") === false, 'there is no root_access_on action');
unset($_SESSION['usr_user_id'], $_SESSION['loggedin'], $_SESSION['permission']);
SetupSteps::resetViewer();

// ======================================================================
section('install.sh site --beta-root-access reaches _site_init.sh');
// ======================================================================
$tools = PathHelper::getSiteRoot() . '/maintenance_scripts/install_tools/';
$install = (string)file_get_contents($tools . 'install.sh');
$init = (string)file_get_contents($tools . '_site_init.sh');
check(preg_match('/--beta-root-access\)\s+BETA_ROOT_ACCESS=1/', $install) === 1, 'install.sh site takes --beta-root-access');
check(strpos($install, 'export JOINERY_BETA_ROOT_ACCESS=1') !== false, 'and exports it for _site_init.sh on bare metal');
check(preg_match('/SITE_INIT_ENV_INPUTS=\([^)]*JOINERY_BETA_ROOT_ACCESS/s', $install) === 1,
	'and lists it among the inputs a container\'s env file carries');
check(strpos($init, "\"UPDATE stg_settings SET stg_value = '1' WHERE stg_name = 'beta_root_access_offered';\"") !== false
	&& strpos($init, "SELECT 'beta_root_access_offered', '1'") !== false,
	'_site_init.sh writes beta_root_access_offered = 1, update then insert');

harness_finish();
