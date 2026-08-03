<?php
/** @joinery-test
 * name: settings_reserved_names
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * The boundary between a setting and the form plumbing that shares its POST.
 *
 * The settings pages save by walking the submitted request, so every field the
 * browser sends is a candidate setting — including the ones the form machinery
 * adds itself. Without a boundary those become rows and are re-written on every
 * save. This is not hypothetical: `_csrf_token`, `submit_button`, `__route`,
 * both captcha response fields and seven `*_readonly` path mirrors were all
 * living in stg_settings, the oldest since 2025-10-17.
 *
 * Guarded here:
 *   A. Setting::isReservedName() names the boundary, and is not so broad that
 *      it swallows a real setting.
 *   B. A save never writes a reserved row, even when one already exists.
 *   C. A save never creates a reserved row.
 *
 * Run: php tests/integration/settings_reserved_names_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('data/settings_class.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_settings_logic.php'));
require_once(PathHelper::getIncludePath('adm/logic/admin_settings_email_logic.php'));

$db = DbConnector::get_instance()->get_db_link();


// =========================================================================
section('A. What the boundary covers');
// =========================================================================

$reserved = array(
	'_csrf_token', '__route', 'edit_primary_key_value', 'plugin_settings_target',
	'g-recaptcha-response', 'h-captcha-response', 'submit_button', 'btn_submit',
	'webDir_readonly', 'baseDir_readonly', 'site_template_readonly',
);
foreach ($reserved as $name) {
	check(Setting::isReservedName($name), "$name is never a setting");
}

// Over-broad matching would silently stop real settings from saving, which is a
// worse failure than the one being fixed — it is invisible.
$real = array(
	'webDir', 'baseDir', 'site_template', 'apache_error_log', 'emails_active',
	'joinery_ai_llm_provider', 'mailbox_enabled', 'request_log_retention_days',
);
foreach ($real as $name) {
	check(!Setting::isReservedName($name), "$name is still a writable setting");
}

// The reservation only means something if the live table honors it: a stored
// row with a reserved name is a file-config key being shadowed from the DB.
$stored = $db->query("SELECT stg_name FROM stg_settings")->fetchAll(PDO::FETCH_COLUMN);
$reserved_rows = array_values(array_filter($stored, function ($n) { return Setting::isReservedName($n); }));
check(count($reserved_rows) === 0, 'no stored settings row carries a reserved name',
	implode(', ', $reserved_rows));


// =========================================================================
section('B. A save never writes a reserved row');
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

	// Works whether or not the historic junk row has been cleaned up: create it
	// when absent, and drop it again either way.
	$probe = '_csrf_token';
	$existed = $db->prepare("SELECT stg_value, stg_update_time FROM stg_settings WHERE stg_name = ?");
	$existed->execute(array($probe));
	$row = $existed->fetch(PDO::FETCH_ASSOC);

	if (!$row) {
		$db->prepare("INSERT INTO stg_settings (stg_name, stg_value, stg_usr_user_id, stg_create_time, stg_update_time, stg_group_name)
		              VALUES (?, 'planted-by-test', 1, NOW(), NOW(), 'general')")->execute(array($probe));
		harness_defer(function () use ($db, $probe) {
			$db->prepare("DELETE FROM stg_settings WHERE stg_name = ?")->execute(array($probe));
		});
	} else {
		harness_defer(function () use ($db, $probe, $row) {
			$db->prepare("UPDATE stg_settings SET stg_value = ?, stg_update_time = ? WHERE stg_name = ?")
			   ->execute(array($row['stg_value'], $row['stg_update_time'], $probe));
		});
	}

	$read = $db->prepare("SELECT stg_value, stg_update_time FROM stg_settings WHERE stg_name = ?");
	$read->execute(array($probe));
	$before = $read->fetch(PDO::FETCH_ASSOC);

	admin_settings_logic(array($probe => 'a-freshly-rotated-token'));
	$read->execute(array($probe));
	$after = $read->fetch(PDO::FETCH_ASSOC);

	check($after['stg_value'] === $before['stg_value'],
		'the General save leaves an existing reserved row\'s value alone');
	check($after['stg_update_time'] === $before['stg_update_time'],
		'the General save does not re-stamp it either');

	admin_settings_email_logic(array($probe => 'another-token'));
	$read->execute(array($probe));
	$after_email = $read->fetch(PDO::FETCH_ASSOC);
	check($after_email['stg_value'] === $before['stg_value'],
		'the Email save leaves it alone too');


	// =====================================================================
	section('C. A save never creates a reserved row');
	// =====================================================================

	// Names the form machinery emits that have no row today. If the create path
	// ever mints one of these again, that is the original bug returning.
	$never = array('__route', 'submit_button', 'h-captcha-response', 'webDir_readonly');
	$count = $db->prepare("SELECT count(*) FROM stg_settings WHERE stg_name = ?");

	$absent = array();
	foreach ($never as $name) {
		$count->execute(array($name));
		if ((int)$count->fetchColumn() === 0) $absent[] = $name;
	}

	if (empty($absent)) {
		harness_skip('every candidate name still has a historic row; nothing to prove absent');
	} else {
		$post = array();
		foreach ($absent as $name) $post[$name] = 'submitted-by-a-browser';
		harness_defer(function () use ($db, $absent) {
			$in = implode(',', array_fill(0, count($absent), '?'));
			$db->prepare("DELETE FROM stg_settings WHERE stg_name IN ($in)")->execute($absent);
		});

		admin_settings_logic($post);

		$created = array();
		foreach ($absent as $name) {
			$count->execute(array($name));
			if ((int)$count->fetchColumn() > 0) $created[] = $name;
		}
		check(empty($created),
			'a save creates no setting row for form plumbing (' . count($absent) . ' names tried)',
			implode(', ', $created));
	}
}

harness_finish();
