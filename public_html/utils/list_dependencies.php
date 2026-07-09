<?php
/**
 * list_dependencies.php - the dependency resolver (spec plugin_dependency_installation).
 *
 * Emits the union of dependencies the platform declares, for consumption by
 * the root-moment install tooling (Dockerfile build step, install.sh,
 * upgrade.php). Sources of truth:
 *
 *   - PHP extensions: root composer.json `ext-*` requires (core) plus every
 *     bundled plugin's plugin.json `requires.extensions`.
 *   - Composer packages: every plugin's plugin.json `requires.composer`
 *     (core packages live directly in root composer.json and need no listing).
 *
 * DELIBERATELY ZERO-BOOTSTRAP: this script runs at Docker image build time,
 * where there is no database, no site config, and no framework. It parses
 * JSON files relative to its own location and nothing else. The one flag
 * that needs the database (--active-only) bootstraps lazily and fails with
 * a clear message when no site is initialised.
 *
 * Usage:
 *   php utils/list_dependencies.php                    # extensions, one per line
 *   php utils/list_dependencies.php --apt              # mapped to apt package names
 *   php utils/list_dependencies.php --composer         # plugin-declared composer packages (pkg=constraint)
 *   php utils/list_dependencies.php --orphans          # composer packages no plugin declares (subset of --composer universe)
 *   php utils/list_dependencies.php --active-only ...  # restrict plugin scan to active plugins (needs DB)
 *   php utils/list_dependencies.php --json ...         # JSON object output instead of lines
 *
 * Exit codes: 0 success, 1 usage/parse error, 2 --active-only without a
 * reachable database.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	die('This script can only be run from the command line.');
}

$public_html = dirname(__DIR__);

$args = array_slice($argv ?? ($_SERVER['argv'] ?? array()), 1);
$mode = 'extensions';
$as_apt = false;
$as_json = false;
$active_only = false;
foreach ($args as $arg) {
	switch ($arg) {
		case '--extensions': $mode = 'extensions'; break;
		case '--apt':        $mode = 'extensions'; $as_apt = true; break;
		case '--composer':   $mode = 'composer'; break;
		case '--orphans':    $mode = 'orphans'; break;
		case '--json':       $as_json = true; break;
		case '--active-only': $active_only = true; break;
		case '--help': case '-h':
			echo "Usage: php utils/list_dependencies.php [--extensions|--apt|--composer|--orphans] [--active-only] [--json]\n";
			exit(0);
		default:
			fwrite(STDERR, "Unknown option: {$arg}\n");
			exit(1);
	}
}

/**
 * Read and decode a JSON file; returns array or null (missing/invalid are
 * both reported to STDERR only for invalid - a plugin without a manifest key
 * is normal, a malformed manifest is a bug worth surfacing).
 */
function ld_read_json($path) {
	if (!is_file($path)) {
		return null;
	}
	$decoded = json_decode(file_get_contents($path), true);
	if ($decoded === null) {
		fwrite(STDERR, "WARNING: invalid JSON in {$path} - skipped\n");
	}
	return $decoded;
}

/** All bundled plugin directories (plugins/{name}/plugin.json present). */
function ld_plugin_manifests($public_html) {
	$manifests = array();
	foreach (glob($public_html . '/plugins/*/plugin.json') as $path) {
		$data = ld_read_json($path);
		if (is_array($data)) {
			$manifests[basename(dirname($path))] = $data;
		}
	}
	ksort($manifests);
	return $manifests;
}

/**
 * Restrict a manifest map to active plugins. Requires an initialised site;
 * exits 2 with a clear message when the framework or database is unavailable
 * so build-time callers never trip over it accidentally.
 */
function ld_filter_active($public_html, $manifests) {
	if (!is_file($public_html . '/includes/PathHelper.php')) {
		fwrite(STDERR, "--active-only: framework not found\n");
		exit(2);
	}
	try {
		require_once($public_html . '/includes/PathHelper.php');
		require_once(PathHelper::getIncludePath('includes/Globalvars.php'));
		require_once(PathHelper::getIncludePath('includes/DbConnector.php'));
		$dblink = DbConnector::get_instance()->get_db_link();
		$stmt = $dblink->prepare("SELECT plg_name FROM plg_plugins WHERE plg_status = 'active'");
		$stmt->execute();
		$active = $stmt->fetchAll(PDO::FETCH_COLUMN);
	} catch (Throwable $e) {
		fwrite(STDERR, "--active-only: database unavailable (" . $e->getMessage() . ")\n");
		exit(2);
	}
	return array_intersect_key($manifests, array_flip($active));
}

