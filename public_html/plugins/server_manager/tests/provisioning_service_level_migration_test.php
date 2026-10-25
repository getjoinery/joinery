<?php
/** @joinery-test
 * name: provisioning_service_level_migration
 * tier: test-db
 * env: dev-only
 * needs: [test-db]
 */
/**
 * sm_027: the provisioning service account a site made before the id setting
 * existed (no server_manager_provisioning_service_user_id) was made at 5 and
 * is refused the personal models the pipeline reads and writes. sm_026 finds
 * the account by that setting only, so sm_027 raises the one
 * ProvisioningSetup::adoptableServiceUser() trusts: at provisioning@, owning
 * the active key the pipeline is configured with. An account at that address
 * that does not own the key, and a person who owns other keys, are left alone.
 *
 * Run: php tests/run.php --filter=provisioning_service_level_migration
 *
 * @version 1.0
 */
require_once(__DIR__ . '/../../../tests/lib/harness.php');
harness_boot();
harness_test_mode();

$db = DbConnector::get_instance()->get_db_link();
$run = strtolower(LibraryFunctions::random_string(6));

$migrate = null;
foreach (require(PathHelper::getIncludePath('plugins/server_manager/migrations/migrations.php')) as $mg) {
	if (($mg['id'] ?? '') === 'sm_027_legacy_provisioning_service_user_at_8') $migrate = $mg['up'];
}
check($migrate !== null, 'sm_027 is declared');

function pslm_user(string $email, int $level) {
	$u = make_user('Pslm' . bin2hex(random_bytes(3)), $level);
	$q = DbConnector::get_instance()->get_db_link()->prepare('UPDATE usr_users SET usr_email = ? WHERE usr_user_id = ?');
	$q->execute(array($email, $u->key));
	return (int)$u->key;
}
function pslm_key(int $user_id, string $public) {
	$k = new ApiKey(NULL);
	$k->set('apk_usr_user_id', $user_id);
	$k->set('apk_name', 'pslm');
	$k->set('apk_public_key', $public);
	$k->set('apk_is_active', true);
	$k->set('apk_permission', 3);
	$k->save();
	harness_register_row('apk_api_keys', 'apk_api_key_id', $k->key);
}
function pslm_level(int $id): int {
	$q = DbConnector::get_instance()->get_db_link()->prepare('SELECT usr_permission FROM usr_users WHERE usr_user_id = ?');
	$q->execute(array($id));
	return (int)$q->fetchColumn();
}

$configured = 'pslm' . $run . 'cfg';
$service = pslm_user('provisioning@pslm-' . $run . '.test', 5);
pslm_key($service, $configured);
$squatter = pslm_user('provisioning@pslm-other-' . $run . '.test', 5);
pslm_key($squatter, 'pslm' . $run . 'oth');
$person = pslm_user('someone-' . $run . '@pslm.test', 5);
pslm_key($person, 'pslm' . $run . 'per');

$prev = $db->query("SELECT stg_value FROM stg_settings WHERE stg_name = 'server_manager_getjoinery_api_public_key'")->fetchColumn();
if ($prev === false) {
	$db->prepare("INSERT INTO stg_settings (stg_name, stg_value) VALUES ('server_manager_getjoinery_api_public_key', ?)")->execute(array($configured));
	harness_defer(function () { DbConnector::get_instance()->get_db_link()->exec("DELETE FROM stg_settings WHERE stg_name = 'server_manager_getjoinery_api_public_key'"); });
} else {
	$db->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = 'server_manager_getjoinery_api_public_key'")->execute(array($configured));
	harness_defer(function () use ($prev) { DbConnector::get_instance()->get_db_link()->prepare("UPDATE stg_settings SET stg_value = ? WHERE stg_name = 'server_manager_getjoinery_api_public_key'")->execute(array($prev)); });
}

section('the legacy service account is raised to 8, nothing else');
if ($migrate) {
	ob_start(); $migrate(DbConnector::get_instance()); ob_end_clean();
}
check(pslm_level($service) === 8, 'the provisioning@ account owning the configured key is at 8', (string)pslm_level($service));
check(pslm_level($squatter) === 5, 'a provisioning@ account owning another key stays at 5');
check(pslm_level($person) === 5, 'another person stays at 5');

harness_finish();
