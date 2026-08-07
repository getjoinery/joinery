<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class ScheduleWindowException extends SystemBaseException {}

class ScheduleWindow extends SystemBase {
	public static $prefix = 'scw';
	public static $tablename = 'scw_schedule_windows';
	public static $pkey_column = 'scw_schedule_window_id';

	public static $field_specifications = array(
		'scw_schedule_window_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'scw_sch_schedule_id' => array('type'=>'int8', 'is_nullable'=>false, 'required'=>true),
		'scw_day_of_week' => array('type'=>'int2', 'is_nullable'=>false, 'required'=>true),
		'scw_start_time' => array('type'=>'time', 'is_nullable'=>false, 'required'=>true),
		'scw_end_time' => array('type'=>'time', 'is_nullable'=>false, 'required'=>true),
		'scw_delete_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	// What happens to these rows when a referenced parent is deleted — docs/deletion_system.md
	protected static $foreign_key_actions = array(
		'scw_sch_schedule_id' => array(
			'action' => 'cascade'
		)
	);

	// Cleanup when permanent_delete() runs on a row of this model — docs/deletion_system.md

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

class MultiScheduleWindow extends SystemMultiBase {
	protected static $model_class = 'ScheduleWindow';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];
		if (isset($this->options['schedule_id'])) {
			$filters['scw_sch_schedule_id'] = [$this->options['schedule_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['day_of_week'])) {
			$filters['scw_day_of_week'] = [$this->options['day_of_week'], PDO::PARAM_INT];
		}
		if (isset($this->options['deleted'])) {
			$filters['scw_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('scw_schedule_windows', $filters, $this->order_by, $only_count, $debug);
	}
}
?>