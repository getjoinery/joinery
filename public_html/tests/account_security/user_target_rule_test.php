<?php
/** @joinery-test
 * name: user_target_rule
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Who may change a user, and how a user's second-factor secrets are kept
 * (spec declared_permissions 3.8, B22, B24, B25, B26, B29).
 *
 * What is pinned here:
 *  - the target rule in User::authenticate_write(): below 10, staff change only
 *    a user whose stored level is strictly below their own, and grant at most
 *    their own level, to themselves too; 10 is exempt; a user saving their own
 *    row at an unchanged level is allowed even when their session was zeroed;
 *    the stored level is read from the database for a row never loaded;
 *  - the authenticator secret and the trusted-device key are stored as SecretBox
 *    blobs and open through User::totp_secret() / second_factor_hmac_key(); a
 *    value from before they were sealed still reads; one that no longer opens
 *    reads as not set;
 *  - the node API secret, the same way, through ManagedNode;
 *  - the contact-preferences link token is random, 32 hex characters;
 *  - update_database widens a varchar the spec makes longer
 *    (DatabaseUpdater::widenCharacterColumn()), which is what lets new code write
 *    a sealed value from its first request.
 *
 * Writes nothing but its own fixture users. Every permission case runs on the
 * in-memory object and never saves.
 *
 * Run: php tests/run.php db --filter=user_target_rule
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

/** Whether a user at $actor_permission (id $actor_id) may write $target. */
function utr_may(User $target, int $actor_permission, int $actor_id = 999999999): bool {
	try {
		$target->authenticate_write(array('current_user_id' => $actor_id, 'current_user_permission' => $actor_permission));
		return true;
	} catch (SystemAuthenticationError $e) {
		return false;
	}
}

