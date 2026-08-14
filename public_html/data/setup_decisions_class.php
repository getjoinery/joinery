<?php
/**
 * SetupDecision and MultiSetupDecision classes
 *
 * A recorded "not now" answer to an optional setup step (specs/setup_wizard.md).
 * The row means the question was asked and answered — never that the step is
 * complete. Real state always wins: a step whose live predicate is satisfied
 * ignores its decision row. sud_usr_user_id NULL is a site-scope decision.
 *
 * @version 1.0
 */

class SetupDecisionException extends SystemBaseException {}

class SetupDecision extends SystemBase {
	public static $prefix = 'sud';
	public static $tablename = 'sud_setup_decisions';
	public static $pkey_column = 'sud_setup_decision_id';

	protected static $foreign_key_actions = [
		'sud_usr_user_id' => ['action' => 'permanent_delete'],
	];

	public static $field_specifications = array(
		'sud_setup_decision_id' => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'sud_step_key'          => array('type' => 'varchar(64)', 'required' => true),
		// NULL = a site-scope decision (made by the owner for the deployment).
		'sud_usr_user_id'       => array('type' => 'int4'),
		'sud_decision'          => array('type' => 'varchar(20)', 'is_nullable' => false, 'default' => 'declined'),
		'sud_create_time'       => array('type' => 'timestamp(6)', 'default' => 'now()'),
	);
}

class MultiSetupDecision extends SystemMultiBase {
	protected static $model_class = 'SetupDecision';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['step_key'])) {
			$filters['sud_step_key'] = array($this->options['step_key'], PDO::PARAM_STR);
		}

		// user_id => NULL selects site-scope rows; an int selects that user's rows.
		if (array_key_exists('user_id', $this->options)) {
			if ($this->options['user_id'] === NULL) {
				$filters['sud_usr_user_id'] = 'IS NULL';
			} else {
				$filters['sud_usr_user_id'] = array((int)$this->options['user_id'], PDO::PARAM_INT);
			}
		}

		return $this->_get_resultsv2('sud_setup_decisions', $filters, $this->order_by, $only_count, $debug);
	}
}
