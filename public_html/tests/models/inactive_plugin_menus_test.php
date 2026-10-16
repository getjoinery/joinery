<?php
/** @joinery-test
 * name: inactive_plugin_menus
 * tier: test-db
 * env: any
 * needs: []
 */
/**
 * A plugin that is not active has no menu entries after a plugin sync
 * (PluginManager::pruneInactivePluginMenus): its pages do not load, so an
 * entry left behind is a link to a 404. A deactivation that lost its record of
 * the slugs it owned left them in place, so the sync removes the slugs the
 * manifest declares as well as the recorded ones, and never a slug core or an
 * active plugin declares.
 *
 * Runs in one transaction it rolls back: the bookings plugin is switched off
 * only inside it, so no other suite sees the change.
 *
 * Run: php tests/run.php test-db --only=tests/models/inactive_plugin_menus_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$db = DbConnector::get_instance()->get_db_link();
$slugs_present = function (array $slugs) use ($db) {
	$q = $db->prepare('SELECT amu_slug FROM amu_admin_menus WHERE amu_slug IN (' . implode(',', array_fill(0, count($slugs), '?')) . ')');
	$q->execute($slugs);
	return $q->fetchAll(PDO::FETCH_COLUMN);
};
$bookings = array('bookings-parent', 'bookings', 'booking-types', 'bookings-member');

section('fixture');
$present = $slugs_present($bookings);
if (count($present) !== count($bookings) || !Plugin::get_by_plugin_name('bookings')) {
	harness_skip('the bookings menu entries are not in the test database', implode(', ', $present));
	harness_finish();
	exit;
}
$active_other = $db->query("SELECT amu_slug FROM amu_admin_menus WHERE amu_slug = 'joinery-ai-chat'")->fetchColumn();
check($active_other === 'joinery-ai-chat', 'an active plugin\'s entry (joinery-ai-chat) is there to be kept');

$prune = new ReflectionMethod('PluginManager', 'pruneInactivePluginMenus');
$prune->setAccessible(true);
$manager = new PluginManager();

$db->beginTransaction();
try {
	// Switched off with its record lost, apart from one slug core declares,
	// the shape a deactivation before keepStoredMetadata left behind.
	$q = $db->prepare("UPDATE plg_plugins SET plg_active = 0, plg_metadata = ? WHERE plg_name = 'bookings'");
	$q->execute(array(json_encode(array('_menu_slugs' => array('users')))));
	$active = new MultiPlugin(array('plg_active' => 1));

	section('a plugin that is not active loses its entries');
	$messages = $prune->invoke($manager, $active);
	check($slugs_present($bookings) === array(), 'all four bookings entries are gone, the profile one included');
	check(count($messages) === 1 && strpos($messages[0], 'bookings: removed 4 menu entries') === 0,
		'the sync says which plugin and how many', implode(' | ', $messages));

	section('what core or an active plugin declares stays');
	check($slugs_present(array('users')) === array('users'), 'a core slug the plugin had recorded stays');
	check($slugs_present(array('joinery-ai-chat')) === array('joinery-ai-chat'), 'the active plugin\'s entry stays');
	$meta = json_decode((string)$db->query("SELECT plg_metadata FROM plg_plugins WHERE plg_name = 'bookings'")->fetchColumn(), true);
	check(is_array($meta) && !isset($meta['_menu_slugs']), 'the plugin\'s record of its slugs is cleared');

	section('a second sync has nothing to do');
	check($prune->invoke($manager, new MultiPlugin(array('plg_active' => 1))) === array(), 'no entries removed the second time');
} finally {
	if ($db->inTransaction()) { $db->rollBack(); }
}

section('rolled back');
check(count($slugs_present($bookings)) === count($bookings), 'the bookings entries are back for every other suite');

harness_finish();
