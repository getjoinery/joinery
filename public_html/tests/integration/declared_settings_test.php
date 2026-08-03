<?php
/** @joinery-test
 * name: declared_settings
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A setting is declared once, rendered by shared code, and validated on write
 * regardless of which page it came from.
 *
 * The failure this guards against is silent in both directions. A row nobody
 * declared looks like a setting and is not one — twelve junk rows lived in
 * stg_settings for two years because nothing ever said a word. A declaration
 * nobody renders is a setting with no way to change it, which is how eleven
 * real switches ended up editable only with a database client.
 *
 * Guarded here:
 *   A. Every stored row is declared, or is a reserved name.
 *   B. Every declaration is well-formed.
 *   C. A declared rule is enforced whichever page submits the value — including
 *      a page that never drew that field. This is the check that tells
 *      declaration scope apart from form scope.
 *   D. The behaviours the old save loops carried, which were easy to lose in
 *      the rewrite: an unchanged value is not a write, a blank credential is
 *      not a clear, and the vault gate blocks the change without blocking the
 *      rest of the save.
 *   E. Scope: a save cannot reach a name outside the source it named.
 *   F. Rendering: every field a page shows came from a declaration, and a page
 *      that tries to draw its own version of one is refused.
 *
 * Run: php tests/integration/declared_settings_test.php
 *
 * @version 1.1
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/settings_class.php'));
require_once(PathHelper::getIncludePath('includes/SettingsDeclarations.php'));
require_once(PathHelper::getIncludePath('includes/SettingsWriter.php'));

$db = DbConnector::get_instance()->get_db_link();

// FormWriter starts a session for its CSRF token. Do it here, before the first
// line of test output, or every validate() call warns that headers are sent.
if (session_status() === PHP_SESSION_NONE) {
	@session_start();
}


// =========================================================================
section('A. Every stored row is declared');
// =========================================================================

// This is the permanent backstop, and it is load-bearing rather than
// belt-and-braces: data/files_class.php mints file_signed_url_key with raw SQL
// and ON CONFLICT DO NOTHING, and ThemeManager and OAuth2ProviderConfig create
// rows without any form. Removing auto-create does not by itself keep every row
// declared — only this does.
$rows = $db->query("SELECT stg_name FROM stg_settings ORDER BY stg_name")->fetchAll(PDO::FETCH_COLUMN);
$orphans = array();
foreach ($rows as $name) {
	if (Setting::isReservedName($name)) continue;
	if (!SettingsDeclarations::isDeclared($name)) $orphans[] = $name;
}
check(empty($orphans),
	count($rows) . ' stored rows, all declared',
	'undeclared: ' . implode(', ', $orphans));

// Names that are provably created outside any form must still be declared —
// they are the reason this check cannot be replaced by "no auto-create".
foreach (array('file_signed_url_key', 'database_version', 'system_version') as $name) {
	check(SettingsDeclarations::isDeclared($name), "$name is declared despite being written outside any form");
	check(SettingsDeclarations::isManaged($name), "$name is managed, so no form can offer it");
}


// =========================================================================
section('B. Every declaration is well-formed');
// =========================================================================

$schema_errors = SettingsDeclarations::schemaErrors();
check(empty($schema_errors),
	count(SettingsDeclarations::all()) . ' declarations validate',
	implode("\n", $schema_errors));

// Spot-check that the schema check is not vacuous.
check(count(SettingsDeclarations::schemaErrors()) === 0 && count(SettingsDeclarations::all()) > 300,
	'the declaration set is the real one, not an empty read');

// Every declared validation rule must be a usable regex where it is a pattern —
// a delimiter-less pattern makes preg_match fail and rejects every value.
$bad_patterns = array();
foreach (SettingsDeclarations::all() as $name => $d) {
	if (empty($d['validation']['pattern'])) continue;
	if (@preg_match($d['validation']['pattern'], 'probe') === false) {
		$bad_patterns[] = $name . ' => ' . $d['validation']['pattern'];
	}
}
check(empty($bad_patterns),
	'every declared pattern compiles',
	implode(', ', $bad_patterns));


// =========================================================================
section('C. A declared rule binds every page');
// =========================================================================

// SettingsWriter::validate() seeds its rules from the declarations, not from
// the fields a form registered, so a page that never drew the field is held to
// the same rule as the page that did.
$errors = SettingsWriter::validate(array('webDir' => 'https://example.com'));
check(isset($errors['webDir']), 'webDir rejects a pasted scheme', json_encode($errors));

$errors = SettingsWriter::validate(array('webDir' => 'example.com/'));
check(isset($errors['webDir']), 'webDir rejects a trailing slash', json_encode($errors));

$errors = SettingsWriter::validate(array('webDir' => 'example.com'));
check(empty($errors), 'webDir accepts a bare domain', json_encode($errors));

$errors = SettingsWriter::validate(array('terms_url' => 'javascript:alert(1)'));
check(isset($errors['terms_url']), 'terms_url rejects a javascript: scheme', json_encode($errors));

foreach (array('/terms', 'https://example.com/terms', '') as $ok) {
	$errors = SettingsWriter::validate(array('terms_url' => $ok));
	check(empty($errors), "terms_url accepts " . ($ok === '' ? '(empty)' : $ok), json_encode($errors));
}

// The mailbox clamps were the original complaint: one page enforced them and
// the other did not, so saving the second wrote values the first refused.
$errors = SettingsWriter::validate(array('mailbox_forwarding_rate_limit_window' => '0'));
check(isset($errors['mailbox_forwarding_rate_limit_window']),
	'the rate-limit window floor applies wherever the value is submitted from', json_encode($errors));

$errors = SettingsWriter::validate(array('mailbox_forwarding_rate_limit_window' => '3600'));
check(empty($errors), 'a valid window passes', json_encode($errors));

$errors = SettingsWriter::validate(array('stripe_api_pkey' => 'pk_live_0123456789012345678901234'));
check(isset($errors['stripe_api_pkey']),
	'the Stripe secret key field refuses a publishable key', json_encode($errors));


// =========================================================================
section('D. What the rewrite had to keep');
// =========================================================================

$admin_uid = (int)$db->query(
	"SELECT usr_user_id FROM usr_users
	  WHERE usr_permission >= 10 AND usr_delete_time IS NULL
	  ORDER BY usr_user_id LIMIT 1"
)->fetchColumn();

if (!$admin_uid) {
	harness_skip('no superadmin account to act as');
} else {
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_SESSION = array();
	$_SESSION['usr_user_id'] = $admin_uid;
	$_SESSION['loggedin']    = true;
	$_SESSION['permission']  = 10;

	$probe = 'totp_issuer_name';
	$read = $db->prepare("SELECT stg_value, stg_update_time FROM stg_settings WHERE stg_name = ?");
	$read->execute(array($probe));
	$original = $read->fetch(PDO::FETCH_ASSOC);

	if (!$original) {
		harness_skip("$probe has no row on this deployment");
	} else {
		harness_defer(function () use ($db, $probe, $original) {
			$db->prepare("UPDATE stg_settings SET stg_value = ?, stg_update_time = ? WHERE stg_name = ?")
			   ->execute(array($original['stg_value'], $original['stg_update_time'], $probe));
		});

		$db->prepare("UPDATE stg_settings SET stg_value = 'baseline', stg_update_time = '2020-01-01 00:00:00' WHERE stg_name = ?")
		   ->execute(array($probe));

		// An unchanged field is not a write. The settings form posts every
		// setting on the page, so without this one Save re-stamps
		// stg_update_time on ~160 rows and the column stops answering "when did
		// this value actually change?".
		$result = SettingsWriter::write(array($probe => 'baseline'), array('page' => 'test'));
		$read->execute(array($probe));
		$after = $read->fetch(PDO::FETCH_ASSOC);
		check(!in_array($probe, $result['written'], true), 'an unchanged value is not reported as written');
		check($after['stg_update_time'] === '2020-01-01 00:00:00',
			'an unchanged value does not re-stamp stg_update_time', $after['stg_update_time']);

		$result = SettingsWriter::write(array($probe => 'changed'), array('page' => 'test'));
		$read->execute(array($probe));
		check($read->fetch(PDO::FETCH_ASSOC)['stg_value'] === 'changed', 'a changed value is written');
		check(in_array($probe, $result['written'], true), 'and is reported as written');

		// A validation failure writes nothing at all, rather than writing the
		// valid half of the page and leaving the admin to guess.
		//
		// webDir is the probe because it is the one core setting carrying a
		// reject-don't-rewrite rule. Snapshot it first: if the rule ever stops
		// firing this save WILL write it, and a wrong webDir breaks every
		// absolute URL the deployment builds.
		$read->execute(array('webDir'));
		$webdir_before = $read->fetch(PDO::FETCH_ASSOC);
		harness_defer(function () use ($db, $webdir_before) {
			if (!$webdir_before) return;
			$db->prepare("UPDATE stg_settings SET stg_value = ?, stg_update_time = ? WHERE stg_name = 'webDir'")
			   ->execute(array($webdir_before['stg_value'], $webdir_before['stg_update_time']));
		});

		// Only *changed* values are validated, so the invalid submission has to
		// differ from what is stored for the rule to have anything to say.
		$db->prepare("UPDATE stg_settings SET stg_value = 'valid.example' WHERE stg_name = 'webDir'")->execute();

		$result = SettingsWriter::write(
			array($probe => 'would-be-fine', 'webDir' => 'https://nope.example'),
			array('page' => 'test')
		);
		$read->execute(array($probe));
		check($read->fetch(PDO::FETCH_ASSOC)['stg_value'] === 'changed',
			'a save with one invalid field writes nothing');
		check(isset($result['errors']['webDir']), 'and says which field was wrong', json_encode($result['errors']));

		$read->execute(array('webDir'));
		check($read->fetch(PDO::FETCH_ASSOC)['stg_value'] === 'valid.example',
			'and the invalid value itself was not stored');

		// A managed value is never writable from a request, however it arrives.
		$read->execute(array('system_version'));
		$sysver = $read->fetch(PDO::FETCH_ASSOC);
		if ($sysver) {
			$result = SettingsWriter::write(array('system_version' => '99.99.99'), array('page' => 'test'));
			$read->execute(array('system_version'));
			$now = $read->fetch(PDO::FETCH_ASSOC);
			check(in_array('system_version', $result['refused'], true),
				'a managed setting is refused', json_encode($result['refused']));
			check($now['stg_value'] === $sysver['stg_value'], 'and is not written');
		}
	}


	// =====================================================================
	section('E. Scope');
	// =====================================================================

	// The Plugin Settings tab names one plugin, and a crafted post must not be
	// able to reach a core row or a sibling plugin's row through it.
	$read->execute(array('totp_issuer_name'));
	$before = $read->fetch(PDO::FETCH_ASSOC);

	$result = SettingsWriter::write(
		array('totp_issuer_name' => 'crafted-by-a-plugin-form'),
		array('page' => 'test', 'source' => 'mailbox')
	);
	check(in_array('totp_issuer_name', $result['refused'], true),
		'a core setting is out of scope for a plugin-scoped save', json_encode($result['refused']));

	$read->execute(array('totp_issuer_name'));
	check($read->fetch(PDO::FETCH_ASSOC)['stg_value'] === $before['stg_value'],
		'and its value is untouched');

	// An undeclared name is refused whatever the scope.
	$result = SettingsWriter::write(
		array('a_setting_nobody_declared' => 'x'),
		array('page' => 'test')
	);
	check(in_array('a_setting_nobody_declared', $result['refused'], true),
		'an undeclared name is refused');
	$count = $db->prepare("SELECT COUNT(*) FROM stg_settings WHERE stg_name = ?");
	$count->execute(array('a_setting_nobody_declared'));
	check((int)$count->fetchColumn() === 0, 'and no row is created for it');

	// Form and request plumbing is never a candidate, in any mode.
	$result = SettingsWriter::write(array('_csrf_token' => 'abc'), array('page' => 'test'));
	check(!in_array('_csrf_token', $result['written'], true)
		&& !in_array('_csrf_token', $result['refused'], true),
		'form plumbing is not even considered');
	$count = $db->prepare("SELECT COUNT(*) FROM stg_settings WHERE stg_name = '_csrf_token'");
	$count->execute();
	check((int)$count->fetchColumn() === 0, 'and mints no row');
}

// =========================================================================
section('F. Rendering comes from the declarations, and only from them');
// =========================================================================
// The set of names a settings page emits is not literal in its source: the
// email tab builds its fields per discovered provider, and the plugin tab per
// active plugin. A sweep that reads page source therefore reports clean and is
// wrong. These checks drive the renderer instead and look at what came out.

require_once(PathHelper::getIncludePath('includes/SettingsFieldRenderer.php'));
require_once(PathHelper::getIncludePath('includes/FormWriterV2HTML5.php'));

$declared = SettingsDeclarations::all();

// Every group of every source, rendered for real, and every name it emitted
// checked against the manifests.
$emitted   = array();
$undeclared_emitted = array();
$sources   = array_merge(array('core'), SettingsDeclarations::renderableSources());
foreach ($sources as $source) {
	foreach (SettingsDeclarations::groupsFor($source) as $group) {
		foreach (SettingsFieldRenderer::namesFor($group, $source) as $name) {
			$emitted[] = $name;
			if (!isset($declared[$name])) $undeclared_emitted[] = "$source:$group:$name";
		}
	}
}
check(count($emitted) > 100, 'the renderer has a real amount of work to describe',
	count($emitted) . ' fields across ' . count($sources) . ' sources');
check(empty($undeclared_emitted), 'every name the renderer emits is declared',
	implode(', ', $undeclared_emitted));

// A managed setting is machine-written and must never reach a form.
$managed_emitted = array();
foreach ($emitted as $name) {
	if (!empty($declared[$name]['managed'])) $managed_emitted[] = $name;
}
check(empty($managed_emitted), 'a machine-written setting is never offered as a field',
	implode(', ', $managed_emitted));

// Renderable means labelled: a field titled with its own setting name is a
// declaration somebody forgot to finish.
$unlabelled = array();
foreach ($emitted as $name) {
	if (empty($declared[$name]['label'])) $unlabelled[] = $name;
}
check(empty($unlabelled), 'every renderable declaration carries a label',
	implode(', ', $unlabelled));

// A select must have somewhere to get its choices, and that source must
// actually answer — an options_from that resolves to nothing renders an empty
// dropdown, which reads as "no choices exist" rather than "this is broken".
$empty_options = array();
foreach ($emitted as $name) {
	$d = $declared[$name];
	if (($d['type'] ?? '') !== 'select') continue;
	if (empty($d['options_from'])) continue;
	if (empty(SettingsDeclarations::resolveOptions($d))) $empty_options[] = $name;
}
check(empty($empty_options), 'every discovered option list returns something on this install',
	implode(', ', $empty_options));

// The lock on the door: a page that draws its own field for a declared setting
// is stopped where the violation happens rather than being found later by
// reading source. Asserted by provoking it, not by re-implementing the check.
$form = new FormWriterV2HTML5('guard_probe');
$threw = false;
$message = '';
ob_start();
try {
	$form->textinput('site_name', 'Site Name', array('value' => 'x'));
} catch (Throwable $e) {
	$threw = true;
	$message = $e->getMessage();
}
ob_end_clean();
check($threw, 'a page drawing a declared setting itself is refused', $message);
check($threw && strpos($message, 'site_name') !== false
	&& strpos($message, 'site_identity') !== false
	&& strpos($message, 'settings.json') !== false,
	'and the refusal names the setting, its group, and the manifest to edit', $message);

// The same call through the renderer is allowed — otherwise the guard would
// have banned settings fields outright rather than banning hand-drawn ones.
$rendered = array();
ob_start();
try {
	$rendered = SettingsFieldRenderer::renderGroup($form, 'site_identity', array(
		'source' => 'core',
		'only'   => array('site_name'),
	));
} catch (Throwable $e) {
	$message = $e->getMessage();
}
ob_end_clean();
check($rendered === array('site_name'), 'the same field drawn through the renderer goes through',
	json_encode($rendered) . ' ' . $message);

// The guard must not leak: an ordinary model field that happens to pass
// through the same choke point is untouched.
ob_start();
$ordinary_ok = true;
try {
	$form->textinput('usr_first_name', 'First name', array('value' => 'x'));
} catch (Throwable $e) {
	$ordinary_ok = false;
	$message = $e->getMessage();
}
ob_end_clean();
check($ordinary_ok, 'a field that is not a setting is drawn as before', $message);


harness_finish();
