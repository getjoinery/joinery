<?php
/** @joinery-test
 * name: deletion_cascade_leaf
 * tier: db
 * env: any
 * needs: []
 */
/**
 * A flat cascade lands only on a table nothing else depends on.
 *
 * `cascade` is one SQL DELETE: the dependent's own deletion rules never run
 * (docs/deletion_system.md). On a table that has dependents of its own that
 * is wrong in one of two ways. Where the grandchild has no database foreign
 * key, its rows are left naming a parent that is gone: removing an account
 * left its backup-storage tenant's spaces, ledger rows and runs behind, and
 * referential_integrity caught them on dev. Where the grandchild has one, the
 * DELETE is refused and the whole removal fails: a user with a spooled Direct
 * message could not be removed. The rule for such a table is
 * `permanent_delete`, which loads each row and runs its own rules.
 *
 * A leaf here is a table no rule of its own removes or guards: a dependent
 * whose rule is `null` or `set_value` keeps its row whole when the parent
 * goes, so it does not make the parent a non-leaf.
 *
 * Reads the registered rules (del_deletion_rules, which update_database and
 * plugin sync write from every model's $foreign_key_actions) and the
 * database's own foreign keys.
 *
 * Run: php tests/schema/deletion_cascade_leaf_test.php
 */

if (php_sapi_name() !== 'cli') { echo "This test must be run from the command line.\n"; exit(1); }

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

$db = DbConnector::get_instance()->get_db_link();

section('Registered rules');
$count = (int)$db->query("SELECT count(*) FROM del_deletion_rules WHERE del_action = 'cascade'")->fetchColumn();
check($count > 0, 'there are cascade rules to check', "$count");

section('A cascade lands on a table with no dependents of its own');
// A target is a leaf when no rule of its own removes or guards anything:
// 'null' and 'set_value' leave the dependent row whole, so they do not count.
$rows = $db->query("
	SELECT r.del_source_table, r.del_target_table, r.del_target_column,
	       string_agg(DISTINCT c.del_target_table || ' (' || c.del_action || ')', ', ') AS dependents
	FROM del_deletion_rules r
	JOIN del_deletion_rules c
	  ON c.del_source_table = r.del_target_table
	 AND c.del_action IN ('cascade', 'permanent_delete', 'prevent')
	WHERE r.del_action = 'cascade'
	GROUP BY 1, 2, 3
	ORDER BY 2, 3")->fetchAll(PDO::FETCH_ASSOC);
$names = array();
foreach ($rows as $r) {
	$names[] = $r['del_source_table'] . ' -> ' . $r['del_target_table'] . '.' . $r['del_target_column']
		. ' (which has ' . $r['dependents'] . ')';
}
check(count($rows) === 0, 'every cascade target is a leaf; declare permanent_delete for one that is not',
	implode('; ', $names));

section('No database foreign key refuses a cascade');
// A child whose foreign key restricts (or takes no action on) its parent makes
// a flat DELETE of that parent fail outright.
$fk = $db->query("
	SELECT r.del_source_table, r.del_target_table, c.conrelid::regclass::text AS child
	FROM del_deletion_rules r
	JOIN pg_constraint c
	  ON c.contype = 'f'
	 AND c.confrelid::regclass::text = r.del_target_table
	 AND c.confdeltype IN ('r', 'a')
	WHERE r.del_action = 'cascade'
	ORDER BY 2")->fetchAll(PDO::FETCH_ASSOC);
$names = array();
foreach ($fk as $r) {
	$names[] = $r['del_source_table'] . ' -> ' . $r['del_target_table'] . ' (held by ' . $r['child'] . ')';
}
check(count($fk) === 0, 'no cascade target is held by a restricting foreign key', implode('; ', $names));

harness_finish();
