<?php
/** @joinery-test
 * name: plugin_sync
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * PluginManager::sync() — the deploy-time schema mutator.
 *
 * This runs on every upgrade, against every active plugin, and it writes:
 * tables, columns, unique constraints, indexes, foreign keys, migrations,
 * deletion rules, menus and settings. Nothing else in the platform changes so
 * much in one unattended step, and a deploy is exactly when nobody is watching.
 *
 * What this pins:
 *   - the flat-namespace collision guard actually fires, and fires *before* any
 *     schema is touched, so a collision cannot leave a half-mutated database
 *   - a plugin's declared settings are validated against the naming and typing
 *     rules before they are seeded
 *   - seeding never overwrites a configured value — the invariant that keeps a
 *     deploy from resetting an operator's settings to factory defaults
 *   - every currently-shipped plugin manifest passes those rules, so a bad one
 *     fails here rather than mid-deploy
 *   - sync() is idempotent: a second run reports no further schema changes
 *
 * Run:  php tests/functional/plugins/plugin_sync_test.php
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('includes/PluginManager.php'));
require_once(PathHelper::getIncludePath('includes/PluginHelper.php'));
require_once(PathHelper::getIncludePath('data/plugins_class.php'));
require_once(PathHelper::getIncludePath('data/settings_class.php'));

$manager = PluginManager::getInstance();
$db = DbConnector::get_instance()->get_db_link();

/** Call a protected PluginManager method. */
function pm_call($manager, $method, array $args = array()) {
    $m = new ReflectionMethod('PluginManager', $method);
    return $m->invokeArgs($manager, $args);
}

/** Returns the exception message from $fn, or '' if it did not throw. */
function pm_throws(callable $fn) {
    try { $fn(); return ''; }
    catch (Exception $e) { return $e->getMessage() ?: '(empty message)'; }
}

$active_names = array();
$active = new MultiPlugin(array('plg_active' => 1));
$active->load();
foreach ($active as $p) { $active_names[] = $p->get('plg_name'); }
check(count($active_names) > 0, 'there is at least one active plugin to sync', implode(', ', $active_names));

// ---------------------------------------------------------------------------
section('The flat-namespace collision guard fires on a real collision');

// ajax/, utils/ and tests/ resolve at shared global URLs, so two files with the
// same basename in different plugins are reachable at one address and which one
// answers is undefined. The guard exists to make that a refusal rather than a
// coin flip. Prove it with an actual colliding file rather than by inspection.
check(pm_call($manager, 'validateFlatNamespaceCollisions') === null,
    'the current tree has no flat-namespace collision');

$victim = $active_names[0];
$victim_dir = PathHelper::getIncludePath('plugins/' . $victim . '/utils');
$core_utils = glob(PathHelper::getIncludePath('utils') . '/*.php');
check(count($core_utils) > 0, 'core ships utils/ files to collide with');

$collide_name = basename($core_utils[0]);
$collide_path = $victim_dir . '/' . $collide_name;
$made_dir = false;
if (!is_dir($victim_dir)) { @mkdir($victim_dir, 0777, true); $made_dir = true; }
$pre_existing = file_exists($collide_path);

if ($pre_existing) {
    harness_skip('a plugin utils/ collision is refused', "$collide_path already exists; not overwriting");
} else {
    file_put_contents($collide_path, "<?php // harness collision fixture\n");
    // Always clean up, even if a check below throws.
    harness_defer(function () use ($collide_path, $victim_dir, $made_dir) {
        @unlink($collide_path);
        if ($made_dir) { @rmdir($victim_dir); }
    });

    $msg = pm_throws(function () use ($manager) { pm_call($manager, 'validateFlatNamespaceCollisions'); });
    check($msg !== '', 'a plugin file colliding with a core utils/ basename is refused');
    check(strpos($msg, $collide_name) !== false, 'the refusal names the colliding file', $msg);
    check(strpos($msg, $victim) !== false, 'the refusal names the offending plugin', $msg);
    check(strpos($msg, 'core') !== false, 'the refusal names the other owner', $msg);

    // The guard has to run before anything is written, or a collision leaves the
    // database half-mutated with no record of why. sync() calls it first; assert
    // that ordering by confirming sync() refuses outright while the collision
    // exists.
    $sync_msg = pm_throws(function () use ($manager) { $manager->sync(); });
    check($sync_msg !== '', 'sync() aborts entirely while a collision exists');
    check(strpos($sync_msg, $collide_name) !== false,
        'sync() aborts with the collision error, not some later failure', $sync_msg);

    @unlink($collide_path);
    if ($made_dir) { @rmdir($victim_dir); $made_dir = false; }
    check(pm_call($manager, 'validateFlatNamespaceCollisions') === null,
        'removing the colliding file clears the refusal');
}

