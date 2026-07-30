<?php
/**
 * install_bundle.php — turn on a named set of plugins on a fresh site.
 *
 * A new install ends up with every plugin's *files* on disk and nothing
 * installed or activated, so out of the box it is the bare platform. Which
 * plugins a deployment should start with is a product decision, not a
 * property of any one plugin, so it lives in `install_bundles.json` at the
 * public_html root — beside admin_menus.json and settings.json, where the
 * platform already keeps its declarative sets.
 *
 * Bundles are flat lists and never extend one another. They are alternative
 * products, not layers: the personal suite and a creator-hosting product
 * share nothing, so composition would buy indirection and no reuse.
 *
 * Usage:
 *   sudo -u www-data php install_bundle.php [--bundle=NAME] [--plugins=a,b]
 *                                           [--list] [--dry-run]
 *
 *   --bundle=NAME   Bundle from install_bundles.json. Default: personal.
 *   --plugins=a,b   Install exactly these, ignoring the file. For one-offs
 *                   and for testing; nothing in the install path uses it.
 *   --list          Print the available bundles and exit.
 *   --dry-run       Say what would be installed, change nothing.
 *
 * Safe to re-run: a plugin that is already installed is left alone, and one
 * that is installed but inactive is activated.
 *
 * It lives in sysadmin_tools/ rather than public_html/utils/ deliberately.
 * /utils/<name> is web-routable and the router applies no permission check of
 * its own, and a reachable endpoint that installs and activates plugins should
 * not be relying on its own guard to stay private.
 *
 * Validate with `php -l` only — never the file validator, which executes the
 * file it is checking.
 *
 * @version 1.0
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once(__DIR__ . '/../../public_html/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/PluginManager.php'));

const IB_DEFAULT_BUNDLE = 'personal';

/** Minimal --flag / --flag=value parser. */
function ib_opts(array $argv) {
    $opts = [];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (strpos($arg, '--') !== 0) {
            fwrite(STDERR, "ERROR: unexpected argument '{$arg}'.\n");
            exit(2);
        }
        $body = substr($arg, 2);
        if (strpos($body, '=') !== false) {
            list($key, $value) = explode('=', $body, 2);
            $opts[$key] = $value;
        } else {
            $opts[$body] = true;
        }
    }
    return $opts;
}

/** Read install_bundles.json, or exit with a readable reason. */
function ib_load_bundles() {
    $path = PathHelper::getIncludePath('install_bundles.json');
    if (!is_readable($path)) {
        fwrite(STDERR, "ERROR: install_bundles.json not found at {$path}\n");
        exit(1);
    }
    $decoded = json_decode((string)file_get_contents($path), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "ERROR: install_bundles.json is not valid JSON: " . json_last_error_msg() . "\n");
        exit(1);
    }
    $bundles = [];
    foreach ($decoded as $name => $definition) {
        // Keys starting with an underscore are notes to whoever edits the file.
        if (strpos((string)$name, '_') === 0 || !is_array($definition)) {
            continue;
        }
        $bundles[$name] = $definition;
    }
    return $bundles;
}

$opts = ib_opts($argv);
$bundles = ib_load_bundles();

if (isset($opts['list'])) {
    fwrite(STDOUT, "Available bundles:\n");
    foreach ($bundles as $name => $definition) {
        $label = isset($definition['label']) ? $definition['label'] : $name;
        $plugins = isset($definition['plugins']) && is_array($definition['plugins'])
            ? implode(', ', $definition['plugins']) : '(none)';
        fwrite(STDOUT, "  {$name} — {$label}\n      {$plugins}\n");
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Decide what to install
// ---------------------------------------------------------------------------

$dry_run = isset($opts['dry-run']);
$wanted = [];
$source = '';

if (isset($opts['plugins']) && is_string($opts['plugins'])) {
    foreach (explode(',', $opts['plugins']) as $name) {
        $name = trim($name);
        if ($name !== '') {
            $wanted[] = $name;
        }
    }
    $source = '--plugins';
} else {
    $bundle_name = isset($opts['bundle']) && is_string($opts['bundle'])
        ? trim($opts['bundle']) : IB_DEFAULT_BUNDLE;

    // A name nobody defined is a hard error. Installing nothing and reporting
    // success would leave a site missing the product it was meant to be, with
    // an exit code that said everything went fine.
    if (!isset($bundles[$bundle_name])) {
        fwrite(STDERR, "ERROR: no bundle named '{$bundle_name}' in install_bundles.json.\n");
        fwrite(STDERR, "Available: " . (empty($bundles) ? '(none)' : implode(', ', array_keys($bundles))) . "\n");
        exit(1);
    }

    $definition = $bundles[$bundle_name];
    if (isset($definition['plugins']) && is_array($definition['plugins'])) {
        $wanted = $definition['plugins'];
    }
    $source = "bundle '{$bundle_name}'";
}

if (empty($wanted)) {
    fwrite(STDOUT, "Nothing to install for {$source}.\n");
    exit(0);
}

fwrite(STDOUT, "Installing {$source}: " . implode(', ', $wanted) . "\n");

// ---------------------------------------------------------------------------
// Install and activate
// ---------------------------------------------------------------------------

$manager = new PluginManager();
$failed = [];

foreach ($wanted as $name) {
    $path = PathHelper::getIncludePath('plugins/' . $name);
    if (!is_dir($path)) {
        fwrite(STDERR, "  {$name}: no plugin directory on disk — skipped\n");
        $failed[] = $name;
        continue;
    }

    if ($dry_run) {
        fwrite(STDOUT, "  {$name}: would install and activate\n");
        continue;
    }

    try {
        $existing = Plugin::get_by_plugin_name($name);

        if (!$existing) {
            $manager->install($name);
            $manager->activate($name);
            fwrite(STDOUT, "  {$name}: installed and activated\n");
            continue;
        }

        // A row already exists, so this is not the fresh install this tool is
        // built for. Only the plainly-safe transition is taken: inactive means
        // installed and switched off, which is what activating is for. Any
        // other status — active, stale, failed — is a state somebody or
        // something else established, and a bundle install has no business
        // overriding it silently.
        $status = (string)$existing->get('plg_status');
        if ($status === 'inactive') {
            $manager->activate($name);
            fwrite(STDOUT, "  {$name}: activated\n");
        } else if ($status === 'active') {
            fwrite(STDOUT, "  {$name}: already active\n");
        } else {
            fwrite(STDOUT, "  {$name}: left alone (status '{$status}') — review it at /admin/admin_plugins\n");
        }
    } catch (Exception $e) {
        // One plugin failing should not cost the site the others. The install
        // reports it and keeps going; the operator can install it by hand.
        fwrite(STDERR, "  {$name}: FAILED — " . $e->getMessage() . "\n");
        $failed[] = $name;
    }
}

if (!empty($failed)) {
    fwrite(STDERR, "\n" . count($failed) . " plugin(s) did not install: " . implode(', ', $failed) . "\n");
    fwrite(STDERR, "The site is usable without them. Install from /admin/admin_plugins.\n");
    exit(1);
}

fwrite(STDOUT, $dry_run ? "Dry run — nothing was changed.\n" : "Bundle installed.\n");
exit(0);
