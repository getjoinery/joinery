<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));

class ScheduleOverrideException extends SystemBaseException {}

class ScheduleOverride extends SystemBase {
	public static $prefix = 'sco';
	public static $tablename = 'sco_schedule_overrides';
	public static $access_class = 'config';
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

	/**
	 * A row of a schedule is its schedule owner's: the person whose
	 * availability it is may add, change and remove it. The stored schedule id
	 * decides, so pointing a row at someone else's schedule makes it theirs,
	 * not yours.
	 */
	public function access_owner_is($user_id): bool {
		$schedule_id = (int)$this->access_stored('sco_sch_schedule_id');
		if ((int)$user_id <= 0 || $schedule_id <= 0) {
			return false;
		}
		$schedule = new Schedule($schedule_id, TRUE);
		return $schedule->key && $schedule->access_owner_is($user_id);
	}

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

		return $this->_get_resultsv2('sco_schedule_overrides', $filters, $this->order_by, $only_count, $debug);
	}
}
?>