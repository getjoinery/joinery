<?php
/** @joinery-test
 * name: admin_page_access
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * An admin page needs the level its header declares, before any page code
 * runs, in all three URL families; the menus show what the reader may open
 * (spec declared_permissions 3.4; docs/permissions.md § Pages).
 *
 * - AdminPageAccess maps a path to its page file and the file to its level;
 *   a page without a header needs 10.
 * - Over HTTP: signed out, every family sends to the sign-in page; a 6 opens
 *   a personal page and is refused a config one; a 10 opens both; a route
 *   that turns the check off (publish_theme) is not sent to sign in.
 * - MultiAdminMenu::visible_entries(): an entry shows when its page opens; a
 *   section when one of its entries does.
 *
 * Run: php tests/run.php --filter=admin_page_access
 *
 * @version 1.1 - a kept higher check is the page's level; an inactive plugin's page maps to nothing
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
require_once(__DIR__ . '/../lib/http.php');
harness_boot();
harness_http_boot($argv);

section('a path maps to its page file, a file to its level');
check(AdminPageAccess::fileForPath('/admin/admin_users') === 'adm/admin_users.php', '/admin/{name} is adm/{name}.php');
check(AdminPageAccess::fileForPath('/admin/admin_users?usr_user_id=1') === 'adm/admin_users.php', 'the query string is not part of the page');
check(AdminPageAccess::fileForPath('/plugins/store/admin/admin_orders') === 'plugins/store/admin/admin_orders.php',
	'/plugins/{plugin}/admin/{name} is the plugin\'s admin/ file');
check(AdminPageAccess::fileForPath('/admin/server_manager') === 'plugins/server_manager/views/admin/index.php',
	'/admin/{plugin} is the plugin\'s views/admin/index.php');
check(AdminPageAccess::fileForPath('/admin/server_manager/publish', 'plugins/server_manager/includes/publish_upgrade')
	=== 'plugins/server_manager/includes/publish_upgrade.php', 'a dynamic route\'s view is its page file');
check(AdminPageAccess::fileForPath('/admin/no_such_page_here') === null, 'a path with no file maps to nothing (it 404s)');
check(AdminPageAccess::fileForPath('/admin/../serve') === null, 'a parent segment maps to nothing');
check(AdminPageAccess::levelForFile('adm/admin_users.php') === 6, 'a personal page needs 6');
check(AdminPageAccess::levelForFile('adm/admin_settings.php') === 8, 'a config page needs 8');
check(AdminPageAccess::levelForFile('plugins/server_manager/includes/publish_upgrade.php') === 8, 'a level= header is its number');
check(AdminPageAccess::levelForFile('adm/logic/admin_users_logic.php') === 10, 'a file without a header needs 10');
check(AdminPageAccess::levelForFile('adm/admin_api_keys.php') === AdminPageAccess::headerLevel('adm/admin_api_keys.php'),
	'a page\'s level is its header\'s alone');
check(AdminPageAccess::fileForPath('/plugins/items/admin/admin_item') === null || PluginHelper::isPluginActive('items'),
	'an inactive plugin\'s page maps to no file (it 404s)');
check(AdminPageAccess::isAdminPath('/admin/admin_users') && AdminPageAccess::isAdminPath('/plugins/store/admin/x')
	&& !AdminPageAccess::isAdminPath('/admin') && !AdminPageAccess::isAdminPath('/profile/admin'),
	'the admin URL families, and not the /admin landing or a member page');

section('a menu entry needs what its page needs');
check(AdminPageAccess::levelForMenuUrl('admin_users') === 6, 'admin_users (under /admin/) is 6');
check(AdminPageAccess::levelForMenuUrl('/admin/admin_settings') === 8, 'a full admin path');
check(AdminPageAccess::levelForMenuUrl('/profile/account_edit') === 0, 'a member page is 0');
check(AdminPageAccess::levelForMenuUrl('admin_no_such_page_here') === 10, 'a missing admin page is 10: no one can open it');
$row = function ($id, $page, $parent = null) {
	return (object)array('amu_admin_menu_id' => $id, 'amu_defaultpage' => $page, 'amu_parent_menu_id' => $parent);
};
$rows = array($row(1, ''), $row(2, 'admin_users', 1), $row(3, 'admin_settings', 1), $row(4, ''), $row(5, 'admin_settings', 4));
$ids = function ($rows) { return array_map(function ($r) { return $r->amu_admin_menu_id; }, $rows); };
check($ids(MultiAdminMenu::visible_entries($rows, 6)) === array(1, 2), 'a 6 sees a section with a page it opens, and that page', json_encode($ids(MultiAdminMenu::visible_entries($rows, 6))));
check($ids(MultiAdminMenu::visible_entries($rows, 8)) === array(1, 2, 3, 4, 5), 'an 8 sees everything here');
check($ids(MultiAdminMenu::visible_entries($rows, 5)) === array(), 'a 5 sees no section with nothing it may open');

section('the router, over HTTP');
$suffix = strtoupper(LibraryFunctions::random_string(6));
$anon = harness_jar_new('apa');
foreach (array('/admin/admin_users', '/plugins/store/admin/admin_orders', '/admin/server_manager') as $path) {
	$r = harness_request('GET', $path, array('jar' => $anon, 'follow' => false));
	check($r['status'] === 302 && harness_header_matches($r['headers'], '#^Location:.*/login#i'),
		"signed out, $path sends to the sign-in page", 'status ' . $r['status']);
}
$r = harness_request('GET', '/admin/server_manager/publish_theme', array('jar' => $anon, 'follow' => false));
check($r['status'] !== 302, 'publish_theme turns the check off on its route: no sign-in page', 'status ' . $r['status']);

$six = make_user($suffix . 'P6', 6);
$jar6 = harness_jar_new('apa6');
check(harness_web_login($jar6, $six->get('usr_email'), 'TestPassword_' . $suffix . 'P6') !== null, 'a 6 signs in');
$r = harness_request('GET', '/admin/admin_users', array('jar' => $jar6, 'follow' => false));
check($r['status'] === 200, 'a 6 opens a personal page', 'status ' . $r['status']);
$r = harness_request('GET', '/admin/admin_settings', array('jar' => $jar6, 'follow' => false));
check($r['status'] === 401, 'a 6 is refused a config page', 'status ' . $r['status']);
$r = harness_request('GET', '/plugins/store/admin/admin_orders', array('jar' => $jar6, 'follow' => false));
check($r['status'] === 200, 'and opens a plugin\'s personal page', 'status ' . $r['status']);
$r = harness_request('GET', '/admin/server_manager', array('jar' => $jar6, 'follow' => false));
check($r['status'] === 401, 'and is refused a plugin\'s config page', 'status ' . $r['status']);

$ten = make_user($suffix . 'P10', 10);
$jar10 = harness_jar_new('apa10');
check(harness_web_login($jar10, $ten->get('usr_email'), 'TestPassword_' . $suffix . 'P10') !== null, 'a 10 signs in');
$r = harness_request('GET', '/admin/admin_settings', array('jar' => $jar10, 'follow' => false));
check($r['status'] === 200, 'a 10 opens the config page', 'status ' . $r['status']);

harness_finish();
