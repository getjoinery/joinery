<?php

require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SingleRowAccessor.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));
require_once(PathHelper::getIncludePath('includes/Validator.php'));
require_once(PathHelper::getIncludePath('includes/calendar/CalendarSubject.php'));

class ScheduleException extends SystemBaseException {}

class Schedule extends SystemBase {
	public static $prefix = 'sch';
	public static $tablename = 'sch_schedules';
	public static $pkey_column = 'sch_schedule_id';

	// AI model surface (joinery_ai) — plugins/joinery_ai/docs/overview.md
	public static $ai_readable = true;
	public static $ai_description = 'A schedulable subject\'s working hours: one row per subject defining their availability timezone.';
	// The owner is a CalendarSubject (sch_subject_type + sch_subject_id), not a
	// single owner column — same polymorphic shape as CalendarEntry (type=user only).
	public static $ai_owner_field = ['polymorphic' => [
		'type_column' => 'sch_subject_type',
		'id_column'   => 'sch_subject_id',
		'type_value'  => 'user',
	]];

	public static $field_specifications = array(
		'sch_schedule_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true, 'is_primary_key'=>true),
		'sch_subject_type' => array('type'=>'varchar(32)', 'is_nullable'=>false, 'required'=>true, 'unique_with'=>array('sch_subject_id')),
		'sch_subject_id' => array('type'=>'int8', 'is_nullable'=>false, 'required'=>true),
		'sch_timezone' => array('type'=>'varchar(64)', 'is_nullable'=>false, 'required'=>true),
		'sch_create_time' => array('type'=>'timestamp', 'default'=>'now()'),
		'sch_update_time' => array('type'=>'timestamp'),
		'sch_delete_time' => array('type'=>'timestamp(6)', 'is_nullable'=>true),
	);

	// The owner column (sch_subject_id) is polymorphic, so it does not match the
	// FK auto-detection convention and no generic cascade can be built for it.
	// Owner cleanup is handled subject-aware in CalendarSubject::purge() — see
	// docs/calendar.md and docs/deletion_system.md.
	protected static $foreign_key_actions = array();

	// Cleanup when permanent_delete() runs on a row of this model — docs/deletion_system.md

	// Polymorphic ownership. A schedule is owned by a CalendarSubject, not a real
	// usr_users FK, so the owner-or-staff check resolves the subject first: only a
	// user-typed subject maps to a user id. Other subject types (resource/team/
	// venue) are reserved and have no per-user owner yet, so they are staff-only.
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

	/** True when this schedule's subject is the given user. */
	private function user_owns($user_id) {
		return $this->get('sch_subject_type') === CalendarSubject::TYPE_USER
			&& (int)$this->get('sch_subject_id') === (int)$user_id;
	}

	/** The CalendarSubject this schedule belongs to. */
	function subject() {
		return new CalendarSubject($this->get('sch_subject_type'), $this->get('sch_subject_id'));
	}

	/** Load the single schedule for a subject, or null. (One row per subject.) */
	static function for_subject(CalendarSubject $subject) {
		$results = new MultiSchedule([
			'subject_type' => $subject->type,
			'subject_id'   => $subject->id,
			'deleted'      => false,
		]);
		$results->load();
		return count($results) ? $results->get(0) : null;
	}

	/** Load-or-create the subject's single schedule, seeded with their timezone. */
	static function get_or_create_for_subject(CalendarSubject $subject) {
		$existing = self::for_subject($subject);
		if ($existing) {
			return $existing;
		}
		$schedule = new Schedule(NULL);
		$schedule->set('sch_subject_type', $subject->type);
		$schedule->set('sch_subject_id', $subject->id);
		$schedule->set('sch_timezone', $subject->getTimezone());
		$schedule->save();
		return $schedule;
	}
}

class MultiSchedule extends SystemMultiBase {
	protected static $model_class = 'Schedule';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = [];
		if (isset($this->options['subject_type'])) {
			$filters['sch_subject_type'] = [$this->options['subject_type'], PDO::PARAM_STR];
		}
		if (isset($this->options['subject_id'])) {
			$filters['sch_subject_id'] = [$this->options['subject_id'], PDO::PARAM_INT];
		}

		return $this->_get_resultsv2('sch_schedules', $filters, $this->order_by, $only_count, $debug);
	}
}
?>