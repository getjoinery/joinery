<?php
/** @joinery-test
 * name: stored_path_sinks
 * tier: safe
 * parallel: true
 * env: any
 * needs: []
 */
/**
 * A value the pool can write never chooses which file a request requires, or
 * who may open an admin page.
 *
 * The web user holds the database password and writes APCu, so code that ran
 * once in a worker can plant a row or a cache entry that a later request acts
 * on, with no file in the tree changed and no process left behind
 * (specs/unseal_daemon.md § Channels that skip the foothold, C3). Each sink
 * below takes a value from such a place and is bounded where it is used, not
 * only where a form saves it:
 *
 *  - composerAutoLoad (stg_settings) is ../vendor/ or vendor/, else Composer
 *    loads from the site's own ../vendor/ and the problem is reported;
 *  - a component type's template path (com_template_file) is a components
 *    folder file, and its logic function (com_logic_function) a function name;
 *  - a scheduled task's class (sct_task_class) is a class name;
 *  - a plugin name read from a plg_plugins row is a folder name before it
 *    becomes sync.php or data/*_class.php;
 *  - the level that opens an admin page is read from the page file, so a 0
 *    planted in APCu opens nothing (it may only redraw a menu).
 *
 * Run: php tests/security/stored_path_sinks_test.php
 *
 * @version 1.1 - plugin names from rows, every composerAutoLoad reader, the validator report (reviewer2 B1-B3)
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

// A file outside the tree that, if any sink required it, would say so.
$scratch = harness_scratch_dir('stored_path_sinks');
$marker = $scratch . '/ran';
@unlink($marker);
$payload = '<?php file_put_contents(' . var_export($marker, true) . ', "ran"); function stored_path_canary($c) { return array(); }';
file_put_contents($scratch . '/stored_path_canary.php', $payload);
// The same file, reached by climbing out of each sink's base directory.
$up = str_repeat('../', 12) . ltrim($scratch, '/') . '/stored_path_canary';

/** Put a value in the settings memo, as if it came from stg_settings. */
function stored_path_set_setting(string $name, $value): void {
	$g = Globalvars::get_instance();
	(function () use ($name, $value) { $this->settings[$name] = $value; })->call($g);
}

section('composerAutoLoad: only the vendor tree is ever loaded');
$saved = Globalvars::get_instance()->get_setting('composerAutoLoad', true, true);
foreach (array('../vendor/', 'vendor/') as $ok) {
	stored_path_set_setting('composerAutoLoad', $ok);
	$path = null;
	try { $path = PathHelper::getComposerAutoloadPath(); } catch (Exception $e) {}
	check($path === PathHelper::getBasePath() . $ok . 'autoload.php', "accepts '$ok'", (string)$path);
}
foreach (array('../../../../tmp/', '/tmp/', $up . '/', '../vendor/../', 'vendor', 'vendor/x/', ' ../vendor/') as $bad) {
	stored_path_set_setting('composerAutoLoad', $bad);
	$path = null;
	try { $path = PathHelper::getComposerAutoloadPath(); } catch (Throwable $e) {}
	check($path === PathHelper::getBasePath() . '../vendor/autoload.php' && PathHelper::composerSettingProblem() !== null,
		'never loads ' . json_encode($bad) . ': the site\'s own ../vendor/, with the problem named', (string)$path);
}

// B3 (reviewer2): a refused path is a validator warning, not an exception
// that kills update_database before its report (or before a migration could
// repair the row).
stored_path_set_setting('composerAutoLoad', '../../../../tmp/');
$threw = false;
try { $validator = new ComposerValidator(); $validator->validate(); } catch (Throwable $e) { $threw = true; }
check(!$threw && strpos(implode(' ', $validator->getWarnings()), 'composerAutoLoad must be') !== false,
	'ComposerValidator reports a refused composerAutoLoad as a warning instead of throwing');
stored_path_set_setting('composerAutoLoad', $saved);

// B2 (reviewer2): every reader goes through PathHelper, so the rule cannot be skipped.
$readers = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PathHelper::getRootDir(), FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
	$p = $f->getPathname();
	if (substr($p, -4) !== '.php' || strpos($p, '/vendor/') !== false || strpos($p, '/specs/') !== false) continue;
	if (preg_match('#get_setting\(\s*[\'"]composerAutoLoad[\'"]#', (string)@file_get_contents($p))) {
		$readers[] = substr($p, strlen(PathHelper::getRootDir()) + 1);
	}
}
sort($readers);
check($readers === array('includes/PathHelper.php', 'tests/security/stored_path_sinks_test.php'),
	'composerAutoLoad is read only by PathHelper (and this test)', implode(', ', $readers));

