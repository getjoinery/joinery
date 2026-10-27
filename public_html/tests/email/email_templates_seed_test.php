<?php
/** @joinery-test
 * name: email_templates_seed
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The core email templates every site starts with (spec email_template_defaults).
 *
 * - email_templates/ lists every template the code sends by name (or a plugin
 *   migration ships it), and none of them names one organisation or links
 *   anywhere but the site itself.
 * - EmailTemplateDefaults::seed() adds a missing template, leaves an edited one
 *   alone and does not bring back a soft-deleted one.
 * - Migration 225 replaces only rows still holding the shipped Integral Zen
 *   text, and nothing on Integral Zen's own site.
 * - The welcome email is never given the member's password (B168).
 *
 * The seed and migration sections run inside a transaction that is rolled back,
 * so dev's own template rows are never changed.
 *
 * @version 1.0
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
require_once(PathHelper::getIncludePath('migrations/email_templates_neutral_defaults.php'));

$db = DbConnector::get_instance()->get_db_link();

/** The live row of a template, or NULL. */
function ets_row(PDO $db, string $name): ?array {
	$q = $db->prepare('SELECT * FROM emt_email_templates WHERE emt_name = ? AND emt_delete_time IS NULL ORDER BY emt_email_template_id LIMIT 1');
	$q->execute(array($name));
	$row = $q->fetch(PDO::FETCH_ASSOC);
	return $row ?: NULL;
}

/** Runs $fn in a transaction that is always rolled back. */
function ets_rolled_back(PDO $db, callable $fn): void {
	$db->beginTransaction();
	try {
		$fn();
	} finally {
		$db->rollBack();
	}
}

/** Runs the migration with its output captured, so it does not interleave with the checks. */
function ets_run_migration(): void {
	ob_start();
	email_templates_neutral_defaults();
	ob_end_clean();
}

section('the manifest and its files');
$defaults = EmailTemplateDefaults::all();
check(count($defaults) > 0, 'email_templates.json lists templates', (string)count($defaults));
$names = array_column($defaults, 'name');
check(count($names) === count(array_unique($names)), 'every listed name is unique');
foreach ($defaults as $t) {
	check(trim($t['body']) !== '', "{$t['name']}: its file has a body");
}

section('every template the code sends by name is listed, or shipped by a plugin migration');
// Names used literally at a send site, across core and plugins.
$sent = array();
$roots = array('includes', 'data', 'logic', 'adm', 'ajax', 'views', 'plugins', 'tasks', 'utils');
foreach ($roots as $root) {
	$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PathHelper::getIncludePath($root), FilesystemIterator::SKIP_DOTS));
	foreach ($iter as $file) {
		$path = $file->getPathname();
		if (substr($path, -4) !== '.php' || strpos($path, '/tests/') !== false || strpos($path, '/vendor/') !== false) {
			continue;
		}
		$src = file_get_contents($path);
		if (preg_match_all("/(?:sendTemplate|fromTemplate|new\s+EmailTemplateRenderer)\(\s*'([A-Za-z0-9_\-]+)'/", $src, $m)) {
			foreach ($m[1] as $name) {
				$sent[$name] = true;
			}
		}
	}
}
// The renderer's own lookups and the template settings' defaults.
$sent['default_outer_template'] = true;
$sent['default_footer'] = true;
$settings_json = json_decode(file_get_contents(PathHelper::getIncludePath('settings.json')), true);
foreach ($settings_json['settings'] as $setting) {
	if (($setting['group'] ?? '') === 'email_templates' && !empty($setting['default'])) {
		$sent[$setting['default']] = true;
	}
}
check(isset($sent['forgotpw_content']) && isset($sent['new_account_content']), 'the scan finds the account emails it should', implode(', ', array_keys($sent)));
$plugin_migration_src = '';
foreach (glob(PathHelper::getIncludePath('plugins') . '/*/migrations/*.php') as $file) {
	$plugin_migration_src .= file_get_contents($file);
}
foreach (array_keys($sent) as $name) {
	$listed = in_array($name, $names, true);
	$plugin_shipped = !$listed && strpos($plugin_migration_src, "'" . $name . "'") !== false;
	check($listed || $plugin_shipped, "$name is listed in email_templates.json or shipped by a plugin migration");
}