// A basename shared outside the three flat directories is legitimate — plugins
// routinely have their own includes/ and data/ files named like core's — so the
// guard must not overreach.
$includes_dir = PathHelper::getIncludePath('plugins/' . $victim . '/includes');
$non_flat = $includes_dir . '/' . $collide_name;
if (!file_exists($non_flat)) {
    $made_inc = false;
    if (!is_dir($includes_dir)) { @mkdir($includes_dir, 0777, true); $made_inc = true; }
    file_put_contents($non_flat, "<?php // harness non-flat fixture\n");
    $msg = pm_throws(function () use ($manager) { pm_call($manager, 'validateFlatNamespaceCollisions'); });
    check($msg === '', 'a shared basename outside ajax/utils/tests is allowed', $msg);
    @unlink($non_flat);
    if ($made_inc) { @rmdir($includes_dir); }
} else {
    harness_skip('a shared basename outside ajax/utils/tests is allowed', "$non_flat already exists");
}

// ---------------------------------------------------------------------------
section('Declared settings are validated before they are seeded');

$validate = function ($plugin, array $declared) use ($manager) {
    return pm_throws(function () use ($manager, $plugin, $declared) {
        pm_call($manager, 'validateDeclaredSettings', array($plugin, $declared));
    });
};

// The prefix rule keeps one plugin from claiming a name another plugin — or a
// future core setting — would want.
check($validate('demo', array(array('name' => 'demo_widget_count', 'default' => '3'))) === '',
    'a correctly prefixed setting is accepted');
check($validate('demo', array(array('name' => 'widget_count', 'default' => '3'))) !== '',
    'an unprefixed setting is refused');
$msg = $validate('demo', array(array('name' => 'widget_count')));
check(strpos($msg, 'demo_') !== false, 'the refusal states the required prefix', $msg);

// A setting that moved out of core with a plugin keeps its original name on
// purpose, and says so explicitly rather than by omission.
check($validate('demo', array(array('name' => 'legacy_thing', 'legacy_core' => true))) === '',
    'an explicit legacy_core setting may keep an unprefixed name');

// Colliding with a core setting is refused even under the legacy opt-out —
// otherwise the plugin and core would fight over one row.
$core_path = PathHelper::getIncludePath('settings.json');
$core_json = json_decode(file_get_contents($core_path), true);
$a_core_name = null;
foreach (($core_json['settings'] ?? array()) as $entry) {
    if (!empty($entry['name'])) { $a_core_name = $entry['name']; break; }
}
check($a_core_name !== null, 'settings.json declares core settings to collide with');
if ($a_core_name !== null) {
    check($validate('demo', array(array('name' => $a_core_name))) !== '',
        'a setting colliding with settings.json is refused: ' . $a_core_name);
    check($validate('demo', array(array('name' => $a_core_name, 'legacy_core' => true))) !== '',
        'legacy_core does not excuse a live core collision');
}

// stg_settings values are strings, so a JSON boolean or number in a manifest
// would be silently coerced. Refusing it keeps the manifest honest.
check($validate('demo', array(array('name' => 'demo_flag', 'default' => true))) !== '',
    'a boolean default is refused');
check($validate('demo', array(array('name' => 'demo_count', 'default' => 42))) !== '',
    'a numeric default is refused');
