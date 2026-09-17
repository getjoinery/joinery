<?php
/**
 * CalendarPreference and MultiCalendarPreference classes
 *
 * Per-user calendar email preferences: summary frequency + send hour, and the
 * default reminder lead applied to entries that don't carry their own override.
 * One row per user; absence of a row means the factory defaults (everything
 * off). Edited on /profile/calendar_settings; consumed by CalendarEmailEngine.
 *
 * @version 1.0
 */

class CalendarPreferenceException extends SystemBaseException {}

class CalendarPreference extends SystemBase {
	public static $prefix = 'cpr';
	public static $tablename = 'cpr_calendar_preferences';
	public static $pkey_column = 'cpr_calendar_preference_id';

	// REST CRUD exposure (Layer 1). User-owned (Bucket B): readable + writable
	// under the deny-by-default owner-or-staff row scope.
	public static $api_readable = true;
	public static $api_writable = true;

	// REST API per-record scope: only the owner (or staff, permission >= 5) may read or write this row via the API.
	function authenticate_read($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError('Current user does not have permission to view this entry in '. static::$tablename);
			}
		}
	}

	function authenticate_write($data) {
		if ($this->get(static::$prefix.'_usr_user_id') != $data['current_user_id']) {
			if ($data['current_user_permission'] < 5) {
				throw new SystemAuthenticationError('Current user does not have permission to edit this entry in '. static::$tablename);
			}
		}
	}

	protected static $foreign_key_actions = [
		'cpr_usr_user_id' => ['action' => 'permanent_delete'],
	];

	/** Reminder leads the platform offers, minutes before start. 0 = off. */
	const REMINDER_MINUTE_CHOICES = [0, 60, 30, 15, 5];
	const SUMMARY_FREQUENCIES = ['none', 'daily', 'weekly'];

	public static $field_specifications = array(
		'cpr_calendar_preference_id'   => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'cpr_usr_user_id'              => array('type' => 'int4', 'required' => true, 'unique' => true),
		// none | daily | weekly
		'cpr_summary_frequency'        => array('type' => 'varchar(10)', 'default' => 'none'),
		// Local hour (0-23, in the user's usr_timezone) summaries go out at.
		'cpr_summary_hour'             => array('type' => 'int4', 'default' => 7),
		// Default lead applied to entries with cal_reminder_minutes NULL. 0 = off.
		'cpr_reminder_default_minutes' => array('type' => 'int4', 'default' => 0),
		'cpr_create_time'              => array('type' => 'timestamp(6)', 'default' => 'now()'),
		'cpr_update_time'              => array('type' => 'timestamp(6)'),
	);

	/**
	 * The preference row for a user, or an unsaved defaults object when the user
	 * has never touched calendar settings — callers read effective values from
	 * the return without branching on row existence. Check ->key to tell them apart.
	 */
	public static function get_for($user_id): CalendarPreference {
		$multi = new MultiCalendarPreference(array('user_id' => $user_id), array(), 1);
		$multi->load();
		foreach ($multi as $pref) {
			return $pref;
		}
		$pref = new CalendarPreference(NULL);
		$pref->set('cpr_usr_user_id', (int)$user_id);
		$pref->set('cpr_summary_frequency', 'none');
		$pref->set('cpr_summary_hour', 7);
		$pref->set('cpr_reminder_default_minutes', 0);
		return $pref;
	}
}

class MultiCalendarPreference extends SystemMultiBase {
	protected static $model_class = 'CalendarPreference';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['user_id'])) {
			$filters['cpr_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}

		// Batch lookup for many owners at once (reminder pass default resolution).
		// IDs are server-supplied row keys, cast to int — safe to inline as IN (...).
		if (isset($this->options['user_ids']) && is_array($this->options['user_ids'])) {
			$ids = array_values(array_filter(array_map('intval', $this->options['user_ids'])));
			if ($ids) {
				$filters['cpr_usr_user_id'] = 'IN (' . implode(',', $ids) . ')';
			} else {
				$filters['cpr_usr_user_id'] = '= -1';
			}
		}

		// Users who asked for a summary at all (the summary pass worklist).
		if (!empty($this->options['summary_active'])) {
			$filters['cpr_summary_frequency'] = "IN ('daily', 'weekly')";
		}

		return $this->_get_resultsv2('cpr_calendar_preferences', $filters, $this->order_by, $only_count, $debug);
	}
}
