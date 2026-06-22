<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));

class CalendarEntryException extends SystemBaseException {}

class CalendarEntry extends SystemBase {
	public static $prefix = 'cal';
	public static $tablename = 'cal_items';
	public static $pkey_column = 'cal_calendar_entry_id';

	// AI model surface (joinery_ai) — plugins/joinery_ai/docs/overview.md
	public static $ai_readable = true;
	public static $ai_description = 'Native personal calendar entries: appointments and blocked-out time created directly on a subject\'s calendar.';

	public static $field_specifications = array(
		'cal_calendar_entry_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'cal_subject_type' => array('type'=>'varchar(32)', 'is_nullable'=>false, 'required'=>true),
		'cal_subject_id' => array('type'=>'int8', 'is_nullable'=>false, 'required'=>true),
		'cal_start_utc' => array('type'=>'timestamp(6)', 'is_nullable'=>false, 'required'=>true),
		'cal_end_utc' => array('type'=>'timestamp(6)', 'is_nullable'=>false, 'required'=>true),
		'cal_all_day' => array('type'=>'bool', 'default'=>false),
		'cal_title' => array('type'=>'varchar(255)'),
		'cal_blocks_availability' => array('type'=>'bool', 'default'=>true),
		'cal_visibility' => array('type'=>'varchar(16)', 'default'=>'details'),
		'cal_type' => array('type'=>'varchar(16)', 'default'=>'personal'),
		'cal_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
		'cal_update_time' => array('type'=>'timestamp(6)'),
		'cal_delete_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	// What happens to these rows when a referenced parent is deleted — docs/deletion_system.md
	protected static $foreign_key_actions = array(
		'cal_subject_id' => array(
			'action' => 'cascade'
		)
	);

	// Cleanup when permanent_delete() runs on a row of this model — docs/deletion_system.md
	public static $permanent_delete_actions = array();

	// Polymorphic ownership: a native entry is owned by a CalendarSubject, not a
	// real usr_users FK. Only a user-typed subject maps to a user id; reserved
	// subject types (resource/team/venue) have no per-user owner yet and are
	// staff-only. Mirrors Schedule's owner-or-staff scope.
	function authenticate_read($data) {
		if (!$this->user_owns($data['current_user_id'])
			&& (int)$data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to view this entry in ' . static::$tablename);
		}
	}

	function authenticate_write($data) {
		if (!$this->user_owns($data['current_user_id'])
			&& (int)$data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in ' . static::$tablename);
		}
	}

	/** True when this entry's subject is the given user. */
	private function user_owns($user_id) {
		return $this->get('cal_subject_type') === CalendarSubject::TYPE_USER
			&& (int)$this->get('cal_subject_id') === (int)$user_id;
	}

	/** The CalendarSubject this entry belongs to. */
	function subject() {
		return new CalendarSubject($this->get('cal_subject_type'), $this->get('cal_subject_id'));
	}
}

class MultiCalendarEntry extends SystemMultiBase {
	protected static $model_class = 'CalendarEntry';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];
		if (isset($this->options['subject_type'])) {
			$filters['cal_subject_type'] = [$this->options['subject_type'], PDO::PARAM_STR];
		}
		if (isset($this->options['subject_id'])) {
			$filters['cal_subject_id'] = [$this->options['subject_id'], PDO::PARAM_INT];
		}
		if (isset($this->options['deleted'])) {
			$filters['cal_delete_time'] = $this->options['deleted'] ? "IS NOT NULL" : "IS NULL";
		}

		return $this->_get_resultsv2('cal_items', $filters, $this->order_by, $only_count, $debug);
	}
}
?>