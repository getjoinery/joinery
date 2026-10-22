<?php
/** @joinery-test
 * name: group_members_duplicate_ids
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * migrations/group_members_duplicate_ids.php — group memberships that share an
 * id each get one of their own.
 *
 * Builds the shape a site without the table's primary key has: unique ids, one
 * id held by two rows, another by three, and a sequence left behind them. The
 * first row of each shared id keeps it; the others take ids above every id the
 * table held; every membership (group and member) is still there; a second run
 * changes nothing and the registry's test query then skips it.
 *
 * Runs on a temporary grm_group_members and sequence of the same names, inside
 * a transaction that is rolled back: Postgres resolves the temporary ones
 * first, so no real row or sequence moves (checked before the migration runs).
 *
 * Run: php tests/run.php db --filter=group_members_duplicate_ids
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

require_once(PathHelper::getIncludePath('migrations/group_members_duplicate_ids.php'));

$db = DbConnector::get_instance()->get_db_link();

$registry_test = function () use ($db) {
	require(PathHelper::getIncludePath('migrations/migrations.php'));
	foreach ($migrations as $m) {
		if (($m['migration_file'] ?? '') === 'group_members_duplicate_ids.php') {
			return (int)$db->query($m['test'])->fetchColumn();
		}
	}
	return null;
};

$memberships = function () use ($db) {
	return $db->query('SELECT grm_group_member_id AS id, grm_grp_group_id AS grp, grm_foreign_key_id AS fk
		FROM grm_group_members ORDER BY grm_grp_group_id, grm_foreign_key_id')->fetchAll(PDO::FETCH_ASSOC);
};

$db->beginTransaction();
try {
	$db->exec('CREATE TEMP SEQUENCE grm_group_members_grm_group_member_id_seq');
	$db->exec("CREATE TEMP TABLE grm_group_members (
		grm_group_member_id int8 NOT NULL DEFAULT nextval('grm_group_members_grm_group_member_id_seq'),
		grm_grp_group_id int4, grm_foreign_key_id int8)");
	// In table order: the first row of each shared id is the one that keeps it.
	$rows = array(
		array(1, 7, 100), array(2, 7, 101), array(25, 7, 102), array(25, 8, 100),
		array(28, 8, 101), array(28, 8, 102), array(28, 9, 100), array(3, 9, 101),
	);
	$ins = $db->prepare('INSERT INTO grm_group_members (grm_group_member_id, grm_grp_group_id, grm_foreign_key_id) VALUES (?, ?, ?)');
	foreach ($rows as $r) {
		$ins->execute($r);
	}
	$db->exec("SELECT setval('grm_group_members_grm_group_member_id_seq', 3)");

	section('The shape a site without the key has');
	$resolves_to_temp = $db->query("SELECT to_regclass('grm_group_members') = to_regclass('pg_temp.grm_group_members')
		AND to_regclass('grm_group_members_grm_group_member_id_seq') = to_regclass('pg_temp.grm_group_members_grm_group_member_id_seq')")->fetchColumn();
	check($resolves_to_temp === true || $resolves_to_temp === 't', 'the table and sequence names reach the temporary ones, not the real ones');
	check($registry_test() === 0, 'the registry test says the migration is due');

	section('Each membership gets an id of its own');
	$before = $memberships();
	ob_start();
	$ok = group_members_duplicate_ids();
	$out = ob_get_clean();
	$after = $memberships();
	$ids = array_map('intval', array_column($after, 'id'));
	$by_pair = array();
	foreach ($after as $m) {
		$by_pair[$m['grp'] . '/' . $m['fk']] = (int)$m['id'];
	}
	check($ok === true && strpos($out, 'Gave 3 group membership(s)') !== false, 'it renumbers the three rows that shared an id', $out);
	check(count($ids) === count(array_unique($ids)), 'no id is shared', json_encode($ids));
	check(array_map(function ($m) { return $m['grp'] . '/' . $m['fk']; }, $after)
		=== array_map(function ($m) { return $m['grp'] . '/' . $m['fk']; }, $before), 'every membership is still there', json_encode($after));
	check($by_pair['7/102'] === 25 && $by_pair['8/101'] === 28 && $by_pair['7/100'] === 1 && $by_pair['9/101'] === 3,
		'the first row of each shared id keeps it, and an unshared id is untouched', json_encode($by_pair));
	check(min($by_pair['8/100'], $by_pair['8/102'], $by_pair['9/100']) > 28,
		'the others take ids above every id the table held, though the sequence was behind', json_encode($by_pair));

	section('A second run changes nothing');
	ob_start();
	group_members_duplicate_ids();
	$out = ob_get_clean();
	check(strpos($out, 'Gave 0 group membership(s)') !== false && $memberships() === $after, 'nothing moves', $out);
	check($registry_test() === 1, 'and the registry test skips it');
} finally {
	$db->rollBack();
}

harness_finish();