/** Union of required extension names, lowercased, sorted. */
function ld_extensions($public_html, $manifests) {
	$extensions = array();
	$composer = ld_read_json($public_html . '/composer.json');
	if (is_array($composer)) {
		foreach (array_keys($composer['require'] ?? array()) as $package) {
			if (strpos($package, 'ext-') === 0) {
				$extensions[] = strtolower(substr($package, 4));
			}
		}
	}
	foreach ($manifests as $manifest) {
		foreach (($manifest['requires']['extensions'] ?? array()) as $ext) {
			$extensions[] = strtolower(trim($ext));
		}
	}
	$extensions = array_values(array_unique(array_filter($extensions)));
	sort($extensions);
	return $extensions;
}

/**
 * Map an extension name to the apt package to install. Versioned package
 * first (php8.3-{ext}), unversioned fallback (php-{ext}) - emitted as
 * "primary|fallback" so shell callers can try in order. Extensions compiled
 * into php8.3-common/cli (json, pdo, sodium on Ubuntu) map to no package.
 */
function ld_apt_map($extensions) {
	// Extensions bundled with the base php packages - nothing to install.
	$bundled = array('json', 'pdo', 'sodium', 'openssl', 'pcre', 'spl', 'session',
		'ctype', 'fileinfo', 'filter', 'hash', 'iconv', 'tokenizer', 'zlib',
		'posix', 'ftp', 'calendar', 'exif', 'gettext', 'shmop', 'sockets',
		'sysvmsg', 'sysvsem', 'sysvshm');
	// Derive the versioned package prefix from the PHP actually running this
	// script - it is the PHP that will load the extensions, and hardcoding
	// (e.g. "php8.3") would silently rot on the next PHP bump.
	$php = 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
	$lines = array();
	foreach ($extensions as $ext) {
		if (in_array($ext, $bundled, true)) {
			continue;
		}
		$lines[] = "{$php}-{$ext}|php-{$ext}";
	}
	return $lines;
}

/** Plugin-declared composer packages as name => constraint (last wins on dupes). */
function ld_composer_packages($manifests) {
	$packages = array();
	foreach ($manifests as $manifest) {
		foreach (($manifest['requires']['composer'] ?? array()) as $package => $constraint) {
			// Same vendor/package shape check as ComposerValidator::collectPluginComposerRequires().
			if (is_string($package) && is_string($constraint)
					&& preg_match('#^[a-z0-9]([_.\-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#i', $package)) {
				$packages[strtolower($package)] = $constraint;
			}
		}
	}
	ksort($packages);
	return $packages;
}

/**
 * Composer packages present in root composer.json that no plugin declares
 * AND that are marked as plugin-managed. Plugin-managed entries are the ones
 * the reconcile step added; hand-authored core requires are never orphans.
 * The marker is the "extra.joinery-plugin-packages" list in composer.json,
 * maintained by ComposerValidator::reconcilePluginPackages().
 */
function ld_orphans($public_html, $manifests) {
	$composer = ld_read_json($public_html . '/composer.json');
	$managed = $composer['extra']['joinery-plugin-packages'] ?? array();
	$declared = array_keys(ld_composer_packages($manifests));
	$orphans = array_values(array_diff(array_map('strtolower', $managed), $declared));
	sort($orphans);
	return $orphans;
}

$manifests = ld_plugin_manifests($public_html);
if ($active_only) {
	$manifests = ld_filter_active($public_html, $manifests);
}

switch ($mode) {
	case 'extensions':
		$extensions = ld_extensions($public_html, $manifests);
		$output = $as_apt ? ld_apt_map($extensions) : $extensions;
		if ($as_json) {
			echo json_encode($output, JSON_PRETTY_PRINT) . "\n";
		} else {
			foreach ($output as $line) {
				echo $line . "\n";
			}
		}
		break;
	case 'composer':
		$packages = ld_composer_packages($manifests);
		if ($as_json) {
			echo json_encode($packages, JSON_PRETTY_PRINT) . "\n";
		} else {
			foreach ($packages as $package => $constraint) {
				echo "{$package}={$constraint}\n";
			}
		}
		break;
	case 'orphans':
		$orphans = ld_orphans($public_html, $manifests);
		if ($as_json) {
			echo json_encode($orphans, JSON_PRETTY_PRINT) . "\n";
		} else {
			foreach ($orphans as $package) {
				echo $package . "\n";
			}
		}
		break;
}
exit(0);
