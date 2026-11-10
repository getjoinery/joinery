<?php
/** @joinery-test
 * name: provider_column_migrations
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * Migrations 122 and 124 rename the MailChimp columns to provider-neutral
 * names. The schema step runs before migrations and may already have made the
 * new column from the model, so a database can hold both: then 122 copies the
 * list ids across and 124 has nothing to do. Neither may fail with a duplicate
 * column (an upgrade of getjoinery to 0.8.600 did, and rolled back).
 *
 * Each case runs inside a transaction that is rolled back.
 *
 * Run: php tests/run.php db --filter=provider_column_migrations
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$all = (function () { require PathHelper::getIncludePath('migrations/migrations.php'); return $migrations; })();
$by = array();
foreach ($all as $m) {
	$by[$m['database_version']] = $m;
}
$db = DbConnector::get_instance()->get_db_link();
$one = function (string $sql) use ($db) { return $db->query($sql)->fetchColumn(); };
$has_column = function (string $table, string $column) use ($one): bool {
	return (bool)$one("SELECT count(*) FROM information_schema.columns WHERE table_name = '$table' AND column_name = '$column'");
};
$in_rollback = function (callable $fn) use ($db) {
	$db->beginTransaction();
	try {
		$fn();
	} finally {
		$db->rollBack();
	}
};

if (!$has_column('mlt_mailing_lists', 'mlt_provider_list_id')) {
	section('122');
	harness_skip('mlt_mailing_lists', 'the email_lists table is not on this database');
} else {
	section('122 with both columns copies the list ids');
	$in_rollback(function () use ($db, $by, $one) {
		$db->exec('ALTER TABLE mlt_mailing_lists ADD COLUMN mlt_mailchimp_list_id varchar(64)');
		$list = new MailingList(NULL);
		$list->set('mlt_name', 'HarnessTest provider column');
		$list->set('mlt_link', 'harnesstest-provider-' . bin2hex(random_bytes(3)));
		$list->save();
		$list->load();
		$db->prepare("UPDATE mlt_mailing_lists SET mlt_provider_list_id = NULL, mlt_mailchimp_list_id = 'mc-123' WHERE mlt_mailing_list_id = ?")
			->execute(array($list->key));
		check((int)$one($by['122']['test']) === 0, 'the test says run');
		$db->exec($by['122']['migration_sql']);
		check($one('SELECT mlt_provider_list_id FROM mlt_mailing_lists WHERE mlt_mailing_list_id = ' . (int)$list->key) === 'mc-123',
			'the list id is copied into mlt_provider_list_id');
	});

	section('122 with the old column only renames it');
	$in_rollback(function () use ($db, $by, $has_column) {
		$db->exec('ALTER TABLE mlt_mailing_lists RENAME COLUMN mlt_provider_list_id TO mlt_mailchimp_list_id');
		$db->exec($by['122']['migration_sql']);
		check($has_column('mlt_mailing_lists', 'mlt_provider_list_id') && !$has_column('mlt_mailing_lists', 'mlt_mailchimp_list_id'),
			'the column is renamed');
	});
}

section('124 with both columns does nothing, with the old one only renames');
$in_rollback(function () use ($db, $by, $one, $has_column) {
	$db->exec('ALTER TABLE usr_users ADD COLUMN IF NOT EXISTS usr_mailchimp_user_id varchar(64)');
	$db->exec('ALTER TABLE usr_users ADD COLUMN IF NOT EXISTS usr_mailing_list_provider_id varchar(64)');
	check((int)$one($by['124']['test']) === 0, 'the test says run');
	$db->exec($by['124']['migration_sql']);
	check($has_column('usr_users', 'usr_mailchimp_user_id'), 'both columns: it runs without a duplicate column');
	$db->exec('ALTER TABLE usr_users DROP COLUMN usr_mailing_list_provider_id');
	$db->exec($by['124']['migration_sql']);
	check($has_column('usr_users', 'usr_mailing_list_provider_id') && !$has_column('usr_users', 'usr_mailchimp_user_id'),
		'old column only: it is renamed');
});

harness_finish();
