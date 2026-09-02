<?php
/** @joinery-test
 * name: settings_read_declared
 * tier: safe
 * env: dev-only
 * needs: []
 */
/**
 * The reverse of declared_settings section A.
 *
 * That test asks whether every stored row is declared — it audits the database.
 * This one asks the question the database cannot answer: is every setting the
 * CODE reads reachable by anyone?
 *
 * A name that is read but declared nowhere is a knob that looks adjustable and
 * is not. Declarations are the only source for both halves of the settings
 * system, so an undeclared name is invisible in both directions: the renderer
 * only emits declared names, so no field is ever drawn for it; and the writer
 * refuses an undeclared name and mints no row for it. There is no supported way
 * to give it a value. Whatever `?:` fallback sits next to the read is therefore
 * the only value it will ever have, and the branch that reads the setting is
 * dead code.
 *
 * That failure is silent by construction. `get_setting()` returns '' for a name
 * with no row, so the fallback engages and the feature looks like it works —
 * which is how mailbox_relay_cloud_image spent its whole life handing every
 * relay a hardcoded image, and how the names in KNOWN_UNREACHABLE below are
 * still doing the same thing.
 *
 * This is a ratchet, not a clean bill of health. KNOWN_UNREACHABLE is the list
 * of reads that are already broken this way; the test asserts the set has not
 * GROWN and that nothing on the list has quietly disappeared. Fixing one means
 * declaring the name (or deleting the read) and removing its line — the list is
 * meant to shrink and can never silently grow.
 *
 * Run: php tests/unit/settings_read_declared_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));

// ---------------------------------------------------------------------------
// Names that are legitimately read without a declaration.
// ---------------------------------------------------------------------------

/**
 * Bootstrap keys. These live in the site's config/Globalvars_site.php, which is
 * read before the database exists, so they cannot be database declarations. The
 * list is derived from the installer's template rather than typed out here — a
 * key added to the template is legitimate from that moment, and a key dropped
 * from it stops being legitimate without anyone remembering this test.
 */
function bootstrap_config_keys(): array {
	$template = dirname(PathHelper::getIncludePath('')) . '/maintenance_scripts/install_tools/default_Globalvars_site.php';
	if (!is_file($template)) return array();
	preg_match_all("/settings\['([A-Za-z0-9_]+)'\]/", (string)file_get_contents($template), $m);
	return array_unique($m[1]);
}

/**
 * Names Globalvars::get_setting() answers itself, from its calculated-defaults
 * branch, whether or not a row or a config entry exists.
 */
const CALCULATED_KEYS = array('siteDir', 'upload_dir', 'upload_web_dir', 'static_files_dir');

/**
 * Reads that are undeclared on purpose, each for a reason that is not "nobody
 * got round to it".
 */
const DELIBERATE = array(
	'schema_version'                => 'absent until update_database stamps one; read fail-silently and reported as null by the management API',
	'oauth_test_authorize_endpoint' => 'test fixture — the suite writes the row itself (tests/integration/oauth/fixtures)',
	'oauth_test_token_endpoint'     => 'test fixture — as above',
);

/**
 * Reads that ARE unreachable — a name the code asks for that nothing can ever
 * supply, so the fallback beside the read is its only value and the branch that
 * reads it is dead. Empty, and meant to stay that way: a new entry here is a
 * defect being recorded rather than fixed, so prefer declaring the name or
 * deleting the read. Section B fails on anything not listed here.
 */
const KNOWN_UNREACHABLE = array();

// ---------------------------------------------------------------------------
// Collect every literal get_setting('name') in the tree.
// ---------------------------------------------------------------------------

/**
 * Find literal get_setting() reads in one file.
 *
 * Tokenised rather than grepped, because a regex cannot tell live code from a
 * commented-out block — logic/items_logic.php carries a get_setting('_active')
 * inside a /* *\/ comment, and a grep-based version of this test reported it as
 * a phantom setting.
 *
 * A read whose name is built at runtime (a variable, a concatenation) is not
 * collected: there is no name to check. That is a real blind spot, and the
 * reason to keep preferring literals at the call site.
 *
 * @return array name => true
 */
