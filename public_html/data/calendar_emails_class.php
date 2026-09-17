<?php
/**
 * CalendarEmail and MultiCalendarEmail classes
 *
 * Send ledger for calendar reminder and summary emails. This is a dedup
 * ledger, not an audit trail: cme_dedup_key's unique constraint is the
 * at-most-once guarantee — the engine claims the key before sending, so a
 * re-run (or a concurrent run) can never email the same reminder twice.
 * The descriptive columns exist for reporting and admin inspection.
 *
 * @version 1.0
 */

class CalendarEmailException extends SystemBaseException {}

class CalendarEmail extends SystemBase {
	public static $prefix = 'cme';
	public static $tablename = 'cme_calendar_emails';
	public static $pkey_column = 'cme_calendar_email_id';

	const KIND_REMINDER       = 'reminder';
	const KIND_SUMMARY_DAILY  = 'summary_daily';
	const KIND_SUMMARY_WEEKLY = 'summary_weekly';

	protected static $foreign_key_actions = [
		'cme_usr_user_id'  => ['action' => 'permanent_delete'],
		'cme_cal_entry_id' => ['action' => 'cascade'],
	];

	// Retention: the daily sweep deletes rows older than the window.
	// 0 in the setting means never purge. See docs/scheduled_tasks.md.
	public static $retention_policy = array(
		'label'          => 'Calendar email log',
		'age_column'     => 'cme_send_time',
		'age_unit'       => 'days',
		'window_setting' => 'calendar_email_log_retention_days',
	);

	public static $field_specifications = array(
		'cme_calendar_email_id'    => array('type' => 'int8', 'is_nullable' => false, 'serial' => true, 'is_primary_key' => true),
		'cme_usr_user_id'          => array('type' => 'int4', 'required' => true),
		// reminder | summary_daily | summary_weekly
		'cme_kind'                 => array('type' => 'varchar(20)', 'required' => true),
		// Reminders only; NULL for summaries.
		'cme_cal_entry_id'         => array('type' => 'int8', 'is_nullable' => true),
		'cme_occurrence_start_utc' => array('type' => 'timestamp(6)', 'is_nullable' => true),
		// Summaries only: local Y-m-d the period starts on.
		'cme_period_key'           => array('type' => 'varchar(10)', 'is_nullable' => true),
		'cme_dedup_key'            => array('type' => 'varchar(160)', 'is_nullable' => false, 'required' => true, 'unique' => true),
		'cme_send_time'            => array('type' => 'timestamp(6)', 'default' => 'now()'),
	);

	public static function reminderKey($entry_id, string $occurrence_start_utc): string {
		return 'reminder:' . (int)$entry_id . ':' . $occurrence_start_utc;
	}

	public static function summaryKey(string $kind, $user_id, string $period_key): string {
		return $kind . ':' . (int)$user_id . ':' . $period_key;
	}

	/**
	 * Claim a dedup key. Returns the saved row, or NULL when the key is already
	 * claimed (this email has been handled). The unique constraint makes the
	 * claim atomic; callers send only after a successful claim.
	 */
	public static function claim($user_id, string $kind, string $dedup_key, $entry_id = null, ?string $occurrence_start_utc = null, ?string $period_key = null): ?CalendarEmail {
		$row = new CalendarEmail(NULL);
		$row->set('cme_usr_user_id', (int)$user_id);
		$row->set('cme_kind', $kind);
		$row->set('cme_dedup_key', $dedup_key);
		if ($entry_id !== null) {
			$row->set('cme_cal_entry_id', (int)$entry_id);
		}
		if ($occurrence_start_utc !== null) {
			$row->set('cme_occurrence_start_utc', $occurrence_start_utc);
		}
		if ($period_key !== null) {
			$row->set('cme_period_key', $period_key);
		}
		try {
			$row->save();
		} catch (Exception $e) {
			return NULL; // key already claimed (unique violation) — nothing to send
		}
		return $row;
	}
}

class MultiCalendarEmail extends SystemMultiBase {
	protected static $model_class = 'CalendarEmail';

	protected function getMultiResults($only_count = false, $debug = false) {
		$filters = array();

		if (isset($this->options['user_id'])) {
			$filters['cme_usr_user_id'] = array($this->options['user_id'], PDO::PARAM_INT);
		}

		if (isset($this->options['kind'])) {
			$filters['cme_kind'] = array($this->options['kind'], PDO::PARAM_STR);
		}

		if (isset($this->options['dedup_key'])) {
			$filters['cme_dedup_key'] = array($this->options['dedup_key'], PDO::PARAM_STR);
		}

		if (isset($this->options['cal_entry_id'])) {
			$filters['cme_cal_entry_id'] = array($this->options['cal_entry_id'], PDO::PARAM_INT);
		}

		return $this->_get_resultsv2('cme_calendar_emails', $filters, $this->order_by, $only_count, $debug);
	}
}