check($validate('demo', array(array('name' => 'demo_count', 'default' => '42'))) === '',
    'the string form of the same default is accepted');
check($validate('demo', array(array('name' => 'demo_flag'))) === '',
    'omitting the default entirely is allowed');

// A malformed entry must name its position, or a long manifest gives no clue
// where the problem is.
$msg = $validate('demo', array(array('default' => 'x')));
check($msg !== '', 'an entry with no name is refused');
check(strpos($msg, '#0') !== false, 'the refusal identifies which entry is malformed', $msg);

// ---------------------------------------------------------------------------
section('Every shipped plugin manifest passes those rules');

// The rules above are only worth having if the tree actually satisfies them. A
// manifest that violates one would otherwise surface for the first time during
// a deploy, inside the step that is already mutating schema.
foreach ($active_names as $name) {
    try {
        $helper = PluginHelper::getInstance($name);
    } catch (Exception $e) {
        check(false, "plugin.json for '$name' is readable", $e->getMessage());
        continue;
    }
    $declared = $helper->getDeclaredSettings();
    if (empty($declared)) {
        harness_skip("'$name' declared settings pass validation", 'no settings declared');
        continue;
    }
    $msg = $validate($name, $declared);
    check($msg === '', "'$name' declared settings pass validation (" . count($declared) . ' declared)', $msg);
}

// ---------------------------------------------------------------------------
section('Seeding never overwrites a configured value');

// This is the invariant that matters most on a deploy: an operator changes a
// setting, ships an upgrade, and the upgrade must not hand the setting back to
// its factory default. seed_declared() relies on ON CONFLICT DO NOTHING, which
// in turn relies on a unique index existing on stg_name — assert both, because
// without the index the statement would error rather than skip.
$has_unique = $db->query(
    "SELECT count(*) FROM pg_constraint WHERE conrelid = 'stg_settings'::regclass AND contype = 'u'"
)->fetchColumn();
check((int)$has_unique > 0, 'stg_settings has the unique constraint ON CONFLICT depends on');

$probe_name = 'harness_seed_probe_' . bin2hex(random_bytes(4));
harness_defer(function () use ($db, $probe_name) {
    $db->prepare("DELETE FROM stg_settings WHERE stg_name = ?")->execute(array($probe_name));
});

Setting::seed_declared(array(array('name' => $probe_name, 'default' => 'factory')));
$read = $db->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = ?");
$read->execute(array($probe_name));
check($read->fetchColumn() === 'factory', 'a new setting is seeded with its declared default');

// The operator changes it.
$db->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = ?")
   ->execute(array('operator-chosen', $probe_name));

// The deploy runs again, declaring the same factory default.
Setting::seed_declared(array(array('name' => $probe_name, 'default' => 'factory')));
$read->execute(array($probe_name));
check($read->fetchColumn() === 'operator-chosen',
    're-seeding leaves a configured value untouched');

// And it must not duplicate the row either.
$count = $db->prepare("SELECT count(*) FROM stg_settings WHERE stg_name = ?");
$count->execute(array($probe_name));
check((int)$count->fetchColumn() === 1, 're-seeding creates no second row');

// A declaration with no name is a manifest error, not something to seed blindly.
$bad = pm_throws(function () { Setting::seed_declared(array(array('default' => 'x'))); });
check($bad !== '', 'seeding a nameless declaration is refused');

// ---------------------------------------------------------------------------
section('sync() is idempotent');

// A deploy runs this against a database that is already up to date far more
// often than against one that is not, so the no-op path is the common path. If
// a second run still reports changes, something is being rewritten every deploy
// — which is both a silent write and a sign the updater cannot recognize its
// own output.
$first = $manager->sync();
check(is_array($first), 'sync() returns a result array');

$second = $manager->sync();
$table_messages = isset($second['table_messages']) ? $second['table_messages'] : array();
check(count($table_messages) === 0,
    'a second sync() reports no further table changes',
    implode(' | ', array_slice($table_messages, 0, 5)));

