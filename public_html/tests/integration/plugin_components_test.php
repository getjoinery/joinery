<?php
/** @joinery-test
 * name: plugin_components
 * tier: db
 * env: dev-only
 * needs: []
 * timeout: 120
 */
/**
 * A plugin can ship page components (spec email_lists_plugin G10).
 *
 * Component sync scans each plugin's views/components/ beside core's and the
 * theme's. A plugin component requires its plugin, so it shows only while the
 * plugin is active, and its logic function is found in the plugin's own
 * logic/components/. A plugin cannot replace a core component.
 *
 * The fixture plugin is a directory with no registry row, so it is never
 * active; the "active" case is the same row with its requirement cleared.
 *
 * Run: php tests/run.php db --filter=plugin_components
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$plugin = 'hts_cmp_' . bin2hex(random_bytes(3));
$type_key = $plugin . '_widget';
$root = PathHelper::getIncludePath('plugins/' . $plugin);
$logic_fn = $type_key . '_logic';

harness_defer(function () use ($root, $type_key) {
	foreach (array("views/components/$type_key.json", "views/components/$type_key.php", "logic/components/{$type_key}_logic.php") as $f) {
		@unlink("$root/$f");
	}
	foreach (array('views/components', 'views', 'logic/components', 'logic', '') as $d) {
		@rmdir(rtrim("$root/$d", '/'));
	}
	$q = DbConnector::get_instance()->get_db_link()->prepare('DELETE FROM com_components WHERE com_type_key = ?');
	$q->execute(array($type_key));
});

@mkdir("$root/views/components", 0755, true);
@mkdir("$root/logic/components", 0755, true);
file_put_contents("$root/views/components/$type_key.json", json_encode(array(
	'title' => 'HarnessTest plugin widget',
	'logic_function' => $logic_fn,
	'config_schema' => array('fields' => array()),
)));
file_put_contents("$root/views/components/$type_key.php", '<p>widget says <?php echo htmlspecialchars($component_data["said"] ?? "nothing"); ?></p>');
file_put_contents("$root/logic/components/{$type_key}_logic.php", "<?php function $logic_fn(\$config) { return array('said' => 'hello from the plugin'); }");

section('Sync finds a plugin component');
ThemeManager::getInstance()->syncComponentTypes();
$row = Component::get_by_type_key($type_key);
check($row !== null, 'the plugin component has a row');
check($row && $row->get('com_template_file') === "plugins/$plugin/views/components/$type_key.php", 'its template is the plugin\'s file',
	$row ? $row->get('com_template_file') : '');
check($row && $row->get('com_requires_plugin') === $plugin, 'and it requires its plugin', $row ? (string)$row->get('com_requires_plugin') : '');

section('A plugin cannot take over a core component');
$core_key = basename((string)(glob(PathHelper::getIncludePath('views/components') . '/*.json')[0] ?? ''), '.json');
if ($core_key !== '') {
	copy("$root/views/components/$type_key.json", "$root/views/components/$core_key.json");
	copy("$root/views/components/$type_key.php", "$root/views/components/$core_key.php");
	ThemeManager::getInstance()->syncComponentTypes();
	$core_row = Component::get_by_type_key($core_key);
	check($core_row && strpos((string)$core_row->get('com_template_file'), 'plugins/') !== 0,
		'a plugin file with a core type key leaves the core component in place', $core_row ? (string)$core_row->get('com_template_file') : '');
	@unlink("$root/views/components/$core_key.json");
	@unlink("$root/views/components/$core_key.php");
	ThemeManager::getInstance()->syncComponentTypes();
}

section('Shown only while the plugin is active');
$html = ComponentRenderer::render(null, $type_key);
check(strpos($html, 'widget says') === false, 'the plugin is not active, so it renders nothing', $html);

$row->set('com_requires_plugin', null);
$row->save();
$html = ComponentRenderer::render(null, $type_key);
check(strpos($html, 'widget says hello from the plugin') !== false, 'with the requirement met it renders, with data from the plugin\'s logic', $html);

harness_finish();
