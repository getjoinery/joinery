<?php
/** @joinery-test
 * name: blank_value_repairs
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * Stored values that are blank or malformed, and what reads or repairs them.
 *
 * - A declared number setting stored blank reads as its declared default, never
 *   as the 0 that (int)'' makes; '0' stays 0, and a text setting stays blank.
 * - Migration 219: an account with no timezone (User::CreateNew used to write an
 *   explicit NULL) takes the site's default, and 'remember me' signs such an
 *   account in (its token is written as one column, not a full save()).
 *
 * Run: php tests/run.php test-db --filter=blank_value_repairs
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();

$db = DbConnector::get_instance()->get_db_link();
$gv = Globalvars::get_instance();

/** The registry entry for one migration version. */
function bvr_migration(string $version): array {
	$migrations = array();
	require(PathHelper::getIncludePath('migrations/migrations.php'));
	foreach ($migrations as $m) {
		if ((string)$m['database_version'] === $version) return $m;
	}
	return array();
}

/** Run a migration's test query and, when it says so, its SQL; returns the test's count before. */
function bvr_run(array $m): int {
	$db = DbConnector::get_instance()->get_db_link();
	$count = (int)$db->query($m['test'])->fetchColumn();
	if ($count === 0) {
		$db->exec($m['migration_sql']);
	}
	return $count;
}

/** Store a setting's raw value for this test and put the old one back after. */
function bvr_store(string $name, string $value): void {
	static $restored = array();
	if (!array_key_exists($name, $restored)) {
		$restored[$name] = get_setting_raw($name);
		$old = $restored[$name];
		harness_defer(function () use ($name, $old) {
			set_setting_raw($name, (string)$old);
			Globalvars::get_instance()->forget_setting($name);
		});
	}
	set_setting_raw($name, $value);
	Globalvars::get_instance()->forget_setting($name);
}

// ---------------------------------------------------------------------------
section('A declared number setting stored blank reads as its default');

bvr_store('outbound_monthly_notice_gb', '');
check((string)$gv->get_setting('outbound_monthly_notice_gb') === '1000',
	'blank reads the declared default', var_export($gv->get_setting('outbound_monthly_notice_gb'), true));
bvr_store('outbound_monthly_notice_gb', '0');
check((string)$gv->get_setting('outbound_monthly_notice_gb') === '0', 'a stored 0 stays 0');
bvr_store('outbound_monthly_notice_gb', '250');
check((string)$gv->get_setting('outbound_monthly_notice_gb') === '250', 'a stored number is read as stored');

bvr_store('support_url', '');
check((string)$gv->get_setting('support_url', true, true) === '', 'a blank text setting stays blank');

bvr_store('joinery_ai_failure_email_throttle_seconds', '');
check((string)$gv->get_setting('joinery_ai_failure_email_throttle_seconds') === '86400',
	'a plugin\'s number setting reads its manifest default too');

// ---------------------------------------------------------------------------
section('Migration 219: an account with no timezone takes the site default');

$m219 = bvr_migration('219');
check(!empty($m219['test']) && !empty($m219['migration_sql']), 'migration 219 is registered');

$u = new User(NULL);
$u->set('usr_first_name', 'HarnessTest');
$u->set('usr_last_name', 'NoTimezone');
$u->set('usr_email', harness_fixture_email('bvr_tz_' . LibraryFunctions::random_string(4)));
$u->set('usr_password', User::GeneratePassword('TestPassword_bvr'));
$u->set('usr_permission', 0);
$u->set('usr_terms_accepted_time', gmdate('Y-m-d H:i:s'));
$u->save();
$u->load();
harness_register_user($u);
$uid = (int)$u->key;
$db->prepare('UPDATE usr_users SET usr_timezone = NULL WHERE usr_user_id = ?')->execute(array($uid));

// 'Remember me' with the bad row: the token write touches one column.
$_SESSION['usr_user_id'] = $uid;
$_SESSION['loggedin'] = true;
$threw = null;
set_error_handler(function ($no, $str) { return stripos($str, 'header') !== false || stripos($str, 'cookie') !== false; });
try {
	SessionControl::get_instance()->save_user_to_cookie();
} catch (\Throwable $e) {
	$threw = $e->getMessage();
} finally {
	restore_error_handler();
	unset($_SESSION['usr_user_id'], $_SESSION['loggedin']);
}
check($threw === null, '\'remember me\' signs in an account with no timezone', (string)$threw);
$tokens = json_decode((string)$db->query('SELECT usr_remember_tokens FROM usr_users WHERE usr_user_id = ' . $uid)->fetchColumn(), true);
check(is_array($tokens) && count($tokens) === 1, 'and its token is stored');

check(bvr_run($m219) === 0, 'the test query finds the account');
$tz = (string)$db->query('SELECT usr_timezone FROM usr_users WHERE usr_user_id = ' . $uid)->fetchColumn();
$site_default = trim((string)get_setting_raw('default_timezone'));
check($tz === ($site_default !== '' ? $site_default : 'America/New_York'), 'the account takes the site\'s default timezone', $tz);
check(bvr_run($m219) === 1, 'a second run finds nothing');

harness_finish();