$migration_messages = isset($second['migration_messages']) ? $second['migration_messages'] : array();
check(count($migration_messages) === 0,
    'a second sync() applies no further migrations',
    implode(' | ', array_slice($migration_messages, 0, 5)));

// Errors are collected into the message arrays rather than thrown, so a run that
// "succeeded" can still be carrying failures. Read them out explicitly.
foreach (array('table_messages', 'migration_messages', 'settings_messages') as $bucket) {
    $msgs = isset($first[$bucket]) ? $first[$bucket] : array();
    $errors = array_filter($msgs, function ($m) {
        return stripos($m, 'error') !== false;
    });
    check(count($errors) === 0, "the first sync() reported no errors in $bucket",
        implode(' | ', array_slice(array_values($errors), 0, 3)));
}

// ---------------------------------------------------------------------------
section('A failing plugin does not abort the deploy, but does not vanish either');

// sync() deliberately catches per-plugin failures so one bad plugin cannot stop
// an upgrade. The cost of that choice is that a failure becomes a string in an
// array rather than an exception, and a string nobody prints is a failure nobody
// sees. Every bucket sync() can record a failure into must be surfaced by the
// caller — update_database.php is the only caller on the deploy path.
//
// These are source tripwires, not behavioral checks: running the whole deploy
// script in-test costs more than the risk warrants, so settle for tripping when
// a bucket's only mention disappears from the updater. settings_messages is the
// sharp one — sync() only ever writes to it on exception, so anything in it is
// a plugin whose settings did not sync.
$updater_src = file_get_contents(PathHelper::getIncludePath('utils/update_database.php'));
foreach (array('table_messages', 'migration_messages', 'settings_messages') as $bucket) {
    check(strpos($updater_src, "\$plugin_sync_result['$bucket']") !== false,
        "update_database surfaces $bucket rather than discarding it");
}

// ---------------------------------------------------------------------------
section('Deletion-rule pruning does not depend on activation state');

// pruneOrphanedRules() deletes any rule whose source or target table matches no
// declared model. It runs on every sync. If model discovery were ever limited to
// ACTIVE plugins, deactivating a plugin would quietly delete the deletion rules
// protecting its tables — and its rows would then lose their cascade/prevent
// behaviour while the tables and data still existed. Discovery scans the
// filesystem instead, so an inactive plugin's models still count. Pin that,
// because the failure mode is silent data damage on an unrelated action.
require_once(PathHelper::getIncludePath('data/deletion_rule_class.php'));
$registry = new ReflectionMethod('DeletionRule', 'getModelRegistry');
$reg = $registry->invoke(null);
$known = $reg['all_tables'];

$inactive = new MultiPlugin(array());
$inactive->load();
$checked_inactive = 0;
foreach ($inactive as $p) {
    if ($p->get('plg_active')) { continue; }
    $name = $p->get('plg_name');
    if (!is_dir(PathHelper::getIncludePath('plugins/' . $name . '/data'))) { continue; }
    // The runtime's own discovery, filtered to this plugin — not a regex
    // reimplementation of it, so the table names checked are the ones the
    // registry itself would be asked about.
    $plugin_classes = LibraryFunctions::discover_model_classes(array(
        'require_tablename' => true,
        'include_plugins'   => true,
        'plugin_filter'     => $name,
    ));
    foreach ($plugin_classes as $class) {
        check(isset($known[$class::$tablename]),
            "an inactive plugin's table is still known to discovery: " . $class::$tablename,
            "plugin: $name");
        $checked_inactive++;
    }
}
if ($checked_inactive === 0) {
    harness_skip("an inactive plugin's tables are still known to discovery",
        'no inactive plugin with data classes on this host');
}

// The same guarantee stated directly: discovery must not be filtered by
// activation, so a core table and an active plugin's table are both present.
check(isset($known['usr_users']), 'discovery knows core tables');
check(count($known) > 20, 'discovery returned a full registry, not a filtered subset',
    count($known) . ' tables');

harness_finish();