try {
	section('Staff change only users below their own level');
	$ten   = make_user('TargetRule10', 10);
	$eight = make_user('TargetRule8', 8);
	$zero  = make_user('TargetRule0', 0);

	check(!utr_may($ten, 8), 'an 8 may not change a 10');
	check(!utr_may($ten, 9), 'a 9 may not change a 10');
	check(utr_may($ten, 10), 'a 10 may change another 10');
	check(!utr_may($eight, 8), 'an 8 may not change another 8');
	check(utr_may($zero, 8) && utr_may($zero, 5), 'an 8 or a 5 may change a 0');
	check(!utr_may($zero, 0), 'a member may not change another member');

	$unloaded = new User((int)$ten->key);
	check(!utr_may($unloaded, 8), 'the stored level is read from the database for a row never loaded');

	section('Grant at most your own level');
	$zero->set('usr_permission', 10);
	check(!utr_may($zero, 8), 'an 8 may not grant 10');
	$zero->set('usr_permission', 8);
	check(utr_may($zero, 8), 'an 8 may grant 8');
	$zero->set('usr_permission', 5);
	check(!utr_may($zero, 0, (int)$zero->key), 'a member may not raise themselves');
	$zero->set('usr_permission', 0);
	check(utr_may($zero, 0, (int)$zero->key), 'a member may save their own row');
	check(utr_may($eight, 0, (int)$eight->key), 'an 8 whose session was zeroed may still save their own row at an unchanged level');

	$fresh = new User(NULL);
	$fresh->set('usr_permission', 0);
	check(utr_may($fresh, 8), 'an 8 may create a member');
	$fresh->set('usr_permission', 9);
	check(!utr_may($fresh, 8), 'an 8 may not create a 9');

	section('The authenticator secret is sealed');
	$seed = 'JBSWY3DPEHPK3PXP';
	$zero->enable_totp($seed);
	$stored = (string)$zero->get('usr_totp_secret');
	check(SecretBox::looksEncrypted($stored) && strpos($stored, $seed) === false, 'it is stored as a SecretBox blob, not the seed');
	$reloaded = new User((int)$zero->key, TRUE);
	check($reloaded->totp_secret() === $seed, 'totp_secret() opens it after a reload');

	$legacy = new User((int)$zero->key, TRUE);
	$legacy->set('usr_totp_secret', $seed);
	check($legacy->totp_secret() === $seed, 'a value from before sealing still reads');

	$dead = new User((int)$zero->key, TRUE);
	$dead->set('usr_totp_secret', substr($stored, 0, -6) . 'AAAAAA');
	check($dead->totp_secret() === null, 'a blob that no longer opens reads as not set');

	section('The trusted-device key is sealed');
	$zero->rotate_second_factor_hmac_key();
	$reloaded = new User((int)$zero->key, TRUE);
	$key = $reloaded->second_factor_hmac_key();
	check(SecretBox::looksEncrypted((string)$reloaded->get('usr_second_factor_hmac_key')), 'it is stored as a SecretBox blob');
	check($key !== null && strlen($key) === 128 && ctype_xdigit($key), 'second_factor_hmac_key() opens it to 128 hex characters');

	$reloaded->disable_totp();
	$after = new User((int)$zero->key, TRUE);
	check($after->totp_secret() === null, 'turning the authenticator off clears its secret');
	check($after->second_factor_hmac_key() !== null && $after->second_factor_hmac_key() !== $key,
		'and mints a new sealed trusted-device key');

	section('The node API secret is sealed');
	if (class_exists('ManagedNode')) {
		$node = new ManagedNode(NULL);
		$node->set_api_secret_key('node-secret-value');
		check(SecretBox::looksEncrypted((string)$node->get('mgn_api_secret_key')), 'it is stored as a SecretBox blob');
		check($node->api_secret_key() === 'node-secret-value', 'api_secret_key() opens it');
		$node->set_api_secret_key(null);
		check($node->get('mgn_api_secret_key') === null && $node->api_secret_key() === null, 'null removes it');
		$node->set('mgn_api_secret_key', 'v1.sodium.not-a-real-blob');
		check($node->api_secret_key() === null && !JobCommandBuilder::has_api_creds($node),
			'one that no longer opens counts as no credential');
	} else {
		check(true, 'server_manager is not active here; the node checks are skipped');
	}

	section('The contact-preferences link token is random');
	$a = User::new_authhash();
	$b = User::new_authhash();
	check(strlen($a) === 32 && ctype_xdigit($a) && $a !== $b, '32 hex characters, a new one each time');
	$fixture = new User((int)$ten->key, TRUE);
	check(strlen((string)$fixture->get('usr_authhash')) === 32, 'a saved user carries one');

	section('update_database widens a column the spec makes longer');
	$db = DbConnector::get_instance()->get_db_link();
	$db->exec('CREATE TEMP TABLE utr_widen (narrow varchar(8), wider varchar(8), same varchar(8))');
	$widen = new ReflectionMethod('DatabaseUpdater', 'widenCharacterColumn');
	$widen->setAccessible(true);
	$updater = new DatabaseUpdater();
	$results = array('messages' => array(), 'errors' => array());
	$live = array('data_type' => 'character varying', 'character_maximum_length' => 8);
	$to_text = $widen->invokeArgs($updater, array('utr_widen', 'narrow', array('type' => 'text'), $live, $db, &$results));
	$to_longer = $widen->invokeArgs($updater, array('utr_widen', 'wider', array('type' => 'varchar(64)'), $live, $db, &$results));
	$unchanged = $widen->invokeArgs($updater, array('utr_widen', 'same', array('type' => 'varchar(8)'), $live, $db, &$results));
	$types = $db->query("SELECT attname, format_type(atttypid, atttypmod) FROM pg_attribute
		WHERE attrelid = 'utr_widen'::regclass AND attnum > 0")->fetchAll(PDO::FETCH_KEY_PAIR);
	check($to_text && $types['narrow'] === 'text', 'varchar(8) to text', json_encode($types));
	check($to_longer && $types['wider'] === 'character varying(64)', 'varchar(8) to varchar(64)', json_encode($types));
	check(!$unchanged && $types['same'] === 'character varying(8)' && empty($results['errors']),
		'a column already as wide is left alone', json_encode($results['errors']));
	$db->exec('DROP TABLE utr_widen');
} catch (Throwable $e) {
	check(false, 'suite ran to completion — ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

harness_finish();