function literal_setting_reads(string $file): array {
	$found = array();
	$src = (string)file_get_contents($file);
	if (strpos($src, 'get_setting') === false) return $found;

	$tokens = @token_get_all($src);
	$count = count($tokens);
	for ($i = 0; $i < $count; $i++) {
		$t = $tokens[$i];
		if (!is_array($t) || $t[0] !== T_STRING || $t[1] !== 'get_setting') continue;

		// Walk past whitespace to the '(' and then to the first argument.
		$j = $i + 1;
		while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
		if ($j >= $count || $tokens[$j] !== '(') continue;
		$j++;
		while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
		if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) continue;

		$name = trim($tokens[$j][1], "'\"");
		if ($name !== '' && preg_match('/^[A-Za-z0-9_]+$/', $name)) $found[$name] = true;
	}
	return $found;
}

/** Every .php file under public_html worth scanning. */
function scannable_php_files(): array {
	$root = rtrim(PathHelper::getIncludePath(''), '/');
	$skip = array('/vendor/', '/node_modules/', '/specs/', '/.git/', '/uploads/', '/static_files/');
	$files = array();
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST);
	foreach ($it as $f) {
		if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
		$path = str_replace('\\', '/', $f->getPathname());
		foreach ($skip as $s) {
			if (strpos($path, $s) !== false) continue 2;
		}
		$files[] = $path;
	}
	sort($files);
	return $files;
}


// =========================================================================
section('A. The scan reaches the code it claims to');
// =========================================================================

$files = scannable_php_files();
check(count($files) > 500, 'the scan covers the tree', count($files) . ' php files');

$reads = array();   // name => [relative file paths]
$root_len = strlen(rtrim(PathHelper::getIncludePath(''), '/')) + 1;
foreach ($files as $file) {
	foreach (array_keys(literal_setting_reads($file)) as $name) {
		$reads[$name][] = substr($file, $root_len);
	}
}
ksort($reads);
check(count($reads) > 250, 'and finds a real number of literal reads', count($reads) . ' distinct names');

// The tokeniser's whole purpose. If this regresses, every commented-out read in
// the tree becomes a false finding and the list below stops being trustworthy.
check(!isset($reads['_active']),
	'a read inside a comment block is not counted',
	'logic/items_logic.php carries get_setting(\'_active\') inside /* */');


// =========================================================================
section('B. Every name the code reads is reachable');
// =========================================================================

$bootstrap = bootstrap_config_keys();
check(in_array('secret_box_key', $bootstrap, true) && in_array('dbname', $bootstrap, true),
	'the bootstrap key list was parsed from the installer template',
	count($bootstrap) . ' keys');

$exempt = array_merge($bootstrap, CALCULATED_KEYS,
	array_keys(DELIBERATE), array_keys(KNOWN_UNREACHABLE));

$new = array();
foreach ($reads as $name => $where) {
	if (in_array($name, $exempt, true)) continue;
	if (SettingsDeclarations::isDeclared($name)) continue;
	$new[$name] = $where;
}

check(empty($new),
	'no setting is read that nothing declares',
	$new ? "unreachable: " . json_encode($new, JSON_UNESCAPED_SLASHES)
	     . " — declare it in settings.json or the plugin's plugin.json, or delete the read"
	     : '');


// =========================================================================
section('C. The known-unreachable list is a ratchet');
// =========================================================================

// A name that has been fixed must leave the list, or the list stops describing
// the tree and starts excusing names that no longer need excusing.
$stale = array();
foreach (KNOWN_UNREACHABLE as $name => $why) {
	if (!isset($reads[$name])) {
		$stale[$name] = 'no longer read anywhere — remove this line';
	} elseif (SettingsDeclarations::isDeclared($name)) {
		$stale[$name] = 'is declared now — remove this line';
	}
}
check(empty($stale), 'every listed finding is still a finding', json_encode($stale, JSON_UNESCAPED_SLASHES));

$stale_deliberate = array();
foreach (DELIBERATE as $name => $why) {
	if (!isset($reads[$name])) $stale_deliberate[$name] = 'no longer read anywhere — remove this line';
}
check(empty($stale_deliberate), 'every deliberate exemption is still used',
	json_encode($stale_deliberate, JSON_UNESCAPED_SLASHES));

// The specific read this test was written for. Kept as its own check so the
// reason survives: it was a setting in name only, and hardcoding it was the
// decision rather than an oversight.
check(!isset($reads['mailbox_relay_cloud_image']),
	'the relay image is a constant, not an unreachable setting',
	'RelayCloudProvisioner::INSTANCE_IMAGE');

harness_finish();
