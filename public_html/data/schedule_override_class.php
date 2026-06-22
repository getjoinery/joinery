<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class ScheduleOverrideException extends SystemBaseException {}

class ScheduleOverride extends SystemBase {
	public static $prefix = 'sco';
	public static $tablename = 'sco_schedule_overrides';
	public static $pkey_column = 'sco_schedule_override_id';

	public static $field_specifications = array(
		'sco_schedule_override_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'sco_sch_schedule_id' => array('type'=>'int8', 'is_nullable'=>false, 'required'=>true),
		'sco_date' => array('type'=>'date', 'is_nullable'=>false, 'required'=>true),
		'sco_start_time' => array('type'=>'time'),
		'sco_end_time' => array('type'=>'time'),
		'sco_delete_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	// What happens to these rows when a referenced parent is deleted — docs/deletion_system.md
	protected static $foreign_key_actions = array(
		'sco_sch_schedule_id' => array(
			'action' => 'cascade'
		)
	);

	// Cleanup when permanent_delete() runs on a row of this model — docs/deletion_system.md
	public static $permanent_delete_actions = array();

	// Business-rule extension point. TODO: add cross-field validation, computed
	// export_as_array() keys, or relationship loading here. Override prepare()
	// for validation (docs/validation.md); note prepare() is not guaranteed to
	// run before save() — mandatory transforms belong in save().
	// function prepare() {
	//     $result = parent::prepare();
	//     // ... your checks; set $result['success'] = false and append messages on failure ...
	//     return $result;
	// }
}

class MultiScheduleOverride extends SystemMultiBase {
	protected static $model_class = 'ScheduleOverride';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];
		if (isset($this->options['schedule_id'])) {
			$filters['sco_sch_schedule_id'] = [$this->options['schedule_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['date'])) {
			$filters['sco_date'] = [$this->options['date'], PDO::PARAM_STR];
		}
		if (isset($this->options['deleted'])) {
			$filters['sco_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('sco_schedule_overrides', $filters, $this->order_by, $only_count, $debug);
	}
}
?>