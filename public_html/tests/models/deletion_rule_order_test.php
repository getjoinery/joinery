<?php
/** @joinery-test
 * name: deletion_rule_order
 * tier: db
 * env: dev-only
 * needs: []
 */
/**
 * A dependent's own permanent_delete() may write rows under the same parent,
 * and those rows must not outlive the parent.
 *
 * SystemBase::permanent_delete() walks a record's deletion rules as
 * SystemBase::DELETION_RULE_ORDER says: prevent, then each model-level
 * permanent_delete, then the flat actions. Walked by rule id alone, a flat
 * cascade listed before a model-level rule ran first and found nothing, and
 * the rows the model wrote on its way out were stranded. That is how deleting
 * a user left their DNS filtering devices' PIN backups behind: the device
 * records its PIN in the user's device backups as it is deleted.
 *
 * The fixture is three temporary tables — a parent, a child whose own
 * permanent_delete() writes a log row under the parent, and that log — with
 * the log's flat cascade given the LOWER rule id, the order that stranded
 * rows before. Everything runs inside one transaction that is rolled back,
 * so no table, row or deletion rule outlives the test.
 *
 * Run: php tests/models/deletion_rule_order_test.php
 *
 * @version 1.0
 */

require_once(__DIR__ . '/../lib/harness.php');
harness_boot();

class DroParent extends SystemBase {
	public static $prefix = 'drp';
	public static $tablename = 'drp_dro_parents';
	public static $pkey_column = 'drp_dro_parent_id';
	public static $field_specifications = array(
		'drp_dro_parent_id' => array('type' => 'int8', 'serial' => true),
	);

	// The fixture's tables are temporary, so model discovery cannot see them.
	protected static function getModelClassForTable($table_name) {
		return $table_name === DroChild::$tablename ? 'DroChild' : parent::getModelClassForTable($table_name);
	}
}

class DroChild extends SystemBase {
	public static $prefix = 'drc';
	public static $tablename = 'drc_dro_children';
	public static $pkey_column = 'drc_dro_child_id';
	public static $field_specifications = array(
		'drc_dro_child_id' => array('type' => 'int8', 'serial' => true),
		'drc_drp_dro_parent_id' => array('type' => 'int8'),
	);

	/** Like a DNS filtering device: record something under the parent on the way out. */
	function permanent_delete($debug = false) {
		$db = DbConnector::get_instance()->get_db_link();
		$db->prepare('INSERT INTO drl_dro_logs (drl_drp_dro_parent_id) VALUES (?)')
			->execute(array($this->get('drc_drp_dro_parent_id')));
		return parent::permanent_delete($debug);
	}
}

$db = DbConnector::get_instance()->get_db_link();
$db->beginTransaction();
try {
	section('Setup (temporary tables and rules, rolled back at the end)');
	$db->exec('CREATE TEMP TABLE drp_dro_parents (drp_dro_parent_id bigserial PRIMARY KEY)');
	$db->exec('CREATE TEMP TABLE drc_dro_children (drc_dro_child_id bigserial PRIMARY KEY, drc_drp_dro_parent_id bigint)');
	$db->exec('CREATE TEMP TABLE drl_dro_logs (drl_dro_log_id bigserial PRIMARY KEY, drl_drp_dro_parent_id bigint)');
	$rule = $db->prepare('INSERT INTO del_deletion_rules (del_source_table, del_target_table, del_target_column, del_action)
		VALUES (?, ?, ?, ?)');
	// The flat rule first, so it has the lower id: the order that stranded rows.
	$rule->execute(array('drp_dro_parents', 'drl_dro_logs', 'drl_drp_dro_parent_id', 'cascade'));
	$rule->execute(array('drp_dro_parents', 'drc_dro_children', 'drc_drp_dro_parent_id', 'permanent_delete'));

	$parent_id = (int)$db->query('INSERT INTO drp_dro_parents DEFAULT VALUES RETURNING drp_dro_parent_id')->fetchColumn();
	$db->prepare('INSERT INTO drc_dro_children (drc_drp_dro_parent_id) VALUES (?), (?)')->execute(array($parent_id, $parent_id));
	// One log row already there, so the preview lists both dependent tables.
	$db->prepare('INSERT INTO drl_dro_logs (drl_drp_dro_parent_id) VALUES (?)')->execute(array($parent_id));
	check(true, 'a parent with two children and a log row');

	section('The walk order');
	$parent = new DroParent($parent_id, TRUE);
	$preview = $parent->permanent_delete_dry_run();
	$tables = array_map(fn($d) => $d['table'] ?? ($d['target_table'] ?? ''), $preview['dependencies']);
	$child_at = array_search('drc_dro_children', $tables, true);
	$log_at = array_search('drl_dro_logs', $tables, true);
	check($child_at !== false && $log_at !== false && $child_at < $log_at,
		'the preview walks the model-level rule before the flat one', json_encode($tables));

	section('Rows a dependent writes on its way out go with the parent');
	$parent->permanent_delete();
	$left = function ($table, $column) use ($db, &$parent_id) {
		$q = $db->prepare("SELECT COUNT(*) FROM $table WHERE $column = ?");
		$q->execute(array($parent_id));
		return (int)$q->fetchColumn();
	};
	check($left('drp_dro_parents', 'drp_dro_parent_id') === 0, 'the parent is gone');
	check($left('drc_dro_children', 'drc_drp_dro_parent_id') === 0, 'its children are gone');
	check($left('drl_dro_logs', 'drl_drp_dro_parent_id') === 0,
		'the log rows the children wrote while being deleted are gone too');

	section('prevent still refuses before anything is touched');
	$parent_id = (int)$db->query('INSERT INTO drp_dro_parents DEFAULT VALUES RETURNING drp_dro_parent_id')->fetchColumn();
	$db->prepare('INSERT INTO drc_dro_children (drc_drp_dro_parent_id) VALUES (?)')->execute(array($parent_id));
	$db->exec('CREATE TEMP TABLE drb_dro_blockers (drb_dro_blocker_id bigserial PRIMARY KEY, drb_drp_dro_parent_id bigint)');
	$db->prepare('INSERT INTO drb_dro_blockers (drb_drp_dro_parent_id) VALUES (?)')->execute(array($parent_id));
	$rule->execute(array('drp_dro_parents', 'drb_dro_blockers', 'drb_drp_dro_parent_id', 'prevent'));
	$db->exec('SAVEPOINT before_refusal');
	$refused = false;
	try {
		(new DroParent($parent_id, TRUE))->permanent_delete();
	} catch (SystemDisplayableError $e) {
		$refused = true;
	}
	// Counted before the rollback below, which would undo a premature delete.
	$logs = $left('drl_dro_logs', 'drl_drp_dro_parent_id');
	$children = $left('drc_dro_children', 'drc_drp_dro_parent_id');
	$db->exec('ROLLBACK TO SAVEPOINT before_refusal');
	check($refused, 'a prevent rule with the highest id still refuses');
	check($logs === 0 && $children === 1,
		'and no child was deleted or logged before the refusal', "logs=$logs children=$children");
} catch (\Throwable $e) {
	check(false, 'unhandled exception mid-suite', $e->getMessage());
} finally {
	if ($db->inTransaction()) {
		$db->rollBack();
	}
}

harness_finish();