section('no listed template names one organisation or links off the site');
foreach ($defaults as $t) {
	$text = $t['subject'] . "\n" . $t['body'];
	check(!preg_match('/integral\s*zen/i', $text), "{$t['name']}: no Integral Zen");
	check(!preg_match('#https?://#i', $text), "{$t['name']}: links only through *web_dir*");
}
$welcome = EmailTemplateDefaults::get('new_account_content');
check($welcome && strpos($welcome['body'], '*password*') === false, 'the welcome template has no *password* placeholder (B168)');

section('seed: adds a missing template, never changes or revives one');
ets_rolled_back($db, function () use ($db) {
	$db->exec("DELETE FROM emt_email_templates WHERE emt_name = 'calendar_summary'");
	$db->exec("UPDATE emt_email_templates SET emt_body = 'EDITED BY AN ADMIN' WHERE emt_name = 'calendar_reminder'");
	$db->exec("UPDATE emt_email_templates SET emt_delete_time = NOW() WHERE emt_name = 'subscription_expired'");

	$added = EmailTemplateDefaults::seed();
	check($added === array('calendar_summary'), 'only the missing template is added', implode(', ', $added));

	$row = ets_row($db, 'calendar_summary');
	$default = EmailTemplateDefaults::get('calendar_summary');
	check($row && $row['emt_body'] === $default['body'] && $row['emt_subject'] === $default['subject']
		&& (int)$row['emt_type'] === $default['type'], 'it is added with the file\'s subject, body and type');

	$edited = ets_row($db, 'calendar_reminder');
	check($edited && $edited['emt_body'] === 'EDITED BY AN ADMIN', 'an edited template is left as the admin wrote it');

	$count = (int)$db->query("SELECT COUNT(*) FROM emt_email_templates WHERE emt_name = 'subscription_expired'")->fetchColumn();
	check($count === 1 && ets_row($db, 'subscription_expired') === NULL, 'a soft-deleted template is not brought back');

	check(EmailTemplateDefaults::seed() === array(), 'a second seed adds nothing');
});

section('migration 225: replaces only the unchanged Integral Zen text');
$originals = json_decode(file_get_contents(__DIR__ . '/fixtures/integralzen_shipped_templates.json'), true);
check(is_array($originals) && isset($originals['forgotpw_content']), 'the shipped Integral Zen originals load');
$set_original = $db->prepare('UPDATE emt_email_templates SET emt_subject = ?, emt_body = ?, emt_delete_time = NULL WHERE emt_name = ?');

ets_rolled_back($db, function () use ($db, $originals, $set_original) {
	harness_set_setting_mem('site_name', 'Example Choir');
	harness_set_setting_mem('webDir', 'choir.example.org');
	foreach ($originals as $name => $t) {
		$set_original->execute(array($t['subject'], $t['body'], $name));
	}
	// An admin added one space to the verification email.
	$set_original->execute(array($originals['activation_content']['subject'], $originals['activation_content']['body'] . ' ', 'activation_content'));

	ets_run_migration();

	foreach (array_keys($originals) as $name) {
		if ($name === 'activation_content') {
			continue;
		}
		$row = ets_row($db, $name);
		$default = EmailTemplateDefaults::get($name);
		check($row && $row['emt_body'] === $default['body'] && $row['emt_subject'] === $default['subject'],
			"$name: the unchanged Integral Zen text is replaced by the default");
	}
	$row = ets_row($db, 'activation_content');
	check($row && $row['emt_body'] === $originals['activation_content']['body'] . ' ', 'an edited row is left alone');
});

ets_rolled_back($db, function () use ($db, $originals, $set_original) {
	harness_set_setting_mem('site_name', 'Integral Zen');
	foreach ($originals as $name => $t) {
		$set_original->execute(array($t['subject'], $t['body'], $name));
	}
	ets_run_migration();
	$row = ets_row($db, 'forgotpw_content');
	check($row && $row['emt_body'] === $originals['forgotpw_content']['body'], 'nothing changes on Integral Zen\'s own site');
});

section('B168: the welcome email is never given the password');
// CreateNew sends through the platform mailer, which a test cannot intercept;
// what it can check is that the values it builds for the welcome email carry no
// password key.
$method = new ReflectionMethod('User', 'CreateNew');
$lines = array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
$create_new = implode('', $lines);
check(strpos($create_new, "'password_temporary'") !== false, 'CreateNew still tells the welcome email whether the password is temporary');
check(!preg_match("/email_fill\s*=\s*array\([^)]*'password'\s*=>/s", $create_new), 'CreateNew puts no password into the welcome email\'s values');

harness_finish();