section('Plugin names from plg_plugins rows: a folder name, at every sink that requires');
foreach (array('store', 'mailbox', 'joinery_ai', 'dns-filtering') as $ok) {
	check(PluginHelper::isPluginName($ok), "isPluginName accepts '$ok'");
}
$plugin_up = str_repeat('../', 12) . ltrim($scratch, '/') . '/plug';
foreach (array('../uploads/x', $plugin_up, 'a/b', '.', '..', '', "store\n", null) as $bad) {
	check(!PluginHelper::isPluginName($bad), 'isPluginName refuses ' . json_encode($bad));
}
// A planted plugin outside the tree: a sync hook and a data class, each
// writing the marker if required.
@mkdir($scratch . '/plug/data', 0755, true);
file_put_contents($scratch . '/plug/sync.php', $payload);
file_put_contents($scratch . '/plug/data/planted_class.php', $payload);
check((new PluginManager())->runSyncHook($plugin_up) === array() && !is_file($marker),
	'runSyncHook refuses a row name that climbs out of plugins/ and requires nothing');
$classes = LibraryFunctions::discover_model_classes(array('include_plugins' => true, 'plugin_filter' => $plugin_up));
check(!is_file($marker), 'discover_model_classes refuses the same plugin_filter and requires nothing');
@unlink($scratch . '/plug/sync.php');
@unlink($scratch . '/plug/data/planted_class.php');

section('Component types: a components folder file and a function name');
foreach (array('views/components/text_block.php', 'theme/getjoinery/views/components/cta_section.php',
               'plugins/store/views/components/x.php') as $ok) {
	check(ComponentRenderer::isComponentTemplatePath($ok), "template path accepts '$ok'");
}
foreach (array($up . '.php', '../views/components/x.php', 'views/components/../../serve.php', 'uploads/x.php',
               'theme/a/b/views/components/x.php', 'views/components/x.php.json', '/views/components/x.php',
               "views/components/x.php\n") as $bad) {
	check(!ComponentRenderer::isComponentTemplatePath($bad), 'template path refuses ' . json_encode($bad));
}
$load = new ReflectionMethod('ComponentRenderer', 'load_component_data');
foreach (array($up, '../../x', 'list_signup_logic/../x', 'a-b', '') as $bad) {
	$data = $load->invoke(null, $bad, array(), 'stored_path_sinks');
	check($data === array() && !is_file($marker), 'logic function refuses ' . json_encode($bad) . ' and requires nothing');
}

section('Scheduled tasks: a class name');
$task = new ScheduledTask(null);
foreach (array($up, '../' . $up, 'Foo/../../x', 'Foo.php', ' Foo') as $bad) {
	$task->set('sct_task_class', $bad);
	check($task->resolve_task_file() === null && !is_file($marker), 'task class refuses ' . json_encode($bad));
}
$real = glob(PathHelper::getIncludePath('tasks') . '/*.php');
if ($real) {
	$task->set('sct_task_class', basename($real[0], '.php'));
	check($task->resolve_task_file() === $real[0], 'a real task class still resolves (' . basename($real[0]) . ')');
}

section('Admin pages: the opening level comes from the file, never APCu');
// APCu does not outlive a CLI process, so one process plants a 0 under the
// key the display cache uses for admin_settings, then asks both questions.
$boot = 'require_once(' . var_export(PathHelper::getIncludePath('includes/PathHelper.php'), true) . ');';
$script = $boot
	. ' if (!function_exists("apcu_enabled") || !apcu_enabled()) { echo "no-apcu"; exit; }'
	. ' $rel = "adm/admin_settings.php"; $path = PathHelper::getIncludePath($rel);'
	. ' apcu_store("joinery_admin_page_level:" . md5($path . "|" . (int)filemtime($path)), 0, 60);'
	. ' echo json_encode(array("menu" => AdminPageAccess::levelForMenuUrl("admin_settings"),'
	. '   "gate" => AdminPageAccess::levelForPath("/admin/admin_settings"),'
	. '   "file" => AdminPageAccess::levelForFile($rel), "header" => AdminPageAccess::headerLevel($rel)));';
$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d apc.enable_cli=1 -r ' . escapeshellarg($script) . ' 2>/dev/null');
$got = json_decode($out, true);
if ($out === 'no-apcu') {
	check(true, 'APCu is not loaded here: the planted-level check has nothing to plant into');
} else {
	check(is_array($got), 'the planted-level process ran', substr($out, 0, 200));
	check(is_array($got) && $got['header'] >= 8 && $got['gate'] === $got['header'] && $got['file'] === $got['header'],
		'a 0 planted in APCu does not open admin_settings: the gate reads the header (' . ($got['header'] ?? '?') . ')', $out);
	check(is_array($got) && $got['menu'] === 0,
		'the planted 0 reaches the menu, which only draws a link (the test planted where it meant to)', $out);
}

@unlink($scratch . '/stored_path_canary.php');
check(!is_file($marker), 'the planted file outside the tree never ran');

harness_finish();
