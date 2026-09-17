<?php
/**
 * BookingType — a bookable appointment kind: duration, windows, notice, buffers.
 *
 * @version 1.1 - prefix bty, table bty_booking_types: bkt is BackupTarget's alone
 *   (specs/implemented/shared_prefixes_first_three.md)
 */
require_once(PathHelper::getIncludePath('includes/LibraryFunctions.php'));
require_once(PathHelper::getIncludePath('includes/SystemBase.php'));

class BookingTypeException extends SystemBaseException {}

class BookingType extends SystemBase {

	public static $prefix = 'bty';
	public static $tablename = 'bty_booking_types';
	public static $pkey_column = 'bty_booking_type_id';

	const BOOKING_STATUS_INACTIVE = 0;
	const BOOKING_STATUS_ACTIVE = 1;

	public static $field_specifications = array(
	    'bty_booking_type_id' => array('type'=>'int8', 'is_nullable'=>false, 'serial'=>true),
	    // Provider: native is the only shipped implementation; externals slot in later.
	    'bty_provider' => array('type'=>'varchar(32)', 'default'=>'native'),
	    'bty_external_type_uri' => array('type'=>'varchar(255)'),
	    // Host: availability is this user's one schedule.
	    'bty_usr_user_id' => array('type'=>'int8'),
	    'bty_pro_product_id' => array('type'=>'int4'),
	    'bty_svy_survey_id' => array('type'=>'int8'),
	    'bty_name' => array('type'=>'varchar(255)'),
	    'bty_slug' => array('type'=>'varchar(255)', 'unique'=>true),
	    'bty_description_html' => array('type'=>'text'),
	    'bty_description_plain' => array('type'=>'text'),
	    'bty_status' => array('type'=>'int4', 'zero_on_create'=>true),
	    // Slot shape.
	    'bty_duration_minutes' => array('type'=>'int4'),
	    'bty_slot_increment_minutes' => array('type'=>'int4', 'default'=>30),
	    'bty_buffer_before_minutes' => array('type'=>'int4', 'default'=>0),
	    'bty_buffer_after_minutes' => array('type'=>'int4', 'default'=>0),
	    'bty_min_notice_minutes' => array('type'=>'int4', 'default'=>240),
	    'bty_rolling_days' => array('type'=>'int4', 'default'=>60),
	    'bty_window_start' => array('type'=>'date'),
	    'bty_window_end' => array('type'=>'date'),
	    'bty_max_per_day' => array('type'=>'int4'),
	    'bty_max_per_week' => array('type'=>'int4'),
	    // Location.
	    'bty_location_mode' => array('type'=>'varchar(32)'),
	    'bty_location_details' => array('type'=>'text'),
	    // Cancellation policy.
	    'bty_cancel_notice_minutes' => array('type'=>'int4'),
	    'bty_cancellation_policy_text' => array('type'=>'text'),
	    // Reminders / follow-ups.
	    'bty_send_native_emails' => array('type'=>'bool', 'default'=>true),
	    'bty_reminder_minutes_csv' => array('type'=>'varchar(64)', 'default'=>'1440,60'),
	    'bty_create_time' => array('type'=>'timestamp(6)', 'default'=>'now()'),
	    'bty_delete_time' => array('type'=>'timestamp(6)'),
	    'bty_update_time' => array('type'=>'timestamp(6)'),
	);

	public static $field_constraints = array();

	// A survey attached to a booking type for response collection is
	// optional - deleting the survey should just detach it, not delete the
	// booking type.
	protected static $foreign_key_actions = [
		'bty_svy_survey_id' => ['action' => 'null'],
		'bty_usr_user_id' => ['action' => 'permanent_delete'],
		'bty_pro_product_id' => ['action' => 'null'],
	];

	/** Resolve a booking type by its globally-unique public slug. */
	static function GetBySlug($slug) {
		$results = new MultiBookingType(array('slug' => $slug, 'deleted' => false));
		$results->load();
		return count($results) ? $results->get(0) : false;
	}

	function is_active() {
		return (int)$this->get('bty_status') === self::BOOKING_STATUS_ACTIVE;
	}

	/** Reminder offsets (minutes before start) parsed from the CSV config. */
	function reminder_offsets() {
		$csv = $this->get('bty_reminder_minutes_csv');
		if (!$csv) { return array(); }
		$out = array();
		foreach (explode(',', $csv) as $part) {
			$n = (int)trim($part);
			if ($n > 0) { $out[] = $n; }
		}
		return $out;
	}

	function authenticate_write($data) {
		if ($this->get('bty_usr_user_id') != $data['current_user_id']
			&& (int)$data['current_user_permission'] < 5) {
			throw new SystemAuthenticationError(
				'Current user does not have permission to edit this entry in '. static::$tablename);
		}
	}

}

class MultiBookingType extends SystemMultiBase {
	protected static $model_class = 'BookingType';

	protected function getMultiResults($only_count = false, $debug = false) {
        $filters = [];

        if (isset($this->options['user_id'])) {
            $filters['bty_usr_user_id'] = [$this->options['user_id'], PDO::PARAM_INT];
        }

        if (isset($this->options['slug'])) {
            $filters['bty_slug'] = [$this->options['slug'], PDO::PARAM_STR];
        }

        if (isset($this->options['status'])) {
            $filters['bty_status'] = [$this->options['status'], PDO::PARAM_INT];
        }

        if (isset($this->options['active'])) {
            $filters['bty_status'] = "= " . ($this->options['active'] ? '1' : '0');
        }

        if (isset($this->options['provider'])) {
            $filters['bty_provider'] = [$this->options['provider'], PDO::PARAM_STR];
        }


        return $this->_get_resultsv2('bty_booking_types', $filters, $this->order_by, $only_count, $debug);
    }

}
