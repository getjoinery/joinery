<?php
/**
 * install_bundle.php — turn on a named set of plugins on a fresh site.
 *
 * A published core archive ships an empty plugins directory — plugins are
 * distributed as their own archives — so a new install has no plugin files at
 * all and nothing installed or activated. This tool therefore does two things:
 * it downloads any of the bundle's plugins that are not on disk, from the same
 * published-archives manifest an upgrade uses, and then installs and activates
 * them. Which plugins a deployment should start with is a product decision, not
 * a property of any one plugin, so it lives in `install_bundles.json` at the
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
 * @version 1.1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once(__DIR__ . '/../../public_html/includes/PathHelper.php');
require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
require_once(PathHelper::getIncludePath('includes/PluginManager.php'));
require_once(PathHelper::getIncludePath('includes/DeploymentHelper.php'));

const IB_DEFAULT_BUNDLE = 'personal';

/**
 * Fetch any of the wanted plugins that are not on disk.
 *
 * A published core archive ships an empty plugins directory -- plugins are
 * distributed as their own archives -- so on a fresh install the bundle's files
 * do not exist yet and there is nothing to activate. They come from the same
 * place an upgrade gets them: the upgrade source's published-archives manifest,
 * which answers anonymous callers, exactly as the core archive fetch does.
 *
 * Returns ['errors' => [name => why], 'planned' => [names]]. 'errors' names the
 * plugins that could not be fetched; 'planned' is only populated on a dry run,
 * and names the ones a real run would have downloaded, so the caller can report
 * them as arriving rather than as missing.
 */
function ib_fetch_missing(array $wanted, $dry_run = false) {
    $missing = [];
    foreach ($wanted as $name) {
        if (!is_dir(PathHelper::getIncludePath('plugins/' . $name))) {
            $missing[] = $name;
        }
    }
    if (empty($missing)) {
        return ['errors' => [], 'planned' => []];
    }

    $settings = Globalvars::get_instance();
    $source = rtrim((string)$settings->get_setting('upgrade_source'), '/');
    if ($source === '') {
        return [
            'errors'  => array_fill_keys($missing, 'no upgrade_source is configured, so there is nowhere to fetch from'),
            'planned' => [],
        ];
    }

    fwrite(STDOUT, "Fetching from {$source}: " . implode(', ', $missing) . "\n");

    $manifest_url = $source . '/utils/upgrade?serve-upgrade=1';
    $raw = @file_get_contents($manifest_url);
    $manifest = $raw === false ? null : json_decode((string)$raw, true);
    if (!is_array($manifest)) {
        return [
            'errors'  => array_fill_keys($missing, "could not read the published-archives manifest at {$manifest_url}"),
            'planned' => [],
        ];
    }

    // published_plugins is [{name, version, url}, ...]; url is absolute.
    $url_by_name = [];
    foreach ($manifest['published_plugins'] ?? [] as $entry) {
        if (!empty($entry['name']) && !empty($entry['url'])) {
            $url_by_name[$entry['name']] = $entry['url'];
        }
    }

    $errors = [];
    $planned = [];
    foreach ($missing as $name) {
        if (!isset($url_by_name[$name])) {
            // The release site does not publish it. Nothing the deployer can do
            // about this, so say where the gap is rather than what they should try.
            $errors[$name] = "{$source} does not publish a {$name} archive";
            continue;
        }
        if ($dry_run) {
            fwrite(STDOUT, "  {$name}: would download {$url_by_name[$name]}\n");
            $planned[] = $name;
            continue;
        }

        // Archives unpack as plugins/<name>/..., so the target is the parent.
        $result = DeploymentHelper::downloadAndExtract(
            $url_by_name[$name],
            PathHelper::getIncludePath('plugins') . '/',
            $name
        );
        if (!$result['success']) {
            $errors[$name] = $result['error'];
            continue;
        }
        if (!is_dir(PathHelper::getIncludePath('plugins/' . $name))) {
            $errors[$name] = "archive unpacked but no plugins/{$name} directory appeared";
            continue;
        }
        fwrite(STDOUT, "  {$name}: downloaded\n");
    }
    return ['errors' => $errors, 'planned' => $planned];
}

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
// Fetch whatever is not here yet
// ---------------------------------------------------------------------------

$fetch = ib_fetch_missing($wanted, $dry_run);
$fetch_errors = $fetch['errors'];
$fetch_planned = $fetch['planned'];

// ---------------------------------------------------------------------------
// Install and activate
// ---------------------------------------------------------------------------

$manager = new PluginManager();
$failed = [];

foreach ($wanted as $name) {
    $path = PathHelper::getIncludePath('plugins/' . $name);
    if (!is_dir($path)) {
        // On a dry run the download did not happen, so a plugin the fetch would
        // have brought down is on its way, not missing. Reporting it as missing
        // would make a healthy dry run look like the failure it is meant to rule out.
        if ($dry_run && in_array($name, $fetch_planned, TRUE)) {
            fwrite(STDOUT, "  {$name}: would download, install and activate\n");
            continue;
        }
        $why = $fetch_errors[$name] ?? 'no plugin directory on disk';
        fwrite(STDERR, "  {$name}: {$why} — skipped\n");
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
