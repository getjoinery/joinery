<?php
/** @joinery-test
 * name: email_settings_first_save
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * Choosing an email service and filling in its settings in ONE save works.
 *
 * The Email settings page checked the chosen service against the settings
 * stored BEFORE the save, so a first setup (pick SMTP, type its host, Save)
 * was refused as "not properly configured" and every field was lost (B25).
 * The chosen service is now checked against what the save leaves stored; one
 * that is still not configured goes back to the previous choice, and the rest
 * of the save stays.
 *
 * Run: php tests/run.php test-db --filter=email_settings_first_save
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../lib/harness.php');
harness_boot();
harness_test_mode();
require_once(PathHelper::getIncludePath('data/settings_class.php'));
require_once(__DIR__ . '/../lib/logic.php');

$snapshot = harness_settings_snapshot();
harness_defer(function () use ($snapshot) { harness_settings_restore($snapshot); });
$gv = Globalvars::get_instance();

/** Post the Email settings page as a superadmin; returns the LogicResult. */
function esfs_post(array $fields) {
	$admin = new MultiUser(array('usr_permission' => 10, 'deleted' => false), array('usr_user_id' => 'ASC'), 1);
	$admin_id = 0;
	foreach ($admin as $u) { $admin_id = (int)$u->key; }
	$_SESSION = array('usr_user_id' => $admin_id, 'loggedin' => true, 'permission' => 10);
	return harness_call_logic('adm/logic/admin_settings_email_logic.php', 'admin_settings_email_logic', $fields, 'POST');
}
function esfs_stored(string $name): string {
	$q = DbConnector::GetPreparedStatement('SELECT stg_value FROM stg_settings WHERE stg_name = ?');
	$q->execute(array($name));
	return (string)$q->fetchColumn();
}

// A site with no service chosen and no SMTP host.
Setting::put('email_service', '');
Setting::put('email_fallback_service', '');
Setting::put('smtp_host', '');
foreach (array('email_service', 'email_fallback_service', 'smtp_host') as $n) { $gv->forget_setting($n); }

section('A service and its settings in one save');

$host = 'smtp-' . substr(md5((string)microtime(true)), 0, 6) . '.example.com';
$r = esfs_post(array('email_service' => 'smtp', 'smtp_host' => $host));
check(empty($r->error) && (string)$r->redirect !== '', 'the save is accepted', json_encode(array($r->error, $r->data['errors'] ?? null)));
check(esfs_stored('email_service') === 'smtp', 'SMTP is the chosen service', esfs_stored('email_service'));
check(esfs_stored('smtp_host') === $host, 'and its host is stored', esfs_stored('smtp_host'));

section('A service still not configured goes back; the rest stays');

Setting::put('smtp_host', '');
$gv->forget_setting('smtp_host');
Setting::put('email_service', '');
$gv->forget_setting('email_service');
$r = esfs_post(array('email_service' => 'smtp', 'smtp_host' => '', 'mailgun_domain' => 'mg.esfs.example.com'));
$errors = implode(' ', (array)($r->data['errors'] ?? array()));
check(stripos($errors, 'SMTP host not configured') !== false, 'the page says what is missing', $errors);
check(esfs_stored('email_service') === '', 'the service goes back to what it was', var_export(esfs_stored('email_service'), true));
check(esfs_stored('mailgun_domain') === 'mg.esfs.example.com', 'and the rest of the save stays', esfs_stored('mailgun_domain'));

harness_finish();
